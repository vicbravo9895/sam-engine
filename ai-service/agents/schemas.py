"""
Modelos Pydantic para estructurar las salidas de los agentes.
Define el schema de datos que cada agente debe producir.
"""

from typing import Optional, List, Literal
from pydantic import BaseModel, Field


# ============================================================================
# INGESTION AGENT OUTPUT
# ============================================================================
class CaseData(BaseModel):
    """Información estructurada del caso extraída del payload de alerta."""
    alert_type: str = Field(..., description="Tipo de alerta (panic_button, harsh_braking, etc.)")
    alert_id: str = Field(..., description="ID único de la alerta")
    vehicle_id: str = Field(..., description="ID del vehículo")
    vehicle_name: str = Field(..., description="Nombre/placa del vehículo")
    driver_id: Optional[str] = Field(None, description="ID del conductor si está disponible")
    driver_name: Optional[str] = Field(None, description="Nombre del conductor si está disponible")
    start_time_utc: str = Field(..., description="Timestamp UTC del evento en formato ISO")
    severity_level: Literal["info", "warning", "critical"] = Field(..., description="Nivel de severidad")


# ============================================================================
# PANIC INVESTIGATOR OUTPUT
# ============================================================================
class SupportingEvidence(BaseModel):
    """Evidencia de soporte recopilada durante la investigación."""
    vehicle_stats_summary: str = Field(..., description="Resumen de estadísticas del vehículo")
    vehicle_info_summary: str = Field(..., description="Resumen de información del vehículo y conductor")
    safety_events_summary: str = Field(..., description="Resumen de eventos de seguridad en la ventana de tiempo")
    camera_summary: str = Field(..., description="Resumen del análisis visual de las cámaras")


class PanicAssessment(BaseModel):
    """Evaluación técnica de la alerta de pánico."""
    likelihood: Literal["high", "medium", "low"] = Field(..., description="Probabilidad de emergencia real")
    verdict: Literal["real_panic", "uncertain", "likely_false_positive"] = Field(..., description="Veredicto de la investigación")
    reasoning: str = Field(..., description="Explicación técnica del veredicto")
    supporting_evidence: SupportingEvidence = Field(..., description="Evidencia que soporta el veredicto")
    requires_monitoring: bool = Field(..., description="Si requiere monitoreo continuo")
    next_check_minutes: Optional[int] = Field(None, description="Minutos hasta próxima verificación (solo si requires_monitoring=true)")
    monitoring_reason: Optional[str] = Field(None, description="Razón del monitoreo (solo si requires_monitoring=true)")


# ============================================================================
# TOOL EXECUTION TRACKING
# ============================================================================
class ToolExecution(BaseModel):
    """Registro de ejecución de una tool."""
    tool_name: str = Field(..., description="Nombre de la tool ejecutada")
    status: Literal["success", "error"] = Field(..., description="Estado de la ejecución")
    duration_ms: int = Field(..., description="Duración en milisegundos")
    summary: str = Field(..., description="Resumen conciso del resultado")
    media_urls: Optional[List[str]] = Field(None, description="URLs de imágenes (solo para get_camera_media)")


class AgentExecution(BaseModel):
    """Registro de ejecución de un agente."""
    name: str = Field(..., description="Nombre del agente")
    started_at: str = Field(..., description="Timestamp de inicio en formato ISO")
    completed_at: str = Field(..., description="Timestamp de finalización en formato ISO")
    duration_ms: int = Field(..., description="Duración total en milisegundos")
    output_summary: str = Field(..., description="Resumen del output del agente")
    tools_used: List[ToolExecution] = Field(default_factory=list, description="Tools ejecutadas por este agente")


class AIActions(BaseModel):
    """Registro completo de todas las acciones de IA durante el procesamiento."""
    agents: List[AgentExecution] = Field(..., description="Lista de agentes ejecutados en orden")
    total_duration_ms: int = Field(..., description="Duración total del pipeline")
    total_tools_called: int = Field(..., description="Número total de tools ejecutadas")
