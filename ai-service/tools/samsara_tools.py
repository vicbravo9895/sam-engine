"""
Tools para interactuar con la API de Samsara usando el SDK oficial.
Estas funciones son usadas por el panic_investigator agent.
"""

import functools
import base64
import json
from typing import Dict, Any, List, Optional
from datetime import datetime, timedelta

from samsara import AsyncSamsara
import httpx
from litellm import acompletion

from config import SamsaraConfig, OpenAIConfig
from core.context import current_langfuse_span, current_tool_tracker


def _safe_value_for_logging(value: Any, max_length: int = 200) -> Any:
    """Convierte valores arbitrarios en algo serializable/legible para logs."""
    if isinstance(value, (str, int, float, bool)) or value is None:
        if isinstance(value, str) and len(value) > max_length:
            return value[:max_length - 3] + "..."
        return value
    if isinstance(value, (list, tuple)):
        return [_safe_value_for_logging(v, max_length) for v in value[:5]]
    if isinstance(value, dict):
        limited_items = list(value.items())[:5]
        return {str(k): _safe_value_for_logging(v, max_length) for k, v in limited_items}
    return str(value)[:max_length]


def _serialize_tool_parameters(args: tuple, kwargs: dict) -> Dict[str, Any]:
    """Prepara los parámetros de una tool para logging/JSON."""
    serialized: Dict[str, Any] = {}
    if args:
        serialized["args"] = [_safe_value_for_logging(arg) for arg in args]
    if kwargs:
        serialized["kwargs"] = {key: _safe_value_for_logging(val) for key, val in kwargs.items()}
    return serialized


def _summarize_tool_result(result: Any, max_length: int = 300) -> str:
    """Genera un resumen corto del resultado de la tool."""
    try:
        if isinstance(result, (dict, list)):
            summary = json.dumps(result, ensure_ascii=False)
        else:
            summary = str(result)
    except Exception:
        summary = str(result)
    if len(summary) > max_length:
        return summary[:max_length - 3] + "..."
    return summary


def trace_tool(func):
    """
    Decorador para rastrear la ejecución de tools en Langfuse.
    Crea un span hijo del span actual del agente y captura metadata para actions.
    
    Soporta dos modos:
    - Modo legacy: agent_actions es un dict con "tools_used" como lista de dicts
    - Modo nuevo: agent_result es un dataclass AgentResult con tools como lista de ToolResult
    """
    @functools.wraps(func)
    async def wrapper(*args, **kwargs):
        span = current_langfuse_span.get()
        tracking_ctx = current_tool_tracker.get()

        tool_name = func.__name__
        serialized_params = _serialize_tool_parameters(args, kwargs)
        tool_entry = None
        tool_start = datetime.utcnow()
        use_new_format = False

        # Detectar qué formato usar
        if tracking_ctx:
            # Modo nuevo: usar agent_result y ToolResult dataclass
            if tracking_ctx.get("agent_result"):
                use_new_format = True
                # No creamos la entry aquí, la crearemos al finalizar
            # Modo legacy: usar agent_actions dict
            elif tracking_ctx.get("agent_actions"):
                tool_entry = {
                    "tool_name": tool_name,
                    "called_at": tool_start.isoformat() + "Z",
                    "status": "pending",
                    "parameters": serialized_params
                }
                tracking_ctx["agent_actions"]["tools_used"].append(tool_entry)
                if tracking_ctx.get("ai_actions") is not None:
                    tracking_ctx["ai_actions"]["total_tools_called"] += 1

        tool_span = None
        if span:
            tool_span = span.span(
                name=f"tool_{tool_name}",
                input=serialized_params,
                metadata={
                    "tool_name": tool_name,
                    "type": "tool_execution"
                }
            )

        try:
            result = await func(*args, **kwargs)
            if tool_span:
                tool_span.end(output=result)
            
            # Finalizar tracking
            if tracking_ctx:
                if use_new_format:
                    _create_tool_result(tracking_ctx, tool_name, tool_start, "success", result)
                elif tool_entry:
                    _finalize_tool_entry(
                        tool_entry,
                        tool_start,
                        status="success",
                        summary=_summarize_tool_result(result)
                    )
            return result
        except Exception as e:
            if tool_span:
                tool_span.end(
                    level="ERROR",
                    status_message=str(e),
                    output={"error": str(e)}
                )
            if tracking_ctx:
                if use_new_format:
                    _create_tool_result(tracking_ctx, tool_name, tool_start, "error", None, str(e))
                elif tool_entry:
                    _finalize_tool_entry(
                        tool_entry,
                        tool_start,
                        status="error",
                        summary=str(e)[:200]
                    )
            raise e

    return wrapper


