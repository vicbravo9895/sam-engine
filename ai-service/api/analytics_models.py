"""
Modelos de datos para Analytics API.
Define los schemas de request/response para análisis de safety signals.
"""

from typing import Optional
from pydantic import BaseModel, Field
from datetime import datetime


# ============================================================================
# REQUEST MODELS
# ============================================================================

class SignalData(BaseModel):
    """Datos de un safety signal individual."""
    
    id: int
    driver_id: Optional[str] = None
    driver_name: Optional[str] = None
    vehicle_id: Optional[str] = None
    vehicle_name: Optional[str] = None
    primary_behavior_label: Optional[str] = None
    behavior_labels: list[str] = Field(default_factory=list)
    severity: str = "info"
    event_state: Optional[str] = None
    max_acceleration_g: Optional[float] = None
    latitude: Optional[float] = None
    longitude: Optional[float] = None
    occurred_at: str


class SignalAnalyticsRequest(BaseModel):
    """Request para análisis completo de signals."""
    
    company_id: int = Field(..., description="ID de la compañía")
    signals: list[SignalData] = Field(..., description="Lista de signals a analizar")
    period_days: int = Field(default=30, description="Período de análisis en días")
    include_patterns: bool = Field(default=True, description="Incluir detección de patrones")
    include_risk_scores: bool = Field(default=True, description="Incluir scores de riesgo")
    include_predictions: bool = Field(default=True, description="Incluir predicciones")
    include_insights: bool = Field(default=True, description="Incluir AI insights")


class DriverRiskRequest(BaseModel):
    """Request para obtener risk scores de conductores."""
    
    company_id: int
    signals: list[SignalData]
    period_days: int = 30
    top_n: int = Field(default=10, description="Número de conductores a retornar")


class PatternRequest(BaseModel):
    """Request para detección de patrones."""
    
    company_id: int
    signals: list[SignalData]
    min_correlation: float = Field(default=0.3, description="Correlación mínima para reportar")
    min_cluster_size: int = Field(default=3, description="Tamaño mínimo de cluster geográfico")


class InsightRequest(BaseModel):
    """Request para generación de insights con AI."""
    
    company_id: int
    summary: dict = Field(..., description="Resumen de estadísticas")
    patterns: Optional[dict] = None
    risk_scores: Optional[list[dict]] = None
    predictions: Optional[dict] = None


# ============================================================================
# RESPONSE MODELS - PATTERNS
# ============================================================================

class BehaviorCorrelation(BaseModel):
    """Correlación entre dos comportamientos."""
    
    behavior_a: str
    behavior_b: str
    correlation: float = Field(..., ge=-1, le=1)
    co_occurrence_count: int
    description: str


class TemporalHotspot(BaseModel):
    """Hotspot temporal identificado."""
    
    type: str = Field(..., description="hour | day_of_week | time_range")
    value: str = Field(..., description="Valor del hotspot (ej: '14-16', 'Lunes')")
    signal_count: int
    severity_breakdown: dict[str, int]
    risk_level: str = Field(..., description="low | medium | high")
    description: str


class GeoCluster(BaseModel):
    """Cluster geográfico de signals."""
    
    center_lat: float
    center_lon: float
    radius_km: float
    signal_count: int
    top_behaviors: list[str]
    address_hint: Optional[str] = None


class EscalationPattern(BaseModel):
    """Patrón de escalación de un conductor."""
    
    driver_id: str
    driver_name: Optional[str]
    warning_count: int
    critical_count: int
    escalation_rate: float = Field(..., description="Ratio de warning->critical")
    trend: str = Field(..., description="improving | stable | worsening")
    description: str


class PatternResult(BaseModel):
    """Resultado completo de detección de patrones."""
    
    behavior_correlations: list[BehaviorCorrelation] = Field(default_factory=list)
    temporal_hotspots: list[TemporalHotspot] = Field(default_factory=list)
    geo_clusters: list[GeoCluster] = Field(default_factory=list)
    escalation_patterns: list[EscalationPattern] = Field(default_factory=list)


# ============================================================================
# RESPONSE MODELS - RISK SCORING
# ============================================================================

class RiskFactor(BaseModel):
    """Factor que contribuye al score de riesgo."""
    
    factor: str
    weight: float
    value: float
    contribution: float
    description: str


class DriverRiskScore(BaseModel):
    """Score de riesgo de un conductor."""
    
    driver_id: str
    driver_name: Optional[str]
    risk_score: float = Field(..., ge=0, le=100)
    risk_level: str = Field(..., description="low | medium | high | critical")
    signal_count: int
    critical_count: int
    warning_count: int
    top_behaviors: list[str]
    risk_factors: list[RiskFactor]
    trend: str = Field(..., description="improving | stable | worsening")
    trend_delta: float = Field(..., description="Cambio en score vs período anterior")


class DriverRiskResponse(BaseModel):
    """Response con scores de riesgo de conductores."""
    
    drivers: list[DriverRiskScore]
    fleet_avg_score: float
    high_risk_count: int
    computed_at: str


# ============================================================================
# RESPONSE MODELS - PREDICTIONS
# ============================================================================

class DriverPrediction(BaseModel):
    """Predicción de riesgo para un conductor."""
    
    driver_id: str
    driver_name: Optional[str]
    current_risk_score: float
    predicted_risk_7d: float
    incident_probability: float = Field(..., ge=0, le=1, description="Probabilidad 0-1 de incidente crítico")
    confidence: float = Field(..., ge=0, le=1)
    warning_signals: list[str]
    recommendation: str


class VolumeForecast(BaseModel):
    """Pronóstico de volumen de signals."""
    
    current_avg_daily: float
    predicted_avg_daily: float
    trend: str = Field(..., description="increasing | stable | decreasing")
    confidence: float
    forecast_days: list[dict]  # [{date, predicted_count}]


class PredictionResult(BaseModel):
    """Resultado completo de predicciones."""
    
    at_risk_drivers: list[DriverPrediction]
    volume_forecast: VolumeForecast
    alerts: list[str] = Field(default_factory=list, description="Alertas importantes")


# ============================================================================
# RESPONSE MODELS - AI INSIGHTS
# ============================================================================

class Insight(BaseModel):
    """Un insight generado por AI."""
    
    id: str
    category: str = Field(..., description="pattern | risk | prediction | recommendation")
    priority: str = Field(..., description="low | medium | high")
    title: str
    description: str
    data_points: list[str] = Field(default_factory=list)
    action_items: list[str] = Field(default_factory=list)


class InsightResponse(BaseModel):
    """Response con insights generados por AI."""
    
    insights: list[Insight]
    generated_at: str
    model_used: str


# ============================================================================
# MAIN RESPONSE MODEL
# ============================================================================

class SignalAnalyticsResponse(BaseModel):
    """Response completo de analytics de signals."""
    
    company_id: int
    period_days: int
    computed_at: str
    
    # Patrones detectados
    patterns: Optional[PatternResult] = None
    
    # Risk scores de conductores
    driver_risk: Optional[DriverRiskResponse] = None
    
    # Predicciones
    predictions: Optional[PredictionResult] = None
    
    # AI Insights
    insights: Optional[InsightResponse] = None
    
    # Metadata de procesamiento
    processing_time_ms: int
    errors: list[str] = Field(default_factory=list)
