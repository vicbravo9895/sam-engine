"""
Tools para interactuar con la API de Samsara usando el SDK oficial.
Estas funciones son usadas por el panic_investigator agent.
"""

import functools
import os
import base64
from typing import Dict, Any, List, Optional
from datetime import datetime, timedelta

from samsara import AsyncSamsara
import httpx
from litellm import acompletion

from config import SamsaraConfig, OpenAIConfig
from core.context import current_langfuse_span


def trace_tool(func):
    """
    Decorador para rastrear la ejecución de tools en Langfuse.
    Crea un span hijo del span actual del agente.
    """
    @functools.wraps(func)
    async def wrapper(*args, **kwargs):
        span = current_langfuse_span.get()
        if not span:
            return await func(*args, **kwargs)
        
        # Crear nombre del span basado en la función
        tool_name = func.__name__
        
        # Preparar input para el span
        input_data = {
            "args": args,
            "kwargs": kwargs
        }
        
        # Iniciar span de tool
        tool_span = span.span(
            name=f"tool_{tool_name}",
            input=input_data,
            metadata={
                "tool_name": tool_name,
                "type": "tool_execution"
            }
        )
        
        try:
            # Ejecutar la tool
            result = await func(*args, **kwargs)
            
            # Registrar output exitoso
            tool_span.end(output=result)
            return result
            
        except Exception as e:
            # Registrar error
            tool_span.end(
                level="ERROR",
                status_message=str(e),
                output={"error": str(e)}
            )
            raise e
            
    return wrapper


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
        # IMPORTANTE: No usar start_time/end_time ya que causan error de validación Pydantic
        # El SDK espera que la respuesta tenga data como lista, pero puede ser None
        response = await client.driver_vehicle_assignments.get_driver_vehicle_assignments(
            filter_by='vehicles',
            vehicle_ids=[vehicle_id]
        )
        
        if hasattr(response, 'to_dict'):
            return response.to_dict()
        if hasattr(response, 'data'):
            # Handle None data to prevent Pydantic validation error
            if response.data is None:
                return {"data": [], "vehicle_id": vehicle_id, "note": "No driver assignments found for this vehicle"}
            return {"data": [item.to_dict() if hasattr(item, 'to_dict') else str(item) for item in response.data]}
            
        return {"data": str(response)}
        
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
        
        if hasattr(response, 'to_dict'):
            result = response.to_dict()
        elif hasattr(response, 'data'):
            result = {"data": [item.to_dict() if hasattr(item, 'to_dict') else str(item) for item in response.data]}
        else:
            result = {"data": str(response)}
        
        # Si analyze_images está habilitado, analizar las imágenes con AI
        if analyze_images and 'data' in result:
            media_items = result.get('data', [])
            if isinstance(media_items, list):
                image_analyses = await _analyze_media_images(media_items)
                result['ai_analysis'] = image_analyses
        
        return result
        
    except Exception as e:
        return {"error": str(e), "vehicle_id": vehicle_id}


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
    
    # Si no hay span padre, proceder sin tracing manual (LiteLLM lo hará automáticamente)
    if not parent_span:
        return await _analyze_images_without_tracing(image_items)
    
    # Crear span para el análisis de imágenes
    analysis_span = parent_span.span(
        name="image_analysis",
        input={"total_images": len(image_items)},
        metadata={
            "type": "vision_analysis",
            "model": OpenAIConfig.MODEL_GPT4O
        }
    )
    
    # Analizar cada imagen
    async with httpx.AsyncClient(timeout=30.0) as http_client:
        for idx, item in enumerate(image_items):
            # Crear span para cada imagen individual
            image_span = analysis_span.span(
                name=f"analyze_image_{idx}",
                metadata={
                    "image_index": idx,
                    "input_type": item.get('input') if isinstance(item, dict) else getattr(item, 'input', 'unknown'),
                    "timestamp": item.get('start_time') if isinstance(item, dict) else getattr(item, 'start_time', 'unknown')
                }
            )
            
            try:
                # Extraer URL de la imagen
                url = None
                if isinstance(item, dict):
                    url_info = item.get('url_info', {})
                    url = url_info.get('url') if isinstance(url_info, dict) else None
                elif hasattr(item, 'url_info'):
                    url = item.url_info.url if hasattr(item.url_info, 'url') else None
                
                if not url:
                    image_span.end(
                        level="WARNING",
                        output={"error": "No URL found for image"}
                    )
                    continue
                
                # Descargar la imagen
                response = await http_client.get(url)
                if response.status_code != 200:
                    image_span.end(
                        level="WARNING",
                        output={"error": f"Failed to download image: HTTP {response.status_code}"}
                    )
                    continue
                
                image_data = response.content
                image_size_kb = len(image_data) / 1024
                
                # Convertir imagen a base64 para OpenAI
                base64_image = base64.b64encode(image_data).decode('utf-8')
                
                # Analizar con OpenAI GPT-4o Vision vía LiteLLM
                prompt = """Analiza esta imagen de dashcam y describe:
1. ¿Qué se ve en la escena? (vehículos, personas, entorno)
2. ¿Hay alguna situación de riesgo o anómala visible?
3. ¿El conductor parece estar en peligro o en una situación de emergencia?
4. ¿Hay evidencia visual de un incidente (colisión, frenado brusco, etc.)?

Sé conciso y objetivo. Responde en español."""
                
                # Llamar a OpenAI Vision usando LiteLLM
                # LiteLLM automáticamente trazará esto en Langfuse gracias a success_callback
                vision_response = await acompletion(
                    model=OpenAIConfig.MODEL_GPT4O,  # GPT-4o soporta visión
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
                    api_key=OpenAIConfig.API_KEY,
                    metadata={
                        "image_index": idx,
                        "image_size_kb": round(image_size_kb, 2),
                        "camera_input": item.get('input') if isinstance(item, dict) else getattr(item, 'input', 'unknown')
                    }
                )
                
                analysis = {
                    "input": item.get('input') if isinstance(item, dict) else getattr(item, 'input', 'unknown'),
                    "timestamp": item.get('start_time') if isinstance(item, dict) else getattr(item, 'start_time', 'unknown'),
                    "analysis": vision_response.choices[0].message.content,
                    "url": url,
                    "image_size_kb": round(image_size_kb, 2)
                }
                
                analyses.append(analysis)
                
                # Finalizar span de imagen con éxito
                image_span.end(
                    output={
                        "analysis_preview": analysis["analysis"][:100] + "..." if len(analysis["analysis"]) > 100 else analysis["analysis"],
                        "image_size_kb": round(image_size_kb, 2)
                    }
                )
                
            except Exception as e:
                error_msg = f"Error analyzing image: {str(e)}"
                analyses.append({
                    "error": error_msg,
                    "item": str(item)
                })
                
                # Finalizar span de imagen con error
                image_span.end(
                    level="ERROR",
                    status_message=error_msg,
                    output={"error": error_msg}
                )
    
    result = {
        "total_images_analyzed": len(analyses),
        "analyses": analyses
    }
    
    # Finalizar span de análisis
    analysis_span.end(output=result)
    
    return result