def _create_tool_result(tracking_ctx: Dict, tool_name: str, start_time: datetime, status: str, result: Any, error: str = None):
    """Crea un ToolResult y lo agrega al agent_result."""
    from services.pipeline_executor import ToolResult, _generate_tool_summary, _extract_media_urls
    
    duration_ms = int((datetime.utcnow() - start_time).total_seconds() * 1000)
    
    if error:
        summary = error[:200]
    else:
        summary = _generate_tool_summary(tool_name, result)
    
    tool_result = ToolResult(
        name=tool_name,
        status=status,
        duration_ms=duration_ms,
        summary=summary
    )
    
    # Extraer media_urls para get_camera_media
    if tool_name == "get_camera_media" and result:
        print(f"[DEBUG] _create_tool_result: tool_name={tool_name}")
        print(f"[DEBUG] _create_tool_result: result type={type(result)}")
        if isinstance(result, dict):
            print(f"[DEBUG] _create_tool_result: result keys={result.keys()}")
        urls = _extract_media_urls(result)
        print(f"[DEBUG] _create_tool_result: extracted urls={urls}")
        tool_result.media_urls = urls
    
    # Agregar al agent_result
    agent_result = tracking_ctx.get("agent_result")
    if agent_result:
        if tool_name == "get_camera_media":
            print(f"[DEBUG] _create_tool_result: tool_result.media_urls = {tool_result.media_urls}")
        agent_result.tools.append(tool_result)
    
    # Actualizar contador
    executor = tracking_ctx.get("executor")
    if executor:
        executor._total_tools += 1


def _finalize_tool_entry(tool_entry: Dict[str, Any], start_time: datetime, status: str, summary: str) -> None:
    """Actualiza el registro del tool call con duración y resultado."""
    duration_ms = int((datetime.utcnow() - start_time).total_seconds() * 1000)
    tool_entry["duration_ms"] = duration_ms
    tool_entry["status"] = status
    tool_entry["result_summary"] = summary

def _get_client() -> AsyncSamsara:
    """Helper to get the AsyncSamsara client."""
    token = SamsaraConfig.API_TOKEN
    if not token:
        # Fallback for local dev without token if needed, or raise error
        # For now, we assume token is present or handled by caller
        pass
    return AsyncSamsara(token=token)


@trace_tool
async def get_vehicle_stats(
    vehicle_id: str, 
    event_time: str,
    types: Optional[List[str]] = None
) -> Dict[str, Any]:
    """
    Obtiene estadísticas históricas del vehículo alrededor del momento del evento.
    
    Args:
        vehicle_id: ID del vehículo en Samsara
        event_time: Timestamp ISO 8601 del evento (happenedAtTime de la alerta)
        types: Lista de tipos de estadísticas a recuperar (opcional)
               e.g. ["fuelPercents", "gps", "engineStates"]
        
    Returns:
        Dict con estadísticas del vehículo en la ventana de tiempo del evento.
    """
    client = _get_client()
    try:
        # Si no se especifican tipos, traemos los más comunes para contexto del evento
        if not types:
            types = ["gps", "engineStates", "fuelPercents", "batteryVoltages"]
        
        # Parsear el event_time y crear ventana de tiempo alrededor del evento
        # 5 minutos antes para ver el contexto previo
        # 2 minutos después para ver las consecuencias inmediatas
        event_dt = datetime.fromisoformat(event_time.replace('Z', '+00:00'))
        start_time = event_dt - timedelta(minutes=5)
        end_time = event_dt + timedelta(minutes=2)
        
        # Usamos vehicle_stats.get_vehicle_stats_history() según el SDK v4.1.0
        # Este método soporta start_time/end_time para obtener stats históricos
        # en una ventana de tiempo específica alrededor del evento
        
        response = await client.vehicle_stats.get_vehicle_stats_history(
            start_time=start_time.isoformat(),
            end_time=end_time.isoformat(),
            vehicle_ids=[vehicle_id],
            types=types
        )
        
        # Convertir a dict si es posible, o extraer data
        if hasattr(response, 'to_dict'):
            return response.to_dict()
        if hasattr(response, 'data'):
             # Si es una lista de objetos, intentamos serializarlos
            return {"data": [item.to_dict() if hasattr(item, 'to_dict') else str(item) for item in response.data]}
            
        return {"data": str(response)}

    except Exception as e:
        return {"error": str(e), "vehicle_id": vehicle_id, "event_time": event_time}


