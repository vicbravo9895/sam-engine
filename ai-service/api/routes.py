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
from core.context import current_langfuse_span
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
        
        # Ejecutar el runner de forma asíncrona
        async for event in runner.run_async(
            user_id=user_id,
            session_id=session_id,
            new_message=initial_message
        ):
            # Detectar cambio de agente y gestionar spans
            # Usamos 'author' para identificar el agente que emitió el evento
            if hasattr(event, 'author') and event.author:
                agent_name = event.author
                
                # Si cambiamos de agente
                if current_agent and current_agent != agent_name:
                    # Cerrar el span anterior
                    if current_agent in active_spans:
                        # El output de este agente se convierte en el input del siguiente
                        last_output = agent_accumulated_output.get(current_agent, "")
                        active_spans[current_agent].end(output=last_output)
                        del active_spans[current_agent]
                        
                        # Actualizar el input para el nuevo agente
                        if last_output:
                            current_input = last_output
                
                current_agent = agent_name
                
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
