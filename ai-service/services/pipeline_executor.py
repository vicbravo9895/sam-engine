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
from core import runner, session_service
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
        # ANALIZAR IMÁGENES PRE-CARGADAS AUTOMÁTICAMENTE
        # =========================================================
        # Laravel ya pre-carga las URLs de las imágenes.
        # Aquí las analizamos con Vision AI ANTES de que el investigador
        # empiece, para que no tenga que llamar a get_camera_media.
        preloaded_analysis = await analyze_preloaded_media(payload)
        if preloaded_analysis:
            # Agregar el análisis al payload para que esté disponible
            payload['preloaded_camera_analysis'] = preloaded_analysis
            logger.info(f"Preloaded camera analysis: {preloaded_analysis.get('total_images_analyzed', 0)} images analyzed")
        
        # Guardar para incluirlo en el resultado final
        self._camera_analysis = preloaded_analysis
        
        # Construir mensaje inicial
        initial_message = self._build_initial_message(payload, is_revalidation, context)
        current_input = initial_message.parts[0].text
        
        # Crear span del pipeline
        self._create_pipeline_span(session_id, current_input, context)
        
        try:
            alert_context = None
            assessment = None
            human_message = None
            notification_decision = None
            
            # Ejecutar pipeline
            async for event in runner.run_async(
                user_id=ServiceConfig.DEFAULT_USER_ID,
                session_id=session_id,
                new_message=initial_message
            ):
                # Procesar cambio de agente
                agent_name = self._detect_agent(event)
                if agent_name:
                    current_input = self._handle_agent_change(agent_name, session_id, current_input)
                
                # Procesar tool calls/responses (fallback)
                tracker = current_tool_tracker.get()
                if not tracker:
                    self._process_tool_events(event)
                
                # Capturar texto generado
                text = self._extract_text(event)
                if text:
                    self._agent_outputs[self._current_agent] = text
                    self._update_span_output(text)
                    
                    # Parsear según el agente actual
                    if self._current_agent == "triage_agent":
                        parsed = self._try_parse_json(text, ["alert_type", "alert_kind"])
                        if parsed:
                            alert_context = parsed
                    
                    elif self._current_agent == "investigator_agent":
                        parsed = self._try_parse_json(text, ["likelihood", "verdict"])
                        if parsed:
                            assessment = parsed
                    
                    elif self._current_agent == "final_agent":
                        # human_message es STRING, no JSON
                        human_message = text.strip()
                    
                    elif self._current_agent == "notification_decision_agent":
                        parsed = self._try_parse_json(text, ["should_notify"])
                        if parsed:
                            notification_decision = parsed
            
            # Finalizar último agente
            self._finalize_current_agent()
            
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
        """Construye el mensaje inicial para el pipeline."""
        payload_json = json.dumps(payload, ensure_ascii=False, indent=2)
        
        # Verificar si hay análisis de imágenes pre-cargadas
        preloaded_camera_analysis = payload.get('preloaded_camera_analysis')
        camera_analysis_note = ""
        if preloaded_camera_analysis:
            num_images = preloaded_camera_analysis.get('total_images_analyzed', 0)
            camera_analysis_note = f"""
ANÁLISIS DE IMÁGENES YA DISPONIBLE:
- Se analizaron {num_images} imágenes con Vision AI
- El análisis está en `preloaded_camera_analysis` del payload
- ⚠️ NO LLAMES A get_camera_media - ya tienes el análisis completo
- Usa este análisis para tu evaluación
"""
        
        if is_revalidation and context:
            # Extraer información sobre datos nuevos disponibles
            revalidation_data = payload.get('revalidation_data', {})
            metadata = revalidation_data.get('_metadata', {})
            query_window = metadata.get('query_window', {})
            
            new_safety_events_count = revalidation_data.get('safety_events_since_last_check', {}).get('total_events', 0)
            new_camera_items_count = revalidation_data.get('camera_media_since_last_check', {}).get('total_items_selected', 0)
            minutes_covered = query_window.get('minutes_covered', 0)
            
            # Historial acumulado de todas las ventanas consultadas
            windows_history = payload.get('revalidation_windows_history', [])
            total_windows = len(windows_history)
            total_minutes_observed = sum(w.get('time_window', {}).get('minutes_covered', 0) for w in windows_history)
            
            temporal_context = f"""
CONTEXTO DE REVALIDACIÓN:
- Evento original: {context.get('original_event_time', 'unknown')}
- Primera investigación: {context.get('first_investigation_time', 'unknown')}
- Última investigación: {context.get('last_investigation_time', 'unknown')}
- Revalidación actual: {context.get('current_revalidation_time', 'unknown')}
- Número de investigaciones: {context.get('investigation_count', 0)}

COBERTURA TEMPORAL ACUMULADA:
- Total de ventanas consultadas: {total_windows}
- Tiempo total de observación: {total_minutes_observed} minutos
- HISTORIAL COMPLETO disponible en `revalidation_windows_history` del payload

DATOS NUEVOS EN ESTA VENTANA (desde última investigación hasta ahora):
- Ventana temporal consultada: {minutes_covered} minutos
- Nuevos safety events: {new_safety_events_count}
- Nuevas imágenes de cámara: {new_camera_items_count}
- IMPORTANTE: Revisa `revalidation_data` en el payload para información FRESCA
{camera_analysis_note}
ANÁLISIS PREVIO:
{json.dumps(context.get('previous_assessment', {}), ensure_ascii=False, indent=2)}

HISTORIAL DE VENTANAS CONSULTADAS:
{json.dumps(windows_history, ensure_ascii=False, indent=2)}

INSTRUCCIONES:
1. PRIORIZA los datos en `revalidation_data` - son NUEVOS desde la última investigación
2. Revisa el HISTORIAL de ventanas para ver la evolución de la situación
3. Compara findings entre ventanas: ¿hubo safety events antes que ya no hay? ¿o siguen apareciendo?
4. Con {total_minutes_observed} minutos de observación, evalúa si tienes suficiente evidencia
5. Si ya hay 3+ ventanas sin novedad, considera dar un veredicto definitivo
6. ⚠️ NO LLAMES A get_camera_media si ya hay análisis en preloaded_camera_analysis
"""
            text = f"Revalida esta alerta de Samsara:\n\n{temporal_context}\n\nPAYLOAD:\n{payload_json}"
        else:
            extra_note = camera_analysis_note if camera_analysis_note else ""
            text = f"Analiza esta alerta de Samsara:\n{extra_note}\n\nPAYLOAD:\n{payload_json}"
        
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
    
    def _handle_agent_change(
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
                        return parsed
        except (json.JSONDecodeError, Exception):
            pass
        
        return None