@trace_tool
async def get_vehicle_info(vehicle_id: str) -> Dict[str, Any]:
    """
    Obtiene información estática del vehículo (VIN, modelo, etc.).
    
    Args:
        vehicle_id: ID del vehículo
        
    Returns:
        Dict con información del vehículo.
    """
    client = _get_client()
    try:
        # CORRECCIÓN: Usar .get() en lugar de .get_vehicle()
        response = await client.vehicles.get(id=vehicle_id)
        
        if hasattr(response, 'to_dict'):
            return response.to_dict()
        if hasattr(response, 'data'):
            return response.data.to_dict() if hasattr(response.data, 'to_dict') else {"data": str(response.data)}
            
        return {"data": str(response)}
    except Exception as e:
        return {"error": str(e), "vehicle_id": vehicle_id}


@trace_tool
async def get_driver_assignment(
    vehicle_id: str, 
    timestamp_utc: str
) -> Dict[str, Any]:
    """
    Busca quién conducía el vehículo en un momento específico.
    
    Args:
        vehicle_id: ID del vehículo
        timestamp_utc: Timestamp ISO 8601 del momento de interés
        
    Returns:
        Dict con información de la asignación (conductor).
    """
    client = _get_client()
    try:
        ts = datetime.fromisoformat(timestamp_utc.replace('Z', '+00:00'))
        start_time = (ts - timedelta(minutes=10)).isoformat()
        end_time = (ts + timedelta(minutes=10)).isoformat()
        
        # Usamos get_driver_vehicle_assignments() según el SDK v4.1.0
        # IMPORTANTE: El SDK puede retornar data con driver=None lo que causa error de validación Pydantic
        # Manejamos esto capturando el error y retornando respuesta limpia
        try:
            response = await client.driver_vehicle_assignments.get_driver_vehicle_assignments(
                filter_by='vehicles',
                vehicle_ids=[vehicle_id]
            )
            
            if hasattr(response, 'to_dict'):
                return response.to_dict()
            if hasattr(response, 'data'):
                # Handle None data to prevent Pydantic validation error
                if response.data is None:
                    return {
                        "data": [], 
                        "vehicle_id": vehicle_id, 
                        "note": "No driver assignments found for this vehicle"
                    }
                return {
                    "data": [item.to_dict() if hasattr(item, 'to_dict') else str(item) for item in response.data]
                }
                
            return {"data": str(response)}
            
        except Exception as validation_error:
            # Si es un error de validación Pydantic (driver=None), retornar respuesta limpia
            error_str = str(validation_error)
            if "validation error" in error_str.lower() and "driver" in error_str.lower():
                return {
                    "data": [],
                    "vehicle_id": vehicle_id,
                    "note": "No driver information available for this vehicle at the specified time",
                    "warning": "Driver field was null in API response"
                }
            # Si es otro tipo de error, re-lanzar
            raise
        
    except Exception as e:
        return {"error": str(e), "vehicle_id": vehicle_id}


