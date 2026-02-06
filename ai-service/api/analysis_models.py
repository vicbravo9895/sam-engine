"""
Modelos Pydantic para el endpoint de análisis on-demand.
Define los schemas de request/response para análisis de flota.
"""

from typing import Any, Dict, List, Optional
from pydantic import BaseModel, Field
from enum import Enum


# ============================================================================
# ENUMS
# ============================================================================

class AnalysisType(str, Enum):
    """Tipos de análisis disponibles."""
    DRIVER_RISK_PROFILE = "driver_risk_profile"
    FLEET_SAFETY_OVERVIEW = "fleet_safety_overview"
    VEHICLE_HEALTH = "vehicle_health"
    OPERATIONAL_EFFICIENCY = "operational_efficiency"
    ANOMALY_DETECTION = "anomaly_detection"


class RiskLevel(str, Enum):
    """Niveles de riesgo para los análisis."""
    LOW = "low"
    MEDIUM = "medium"
    HIGH = "high"
    CRITICAL = "critical"


class TrendDirection(str, Enum):
    """Dirección de tendencia para métricas."""
    UP = "up"
    DOWN = "down"
    STABLE = "stable"


# ============================================================================
# REQUEST MODELS
# ============================================================================

class AnalysisRequest(BaseModel):
    """Request body para el endpoint de análisis on-demand."""

    analysis_type: AnalysisType = Field(
        ...,
        description="Tipo de análisis a ejecutar"
    )

    company_id: int = Field(
        ...,
        description="ID de la empresa (multi-tenant)"
    )

    parameters: Dict[str, Any] = Field(
        default_factory=dict,
        description="Parámetros específicos del análisis (vehicle_ids, driver_ids, days_back, etc.)"
    )

    raw_data: Dict[str, Any] = Field(
        ...,
        description="Datos crudos pre-obtenidos desde Samsara (safety_events, vehicle_stats, trips, etc.)"
    )


# ============================================================================
# RESPONSE MODELS
# ============================================================================

class AnalysisMetric(BaseModel):
    """Una métrica individual del análisis."""

    key: str = Field(..., description="Identificador de la métrica")
    label: str = Field(..., description="Etiqueta legible en español")
    value: Any = Field(..., description="Valor de la métrica (número, string, etc.)")
    unit: Optional[str] = Field(None, description="Unidad (%, km/h, eventos, etc.)")
    trend: Optional[TrendDirection] = Field(None, description="Dirección de tendencia")
    trend_value: Optional[str] = Field(None, description="Valor de la tendencia (ej: '+12%')")
    severity: Optional[RiskLevel] = Field(None, description="Nivel de severidad de esta métrica")


class AnalysisFinding(BaseModel):
    """Un hallazgo determinista del análisis."""

    title: str = Field(..., description="Título del hallazgo")
    description: str = Field(..., description="Descripción detallada")
    severity: RiskLevel = Field(..., description="Severidad del hallazgo")
    category: str = Field(..., description="Categoría (seguridad, operativo, mantenimiento, etc.)")
    evidence: Optional[List[str]] = Field(None, description="Evidencia que respalda el hallazgo")


class AnalysisResponse(BaseModel):
    """Response del endpoint de análisis on-demand."""

    status: str = Field(..., description="success o error")
    analysis_type: str = Field(..., description="Tipo de análisis ejecutado")
    title: str = Field(..., description="Título del análisis en español")
    summary: str = Field(..., description="Resumen ejecutivo en una línea")
    metrics: List[AnalysisMetric] = Field(
        default_factory=list,
        description="Métricas clave del análisis"
    )
    findings: List[AnalysisFinding] = Field(
        default_factory=list,
        description="Hallazgos deterministas"
    )
    insights: str = Field(
        default="",
        description="Interpretación generada por LLM (ejecutivo/operativo)"
    )
    recommendations: List[str] = Field(
        default_factory=list,
        description="Recomendaciones accionables"
    )
    risk_level: RiskLevel = Field(
        default=RiskLevel.LOW,
        description="Nivel de riesgo general"
    )
    data_window: Dict[str, Any] = Field(
        default_factory=dict,
        description="Ventana temporal analizada"
    )
    methodology: str = Field(
        default="",
        description="Breve descripción del enfoque de análisis"
    )
    error: Optional[str] = Field(None, description="Mensaje de error si status=error")
