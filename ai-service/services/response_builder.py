"""
AlertResponseBuilder: Construye respuestas estructuradas para la API.
Transforma PipelineResult en el nuevo contrato de respuesta.

NUEVO CONTRATO:
{
  "status": "success|error",
  "event_id": <int>,
  "alert_context": { ... JSON ... },          // Salida de triage (SIEMPRE objeto)
  "assessment": { ... JSON ... },             // Evaluación técnica (SIEMPRE objeto)
  "human_message": "string",                  // Mensaje final (SIEMPRE string)
  "notification_decision": { ... JSON ... },  // Decisión sin side effects
  "notification_execution": { ... JSON ... }, // Resultados de ejecución
  "execution": { ... JSON ... }               // Trazabilidad
}
"""

import json
import re
from typing import Any, Dict, Optional

from agents.schemas import PipelineResult, AgentResult, ToolResult


# ============================================================================
# HELPER FUNCTIONS
# ============================================================================
def _build_tool_response(tool: ToolResult) -> Dict[str, Any]:
    """Construye el objeto de respuesta para una tool."""
    result = {
        "name": tool.name,
        "duration_ms": tool.duration_ms,
        "summary": tool.summary
    }
    if tool.media_urls:
        result["media_urls"] = tool.media_urls
    return result


def _build_agent_response(agent: AgentResult) -> Dict[str, Any]:
    """Construye el objeto de respuesta para un agente."""
    result = {
        "name": agent.name,
        "duration_ms": agent.duration_ms,
        "summary": agent.summary
    }
    if agent.tools:
        result["tools"] = [_build_tool_response(t) for t in agent.tools]
    return result


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


def _ensure_object(value: Any) -> Optional[Dict[str, Any]]:
    """Asegura que el valor sea un objeto JSON, no un string."""
    if value is None:
        return None
    if isinstance(value, dict):
        return value
    if isinstance(value, str):
        try:
            # Limpiar markdown antes de parsear
            clean_text = _clean_markdown(value)
            parsed = json.loads(clean_text)
            if isinstance(parsed, dict):
                return parsed
        except:
            pass
    return None


def _ensure_string(value: Any) -> str:
    """Asegura que el valor sea un string, no JSON."""
    if value is None:
        return ""
    if isinstance(value, str):
        return value
    if isinstance(value, dict):
        # Si es dict, convertir a string legible
        return str(value)
    return str(value)


# ============================================================================
# ALERT RESPONSE BUILDER
# ============================================================================
class AlertResponseBuilder:
    """
    Construye respuestas estructuradas y limpias para la API.
    
    Implementa el NUEVO CONTRATO:
    - alert_context: SIEMPRE objeto JSON
    - assessment: SIEMPRE objeto JSON  
    - human_message: SIEMPRE string
    - notification_decision: objeto JSON (decisión)
    - notification_execution: objeto JSON (resultados de ejecución)
    - execution: objeto JSON (trazabilidad)
    """
    
    @staticmethod
    def build(
        result: PipelineResult,
        event_id: int
    ) -> Dict[str, Any]:
        """
        Construye la respuesta final de la API.
        
        Args:
            result: Resultado del pipeline
            event_id: ID del evento
            
        Returns:
            Dict conforme al nuevo contrato
        """
        if not result.success:
            return {
                "status": "error",
                "event_id": event_id,
                "error": result.error or "Unknown error"
            }
        
        # Construir respuesta base
        response = {
            "status": "success",
            "event_id": event_id,
        }
        
        # alert_context - SIEMPRE objeto, nunca string
        alert_context = _ensure_object(result.alert_context)
        if alert_context:
            response["alert_context"] = alert_context
        else:
            # Proveer estructura mínima si no existe
            response["alert_context"] = {}
        
        # assessment - SIEMPRE objeto, nunca string
        assessment = _ensure_object(result.assessment)
        if assessment:
            response["assessment"] = assessment
        else:
            response["assessment"] = {}
        
        # human_message - SIEMPRE string, nunca JSON
        response["human_message"] = _ensure_string(result.human_message) or "Procesamiento completado"
        
        # notification_decision - objeto JSON (decisión sin side effects)
        notification_decision = _ensure_object(result.notification_decision)
        if notification_decision:
            response["notification_decision"] = notification_decision
        else:
            response["notification_decision"] = {
                "should_notify": False,
                "escalation_level": "none",
                "channels_to_use": [],
                "recipients": [],
                "message_text": "",
                "dedupe_key": "",
                "reason": "Sin decisión de notificación"
            }
        
        # notification_execution - objeto JSON (resultados de ejecución)
        notification_execution = _ensure_object(result.notification_execution)
        if notification_execution:
            response["notification_execution"] = notification_execution
        else:
            response["notification_execution"] = {
                "attempted": False,
                "results": [],
                "timestamp_utc": "",
                "dedupe_key": "",
                "throttled": False,
                "throttle_reason": None
            }
        
        # execution - trazabilidad
        response["execution"] = {
            "total_duration_ms": result.total_duration_ms,
            "total_tools_called": result.total_tools_called,
            "agents": [_build_agent_response(a) for a in result.agents]
        }
        
        # camera_analysis - URLs de imágenes para que Laravel las persista
        if result.camera_analysis:
            # Extraer las URLs de las imágenes analizadas
            media_urls = []
            for analysis in result.camera_analysis.get("analyses", []):
                url = analysis.get("samsara_url")
                if url:
                    media_urls.append(url)
            
            if media_urls:
                response["camera_analysis"] = {
                    "total_images": result.camera_analysis.get("total_images_analyzed", 0),
                    "media_urls": media_urls,
                    "analyses": result.camera_analysis.get("analyses", [])
                }
        
        return response
    
    @staticmethod
    def build_error(event_id: int, error: str) -> Dict[str, Any]:
        """Construye una respuesta de error."""
        return {
            "status": "error",
            "event_id": event_id,
            "error": error
        }
    
    @staticmethod
    def extract_for_laravel(response: Dict[str, Any]) -> Dict[str, Any]:
        """
        Extrae los campos relevantes para guardar en Laravel.
        
        Returns:
            Dict con campos listos para el modelo SamsaraEvent
        """
        assessment = response.get("assessment", {})
        
        return {
            "ai_status": "completed" if response.get("status") == "success" else "failed",
            "ai_assessment": assessment,
            "ai_message": response.get("human_message", ""),
            "alert_context": response.get("alert_context", {}),
            "notification_decision": response.get("notification_decision", {}),
            "notification_execution": response.get("notification_execution", {}),
            "ai_actions": response.get("execution", {}),
            
            # Campos operativos del assessment
            "dedupe_key": assessment.get("dedupe_key"),
            "risk_escalation": assessment.get("risk_escalation"),
            "proactive_flag": response.get("alert_context", {}).get("proactive_flag", False),
            "data_consistency": assessment.get("supporting_evidence", {}).get("data_consistency", {}),
            "recommended_actions": assessment.get("recommended_actions", []),
            
            # Campos de monitoreo
            "requires_monitoring": assessment.get("requires_monitoring", False),
            "next_check_minutes": assessment.get("next_check_minutes"),
            "monitoring_reason": assessment.get("monitoring_reason"),
        }

