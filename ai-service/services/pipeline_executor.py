"""
PipelineExecutor: Servicio que ejecuta el pipeline de agentes ADK.
Encapsula toda la lógica de ejecución, tracking y captura de resultados.

ACTUALIZADO: Nuevo contrato de respuesta.
- alert_context (antes: triage)
- assessment 
- human_message (string, no JSON)
- notification_decision (decisión sin side effects)
- notification_execution (ejecutado por código)
"""

import json
import logging
import re
import uuid
from datetime import datetime
from typing import Any, Dict, List, Optional

from google.genai import types

logger = logging.getLogger(__name__)

from config import ServiceConfig, langfuse_client
from core import runner, revalidation_runner, session_service
from core.context import current_langfuse_span, current_tool_tracker
from agents.agent_definitions import AGENTS_BY_NAME
from agents.schemas import ToolResult, AgentResult, PipelineResult
from .notification_executor import execute_notifications
from .preloaded_media_analyzer import analyze_preloaded_media


# ============================================================================
# HELPER FUNCTIONS
# ============================================================================
def _generate_tool_summary(tool_name: str, response: Any) -> str:
    """Genera un resumen conciso para la ejecución de una tool."""
    try:
        if isinstance(response, str):
            try:
                response = json.loads(response)
            except:
                import ast
                try:
                    response = ast.literal_eval(response)
                except:
                    pass
        
        if not isinstance(response, dict):
            return "Completado"
        
        if tool_name == "get_vehicle_stats":
            return "Estadísticas del vehículo obtenidas"
        elif tool_name == "get_vehicle_info":
            return "Información del vehículo obtenida"
        elif tool_name == "get_driver_assignment":
            data = response.get('data', [])
            if not data:
                return "Sin conductor asignado"
            return "Conductor identificado"
        elif tool_name == "get_safety_events":
            total = response.get('total_events', 0)
            return f"{total} eventos de seguridad"
        elif tool_name == "get_camera_media":
            ai_analysis = response.get('ai_analysis', {})
            if ai_analysis and 'analyses' in ai_analysis:
                count = len(ai_analysis['analyses'])
                return f"{count} imágenes analizadas"
            data = response.get('data', [])
            return f"{len(data)} elementos de media"
        
        return "Completado"
    except Exception:
        return "Completado"


def _extract_media_urls(response: Any) -> List[str]:
    """Extrae URLs de media (samsara_url) de la respuesta de get_camera_media."""
    try:
        if isinstance(response, str):
            try:
                response = json.loads(response)
            except:
                import ast
                try:
                    response = ast.literal_eval(response)
                except:
                    return []
        
        if not isinstance(response, dict):
            return []
        
        urls = []
        ai_analysis = response.get('ai_analysis', {})
        
        if ai_analysis and 'analyses' in ai_analysis:
            for analysis in ai_analysis['analyses']:
                url = analysis.get('samsara_url') or analysis.get('download_url') or analysis.get('url')
                if url:
                    urls.append(url)
        
        if not urls:
            data = response.get('data', [])
            for item in data:
                if isinstance(item, dict):
                    url = item.get('samsara_url') or item.get('download_url') or item.get('url')
                    if url:
                        urls.append(url)
        
        return urls
    except Exception:
        return []


def _clean_markdown(text: str) -> str:
    """Limpia bloques de código markdown del texto."""
    if not text:
        return text
    
    # Remover bloques de código markdown (```json ... ``` o ``` ... ```)
    # Patrón para capturar el contenido entre los bloques
    pattern = r'```(?:json|JSON)?\s*\n?(.*?)\n?```'
    match = re.search(pattern, text, re.DOTALL)
    if match:
        # Si encontramos un bloque de código, usar solo el contenido
        text = match.group(1).strip()
    else:
        # Si no hay bloques, intentar limpiar cualquier ``` residual
        text = re.sub(r'^```\w*\s*', '', text, flags=re.MULTILINE)
        text = re.sub(r'\s*```$', '', text, flags=re.MULTILINE)
        text = text.strip()
    
    return text


def _generate_agent_summary(agent_name: str, raw_output: str) -> str:
    """Genera un resumen conciso basado en el output del agente."""
    clean_text = _clean_markdown(raw_output)
    
    try:
        data = json.loads(clean_text)
        
        # Triage agent
        if agent_name == "triage_agent":
            alert_type = data.get("alert_type", "unknown")
            alert_kind = data.get("alert_kind", "unknown")
            severity = data.get("severity_level", "unknown")
            return f"Triaje: {alert_type} ({alert_kind}, {severity})"
        
        # Investigator agent
        elif agent_name == "investigator_agent":
            verdict = data.get("verdict", "unknown")
            confidence = data.get("confidence", 0)
            confidence_pct = int(confidence * 100) if isinstance(confidence, float) else confidence
            risk = data.get("risk_escalation", "monitor")
            return f"Evaluación: {verdict} ({confidence_pct}% confianza, {risk})"
        
        # Final agent - returns string, not JSON
        elif agent_name == "final_agent":
            return clean_text[:150] + "..." if len(clean_text) > 150 else clean_text
        
        # Notification decision agent
        elif agent_name == "notification_decision_agent":
            should_notify = data.get("should_notify", False)
            escalation = data.get("escalation_level", "none")
            channels = data.get("channels_to_use", [])
            if should_notify:
                return f"Notificación: {escalation} via {', '.join(channels)}"
            return f"Sin notificación ({escalation})"
            
    except (json.JSONDecodeError, Exception):
        pass
    
    # Fallback: truncar
    return clean_text[:150] + "..." if len(clean_text) > 150 else clean_text