@trace_tool
async def get_camera_media(
    vehicle_id: str, 
    timestamp_utc: str,
    analyze_images: bool = True
) -> Dict[str, Any]:
    """
    Busca videos o imágenes de la cámara del vehículo alrededor del timestamp.
    Si analyze_images=True, usa AI para analizar las imágenes y detectar situaciones relevantes.
    
    Args:
        vehicle_id: ID del vehículo
        timestamp_utc: Timestamp ISO 8601 del evento
        analyze_images: Si True, analiza las imágenes con AI (default: True)
        
    Returns:
        Dict con urls de media encontrados y análisis de AI si está habilitado.
    """
    client = _get_client()
    try:
        ts = datetime.fromisoformat(timestamp_utc.replace('Z', '+00:00'))
        start_time = (ts - timedelta(minutes=2)).isoformat()
        end_time = (ts + timedelta(minutes=2)).isoformat()
        
        # Usamos media.list_uploaded_media() según el SDK v4.1.0
        # Método real disponible en AsyncMediaClient
        # Nota: vehicle_ids debe ser string, no lista
        
        response = await client.media.list_uploaded_media(
            vehicle_ids=vehicle_id,  # String, no lista
            start_time=start_time,
            end_time=end_time
        )
        
        result = {}
        media_items = []
        
        # Extraer los objetos de media correctamente del SDK response
        if hasattr(response, 'data') and response.data:
            # response.data es un objeto con atributo 'media', no un dict
            if hasattr(response.data, 'media'):
                media_items = response.data.media
            elif isinstance(response.data, dict):
                # Buscar la key 'media' que contiene la lista de objetos
                for key, value in response.data.items():
                    if isinstance(value, list):
                        media_items = value
                        break
            elif isinstance(response.data, list):
                media_items = response.data
        
        # Convertir media_items a dict para el resultado
        if media_items:
            result['data'] = []
            for item in media_items:
                try:
                    item_dict = {}
                    if hasattr(item, 'to_dict'):
                        item_dict = item.to_dict()
                    elif isinstance(item, dict):
                        item_dict = item.copy()
                    elif hasattr(item, '__dict__'):
                        item_dict = item.__dict__
                    else:
                        item_dict = {"raw": str(item)}
                    
                    # Flatten URL info if present
                    if 'url_info' in item_dict:
                        url_info = item_dict['url_info']
                        if isinstance(url_info, dict):
                            if 'url' in url_info:
                                item_dict['url'] = url_info['url']
                            if 'download_url' in url_info:
                                item_dict['download_url'] = url_info['download_url']
                    elif hasattr(item, 'url_info'):
                        # Fallback if to_dict didn't handle nested objects
                        if hasattr(item.url_info, 'url'):
                            item_dict['url'] = item.url_info.url
                        if hasattr(item.url_info, 'download_url'):
                            item_dict['download_url'] = item.url_info.download_url
                            
                    result['data'].append(item_dict)
                except Exception as e:
                    print(f"Error serializing media item: {e}")
                    result['data'].append(str(item))
        else:
            result['data'] = []
        
        # Si analyze_images está habilitado, analizar las imágenes con AI
        # Pasar los objetos originales, no los dicts
        if analyze_images and media_items:
            image_analyses = await _analyze_media_images(media_items)
            result['ai_analysis'] = image_analyses
        
        return result
        
    except Exception as e:
        import traceback
        print(f"ERROR in get_camera_media: {str(e)}")
        print(f"ERROR traceback: {traceback.format_exc()}")
        return {
            "error": str(e), 
            "vehicle_id": vehicle_id,
            "error_type": type(e).__name__
        }


async def _analyze_media_images(media_items: List[Any]) -> Dict[str, Any]:
    """
    Analiza imágenes de media usando OpenAI GPT-4o Vision vía LiteLLM.
    Incluye trazabilidad completa en Langfuse.
    
    Args:
        media_items: Lista de objetos de media de Samsara
        
    Returns:
        Dict con análisis de cada imagen relevante
    """
    analyses = []
    
    # Verificar que tenemos API key de OpenAI
    if not OpenAIConfig.API_KEY:
        return {"error": "OPENAI_API_KEY not configured", "analyses": []}
    
    # Obtener span actual de Langfuse para crear sub-spans
    parent_span = current_langfuse_span.get()
    
    # Filtrar solo imágenes
    image_items = []
    for item in media_items:
        if isinstance(item, dict):
            if item.get('media_type') == 'image':
                image_items.append(item)
        elif hasattr(item, 'media_type') and item.media_type == 'image':
            image_items.append(item)
    
    # Si no hay imágenes, retornar vacío
    if not image_items:
        return {"total_images_analyzed": 0, "analyses": []}
    
    # Proceder con análisis SIN crear spans manuales para evitar MinIO errors
    # LiteLLM seguirá trazando las llamadas automáticamente
    return await _analyze_images_without_tracing(image_items)


