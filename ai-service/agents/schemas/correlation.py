"""
Schema para el output del Correlation Agent.

Este agente analiza alertas relacionadas para detectar patrones y 
generar un resumen de incidente cuando hay correlación entre eventos.
"""

from typing import Optional, List, Literal
from pydantic import BaseModel, Field


class RelatedEvent(BaseModel):
    """Información de un evento relacionado."""
    
    event_id: int = Field(
        ...,
        description="ID del evento relacionado"
    )
    event_type: str = Field(
        ...,
        description="Tipo del evento (panic_button, harsh_braking, etc.)"
    )
    time_delta_seconds: int = Field(
        ...,
        description="Diferencia en segundos con el evento principal (negativo = antes, positivo = después)"
    )
    correlation_type: Literal["temporal", "causal", "pattern"] = Field(
        ...,
        description="Tipo de correlación detectada"
    )
    relevance: Literal["high", "medium", "low"] = Field(
        ...,
        description="Relevancia de este evento para el incidente"
    )
    brief_summary: str = Field(
        ...,
        description="Resumen breve de lo que aporta este evento al análisis"
    )


class CorrelationResult(BaseModel):
    """
    Resultado del análisis de correlación.
    
    Este schema representa el output del Correlation Agent.
    """
    
    # =========================================================================
    # Detección de correlaciones
    # =========================================================================
    has_correlations: bool = Field(
        ...,
        description="True si se detectaron correlaciones significativas entre eventos"
    )
    
    # =========================================================================
    # Clasificación del incidente
    # =========================================================================
    incident_type: Optional[Literal["collision", "emergency", "pattern", "unknown"]] = Field(
        None,
        description="Tipo de incidente detectado. None si no hay incidente."
    )
    
    correlation_strength: float = Field(
        ...,
        ge=0.0,
        le=1.0,
        description="Fuerza de la correlación general (0.0 = ninguna, 1.0 = muy fuerte)"
    )
    
    # =========================================================================
    # Eventos relacionados
    # =========================================================================
    related_events: List[RelatedEvent] = Field(
        default_factory=list,
        description="Lista de eventos relacionados con su análisis"
    )
    
    # =========================================================================
    # Análisis narrativo
    # =========================================================================
    incident_summary: str = Field(
        ...,
        description="Resumen del incidente detectado en español (2-4 oraciones)"
    )
    
    pattern_description: Optional[str] = Field(
        None,
        description="Descripción del patrón detectado si es tipo 'pattern'"
    )
    
    # =========================================================================
    # Recomendaciones
    # =========================================================================
    urgency_assessment: Literal["critical", "high", "medium", "low"] = Field(
        ...,
        description="Evaluación de urgencia considerando todos los eventos correlacionados"
    )
    
    recommended_action: str = Field(
        ...,
        description="Acción recomendada basada en el análisis de correlación"
    )
    
    # =========================================================================
    # Ajustes al assessment original
    # =========================================================================
    should_escalate: bool = Field(
        default=False,
        description="True si la correlación sugiere escalar la prioridad del evento principal"
    )
    
    escalation_reason: Optional[str] = Field(
        None,
        description="Razón de la escalación si should_escalate es True"
    )