# ============================================================================
# PIPELINE EXECUTOR
# ============================================================================
class PipelineExecutor:
    """
    Ejecuta el pipeline de agentes ADK y captura resultados.
    
    Esta clase encapsula toda la lógica de:
    - Creación de sesiones ADK
    - Tracking de spans en Langfuse
    - Captura de resultados de agentes y tools
    - Ejecución de notificaciones post-pipeline
    
    OPTIMIZACIÓN: El triage_agent recibe un payload mínimo para clasificación rápida.
    El payload completo se inyecta al investigator_agent.
    """
    
    def __init__(self):
        self._trace = None
        self._pipeline_span = None
        self._active_spans: Dict[str, Any] = {}
        self._agent_results: List[AgentResult] = []
        self._current_agent: Optional[str] = None
        self._current_agent_result: Optional[AgentResult] = None
        self._agent_start_time: Optional[datetime] = None
        self._agent_outputs: Dict[str, str] = {}
        self._pending_tools: Dict[str, Dict] = {}
        self._total_tools = 0
        self._camera_analysis: Optional[Dict[str, Any]] = None
        # Guardar payload completo para inyectarlo al investigator
        self._full_payload: Optional[Dict[str, Any]] = None
        self._is_revalidation: bool = False
        self._revalidation_context: Optional[Dict[str, Any]] = None
        # Flag para indicar si saltamos el triage (optimización de revalidación)
        self._skip_triage: bool = False
    
    async def execute(
        self,
        payload: Dict[str, Any],
        event_id: int,
        is_revalidation: bool = False,
        context: Optional[Dict[str, Any]] = None
    ) -> PipelineResult:
        """
        Ejecuta el pipeline de agentes para una alerta.
        
        Args:
            payload: Payload de la alerta de Samsara
            event_id: ID del evento en la base de datos
            is_revalidation: True si es una revalidación
            context: Contexto adicional para revalidaciones
            
        Returns:
            PipelineResult con alert_context, assessment, human_message, etc.
        """
        # Guardar payload completo para inyectarlo al investigator después del triage
        self._full_payload = payload
        self._is_revalidation = is_revalidation
        self._revalidation_context = context
        
        # Extraer metadata
        alert_type = payload.get("alertType", "unknown")
        vehicle_id = payload.get("vehicle", {}).get("id", "unknown")
        driver_name = payload.get("driver", {}).get("name", "unknown")
        driver_id = payload.get("driver", {}).get("id")
        
        # Crear trace de Langfuse
        self._create_trace(
            event_id=event_id,
            alert_type=alert_type,
            vehicle_id=vehicle_id,
            driver_name=driver_name,
            is_revalidation=is_revalidation,
            context=context
        )
        
        # Crear sesión ADK
        session_id = str(uuid.uuid4())
        await session_service.create_session(
            user_id=ServiceConfig.DEFAULT_USER_ID,
            session_id=session_id,
            app_name=ServiceConfig.APP_NAME
        )
        
        # =========================================================
        # DETERMINAR SI PODEMOS SALTAR EL TRIAGE (OPTIMIZACIÓN)
        # =========================================================
        # En revalidaciones con alert_context previo, saltamos el triage
        # porque ya conocemos el tipo de alerta. Esto ahorra ~2 minutos.
        skip_triage = (
            is_revalidation 
            and context 
            and context.get("previous_alert_context")
        )
        self._skip_triage = skip_triage
        
        # Seleccionar el runner correcto
        selected_runner = revalidation_runner if skip_triage else runner
        
        if skip_triage:
            logger.info(f"OPTIMIZATION: Skipping triage for revalidation (event_id={event_id})")
        
        # =========================================================
        # CONSTRUIR MENSAJE INICIAL
        # =========================================================
        import asyncio
        
        if skip_triage:
            # Para revalidaciones: construir input directo para investigator
            initial_message = self._build_revalidation_message(payload, context)
        else:
            # Para procesamiento inicial: construir input para triage (payload mínimo)
            initial_message = self._build_initial_message(payload, is_revalidation, context)
        
        # =========================================================
        # ANALIZAR IMÁGENES EN PARALELO
        # =========================================================
        camera_analysis_task = asyncio.create_task(analyze_preloaded_media(payload))
        
        # Guardar referencia para obtener resultado después
        self._camera_analysis_task = camera_analysis_task
        self._camera_analysis = None
        current_input = initial_message.parts[0].text
        
        # Crear span del pipeline
        self._create_pipeline_span(session_id, current_input, context)
        
        try:
            # Si saltamos el triage, usar el alert_context previo
            alert_context = None
            if skip_triage:
                alert_context = context.get("previous_alert_context")
                if not alert_context:
                    logger.error(f"Revalidation without previous_alert_context (event_id={event_id})")
                    return PipelineResult(
                        success=False,
                        error="Revalidation requires previous_alert_context but it was not provided"
                    )
                logger.info(f"Using previous alert_context for revalidation", extra={
                    "context": {
                        "alert_type": alert_context.get("alert_type"),
                        "alert_kind": alert_context.get("alert_kind"),
                        "event_id": event_id,
                    }
                })
            
            assessment = None
            human_message = None
            notification_decision = None
            
            # Ejecutar pipeline (usa selected_runner según sea revalidación o no)
            event_count = 0
            async for event in selected_runner.run_async(
                user_id=ServiceConfig.DEFAULT_USER_ID,
                session_id=session_id,
                new_message=initial_message
            ):
                event_count += 1
                
                # Procesar cambio de agente
                agent_name = self._detect_agent(event)
                if agent_name:
                    logger.debug(f"Agent detected: {agent_name} (event #{event_count})")
                    current_input = await self._handle_agent_change(agent_name, session_id, current_input)
                
                # Procesar tool calls/responses (fallback)
                tracker = current_tool_tracker.get()
                if not tracker:
                    self._process_tool_events(event)
                
                # Capturar texto generado
                text = self._extract_text(event)
                if text:
                    logger.debug(f"Text extracted from {self._current_agent} (event #{event_count})", extra={
                        "context": {
                            "text_length": len(text),
                            "text_preview": text[:200] if len(text) > 200 else text,
                        }
                    })
                    self._agent_outputs[self._current_agent] = text
                    self._update_span_output(text)
                    
                    # Parsear según el agente actual
                    if self._current_agent == "triage_agent":
                        parsed = self._try_parse_json(text, ["alert_type", "alert_kind"])
                        if parsed:
                            alert_context = parsed
                            logger.info(f"Triage parsed successfully: alert_type={parsed.get('alert_type')}")
                    
                    elif self._current_agent == "investigator_agent":
                        parsed = self._try_parse_json(text, ["likelihood", "verdict"])
                        if parsed:
                            assessment = parsed
                            logger.info(f"Assessment parsed successfully: verdict={parsed.get('verdict')}, risk_escalation={parsed.get('risk_escalation')}")
                        else:
                            logger.warning(f"Failed to parse assessment from investigator output", extra={
                                "context": {
                                    "text_length": len(text),
                                    "is_revalidation": is_revalidation,
                                }
                            })
                    
                    elif self._current_agent == "final_agent":
                        # human_message es STRING, no JSON
                        human_message = text.strip()
                        logger.debug(f"Human message captured: {len(human_message)} chars")
                    
                    elif self._current_agent == "notification_decision_agent":
                        parsed = self._try_parse_json(text, ["should_notify"])
                        if parsed:
                            notification_decision = parsed
                            logger.info(f"Notification decision parsed: should_notify={parsed.get('should_notify')}")
            
            # Log resumen del pipeline
            logger.info(f"Pipeline iteration completed (event_id={event_id})", extra={
                "context": {
                    "total_events": event_count,
                    "agents_with_output": list(self._agent_outputs.keys()),
                    "has_assessment": assessment is not None,
                    "has_alert_context": alert_context is not None,
                    "is_revalidation": is_revalidation,
                }
            })
            
            # Finalizar último agente
            self._finalize_current_agent()
            
            # Asegurar que el análisis de imágenes esté completo
            if hasattr(self, '_camera_analysis_task') and self._camera_analysis_task:
                try:
                    self._camera_analysis = await self._camera_analysis_task
                    self._camera_analysis_task = None
                except Exception as e:
                    logger.error(f"Error completing camera analysis: {e}")
            
            # =========================================================
            # VALIDACIÓN CRÍTICA: Verificar que tenemos un assessment válido
            # Si el pipeline no generó un assessment, es un error
            # =========================================================
            if not assessment or not isinstance(assessment, dict):
                logger.error(f"Pipeline completed but no assessment was generated (event_id={event_id})", extra={
                    "context": {
                        "has_alert_context": bool(alert_context),
                        "has_human_message": bool(human_message),
                        "agent_outputs": list(self._agent_outputs.keys()),
                        "is_revalidation": is_revalidation,
                    }
                })
                # Cerrar spans antes de retornar error
                self._close_all_spans()
                return PipelineResult(
                    success=False,
                    error="Pipeline completed but investigator_agent did not generate a valid assessment"
                )
            
            # Validar que el assessment tenga los campos requeridos
            required_fields = ["verdict", "likelihood", "confidence", "risk_escalation"]
            missing_fields = [f for f in required_fields if f not in assessment]
            if missing_fields:
                logger.error(f"Assessment is missing required fields (event_id={event_id})", extra={
                    "context": {
                        "missing_fields": missing_fields,
                        "assessment_keys": list(assessment.keys()),
                        "is_revalidation": is_revalidation,
                    }
                })
                # Cerrar spans antes de retornar error
                self._close_all_spans()
                return PipelineResult(
                    success=False,
                    error=f"Assessment is missing required fields: {', '.join(missing_fields)}"
                )
            
            # Ejecutar notificaciones (código determinista, no LLM)
            notification_execution = None
            if notification_decision and notification_decision.get("should_notify"):
                notification_execution = await execute_notifications(
                    decision=notification_decision,
                    event_id=event_id,
                    vehicle_id=vehicle_id if vehicle_id != "unknown" else None,
                    driver_id=driver_id
                )
            else:
                notification_execution = {
                    "attempted": False,
                    "results": [],
                    "timestamp_utc": datetime.utcnow().isoformat() + "Z",
                    "dedupe_key": notification_decision.get("dedupe_key", "") if notification_decision else "",
                    "throttled": False,
                    "throttle_reason": None
                }
            
            # Cerrar spans
            self._close_all_spans()
            
            # Actualizar trace
            self._finalize_trace(assessment, human_message)
            
            logger.info(f"Pipeline completed successfully (event_id={event_id})", extra={
                "context": {
                    "verdict": assessment.get("verdict"),
                    "risk_escalation": assessment.get("risk_escalation"),
                    "requires_monitoring": assessment.get("requires_monitoring", False),
                    "is_revalidation": is_revalidation,
                }
            })
            
            return PipelineResult(
                success=True,
                alert_context=alert_context,
                assessment=assessment,
                human_message=human_message or "Procesamiento completado",
                notification_decision=notification_decision,
                notification_execution=notification_execution,
                camera_analysis=self._camera_analysis,  # Para que Laravel persista las imágenes
                agents=self._agent_results,
                total_duration_ms=sum(a.duration_ms for a in self._agent_results),
                total_tools_called=self._total_tools
            )
            
        except Exception as e:
            self._handle_error(e)
            return PipelineResult(
                success=False,
                error=str(e)
            )
        finally:
            current_tool_tracker.set(None)
    
    # =========================================================================
    # PRIVATE: Langfuse Tracing
    # =========================================================================
    def _create_trace(
        self,
        event_id: int,
        alert_type: str,
        vehicle_id: str,
        driver_name: str,
        is_revalidation: bool,
        context: Optional[Dict]
    ):
        """Crea el trace de Langfuse."""
        if not langfuse_client:
            return
        
        name = "samsara_alert_revalidation" if is_revalidation else "samsara_alert_processing"
        metadata = {
            "event_id": event_id,
            "alert_type": alert_type,
            "vehicle_id": vehicle_id,
            "driver_name": driver_name,
        }
        if is_revalidation and context:
            metadata["investigation_count"] = context.get("investigation_count", 0)
            metadata["is_revalidation"] = True
        
        self._trace = langfuse_client.trace(
            name=name,
            user_id=ServiceConfig.DEFAULT_USER_ID,
            metadata=metadata,
            tags=["samsara", "revalidation" if is_revalidation else "alert", alert_type]
        )
    
    def _create_pipeline_span(self, session_id: str, input_text: str, context: Optional[Dict]):
        """Crea el span del pipeline."""
        if not self._trace:
            return
        
        metadata = {
            "session_id": session_id,
            "user_id": ServiceConfig.DEFAULT_USER_ID
        }
        if context:
            metadata["investigation_count"] = context.get("investigation_count", 0)
        
        self._pipeline_span = self._trace.span(
            name="agent_pipeline_execution",
            metadata=metadata,
            input=input_text
        )
    
    def _create_agent_span(self, agent_name: str, session_id: str, current_input: str):
        """Crea un span para un agente."""
        if not self._trace or agent_name in self._active_spans:
            return
        
        available_tools = []
        agent_def = AGENTS_BY_NAME.get(agent_name)
        if agent_def and hasattr(agent_def, 'tools') and agent_def.tools:
            available_tools = [t.__name__ for t in agent_def.tools]
        
        span = self._trace.span(
            name=f"agent_{agent_name}",
            metadata={
                "agent_name": agent_name,
                "session_id": session_id,
                "available_tools": available_tools
            },
            parent_observation_id=self._pipeline_span.id if self._pipeline_span else None,
            input=current_input
        )
        self._active_spans[agent_name] = span
        current_langfuse_span.set(span)
    
    def _close_all_spans(self):
        """Cierra todos los spans abiertos."""
        for agent_name, span in self._active_spans.items():
            output = self._agent_outputs.get(agent_name, "")
            span.end(output=output)
        self._active_spans.clear()
        
        if self._pipeline_span:
            self._pipeline_span.end()
    
    def _finalize_trace(self, assessment: Optional[Dict], human_message: Optional[str]):
        """Finaliza el trace de Langfuse."""
        if not self._trace:
            return
        
        self._trace.update(
            output={
                "status": "success",
                "assessment": assessment,
                "human_message": human_message
            }
        )
        
        # Flush to ensure traces are sent
        if langfuse_client:
            langfuse_client.flush()
    
    def _handle_error(self, error: Exception):
        """Maneja errores y actualiza el trace."""
        if self._trace:
            self._trace.update(
                level="ERROR",
                status_message=str(error)
            )
        
        # Flush to ensure error traces are sent
        if langfuse_client:
            langfuse_client.flush()
    
    # =========================================================================
    # PRIVATE: Message Building
    # =========================================================================
    def _build_initial_message(
        self,
        payload: Dict[str, Any],
        is_revalidation: bool,
        context: Optional[Dict]
    ) -> types.Content:
        """
        Construye el mensaje inicial para el pipeline.
        
        OPTIMIZACIÓN: Solo enviamos datos esenciales al triage_agent para
        clasificación rápida. El payload completo se inyecta al investigator
        después del triage para que tenga toda la información disponible.
        
        Esto reduce el tiempo del triage de ~2 minutos a ~5-10 segundos.
        """
        # Construir payload MÍNIMO para el triage (solo datos de clasificación)
        minimal_payload = self._build_triage_payload(payload, is_revalidation, context)
        minimal_json = json.dumps(minimal_payload, ensure_ascii=False, indent=2)
        
        if is_revalidation and context:
            text = f"Clasifica esta revalidación de alerta de Samsara:\n\nPAYLOAD:\n{minimal_json}"
        else:
            text = f"Clasifica esta alerta de Samsara:\n\nPAYLOAD:\n{minimal_json}"
        
        return types.Content(parts=[types.Part(text=text)])
    
    def _build_triage_payload(
        self,
        payload: Dict[str, Any],
        is_revalidation: bool,
        context: Optional[Dict]
    ) -> Dict[str, Any]:
        """
        Construye payload para el triage_agent con información suficiente para clasificación.
        
        IMPORTANTE: Incluimos suficiente contexto para que el triage pueda:
        - Clasificar correctamente el tipo de alerta (panic, safety, tampering, etc.)
        - Extraer información del vehículo y conductor
        - Determinar la severidad
        
        Buscamos información en múltiples lugares del payload porque Samsara
        tiene diferentes estructuras según el tipo de evento.
        """
        # Extraer datos del nivel superior del payload
        data = payload.get("data", {})
        preloaded = payload.get("preloaded_data", {})
        
        # =====================================================================
        # EXTRACCIÓN DE VEHÍCULO (buscar en múltiples lugares)
        # =====================================================================
        vehicle = self._extract_vehicle_info(payload)
        
        # =====================================================================
        # EXTRACCIÓN DE CONDUCTOR (buscar en múltiples lugares)
        # =====================================================================
        safety_event_detail = payload.get("safety_event_detail") or preloaded.get("safety_event_detail", {})
        driver = self._extract_driver_info(payload, safety_event_detail)
        
        # =====================================================================
        # EXTRACCIÓN DE TIPO DE EVENTO
        # =====================================================================
        event_type = payload.get("eventType") or payload.get("type") or data.get("eventType")
        alert_type = payload.get("alertType") or data.get("alertType")
        
        # Behavior label es CRUCIAL para clasificar safety events
        behavior_label = (
            payload.get("behavior_label") 
            or (safety_event_detail.get("behavior_label") if safety_event_detail else None)
            or (safety_event_detail.get("behaviorLabel") if safety_event_detail else None)
        )
        
        # También extraer behavior_name si existe
        behavior_name = safety_event_detail.get("behavior_name") if safety_event_detail else None
        
        # =====================================================================
        # EXTRAER DESCRIPCIÓN DEL EVENTO
        # =====================================================================
        event_description = self._extract_event_description(payload)
        
        # =====================================================================
        # CONSTRUIR PAYLOAD PARA TRIAGE
        # =====================================================================
        minimal_payload = {
            # Tipo de evento
            "eventType": event_type,
            "alertType": alert_type,
            "event_description": event_description,
            
            # Info del vehículo
            "vehicle": vehicle,
            
            # Info del conductor
            "driver": driver,
            
            # Timestamps
            "happenedAtTime": (
                payload.get("happenedAtTime") 
                or data.get("happenedAtTime")
                or (safety_event_detail.get("time") if safety_event_detail else None)
            ),
            
            # Severity
            "severity": payload.get("severity") or data.get("severity"),
            
            # Behavior label y name (para safety events - CRUCIAL para clasificación)
            "behavior_label": behavior_label,
            "behavior_name": behavior_name,
            "samsara_severity": (
                payload.get("samsara_severity") 
                or (safety_event_detail.get("severity") if safety_event_detail else None)
            ),
            
            # Notification contacts (solo nombres y roles, sin datos sensibles)
            "notification_contacts": self._extract_minimal_contacts(payload.get("notification_contacts", {})),
            
            # Indicar si hay datos pre-cargados disponibles
            "has_preloaded_data": bool(preloaded),
            "has_safety_event_detail": bool(safety_event_detail),
            "has_camera_media": bool(preloaded.get("camera_media")),
        }
        
        # Si tenemos safety_event_detail, incluir resumen relevante
        if safety_event_detail:
            minimal_payload["safety_event_summary"] = {
                "behavior_label": safety_event_detail.get("behavior_label"),
                "behavior_name": safety_event_detail.get("behavior_name"),
                "severity": safety_event_detail.get("severity"),
                "max_acceleration_g": safety_event_detail.get("maxAccelerationGForce"),
                "has_video": bool(safety_event_detail.get("downloadForwardVideoUrl") or safety_event_detail.get("downloadInwardVideoUrl")),
            }
        
        # Para revalidaciones, agregar contexto mínimo
        if is_revalidation and context:
            minimal_payload["is_revalidation"] = True
            minimal_payload["investigation_count"] = context.get("investigation_count", 0)
            minimal_payload["previous_verdict"] = context.get("previous_assessment", {}).get("verdict")
            minimal_payload["previous_risk_escalation"] = context.get("previous_assessment", {}).get("risk_escalation")
            
            # Resumen de ventanas sin el historial completo
            windows_history = payload.get('revalidation_windows_history', [])
            revalidation_data = payload.get('revalidation_data', {})
            
            minimal_payload["revalidation_summary"] = {
                "total_windows_checked": len(windows_history),
                "total_minutes_observed": sum(w.get('time_window', {}).get('minutes_covered', 0) for w in windows_history),
                "new_safety_events_this_window": revalidation_data.get('safety_events_since_last_check', {}).get('total_events', 0),
                "new_camera_items_this_window": revalidation_data.get('camera_media_since_last_check', {}).get('total_items', 0),
            }
        
        # Log para debugging
        logger.debug("Triage payload built", extra={"context": {
            "has_vehicle_id": bool(vehicle.get("id")),
            "has_driver_id": bool(driver.get("id")),
            "event_type": event_type,
            "alert_type": alert_type,
            "behavior_label": behavior_label,
            "behavior_name": behavior_name,
        }})
        
        return minimal_payload
    
    def _extract_vehicle_info(self, payload: Dict[str, Any]) -> Dict[str, Any]:
        """
        Extrae información del vehículo buscando en múltiples lugares del payload.
        
        Samsara tiene diferentes estructuras según el tipo de evento:
        - Nivel superior: payload.vehicle
        - AlertIncident: data.conditions[].details[].vehicle
        - Safety events: preloaded_data.vehicle_info
        """
        data = payload.get("data", {})
        preloaded = payload.get("preloaded_data", {})
        
        # 1. Nivel superior
        if payload.get("vehicle") and isinstance(payload["vehicle"], dict):
            v = payload["vehicle"]
            if v.get("id"):
                return {"id": v.get("id"), "name": v.get("name")}
        
        # 2. Dentro de data
        if data.get("vehicle") and isinstance(data["vehicle"], dict):
            v = data["vehicle"]
            if v.get("id"):
                return {"id": v.get("id"), "name": v.get("name")}
        
        # 3. Dentro de data.conditions (AlertIncident)
        conditions = data.get("conditions", [])
        if isinstance(conditions, list):
            for condition in conditions:
                details = condition.get("details", [])
                if isinstance(details, list):
                    for detail in details:
                        if isinstance(detail, dict) and detail.get("vehicle"):
                            v = detail["vehicle"]
                            if v.get("id"):
                                return {"id": v.get("id"), "name": v.get("name")}
        
        # 4. Desde preloaded_data.vehicle_info
        vehicle_info = preloaded.get("vehicle_info", {})
        if vehicle_info.get("id"):
            return {"id": vehicle_info.get("id"), "name": vehicle_info.get("name")}
        
        # 5. Desde safety_event_detail.vehicle
        safety_detail = payload.get("safety_event_detail") or preloaded.get("safety_event_detail", {})
        if safety_detail.get("vehicle"):
            v = safety_detail["vehicle"]
            if v.get("id"):
                return {"id": v.get("id"), "name": v.get("name")}
        
        # 6. IDs directos
        if payload.get("vehicleId"):
            return {"id": payload.get("vehicleId"), "name": payload.get("vehicleName")}
        
        return {"id": None, "name": None}
    
    def _extract_driver_info(self, payload: Dict[str, Any], safety_event_detail: Dict[str, Any]) -> Dict[str, Any]:
        """
        Extrae información del conductor buscando en múltiples lugares.
        
        Prioridad:
        1. safety_event_detail.driver (conductor del momento del evento)
        2. preloaded_data.driver_assignment.driver
        3. payload.driver
        """
        preloaded = payload.get("preloaded_data", {})
        
        # 1. Desde safety_event_detail (más preciso para el momento del evento)
        if safety_event_detail and safety_event_detail.get("driver"):
            d = safety_event_detail["driver"]
            if d.get("id"):
                return {"id": d.get("id"), "name": d.get("name")}
        
        # 2. Desde driver_assignment
        assignment = preloaded.get("driver_assignment", {})
        if assignment.get("driver"):
            d = assignment["driver"]
            if d.get("id"):
                return {"id": d.get("id"), "name": d.get("name")}
        
        # 3. Nivel superior
        if payload.get("driver") and isinstance(payload["driver"], dict):
            d = payload["driver"]
            if d.get("id"):
                return {"id": d.get("id"), "name": d.get("name")}
        
        # 4. Dentro de data
        data = payload.get("data", {})
        if data.get("driver") and isinstance(data["driver"], dict):
            d = data["driver"]
            if d.get("id"):
                return {"id": d.get("id"), "name": d.get("name")}
        
        # 5. IDs directos
        if payload.get("driverId"):
            return {"id": payload.get("driverId"), "name": payload.get("driverName")}
        
        return {"id": None, "name": None}
    
    def _extract_event_description(self, payload: Dict[str, Any]) -> Optional[str]:
        """
        Extrae la descripción del evento buscando en múltiples lugares.
        """
        # 1. Nivel superior
        if payload.get("event_description"):
            return payload["event_description"]
        
        # 2. Dentro de data.conditions
        data = payload.get("data", {})
        conditions = data.get("conditions", [])
        if isinstance(conditions, list):
            for condition in conditions:
                if condition.get("description"):
                    return condition["description"]
        
        # 3. Desde safety_event_detail
        preloaded = payload.get("preloaded_data", {})
        safety_detail = payload.get("safety_event_detail") or preloaded.get("safety_event_detail", {})
        if safety_detail:
            # Usar behavior_name como descripción si existe
            if safety_detail.get("behavior_name"):
                return safety_detail["behavior_name"]
            if safety_detail.get("behavior_label"):
                return safety_detail["behavior_label"]
        
        return None
    
    def _extract_minimal_contacts(self, contacts: Dict[str, Any]) -> Dict[str, Any]:
        """Extrae solo nombre y rol de los contactos (sin números de teléfono)."""
        if not contacts:
            return {}
        
        minimal = {}
        for key, contact in contacts.items():
            if isinstance(contact, dict):
                minimal[key] = {
                    "name": contact.get("name"),
                    "role": contact.get("role"),
                }
        return minimal
    
    def _build_revalidation_message(
        self,
        payload: Dict[str, Any],
        context: Dict[str, Any]
    ) -> types.Content:
        """
        Construye mensaje para revalidación SIN TRIAGE.
        
        OPTIMIZACIÓN: En revalidaciones usamos el alert_context previo
        y enviamos directamente al investigator_agent. Esto ahorra ~2 minutos.
        
        El mensaje incluye:
        - alert_context previo (del triage original)
        - Datos pre-cargados actualizados
        - Datos de revalidación (nuevos desde última investigación)
        - Contexto temporal de la revalidación
        """
        parts = []
        
        # 1. Alert context previo (del triage original)
        previous_alert_context = context.get("previous_alert_context", {})
        parts.append("## CONTEXTO DE ALERTA (del triaje original)")
        parts.append("```json")
        parts.append(json.dumps(previous_alert_context, ensure_ascii=False, indent=2))
        parts.append("```")
        
        # 2. Contexto temporal de revalidación
        parts.append("\n## CONTEXTO DE REVALIDACIÓN")
        parts.append(f"- Evento original: {context.get('original_event_time', 'unknown')}")
        parts.append(f"- Primera investigación: {context.get('first_investigation_time', 'unknown')}")
        parts.append(f"- Última investigación: {context.get('last_investigation_time', 'unknown')}")
        parts.append(f"- Revalidación actual: {context.get('current_revalidation_time', 'unknown')}")
        parts.append(f"- Número de investigación: {context.get('investigation_count', 0) + 1}")
        
        # 3. Assessment previo
        previous_assessment = context.get("previous_assessment", {})
        if previous_assessment:
            parts.append("\n## EVALUACIÓN PREVIA")
            parts.append(f"- Veredicto anterior: {previous_assessment.get('verdict', 'unknown')}")
            parts.append(f"- Confianza anterior: {previous_assessment.get('confidence', 0)}")
            parts.append(f"- Risk escalation: {previous_assessment.get('risk_escalation', 'unknown')}")
            parts.append(f"- Razón de monitoreo: {previous_assessment.get('monitoring_reason', 'N/A')}")
        
        # 4. Datos pre-cargados
        preloaded = payload.get('preloaded_data', {})
        if preloaded:
            # Excluir camera_media - se agrega con el análisis Vision
            filtered_preloaded = {k: v for k, v in preloaded.items() if k != 'camera_media'}
            parts.append("\n## DATOS PRE-CARGADOS (preloaded_data)")
            parts.append(json.dumps(filtered_preloaded, ensure_ascii=False, indent=2))
        
        # 5. Safety event detail si existe
        safety_detail = payload.get('safety_event_detail')
        if safety_detail:
            parts.append("\n## DETALLE DEL SAFETY EVENT")
            parts.append(json.dumps(safety_detail, ensure_ascii=False, indent=2))
        
        # 6. Datos NUEVOS de revalidación (lo más importante)
        revalidation_data = payload.get('revalidation_data', {})
        if revalidation_data:
            parts.append("\n## ⭐ DATOS NUEVOS (revalidation_data) - PRIORIZA ESTOS")
            # Excluir camera_media - se agrega con el análisis Vision
            filtered_reval = {k: v for k, v in revalidation_data.items() if 'camera' not in k.lower()}
            parts.append(json.dumps(filtered_reval, ensure_ascii=False, indent=2))
            
            # Resumen de lo nuevo
            new_safety = revalidation_data.get('safety_events_since_last_check', {}).get('total_events', 0)
            new_camera = revalidation_data.get('camera_media_since_last_check', {}).get('total_items', 0)
            parts.append(f"\n**Resumen de datos nuevos:**")
            parts.append(f"- Nuevos safety events: {new_safety}")
            parts.append(f"- Nuevas imágenes de cámara: {new_camera}")
        
        # 7. Historial de ventanas
        windows_history = payload.get('revalidation_windows_history', [])
        if windows_history:
            total_minutes = sum(w.get('time_window', {}).get('minutes_covered', 0) for w in windows_history)
            parts.append(f"\n## HISTORIAL DE INVESTIGACIONES ({len(windows_history)} ventanas, {total_minutes} minutos observados)")
            parts.append(json.dumps(windows_history, ensure_ascii=False, indent=2))
        
        # 8. Contactos para notificaciones
        contacts = payload.get('notification_contacts', {})
        if contacts:
            parts.append("\n## CONTACTOS PARA NOTIFICACIONES")
            parts.append(json.dumps(contacts, ensure_ascii=False, indent=2))
        
        # 9. Instrucciones finales
        parts.append("\n## INSTRUCCIONES")
        parts.append("1. PRIORIZA los datos en `revalidation_data` - son NUEVOS desde la última investigación")
        parts.append("2. Compara con la evaluación previa - ¿la situación mejoró, empeoró o sigue igual?")
        parts.append("3. Si hay nuevos safety events, evalúa si indican un patrón de riesgo")
        parts.append("4. Si no hay novedad y ya hay suficiente tiempo de observación, considera dar veredicto definitivo")
        
        text = "\n".join(parts)
        return types.Content(parts=[types.Part(text=text)])
    
    # =========================================================================
    # PRIVATE: Agent Tracking
    # =========================================================================
    def _detect_agent(self, event: Any) -> Optional[str]:
        """Detecta el agente que emitió el evento."""
        agent_name = getattr(event, 'author', None)
        
        if not agent_name:
            has_content = hasattr(event, 'tool_requests') or (
                hasattr(event, 'content') and event.content
            )
            if has_content:
                agent_name = self._current_agent or "unknown_agent"
        
        return agent_name
    
    def _build_final_agent_input(self, alert_context: str, assessment: str) -> str:
        """
        Construye input compacto para el final_agent.
        
        OPTIMIZACIÓN: Solo enviamos lo necesario para generar el mensaje humano:
        - Alert context (tipo de alerta, vehículo, conductor)
        - Assessment (veredicto, confianza, acciones recomendadas)
        
        NO enviamos el payload completo ni datos de investigación detallados.
        """
        parts = []
        
        # 1. Alert context (resumido)
        parts.append("## CONTEXTO DE ALERTA (alert_context)")
        # Limitar el tamaño para evitar tokens innecesarios
        if len(alert_context) > 3000:
            parts.append(alert_context[:3000] + "\n... (truncado)")
        else:
            parts.append(alert_context)
        
        # 2. Assessment (completo - es lo que necesita para el mensaje)
        parts.append("\n## EVALUACIÓN TÉCNICA (assessment)")
        if len(assessment) > 3000:
            parts.append(assessment[:3000] + "\n... (truncado)")
        else:
            parts.append(assessment)
        
        # 3. Instrucciones claras
        parts.append("\n## INSTRUCCIONES")
        parts.append("Genera un mensaje claro y conciso en ESPAÑOL para el equipo de monitoreo.")
        parts.append("Incluye: tipo de alerta, vehículo, conductor, veredicto, y acción recomendada.")
        parts.append("El mensaje debe ser de 4-7 líneas máximo.")
        
        return "\n".join(parts)
    
    def _build_notification_input(self, assessment: str, alert_context: str) -> str:
        """
        Construye input compacto para el notification_decision_agent.
        
        OPTIMIZACIÓN: Solo enviamos lo necesario para decidir notificaciones:
        - Assessment (veredicto, risk_escalation, confianza)
        - Contactos disponibles
        """
        parts = []
        
        # 1. Assessment (es lo principal para decidir notificaciones)
        parts.append("## EVALUACIÓN (assessment)")
        if len(assessment) > 2000:
            parts.append(assessment[:2000] + "\n... (truncado)")
        else:
            parts.append(assessment)
        
        # 2. Info básica del alert_context
        parts.append("\n## CONTEXTO DE ALERTA")
        if len(alert_context) > 1500:
            parts.append(alert_context[:1500] + "\n... (truncado)")
        else:
            parts.append(alert_context)
        
        # 3. Contactos para notificaciones (del payload)
        if self._full_payload:
            contacts = self._full_payload.get('notification_contacts', {})
            if contacts:
                parts.append("\n## CONTACTOS DISPONIBLES")
                parts.append(json.dumps(contacts, ensure_ascii=False, indent=2))
        
        return "\n".join(parts)
    
    def _build_investigator_input(self, triage_output: str) -> str:
        """
        Construye el input completo para el investigator_agent.
        
        Incluye:
        - Output del triage (alert_context)
        - Payload completo con preloaded_data
        - Contexto de revalidación si aplica
        
        El investigator necesita todos los datos para hacer su análisis.
        """
        parts = []
        
        # 1. Output del triage (alert_context)
        parts.append("## CONTEXTO DEL TRIAJE (alert_context)")
        parts.append(triage_output)
        
        # 2. Datos pre-cargados del payload
        if self._full_payload:
            preloaded = self._full_payload.get('preloaded_data', {})
            if preloaded:
                parts.append("\n## DATOS PRE-CARGADOS (preloaded_data)")
                # No incluir camera_media aquí - se agrega con el análisis Vision
                filtered_preloaded = {k: v for k, v in preloaded.items() if k != 'camera_media'}
                parts.append(json.dumps(filtered_preloaded, ensure_ascii=False, indent=2))
            
            # Safety event detail si existe
            safety_detail = self._full_payload.get('safety_event_detail')
            if safety_detail:
                parts.append("\n## DETALLE DEL SAFETY EVENT")
                parts.append(json.dumps(safety_detail, ensure_ascii=False, indent=2))
        
        # 3. Datos de revalidación si aplica
        if self._is_revalidation and self._full_payload:
            revalidation_data = self._full_payload.get('revalidation_data', {})
            if revalidation_data:
                parts.append("\n## DATOS DE REVALIDACIÓN (revalidation_data)")
                # No incluir camera_media - se agrega con el análisis Vision
                filtered_reval = {k: v for k, v in revalidation_data.items() if 'camera' not in k.lower()}
                parts.append(json.dumps(filtered_reval, ensure_ascii=False, indent=2))
            
            windows_history = self._full_payload.get('revalidation_windows_history', [])
            if windows_history:
                parts.append("\n## HISTORIAL DE VENTANAS DE INVESTIGACIÓN")
                parts.append(json.dumps(windows_history, ensure_ascii=False, indent=2))
            
            if self._revalidation_context:
                parts.append("\n## CONTEXTO TEMPORAL")
                parts.append(f"- Evento original: {self._revalidation_context.get('original_event_time', 'unknown')}")
                parts.append(f"- Número de investigaciones: {self._revalidation_context.get('investigation_count', 0)}")
                parts.append(f"- Último veredicto: {self._revalidation_context.get('previous_assessment', {}).get('verdict', 'unknown')}")
        
        # 4. Contactos para notificaciones
        if self._full_payload:
            contacts = self._full_payload.get('notification_contacts', {})
            if contacts:
                parts.append("\n## CONTACTOS PARA NOTIFICACIONES")
                parts.append(json.dumps(contacts, ensure_ascii=False, indent=2))
        
        return "\n".join(parts)
    
    async def _inject_camera_analysis_if_ready(self, current_input: str) -> str:
        """
        Inyecta el análisis de imágenes al input si está listo.
        Se llama cuando el investigator_agent comienza.
        """
        if not hasattr(self, '_camera_analysis_task') or not self._camera_analysis_task:
            return current_input
        
        try:
            # Esperar el resultado del análisis (ya debería estar listo)
            self._camera_analysis = await self._camera_analysis_task
            self._camera_analysis_task = None
            
            if self._camera_analysis:
                num_images = self._camera_analysis.get('total_images_analyzed', 0)
                logger.info(f"Camera analysis ready: {num_images} images analyzed")
                
                # Inyectar al input del investigator
                analysis_json = json.dumps(self._camera_analysis, ensure_ascii=False, indent=2)
                camera_note = f"""

## ANÁLISIS DE IMÁGENES (Vision AI)

Se analizaron {num_images} imágenes con Vision AI:

```json
{analysis_json}
```

⚠️ IMPORTANTE: Ya tienes el análisis completo de las imágenes. NO necesitas llamar a get_camera_media.
"""
                current_input = current_input + camera_note
        except Exception as e:
            logger.error(f"Error getting camera analysis: {e}")
        
        return current_input
    
    async def _handle_agent_change(
        self,
        agent_name: str,
        session_id: str,
        current_input: str
    ) -> str:
        """Maneja el cambio de agente y retorna el nuevo input."""
        # Si cambió de agente, finalizar el anterior
        if self._current_agent and self._current_agent != agent_name:
            self._finalize_current_agent()
            
            # Cerrar span anterior
            if self._current_agent in self._active_spans:
                last_output = self._agent_outputs.get(self._current_agent, "")
                self._active_spans[self._current_agent].end(output=last_output)
                del self._active_spans[self._current_agent]
                
                if last_output:
                    current_input = last_output
            
            current_tool_tracker.set(None)
            
            # Si estamos entrando al investigator DESDE el triage
            if agent_name == "investigator_agent" and not self._skip_triage:
                # Construir input con todos los datos necesarios para la investigación
                triage_output = self._agent_outputs.get("triage_agent", current_input)
                current_input = self._build_investigator_input(triage_output)
                # Inyectar análisis de imágenes
                current_input = await self._inject_camera_analysis_if_ready(current_input)
            
            # OPTIMIZACIÓN: Input compacto para final_agent
            elif agent_name == "final_agent":
                # En revalidación sin triage, usar previous_alert_context
                if self._skip_triage and self._revalidation_context:
                    alert_ctx = json.dumps(self._revalidation_context.get("previous_alert_context", {}), ensure_ascii=False)
                else:
                    alert_ctx = self._agent_outputs.get("triage_agent", "{}")
                assessment = self._agent_outputs.get("investigator_agent", current_input)
                current_input = self._build_final_agent_input(alert_ctx, assessment)
            
            # OPTIMIZACIÓN: Input compacto para notification_decision_agent
            elif agent_name == "notification_decision_agent":
                # En revalidación sin triage, usar previous_alert_context
                if self._skip_triage and self._revalidation_context:
                    alert_ctx = json.dumps(self._revalidation_context.get("previous_alert_context", {}), ensure_ascii=False)
                else:
                    alert_ctx = self._agent_outputs.get("triage_agent", "{}")
                assessment = self._agent_outputs.get("investigator_agent", "{}")
                current_input = self._build_notification_input(assessment, alert_ctx)
        
        # Si el investigator es el PRIMER agente (revalidación sin triage)
        # Solo necesitamos inyectar el análisis de cámara
        if agent_name == "investigator_agent" and self._skip_triage and self._current_agent is None:
            current_input = await self._inject_camera_analysis_if_ready(current_input)
        
        self._current_agent = agent_name
        
        # Iniciar nuevo agente si no existe
        existing_names = [a.name for a in self._agent_results]
        if agent_name not in existing_names:
            self._agent_start_time = datetime.utcnow()
            self._current_agent_result = AgentResult(
                name=agent_name,
                started_at=self._agent_start_time.isoformat() + "Z"
            )
            self._agent_results.append(self._current_agent_result)
        
        # Crear span
        self._create_agent_span(agent_name, session_id, current_input)
        
        # Configurar tracker para tools
        if self._current_agent_result:
            current_tool_tracker.set({
                "agent_name": self._current_agent,
                "agent_result": self._current_agent_result,
                "executor": self
            })
        
        return current_input
    
    def _finalize_current_agent(self):
        """Finaliza el agente actual."""
        if not self._current_agent or not self._current_agent_result:
            return
        
        if self._current_agent_result.completed_at:
            return  # Ya finalizado
        
        if self._agent_start_time:
            duration = int((datetime.utcnow() - self._agent_start_time).total_seconds() * 1000)
            self._current_agent_result.duration_ms = duration
        
        self._current_agent_result.completed_at = datetime.utcnow().isoformat() + "Z"
        
        raw_output = self._agent_outputs.get(self._current_agent, "")
        self._current_agent_result.summary = _generate_agent_summary(
            self._current_agent, raw_output
        )
    
    # =========================================================================
    # PRIVATE: Tool Tracking
    # =========================================================================
    def _process_tool_events(self, event: Any):
        """Procesa eventos de tools (fallback si no hay tracker)."""
        # Tool requests
        tool_list = []
        if hasattr(event, 'tool_requests') and event.tool_requests:
            tool_list = event.tool_requests
        elif hasattr(event, 'tool_calls') and event.tool_calls:
            tool_list = event.tool_calls
        
        for tool_req in tool_list:
            tool_name = "unknown_tool"
            if hasattr(tool_req, 'name'):
                tool_name = tool_req.name
            elif hasattr(tool_req, 'function') and hasattr(tool_req.function, 'name'):
                tool_name = tool_req.function.name
            
            self._pending_tools[tool_name] = {
                "start_time": datetime.utcnow()
            }
            self._total_tools += 1
        
        # Tool responses
        if hasattr(event, 'tool_responses') and event.tool_responses:
            for tool_resp in event.tool_responses:
                tool_name = getattr(tool_resp, 'name', 'unknown_tool')
                
                if tool_name in self._pending_tools:
                    start_time = self._pending_tools[tool_name]["start_time"]
                    duration = int((datetime.utcnow() - start_time).total_seconds() * 1000)
                    
                    response = getattr(tool_resp, 'response', None)
                    summary = _generate_tool_summary(tool_name, response)
                    
                    tool_result = ToolResult(
                        name=tool_name,
                        status="success",
                        duration_ms=duration,
                        summary=summary
                    )
                    
                    if tool_name == "get_camera_media":
                        tool_result.media_urls = _extract_media_urls(response)
                    
                    if self._current_agent_result:
                        self._current_agent_result.tools.append(tool_result)
                    
                    del self._pending_tools[tool_name]
    
    # =========================================================================
    # PRIVATE: Text Processing
    # =========================================================================
    def _extract_text(self, event: Any) -> Optional[str]:
        """Extrae texto del evento."""
        if not (hasattr(event, 'content') and event.content and event.content.parts):
            return None
        
        for part in event.content.parts:
            if hasattr(part, 'text') and part.text:
                return part.text.strip()
        
        return None
    
    def _update_span_output(self, text: str):
        """Actualiza el output del span actual."""
        if self._current_agent and self._current_agent in self._active_spans:
            self._active_spans[self._current_agent].update(output=text)
    
    def _try_parse_json(self, text: str, required_keys: List[str]) -> Optional[Dict[str, Any]]:
        """Intenta parsear el texto como JSON y verificar keys requeridas."""
        try:
            clean_text = _clean_markdown(text)
            parsed = json.loads(clean_text)
            
            if isinstance(parsed, dict):
                # Verificar que tiene al menos una de las keys requeridas
                for key in required_keys:
                    if key in parsed:
                        logger.debug(f"Successfully parsed JSON with key '{key}'", extra={
                            "context": {
                                "agent": self._current_agent,
                                "parsed_keys": list(parsed.keys()),
                            }
                        })
                        return parsed
                
                # Si tiene otras keys pero no las requeridas, loguear para debugging
                logger.warning(f"Parsed JSON but missing required keys", extra={
                    "context": {
                        "agent": self._current_agent,
                        "required_keys": required_keys,
                        "actual_keys": list(parsed.keys()),
                        "text_preview": text[:200] if len(text) > 200 else text,
                    }
                })
        except json.JSONDecodeError as e:
            logger.warning(f"Failed to parse JSON from agent output", extra={
                "context": {
                    "agent": self._current_agent,
                    "error": str(e),
                    "text_preview": text[:500] if len(text) > 500 else text,
                }
            })
        except Exception as e:
            logger.error(f"Unexpected error parsing JSON", extra={
                "context": {
                    "agent": self._current_agent,
                    "error": str(e),
                    "error_type": type(e).__name__,
                }
            })
        
        return None

