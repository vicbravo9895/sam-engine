"""
FastAPI routes for Safety Signal Analytics.

Provides endpoints for:
- Full analytics pipeline
- Driver risk scoring
- Pattern detection
- AI insights generation
"""

from fastapi import APIRouter, HTTPException
from datetime import datetime

from api.analytics_models import (
    SignalAnalyticsRequest,
    SignalAnalyticsResponse,
    DriverRiskRequest,
    DriverRiskResponse,
    PatternRequest,
    PatternResult,
    InsightRequest,
    InsightResponse,
)
from analytics import AnalyticsEngine


router = APIRouter(prefix="/analytics", tags=["Analytics"])

# Singleton engine instance
_engine: AnalyticsEngine | None = None


def get_engine() -> AnalyticsEngine:
    """Get or create the analytics engine singleton."""
    global _engine
    if _engine is None:
        _engine = AnalyticsEngine()
    return _engine


@router.post("/signals", response_model=SignalAnalyticsResponse)
async def analyze_signals(request: SignalAnalyticsRequest) -> SignalAnalyticsResponse:
    """
    Run full analytics pipeline on safety signals.
    
    This endpoint orchestrates all analytics components:
    - Pattern detection (correlations, hotspots, clusters)
    - Driver risk scoring
    - Incident prediction
    - AI-powered insights
    
    Use the include_* flags to enable/disable specific components.
    """
    if not request.signals:
        return SignalAnalyticsResponse(
            company_id=request.company_id,
            period_days=request.period_days,
            computed_at=datetime.utcnow().isoformat() + "Z",
            patterns=None,
            driver_risk=None,
            predictions=None,
            insights=None,
            processing_time_ms=0,
            errors=["No signals provided"],
        )

    engine = get_engine()
    return await engine.analyze(request)


@router.post("/driver-risk", response_model=DriverRiskResponse)
async def get_driver_risk(request: DriverRiskRequest) -> DriverRiskResponse:
    """
    Calculate risk scores for drivers.
    
    Returns a ranked list of drivers with their risk scores (0-100),
    risk level (low/medium/high/critical), contributing factors,
    and trend information.
    """
    if not request.signals:
        return DriverRiskResponse(
            drivers=[],
            fleet_avg_score=0,
            high_risk_count=0,
            computed_at=datetime.utcnow().isoformat() + "Z",
        )

    engine = get_engine()
    return engine.get_driver_risk(request)


@router.post("/patterns", response_model=PatternResult)
async def detect_patterns(request: PatternRequest) -> PatternResult:
    """
    Detect patterns in safety signals.
    
    Analyzes signals to find:
    - Behavior correlations (which behaviors occur together)
    - Temporal hotspots (high-risk hours/days)
    - Geographic clusters (zones with high incidence)
    - Escalation patterns (drivers progressing from warning to critical)
    """
    if not request.signals:
        return PatternResult()

    engine = get_engine()
    return engine.detect_patterns(request)


@router.post("/insights", response_model=InsightResponse)
async def generate_insights(request: InsightRequest) -> InsightResponse:
    """
    Generate AI-powered insights from analytics data.
    
    Uses GPT to analyze patterns, risk scores, and predictions
    to generate actionable insights in natural language (Spanish).
    
    Requires pre-computed analytics data (summary, patterns, risk_scores, predictions).
    """
    engine = get_engine()
    return await engine.generate_insights(request)


@router.get("/health")
async def analytics_health():
    """Health check for analytics service."""
    return {
        "status": "ok",
        "service": "analytics",
        "timestamp": datetime.utcnow().isoformat() + "Z",
    }