async def _analyze_images_without_tracing(image_items: List[Any]) -> Dict[str, Any]:
    """
    Versión sin tracing manual para cuando no hay span padre.
    LiteLLM seguirá trazando las llamadas automáticamente.
    """
    analyses = []
    
    async with httpx.AsyncClient(timeout=30.0) as http_client:
        for item in image_items:
            try:
                # Extraer URL de la imagen
                url = None
                if isinstance(item, dict):
                    url_info = item.get('url_info', {})
                    url = url_info.get('url') if isinstance(url_info, dict) else None
                elif hasattr(item, 'url_info'):
                    url = item.url_info.url if hasattr(item.url_info, 'url') else None
                
                if not url:
                    continue
                
                # Descargar la imagen
                response = await http_client.get(url)
                if response.status_code != 200:
                    continue
                
                image_data = response.content
                base64_image = base64.b64encode(image_data).decode('utf-8')
                
                prompt = """Analiza esta imagen de dashcam y describe:
1. ¿Qué se ve en la escena? (vehículos, personas, entorno)
2. ¿Hay alguna situación de riesgo o anómala visible?
3. ¿El conductor parece estar en peligro o en una situación de emergencia?
4. ¿Hay evidencia visual de un incidente (colisión, frenado brusco, etc.)?

Sé conciso y objetivo. Responde en español."""
                
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
                
                analysis = {
                    "input": item.get('input') if isinstance(item, dict) else getattr(item, 'input', 'unknown'),
                    "timestamp": item.get('start_time') if isinstance(item, dict) else getattr(item, 'start_time', 'unknown'),
                    "analysis": vision_response.choices[0].message.content,
                    "url": url
                }
                
                analyses.append(analysis)
                
            except Exception as e:
                analyses.append({
                    "error": f"Error analyzing image: {str(e)}",
                    "item": str(item)
                })
    
    return {
        "total_images_analyzed": len(analyses),
        "analyses": analyses
    }


