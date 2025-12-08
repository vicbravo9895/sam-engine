"""
PipelineExecutor: Servicio que ejecuta el pipeline de agentes ADK.
Encapsula toda la lógica de ejecución, tracking y captura de resultados.
"""

import json
import re
import uuid
from dataclasses import dataclass, field
from datetime import datetime
from typing import Any, Dict, List, Optional

from google.genai import types

from config import ServiceConfig, langfuse_client
from core import runner, session_service
from core.context import current_langfuse_span, current_tool_tracker
from agents.agent_definitions import AGENTS_BY_NAME


# ============================================================================
# DATA CLASSES
# ============================================================================
@dataclass
class ToolResult:
    """Resultado de ejecución de una tool."""
    name: str
    status: str = "success"
    duration_ms: int = 0
    summary: str = ""
    media_urls: Optional[List[str]] = None


@dataclass
class AgentResult:
    """Resultado de ejecución de un agente."""
    name: str
    started_at: str = ""
    completed_at: str = ""
    duration_ms: int = 0
    summary: str = ""
    tools: List[ToolResult] = field(default_factory=list)


@dataclass
class PipelineResult:
    """Resultado completo de la ejecución del pipeline."""
    success: bool = True
    assessment: Optional[Dict[str, Any]] = None
    message: Optional[str] = None
    agents: List[AgentResult] = field(default_factory=list)
    total_duration_ms: int = 0
    total_tools_called: int = 0
    error: Optional[str] = None


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
                    print(f"[DEBUG] _extract_media_urls: Could not parse string response")
                    return []
        
        if not isinstance(response, dict):
            print(f"[DEBUG] _extract_media_urls: Response is not a dict, type={type(response)}")
            return []
        
        urls = []
        ai_analysis = response.get('ai_analysis', {})
        print(f"[DEBUG] _extract_media_urls: ai_analysis keys = {ai_analysis.keys() if ai_analysis else 'None'}")
        
        if ai_analysis and 'analyses' in ai_analysis:
            for analysis in ai_analysis['analyses']:
                # Priorizar samsara_url (nueva estructura)
                url = analysis.get('samsara_url') or analysis.get('download_url') or analysis.get('url')
                print(f"[DEBUG] _extract_media_urls: Found URL = {url[:50] if url else 'None'}...")
                if url:
                    urls.append(url)
        
        if not urls:
            data = response.get('data', [])
            print(f"[DEBUG] _extract_media_urls: Checking data array, length = {len(data)}")
            for item in data:
                if isinstance(item, dict):
                    url = item.get('samsara_url') or item.get('download_url') or item.get('url')
                    if url:
                        urls.append(url)
        
        print(f"[DEBUG] _extract_media_urls: Returning {len(urls)} URLs")
        return urls
    except Exception as e:
        print(f"[DEBUG] _extract_media_urls: Exception = {e}")
        return []


def _clean_markdown(text: str) -> str:
    """Limpia bloques de código markdown del texto."""
    if "```" in text:
        text = re.sub(r'^```\w*\s*', '', text)
        text = re.sub(r'\s*```$', '', text)
    return text.strip()


def _generate_agent_summary(agent_name: str, raw_output: str) -> str:
    """Genera un resumen conciso basado en el output del agente."""
    clean_text = _clean_markdown(raw_output)
    
    try:
        data = json.loads(clean_text)
        
        if agent_name == "ingestion_agent":
            alert_type = data.get("alert_type", "unknown")
            vehicle_name = data.get("vehicle_name", "unknown")
            return f"Alerta de {alert_type} procesada para {vehicle_name}"
        
        elif agent_name == "panic_investigator":
            verdict = data.get("verdict", "unknown")
            likelihood = data.get("likelihood", "unknown")
            verdict_map = {
                "real_panic": "pánico real",
                "uncertain": "incierto",
                "likely_false_positive": "probable falso positivo"
            }
            likelihood_map = {"high": "alta", "medium": "media", "low": "baja"}
            return f"Evaluación: {verdict_map.get(verdict, verdict)} (probabilidad {likelihood_map.get(likelihood, likelihood)})"
        
        elif agent_name == "final_agent":
            return clean_text[:150] + "..." if len(clean_text) > 150 else clean_text
            
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
    - Parsing del assessment y mensaje final
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
            PipelineResult con assessment, mensaje y metadata de ejecución
        """
        # Extraer metadata
        alert_type = payload.get("alertType", "unknown")
        vehicle_id = payload.get("vehicle", {}).get("id", "unknown")
        driver_name = payload.get("driver", {}).get("name", "unknown")
        
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
        
        # Construir mensaje inicial
        initial_message = self._build_initial_message(payload, is_revalidation, context)
        current_input = initial_message.parts[0].text
        
        # Crear span del pipeline
        self._create_pipeline_span(session_id, current_input, context)
        
        try:
            assessment = None
            message = None
            
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
                    
                    # Intentar parsear assessment
                    parsed = self._try_parse_assessment(text)
                    if parsed:
                        assessment = parsed
                    elif not message:
                        message = text
            
            # Finalizar último agente
            self._finalize_current_agent()
            
            # Cerrar spans
            self._close_all_spans()
            
            # Actualizar trace
            self._finalize_trace(assessment, message)
            
            return PipelineResult(
                success=True,
                assessment=assessment,
                message=message or "Procesamiento completado",
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
    
    def _finalize_trace(self, assessment: Optional[Dict], message: Optional[str]):
        """Finaliza el trace de Langfuse."""
        if not self._trace:
            return
        
        self._trace.update(
            output={
                "status": "success",
                "assessment": assessment,
                "message": message
            }
        )
    
    def _handle_error(self, error: Exception):
        """Maneja errores y actualiza el trace."""
        if self._trace:
            self._trace.update(
                level="ERROR",
                status_message=str(error)
            )
    
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
        
        if is_revalidation and context:
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
            text = f"Revalida esta alerta de Samsara:\n\n{temporal_context}\n\nPAYLOAD:\n{payload_json}"
        else:
            text = f"Analiza esta alerta de Samsara:\n\n{payload_json}"
        
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
        # El decorador trace_tool en samsara_tools.py usará agent_result y executor
        # para crear ToolResult y actualizar el contador
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
    
    def _try_parse_assessment(self, text: str) -> Optional[Dict[str, Any]]:
        """Intenta parsear el texto como assessment."""
        try:
            clean_text = _clean_markdown(text)
            parsed = json.loads(clean_text)
            
            if 'panic_assessment' in parsed:
                return parsed['panic_assessment']
            elif isinstance(parsed, dict) and 'likelihood' in parsed:
                return parsed
        except (json.JSONDecodeError, Exception):
            pass
        
        return None
