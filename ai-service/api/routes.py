"""
Definición de rutas de la API FastAPI.
Contiene los endpoints del servicio.
"""

import json
import uuid
from datetime import datetime
from typing import Optional

from fastapi import APIRouter, HTTPException
from google.genai import types

from config import ServiceConfig, langfuse_client
from core import runner, session_service
from core.context import current_langfuse_span, current_tool_tracker
from agents.agent_definitions import AGENTS_BY_NAME
from .models import AlertRequest, HealthResponse
from .breadcrumbs import create_breadcrumb


# ============================================================================
# ROUTER
# ============================================================================
router = APIRouter()


# ============================================================================
# ENDPOINT: POST /alerts/ingest
# ============================================================================
@router.post("/alerts/ingest")
async def ingest_alert(request: AlertRequest):
    """
    Procesa una alerta de Samsara de forma síncrona.
    
    Este endpoint es llamado por el Job de Laravel (ProcessSamsaraEventJob).
    Ejecuta el pipeline de agentes y retorna los resultados para que
    Laravel los guarde en la base de datos.
    
    Args:
        request: AlertRequest con event_id y payload de Samsara
        
    Returns:
        JSON con assessment y message para guardar en DB
    """
    event_id = request.event_id
    alert_payload = request.payload
    
    # Extraer metadata del payload para Langfuse
    alert_type = alert_payload.get("alertType", "unknown")
    vehicle_id = alert_payload.get("vehicle", {}).get("id", "unknown")
    driver_name = alert_payload.get("driver", {}).get("name", "unknown")

    # Crear trace de Langfuse para esta alerta
    trace = None
    if langfuse_client:
        trace = langfuse_client.trace(
            name="samsara_alert_processing",
            user_id=ServiceConfig.DEFAULT_USER_ID,
            metadata={
                "event_id": event_id,
                "alert_type": alert_type,
                "vehicle_id": vehicle_id,
                "driver_name": driver_name,
            },
            tags=["samsara", "alert", alert_type]
        )

    # Crear sesión para este análisis
    user_id = ServiceConfig.DEFAULT_USER_ID
    session_id = str(uuid.uuid4())
    
    await session_service.create_session(
        user_id=user_id, 
        session_id=session_id,
        app_name=ServiceConfig.APP_NAME
    )
    
    # Construir mensaje inicial con el payload
    payload_json = json.dumps(alert_payload, ensure_ascii=False, indent=2)
    initial_message = types.Content(
        parts=[types.Part(text=f"Analiza esta alerta de Samsara:\\n\\n{payload_json}")]
    )
    
    try:
        # Variables para capturar resultados
        assessment = None
        message = None
        
        # Variables para rastrear spans de agentes
        active_spans = {}
        current_agent = None
        
        # Variables para rastrear el flujo de información (inputs/outputs)
        # El input inicial es el mensaje que enviamos al pipeline
        current_input = f"Analiza esta alerta de Samsara:\n\n{payload_json}"
        agent_accumulated_output = {}  # Para guardar el output completo de cada agente
        
        # Crear span para el pipeline de agentes
        pipeline_span = None
        if trace:
            pipeline_span = trace.span(
                name="agent_pipeline_execution",
                metadata={
                    "session_id": session_id,
                    "user_id": user_id
                },
                input=current_input
            )
        
        # ============================================================================
        # AI ACTIONS TRACKING
        # ============================================================================
        # Estructura para capturar todas las acciones de los agentes
        ai_actions = {
            "agents": [],
            "total_duration_ms": 0,
            "total_tools_called": 0
        }
        
        # Variables para tracking de agentes
        current_agent_actions = None
        agent_start_time = None
        
        # Variables para tracking de tool calls
        pending_tool_calls = {}  # {tool_name: {started_at, parameters}}
        
        # Ejecutar el runner de forma asíncrona
        async for event in runner.run_async(
            user_id=user_id,
            session_id=session_id,
            new_message=initial_message
        ):
            # DEBUG: Verbose event inspection
            print(f"DEBUG: Event type: {type(event).__name__}")
            print(f"DEBUG: Has tool_requests: {hasattr(event, 'tool_requests')}")
            if hasattr(event, 'tool_requests') and event.tool_requests:
                print(f"DEBUG: Tool requests: {event.tool_requests}")
            print(f"DEBUG: Has tool_responses: {hasattr(event, 'tool_responses')}")
            if hasattr(event, 'tool_responses') and event.tool_responses:
                print(f"DEBUG: Tool responses: {event.tool_responses}")
            print(f"DEBUG: Current agent: {current_agent}")
            print(f"DEBUG: Current actions obj exists: {current_agent_actions is not None}")
            
            # Detectar cambio de agente y gestionar spans
            # Usamos 'author' para identificar el agente que emitió el evento
            agent_name = getattr(event, 'author', None)
            
            # Si no hay autor pero hay contenido o tools, usar el último conocido o 'unknown'
            if not agent_name and (hasattr(event, 'tool_requests') or (hasattr(event, 'content') and event.content)):
                agent_name = current_agent or "unknown_agent"
            
            if agent_name:
                # Si cambiamos de agente
                if current_agent and current_agent != agent_name:
                    # ============ AI ACTIONS: Finalizar agente anterior ============
                    if current_agent_actions and agent_start_time:
                        duration_ms = int((datetime.utcnow() - agent_start_time).total_seconds() * 1000)
                        current_agent_actions["completed_at"] = datetime.utcnow().isoformat() + "Z"
                        current_agent_actions["duration_ms"] = duration_ms
                        
                        # Limpiar output summary de markdown
                        raw_summary = agent_accumulated_output.get(current_agent, "")
                        clean_summary = raw_summary
                        if "```" in clean_summary:
                            import re
                            clean_summary = re.sub(r'^```\w*\s*', '', clean_summary)
                            clean_summary = re.sub(r'\s*```$', '', clean_summary)
                            clean_summary = clean_summary.strip()
                        
                        current_agent_actions["output_summary"] = clean_summary[:500]
                        ai_actions["total_duration_ms"] += duration_ms
                    
                    # Cerrar el span anterior
                    if current_agent in active_spans:
                        # El output de este agente se convierte en el input del siguiente
                        last_output = agent_accumulated_output.get(current_agent, "")
                        active_spans[current_agent].end(output=last_output)
                        del active_spans[current_agent]
                        
                        # Actualizar el input para el nuevo agente
                        if last_output:
                            current_input = last_output
                    
                    # Limpiar tracker de tools para evitar asignaciones incorrectas
                    current_tool_tracker.set(None)
                
                current_agent = agent_name
                
                # ============ AI ACTIONS: Iniciar nuevo agente ============
                if current_agent not in [a["name"] for a in ai_actions["agents"]]:
                    agent_start_time = datetime.utcnow()
                    current_agent_actions = {
                        "name": current_agent,
                        "started_at": agent_start_time.isoformat() + "Z",
                        "completed_at": None,
                        "duration_ms": 0,
                        "output_summary": "",
                        "tools_used": []
                    }
                    ai_actions["agents"].append(current_agent_actions)
                
                # Iniciar nuevo span si no existe para este agente
                if trace and agent_name not in active_spans:
                    # Obtener tools disponibles para este agente
                    available_tools = []
                    agent_def = AGENTS_BY_NAME.get(agent_name)
                    if agent_def and hasattr(agent_def, 'tools') and agent_def.tools:
                        available_tools = [t.__name__ for t in agent_def.tools]

                    span = trace.span(
                        name=f"agent_{agent_name}",
                        metadata={
                            "agent_name": agent_name,
                            "session_id": session_id,
                            "available_tools": available_tools
                        },
                        parent_observation_id=pipeline_span.id if pipeline_span else None,
                        input=current_input
                    )
                    active_spans[agent_name] = span
                    
                    # Establecer el span actual en el contexto para que las tools lo usen
                    current_langfuse_span.set(span)
                
                # Compartir contexto del agente para que las tools registren su ejecución
                if current_agent_actions:
                    current_tool_tracker.set({
                        "agent_name": current_agent,
                        "agent_actions": current_agent_actions,
                        "ai_actions": ai_actions
                    })
            
            tracker_context = current_tool_tracker.get()

            # ============ AI ACTIONS: Capturar Tool Calls (fallback si no hay tracker) ============
            if not tracker_context:
                # Verificamos tool_requests (ADK estándar) y tool_calls (OpenAI raw)
                has_tools = False
                tool_list = []
                
                if hasattr(event, 'tool_requests') and event.tool_requests:
                    has_tools = True
                    tool_list = event.tool_requests
                elif hasattr(event, 'tool_calls') and event.tool_calls:
                    has_tools = True
                    tool_list = event.tool_calls
                    
                if has_tools:
                    for tool_req in tool_list:
                        # Intentar obtener nombre de diferentes formas
                        tool_name = "unknown_tool"
                        if hasattr(tool_req, 'name'):
                            tool_name = tool_req.name
                        elif hasattr(tool_req, 'function') and hasattr(tool_req.function, 'name'):
                            tool_name = tool_req.function.name
                            
                        tool_call_time = datetime.utcnow()
                        
                        # Extraer parámetros
                        parameters = {}
                        if hasattr(tool_req, 'input') and tool_req.input:
                            try:
                                parameters = dict(tool_req.input) if hasattr(tool_req.input, '__dict__') else {}
                            except:
                                parameters = {"raw": str(tool_req.input)[:200]}
                        elif hasattr(tool_req, 'function') and hasattr(tool_req.function, 'arguments'):
                            try:
                                parameters = json.loads(tool_req.function.arguments)
                            except:
                                parameters = {"raw": str(tool_req.function.arguments)[:200]}
                        
                        # Guardar tool call pendiente
                        pending_tool_calls[tool_name] = {
                            "tool_name": tool_name,
                            "called_at": tool_call_time.isoformat() + "Z",
                            "parameters": parameters,
                            "status": "pending",
                            "start_time": tool_call_time
                        }
                        
                        # Agregar a las acciones del agente actual
                        if current_agent_actions:
                            current_agent_actions["tools_used"].append(pending_tool_calls[tool_name])
                            ai_actions["total_tools_called"] += 1
                        else:
                            print(f"DEBUG: WARNING - Tool call {tool_name} received but current_agent_actions is None")
                
                # ============ AI ACTIONS: Capturar Tool Responses ============
                if hasattr(event, 'tool_responses') and event.tool_responses:
                    for tool_resp in event.tool_responses:
                        tool_name = tool_resp.name if hasattr(tool_resp, 'name') else "unknown_tool"
                        
                        # Buscar el tool call pendiente
                        if tool_name in pending_tool_calls:
                            tool_call = pending_tool_calls[tool_name]
                            end_time = datetime.utcnow()
                            duration_ms = int((end_time - tool_call["start_time"]).total_seconds() * 1000)
                            
                            # Actualizar con resultado
                            tool_call["duration_ms"] = duration_ms
                            tool_call["status"] = "success"
                            
                            # Extraer resumen del resultado
                            result_summary = "Completed"
                            if hasattr(tool_resp, 'response') and tool_resp.response:
                                response_str = str(tool_resp.response)
                                result_summary = response_str[:150] + "..." if len(response_str) > 150 else response_str
                                
                                # Para get_camera_media, agregar detalles de análisis de imágenes
                                if tool_name == "get_camera_media" and isinstance(tool_resp.response, dict):
                                    ai_analysis = tool_resp.response.get('ai_analysis', {})
                                    if ai_analysis and 'analyses' in ai_analysis:
                                        analyses = ai_analysis['analyses']
                                        tool_call["details"] = {
                                            "images_analyzed": len(analyses),
                                            "analyses": [
                                                {
                                                    "camera": a.get('input', 'unknown'),
                                                    "analysis_preview": a.get('analysis', '')[:200]
                                                }
                                                for a in analyses if 'error' not in a
                                            ]
                                        }
                                        result_summary = f"{len(analyses)} images analyzed with AI"
                            
                            tool_call["result_summary"] = result_summary
                            
                            # Limpiar start_time (no es JSON serializable)
                            del tool_call["start_time"]
                            del pending_tool_calls[tool_name]

            # Capturar el texto generado por los agentes
            # El evento no tiene .text directo, viene en content.parts[0].text
            text = None
            if hasattr(event, 'content') and event.content and event.content.parts:
                for part in event.content.parts:
                    if hasattr(part, 'text') and part.text:
                        text = part.text
                        break
            
            if text:
                text = text.strip()
                
                # Acumular output para este agente (para usarlo como input del siguiente)
                if current_agent:
                    agent_accumulated_output[current_agent] = text
                
                # Actualizar output del span actual en tiempo real
                if current_agent and current_agent in active_spans:
                    active_spans[current_agent].update(
                        output=text
                    )
                
                # Intentar parsear como JSON para el assessment
                try:
                    # Limpiar bloques de código markdown si existen
                    clean_text = text
                    if "```" in clean_text:
                        import re
                        # Eliminar ```json ... ``` o simplemente ``` ... ```
                        clean_text = re.sub(r'^```\w*\s*', '', clean_text)
                        clean_text = re.sub(r'\s*```$', '', clean_text)
                        clean_text = clean_text.strip()

                    parsed = json.loads(clean_text)
                    if 'panic_assessment' in parsed:
                        assessment = parsed['panic_assessment']
                    elif isinstance(parsed, dict) and 'likelihood' in parsed:
                        assessment = parsed
                except (json.JSONDecodeError, Exception):
                    # Si no es JSON, es el mensaje final
                    if not message:
                        message = text
            
            # Si es el evento final, no rompemos el loop aquí porque en SequentialAgent
            # cada sub-agente emite un is_final_response=True al terminar.
            # Dejamos que el runner termine naturalmente cuando se agoten los eventos.
            pass
        
        # ============ AI ACTIONS: Finalizar último agente ============
        if current_agent and current_agent_actions and agent_start_time:
             # Verificar si ya se completó (por si acaso el loop terminó justo después de un cambio de agente)
             if current_agent_actions["completed_at"] is None:
                duration_ms = int((datetime.utcnow() - agent_start_time).total_seconds() * 1000)
                current_agent_actions["completed_at"] = datetime.utcnow().isoformat() + "Z"
                current_agent_actions["duration_ms"] = duration_ms
                current_agent_actions["output_summary"] = agent_accumulated_output.get(current_agent, "")[:200]
                ai_actions["total_duration_ms"] += duration_ms

        # Cerrar cualquier span que haya quedado abierto
        for agent_name, span in active_spans.items():
            final_output = agent_accumulated_output.get(agent_name, "")
            span.end(output=final_output)
        
        # Finalizar span del pipeline
        if pipeline_span:
            pipeline_span.end(
                output={
                    "assessment": assessment,
                    "message": message
                }
            )
        
        # Finalizar trace con éxito
        if trace:
            trace.update(
                output={
                    "status": "success",
                    "assessment": assessment,
                    "message": message
                }
            )
        
        # Retornar resultados para que Laravel los guarde
        return {
            "status": "success",
            "event_id": event_id,
            "assessment": assessment or {},
            "message": message or "Procesamiento completado sin mensaje final",
            "actions": ai_actions,
            "requires_monitoring": assessment.get("requires_monitoring", False) if assessment else False,
            "next_check_minutes": assessment.get("next_check_minutes", 15) if assessment else None,
            "monitoring_reason": assessment.get("monitoring_reason", None) if assessment else None,
        }
        
    except Exception as e:
        # Registrar error en Langfuse
        if trace:
            trace.update(
                level="ERROR",
                status_message=str(e)
            )
        
        # En caso de error, retornar error para que Laravel lo maneje
        raise HTTPException(
            status_code=500,
            detail={
                "status": "error",
                "event_id": event_id,
                "error": str(e),
            }
        )
    finally:
        # Limpiar contexto compartido para tool tracking
        current_tool_tracker.set(None)