async def _analyze_images_without_tracing(image_items: List[Any]) -> Dict[str, Any]:
    """
    Versión sin tracing manual para cuando no hay span padre.
    LiteLLM seguirá trazando las llamadas automáticamente.
    """
    analyses = []
    
    async with httpx.AsyncClient(timeout=30.0) as http_client:
        for idx, item in enumerate(image_items):
            try:
                # Extraer URL de la imagen
                url = None
                download_url = None
                if isinstance(item, dict):
                    url_info = item.get('url_info', {})
                    url = url_info.get('url') if isinstance(url_info, dict) else None
                    download_url = url_info.get('download_url') if isinstance(url_info, dict) else None
                elif hasattr(item, 'url_info'):
                    url = item.url_info.url if hasattr(item.url_info, 'url') else None
                    download_url = item.url_info.download_url if hasattr(item.url_info, 'download_url') else None
                
                if not url:
                    continue
                
                camera_input = item.get('input') if isinstance(item, dict) else getattr(item, 'input', 'unknown')
                
                # Descargar la imagen
                response = await http_client.get(url)
                if response.status_code != 200:
                    continue
                
                image_data = response.content
                image_size_kb = len(image_data) / 1024
                
                # Convertir a base64 para análisis con GPT-4o Vision
                base64_image = base64.b64encode(image_data).decode('utf-8')
                
                # Determinar tipo de cámara para contexto
                camera_type = "interior (hacia el conductor)" if "driver" in str(camera_input).lower() else "exterior (hacia el camino)"
                
                prompt = f"""Eres un analista de seguridad de flotas vehiculares. Esta imagen proviene de una dashcam {camera_type} de un vehículo comercial que activó una alerta de seguridad (posible botón de pánico, evento de seguridad, o incidente).

Tu tarea es proporcionar un ANÁLISIS OBJETIVO para ayudar al equipo de monitoreo a decidir si la alerta requiere intervención inmediata o puede considerarse un falso positivo.

IMPORTANTE: NO identifiques personas ni proporciones datos personales. Enfócate en el ESTADO SITUACIONAL y CONTEXTO.

Analiza y responde en este formato estructurado:

## ESTADO GENERAL
- Nivel de alerta visual: [NORMAL / ATENCIÓN / ALERTA / CRÍTICO]
- Descripción breve de la escena (máximo 2 líneas)

## INDICADORES DE SEGURIDAD
{"- Postura y estado aparente del conductor (relajado, tenso, en movimiento, ausente)" if "driver" in str(camera_input).lower() else "- Condiciones del camino y tráfico visible"}
{"- ¿Hay interacción anómala? (gesticulación, movimiento brusco, objetos extraños)" if "driver" in str(camera_input).lower() else "- ¿Hay obstáculos, vehículos detenidos o situaciones de riesgo?"}
- ¿El vehículo parece estar en movimiento, detenido o en maniobra?

## EVIDENCIA PARA DECISIÓN
- Señales que sugieren EMERGENCIA REAL: (listar si hay, o "Ninguna visible")
- Señales que sugieren FALSO POSITIVO: (listar si hay, o "Ninguna visible")
- Elementos NO CONCLUYENTES que requieren más contexto: (listar si hay)

## RECOMENDACIÓN OPERATIVA
[INTERVENIR / MONITOREAR / DESCARTAR] + justificación en una línea

Responde de forma concisa y profesional. Si la imagen está borrosa, oscura, o no permite análisis adecuado, indícalo claramente."""
                
                vision_response = await acompletion(
                    model=OpenAIConfig.MODEL_GPT4O,
                    messages=[
                        {
                            "role": "user",
                            "content": [
                                {"type": "text", "text": prompt},
                                {
                                    "type": "image_url",
                                    "image_url": {
                                        "url": f"data:image/jpeg;base64,{base64_image}"
                                    }
                                }
                            ]
                        }
                    ],
                    api_key=OpenAIConfig.API_KEY
                )
                
                analysis_text = vision_response.choices[0].message.content
                print(f"\n{'='*80}")
                print(f"VISION ANALYSIS - {camera_input}")
                print(f"{'='*80}")
                print(analysis_text)
                print(f"{'='*80}\n")
                
                # Enviar URL de S3 de Samsara - Laravel se encargará de persistir
                analysis = {
                    "input": camera_input,
                    "timestamp": item.get('start_time') if isinstance(item, dict) else getattr(item, 'start_time', 'unknown'),
                    "analysis": analysis_text,
                    "samsara_url": url,  # URL temporal de S3 de Samsara
                    "image_size_kb": round(image_size_kb, 2)
                }
                
                analyses.append(analysis)
                
            except Exception as e:
                import traceback
                error_msg = f"Error analyzing image: {str(e)}"
                print(f"\nERROR - Vision Analysis Failed")
                print(f"Error: {error_msg}")
                print(f"Traceback: {traceback.format_exc()}\n")
                analyses.append({
                    "error": error_msg,
                    "item": str(item)[:200]
                })
    return {
        "total_images_analyzed": len(analyses),
        "analyses": analyses
    }


