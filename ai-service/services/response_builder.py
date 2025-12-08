"""
AlertResponseBuilder: Construye respuestas estructuradas para la API.
Transforma PipelineResult en un formato de respuesta limpio y consistente.
"""

from dataclasses import asdict
from typing import Any, Dict, List, Optional

from .pipeline_executor import PipelineResult, AgentResult, ToolResult


# ============================================================================
# RESPONSE MODELS (como dicts para serialización)
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
        for t in agent.tools:
            if t.name == "get_camera_media":
                print(f"[DEBUG] _build_agent_response: tool={t.name}, media_urls={t.media_urls}")
        result["tools"] = [_build_tool_response(t) for t in agent.tools]
    return result


def _build_monitoring_response(assessment: Optional[Dict[str, Any]]) -> Dict[str, Any]:
    """Construye el objeto de monitoreo desde el assessment."""
    if not assessment:
        return {
            "required": False,
            "next_check_minutes": None,
            "reason": None
        }
    
    return {
        "required": assessment.get("requires_monitoring", False),
        "next_check_minutes": assessment.get("next_check_minutes"),
        "reason": assessment.get("monitoring_reason")
    }


def _build_supporting_evidence(assessment: Optional[Dict[str, Any]]) -> Optional[Dict[str, str]]:
    """Construye el resumen de evidencia de soporte."""
    if not assessment:
        return None
    
    evidence = assessment.get("supporting_evidence")
    if not evidence:
        return None
    
    # Simplificar keys si existen
    simplified = {}
    
    if "vehicle_stats_summary" in evidence:
        simplified["vehicle"] = evidence["vehicle_stats_summary"]
    if "vehicle_info_summary" in evidence:
        simplified["info"] = evidence["vehicle_info_summary"]
    if "safety_events_summary" in evidence:
        simplified["safety"] = evidence["safety_events_summary"]
    if "camera_summary" in evidence:
        simplified["camera"] = evidence["camera_summary"]
    
    return simplified if simplified else evidence


def _build_clean_assessment(assessment: Optional[Dict[str, Any]]) -> Dict[str, Any]:
    """Construye un assessment limpio sin duplicaciones."""
    if not assessment:
        return {}
    
    clean = {
        "likelihood": assessment.get("likelihood"),
        "verdict": assessment.get("verdict"),
        "reasoning": assessment.get("reasoning"),
        "supporting_evidence": _build_supporting_evidence(assessment),
        "monitoring": _build_monitoring_response(assessment)
    }
    
    # Eliminar None values
    return {k: v for k, v in clean.items() if v is not None}


# ============================================================================
# ALERT RESPONSE BUILDER
# ============================================================================
class AlertResponseBuilder:
    """
    Construye respuestas estructuradas y limpias para la API.
    
    Transforma el PipelineResult en un formato que:
    - Elimina duplicaciones (monitoring solo en assessment)
    - Simplifica la estructura de tools (sin raw parameters)
    - Usa nombres de keys más legibles
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
            Dict listo para serializar como JSON
        """
        if not result.success:
            return {
                "status": "error",
                "event_id": event_id,
                "error": result.error or "Unknown error"
            }
        
        return {
            "status": "success",
            "event_id": event_id,
            "assessment": _build_clean_assessment(result.assessment),
            "message": result.message or "Procesamiento completado",
            "execution": {
                "total_duration_ms": result.total_duration_ms,
                "total_tools_called": result.total_tools_called,
                "agents": [_build_agent_response(a) for a in result.agents]
            }
        }
    
    @staticmethod
    def build_error(event_id: int, error: str) -> Dict[str, Any]:
        """Construye una respuesta de error."""
        return {
            "status": "error",
            "event_id": event_id,
            "error": error
        }