# ============================================================================
# ENDPOINT: POST /alerts/revalidate
# ============================================================================
@router.post("/alerts/revalidate")
async def revalidate_alert(request: dict):
    """
    Revalida una alerta existente con contexto temporal adicional.
    
    Este endpoint es llamado por RevalidateSamsaraEventJob para
    reanalizar eventos que requieren monitoreo continuo.
    
    Args:
        request: dict con event_id, payload y context (información temporal)
        
    Returns:
        JSON con assessment actualizado y decisión de monitoreo
    """
    event_id = request.get("event_id")
    alert_payload = request.get("payload")
    context = request.get("context", {})
    
    # Extraer metadata del payload para Langfuse
    alert_type = alert_payload.get("alertType", "unknown")
    vehicle_id = alert_payload.get("vehicle", {}).get("id", "unknown")
    
    # Crear trace de Langfuse para esta revalidación
    trace = None
    if langfuse_client:
        trace = langfuse_client.trace(
            name="samsara_alert_revalidation",
            user_id=ServiceConfig.DEFAULT_USER_ID,
            metadata={
                "event_id": event_id,
                "alert_type": alert_type,
                "vehicle_id": vehicle_id,
                "investigation_count": context.get("investigation_count", 0),
                "is_revalidation": True,
            },
            tags=["samsara", "revalidation", alert_type]
        )
    
    # Crear sesión para este análisis
    user_id = ServiceConfig.DEFAULT_USER_ID
    session_id = str(uuid.uuid4())
    
    await session_service.create_session(
        user_id=user_id,
        session_id=session_id,
        app_name=ServiceConfig.APP_NAME
    )
    
    # Construir mensaje con contexto temporal
    payload_json = json.dumps(alert_payload, ensure_ascii=False, indent=2)
    
    temporal_context = f"""
CONTEXTO DE REVALIDACIÓN:
- Evento original: {context.get('original_event_time', 'unknown')}
- Primera investigación: {context.get('first_investigation_time', 'unknown')}
- Última investigación: {context.get('last_investigation_time', 'unknown')}
- Número de investigaciones: {context.get('investigation_count', 0)}

ANÁLISIS PREVIO:
{json.dumps(context.get('previous_assessment', {}), ensure_ascii=False, indent=2)}

HISTORIAL DE INVESTIGACIONES:
{json.dumps(context.get('investigation_history', []), ensure_ascii=False, indent=2)}

Ahora tienes más contexto temporal. Revalida si puedes dar un veredicto definitivo o si aún necesitas más monitoreo.
"""
    
    initial_message = types.Content(
        parts=[types.Part(text=f"Revalida esta alerta de Samsara:\n\n{temporal_context}\n\nPAYLOAD:\n{payload_json}")]
    )
    
    try:
        assessment = None
        message = None
        
        active_spans = {}
        current_agent = None
        current_input = f"Revalida esta alerta de Samsara:\n\n{temporal_context}\n\nPAYLOAD:\n{payload_json}"
        agent_accumulated_output = {}
        
        pipeline_span = None
        if trace:
            pipeline_span = trace.span(
                name="revalidation_pipeline_execution",
                metadata={
                    "session_id": session_id,
                    "user_id": user_id,
                    "investigation_count": context.get("investigation_count", 0)
                },
                input=current_input
            )
        
        ai_actions = {
            "agents": [],
            "total_duration_ms": 0,
            "total_tools_called": 0
        }
        
        current_agent_actions = None
        agent_start_time = None
        pending_tool_calls = {}
        
        # Ejecutar el runner (mismo flujo que ingest_alert)
        async for event in runner.run_async(
            user_id=user_id,
            session_id=session_id,
            new_message=initial_message
        ):
            agent_name = getattr(event, 'author', None)
            
            if not agent_name and (hasattr(event, 'tool_requests') or (hasattr(event, 'content') and event.content)):
                agent_name = current_agent or "unknown_agent"
            
            if agent_name:
                if current_agent and current_agent != agent_name:
                    if current_agent_actions and agent_start_time:
                        duration_ms = int((datetime.utcnow() - agent_start_time).total_seconds() * 1000)
                        current_agent_actions["completed_at"] = datetime.utcnow().isoformat() + "Z"
                        current_agent_actions["duration_ms"] = duration_ms
                        
                        raw_summary = agent_accumulated_output.get(current_agent, "")
                        clean_summary = raw_summary
                        if "```" in clean_summary:
                            import re
                            clean_summary = re.sub(r'^```\w*\s*', '', clean_summary)
                            clean_summary = re.sub(r'\s*```$', '', clean_summary)
                            clean_summary = clean_summary.strip()
                        
                        current_agent_actions["output_summary"] = clean_summary[:500]
                        ai_actions["total_duration_ms"] += duration_ms
                    
                    if current_agent in active_spans:
                        last_output = agent_accumulated_output.get(current_agent, "")
                        active_spans[current_agent].end(output=last_output)
                        del active_spans[current_agent]
                        
                        if last_output:
                            current_input = last_output
                    
                    current_tool_tracker.set(None)
                
                current_agent = agent_name
                
                if current_agent not in [a["name"] for a in ai_actions["agents"]]:
                    agent_start_time = datetime.utcnow()
                    current_agent_actions = {
                        "name": current_agent,
                        "started_at": agent_start_time.isoformat() + "Z",
                        "completed_at": None,
                        "duration_ms": 0,
                        "output_summary": "",
                        "tools_used": []
                    }
                    ai_actions["agents"].append(current_agent_actions)
                
                if trace and agent_name not in active_spans:
                    available_tools = []
                    agent_def = AGENTS_BY_NAME.get(agent_name)
                    if agent_def and hasattr(agent_def, 'tools') and agent_def.tools:
                        available_tools = [t.__name__ for t in agent_def.tools]

                    span = trace.span(
                        name=f"agent_{agent_name}",
                        metadata={
                            "agent_name": agent_name,
                            "session_id": session_id,
                            "available_tools": available_tools
                        },
                        parent_observation_id=pipeline_span.id if pipeline_span else None,
                        input=current_input
                    )
                    active_spans[agent_name] = span
                    current_langfuse_span.set(span)
                
                if current_agent_actions:
                    current_tool_tracker.set({
                        "agent_name": current_agent,
                        "agent_actions": current_agent_actions,
                        "ai_actions": ai_actions
                    })
            
            # Capturar texto generado
            text = None
            if hasattr(event, 'content') and event.content and event.content.parts:
                for part in event.content.parts:
                    if hasattr(part, 'text') and part.text:
                        text = part.text
                        break
            
            if text:
                text = text.strip()
                
                if current_agent:
                    agent_accumulated_output[current_agent] = text
                
                if current_agent and current_agent in active_spans:
                    active_spans[current_agent].update(output=text)
                
                try:
                    clean_text = text
                    if "```" in clean_text:
                        import re
                        clean_text = re.sub(r'^```\w*\s*', '', clean_text)
                        clean_text = re.sub(r'\s*```$', '', clean_text)
                        clean_text = clean_text.strip()

                    parsed = json.loads(clean_text)
                    if 'panic_assessment' in parsed:
                        assessment = parsed['panic_assessment']
                    elif isinstance(parsed, dict) and 'likelihood' in parsed:
                        assessment = parsed
                except (json.JSONDecodeError, Exception):
                    if not message:
                        message = text
        
        # Finalizar último agente
        if current_agent and current_agent_actions and agent_start_time:
            if current_agent_actions["completed_at"] is None:
                duration_ms = int((datetime.utcnow() - agent_start_time).total_seconds() * 1000)
                current_agent_actions["completed_at"] = datetime.utcnow().isoformat() + "Z"
                current_agent_actions["duration_ms"] = duration_ms
                current_agent_actions["output_summary"] = agent_accumulated_output.get(current_agent, "")[:200]
                ai_actions["total_duration_ms"] += duration_ms

        # Cerrar spans
        for agent_name, span in active_spans.items():
            final_output = agent_accumulated_output.get(agent_name, "")
            span.end(output=final_output)
        
        if pipeline_span:
            pipeline_span.end(
                output={
                    "assessment": assessment,
                    "message": message
                }
            )
        
        if trace:
            trace.update(
                output={
                    "status": "success",
                    "assessment": assessment,
                    "message": message
                }
            )
        
        # Retornar resultados
        return {
            "status": "success",
            "event_id": event_id,
            "assessment": assessment or {},
            "message": message or "Revalidación completada",
            "actions": ai_actions,
            "requires_monitoring": assessment.get("requires_monitoring", False) if assessment else False,
            "next_check_minutes": assessment.get("next_check_minutes", 30) if assessment else None,
            "monitoring_reason": assessment.get("monitoring_reason", None) if assessment else None,
        }
        
    except Exception as e:
        if trace:
            trace.update(
                level="ERROR",
                status_message=str(e)
            )
        
        raise HTTPException(
            status_code=500,
            detail={
                "status": "error",
                "event_id": event_id,
                "error": str(e),
            }
        )
    finally:
        current_tool_tracker.set(None)



# ============================================================================
# ENDPOINT: GET /health
# ============================================================================
@router.get("/health", response_model=HealthResponse)
async def health_check():
    """Endpoint de salud del servicio."""
    return HealthResponse(
        status="healthy",
        service="samsara-alert-ai",
        timestamp=datetime.utcnow().isoformat()
    )