@trace_tool
async def get_safety_events(
    vehicle_id: str,
    event_time: str,
    time_window_minutes_before: int = 30,
    time_window_minutes_after: int = 10,
    time_window_seconds_before: Optional[int] = None,
    time_window_seconds_after: Optional[int] = None
) -> Dict[str, Any]:
    """
    Obtiene eventos de seguridad reportados en una ventana de tiempo alrededor del evento principal.
    Esto permite identificar si hubo otros incidentes de seguridad en el mismo periodo.
    
    IMPORTANTE: Para obtener el detalle de un safety event específico (comportamiento del conductor,
    cámara obstruida, etc.), usa una ventana MUY CORTA de ±5 segundos pasando:
    - time_window_seconds_before=5
    - time_window_seconds_after=5
    
    Args:
        vehicle_id: ID del vehículo en Samsara
        event_time: Timestamp ISO 8601 del evento principal (happenedAtTime de la alerta)
        time_window_minutes_before: Minutos antes del evento a consultar (default: 30)
        time_window_minutes_after: Minutos después del evento a consultar (default: 10)
        time_window_seconds_before: Segundos antes del evento (para búsqueda precisa, ignora minutos si se usa)
        time_window_seconds_after: Segundos después del evento (para búsqueda precisa, ignora minutos si se usa)
        
    Returns:
        Dict con eventos de seguridad encontrados en la ventana de tiempo, incluyendo:
        - Número total de eventos
        - Lista de eventos con detalles (tipo, severidad, timestamp, etc.)
        - Resumen de tipos de eventos encontrados
    """
    client = _get_client()
    try:
        # Parsear el event_time y crear ventana de tiempo
        event_dt = datetime.fromisoformat(event_time.replace('Z', '+00:00'))
        
        # Si se especifican segundos, usar esos (para búsqueda precisa de safety events)
        if time_window_seconds_before is not None or time_window_seconds_after is not None:
            seconds_before = time_window_seconds_before if time_window_seconds_before is not None else 5
            seconds_after = time_window_seconds_after if time_window_seconds_after is not None else 5
            start_time = event_dt - timedelta(seconds=seconds_before)
            end_time = event_dt + timedelta(seconds=seconds_after)
        else:
            # Usar minutos (comportamiento original)
            start_time = event_dt - timedelta(minutes=time_window_minutes_before)
            end_time = event_dt + timedelta(minutes=time_window_minutes_after)
        
        # Llamar al SDK para obtener safety events
        response = await client.safety.get_safety_events(
            start_time=start_time.isoformat(),
            end_time=end_time.isoformat(),
            vehicle_ids=vehicle_id
        )
        
        # Determinar si fue búsqueda precisa
        is_precise_search = time_window_seconds_before is not None or time_window_seconds_after is not None
        
        # Procesar la respuesta
        result = {
            "time_window": {
                "start": start_time.isoformat(),
                "end": end_time.isoformat(),
                "event_time": event_time,
                "is_precise_search": is_precise_search,
                "seconds_before": time_window_seconds_before if is_precise_search else None,
                "seconds_after": time_window_seconds_after if is_precise_search else None,
                "minutes_before": time_window_minutes_before if not is_precise_search else None,
                "minutes_after": time_window_minutes_after if not is_precise_search else None
            },
            "events": [],
            "total_events": 0,
            "event_types_summary": {}
        }
        
        # Extraer eventos de la respuesta
        if hasattr(response, 'data') and response.data:
            events_list = response.data if isinstance(response.data, list) else [response.data]
            
            for event in events_list:
                event_dict = event.to_dict() if hasattr(event, 'to_dict') else event
                result["events"].append(event_dict)
                
                # Contar tipos de eventos para el resumen
                event_type = event_dict.get('event_type', 'unknown')
                result["event_types_summary"][event_type] = result["event_types_summary"].get(event_type, 0) + 1
            
            result["total_events"] = len(result["events"])
        
        # Si no hay eventos, indicarlo claramente
        if result["total_events"] == 0:
            result["note"] = "No se encontraron eventos de seguridad en la ventana de tiempo especificada"
        
        return result
        
    except Exception as e:
        import traceback
        print(f"ERROR in get_safety_events: {str(e)}")
        print(f"ERROR traceback: {traceback.format_exc()}")
        return {
            "error": str(e),
            "vehicle_id": vehicle_id,
            "event_time": event_time,
            "error_type": type(e).__name__
        }
