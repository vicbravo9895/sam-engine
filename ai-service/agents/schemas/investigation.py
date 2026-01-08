"""
Schema para el output del Investigation Agent.
Genérico para manejar todos los tipos de alertas.

ACTUALIZADO: Nuevo contrato con campos operativos estandarizados:
- payload_driver vs assignment_driver
- data_consistency para conflictos
- risk_escalation para nivel de riesgo
- recommended_actions lista de acciones
- dedupe_key para idempotencia
"""

from typing import Optional, List, Literal
from pydantic import BaseModel, Field


class DriverInfo(BaseModel):
    """Información de un conductor."""
    id: Optional[str] = Field(None, description="ID del conductor")
    name: Optional[str] = Field(None, description="Nombre del conductor")


class CameraEvidence(BaseModel):
    """Evidencia visual de las cámaras."""
    visual_summary: str = Field(
        ...,
        description="Resumen del análisis visual de las imágenes"
    )
    media_urls: List[str] = Field(
        default_factory=list,
        description="URLs de las imágenes analizadas"
    )


class DataConsistency(BaseModel):
    """Información sobre consistencia de datos entre fuentes."""
    has_conflict: bool = Field(
        default=False,
        description="True si hay conflictos entre fuentes de datos"
    )
    conflicts: List[str] = Field(
        default_factory=list,
        description="Lista de conflictos detectados"
    )


class SupportingEvidence(BaseModel):
    """Evidencia de soporte recopilada durante la investigación."""
    
    # Información de conductor según fuentes
    payload_driver: Optional[DriverInfo] = Field(
        None,
        description="Conductor según el payload original"
    )
    assignment_driver: Optional[DriverInfo] = Field(
        None,
        description="Conductor según get_driver_assignment tool"
    )
    
    # Resúmenes de cada fuente (opcionales porque no siempre se usan todas las tools)
    vehicle_stats_summary: Optional[str] = Field(
        None,
        description="Resumen de estadísticas del vehículo (movimiento, velocidad, etc.)"
    )
    vehicle_info_summary: Optional[str] = Field(
        None,
        description="Resumen de información del vehículo"
    )
    safety_events_summary: Optional[str] = Field(
        None,
        description="Resumen de eventos de seguridad en la ventana de tiempo"
    )
    
    # Evidencia de cámara estructurada
    camera: Optional[CameraEvidence] = Field(
        None,
        description="Evidencia visual de las cámaras"
    )
    
    # Consistencia de datos
    data_consistency: DataConsistency = Field(
        default_factory=DataConsistency,
        description="Información sobre consistencia entre fuentes de datos"
    )


class InvestigationVerdict(str):
    """Posibles veredictos de la investigación."""
    # Para eventos de pánico
    REAL_PANIC = "real_panic"                       # Pánico real confirmado
    LIKELY_FALSE_POSITIVE = "likely_false_positive" # Probablemente falso positivo
    
    # Para safety events
    CONFIRMED_VIOLATION = "confirmed_violation"     # Violación de seguridad confirmada
    NEEDS_REVIEW = "needs_review"                   # Requiere revisión humana
    NO_ACTION_NEEDED = "no_action_needed"           # No requiere acción
    
    # Para proactividad
    RISK_DETECTED = "risk_detected"                 # Riesgo detectado (tampering, obstrucción)
    
    # Genérico
    UNCERTAIN = "uncertain"                         # Incierto, necesita monitoreo


class AlertAssessment(BaseModel):
    """
    Evaluación técnica estructurada de una alerta.
    Este schema representa el 'assessment' en el contrato de respuesta.
    """
    
    # =========================================================================
    # Evaluación principal
    # =========================================================================
    likelihood: Literal["high", "medium", "low"] = Field(
        ...,
        description="Probabilidad de que la alerta represente un evento real/importante"
    )
    verdict: Literal[
        "real_panic",
        "confirmed_violation",
        "needs_review",
        "uncertain",
        "likely_false_positive",
        "no_action_needed",
        "risk_detected"
    ] = Field(
        ...,
        description="Veredicto de la investigación"
    )
    confidence: float = Field(
        ...,
        ge=0.0,
        le=1.0,
        description="Nivel de confianza en el veredicto (0.0 a 1.0)"
    )
    
    # =========================================================================
    # Explicación
    # =========================================================================
    reasoning: str = Field(
        ...,
        description="Explicación técnica del veredicto en español (3-6 líneas)"
    )
    
    # =========================================================================
    # Evidencia estructurada
    # =========================================================================
    supporting_evidence: SupportingEvidence = Field(
        ...,
        description="Evidencia estructurada que soporta el veredicto"
    )
    
    # =========================================================================
    # Campos operativos estandarizados
    # =========================================================================
    risk_escalation: Literal["monitor", "warn", "call", "emergency"] = Field(
        ...,
        description="Nivel de escalación de riesgo"
    )
    recommended_actions: List[str] = Field(
        default_factory=list,
        description="Lista de acciones recomendadas para el operador"
    )
    dedupe_key: str = Field(
        ...,
        description="Clave única para deduplicación (vehicle_id:event_time:alert_type)"
    )
    
    # =========================================================================
    # Monitoreo continuo
    # =========================================================================
    requires_monitoring: bool = Field(
        ...,
        description="Si requiere monitoreo continuo para verificación"
    )
    next_check_minutes: Optional[int] = Field(
        None,
        description="Minutos hasta próxima verificación (5, 15, 30, 60)"
    )
    monitoring_reason: Optional[str] = Field(
        None,
        description="Razón del monitoreo en español"
    )
    
    # =========================================================================
    # Metadatos del evento
    # =========================================================================
    # NOTA: event_specifics fue eliminado porque Dict[str, Any] no es compatible
    # con OpenAI structured outputs (requiere additionalProperties: false).
    # Si necesitas campos específicos del evento, agrégalos como campos tipados.


# Alias para compatibilidad con código existente
PanicAssessment = AlertAssessment

