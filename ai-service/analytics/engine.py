"""
Analytics Engine - Main orchestrator for safety signal analytics.

Coordinates all analytics components:
- Pattern detection
- Driver risk scoring
- Incident prediction
- AI insight generation
"""

import time
from datetime import datetime
from typing import Optional

from api.analytics_models import (
    SignalData,
    SignalAnalyticsRequest,
    SignalAnalyticsResponse,
    DriverRiskRequest,
    DriverRiskResponse,
    PatternRequest,
    PatternResult,
    InsightRequest,
    InsightResponse,
)
from .pattern_detector import PatternDetector
from .risk_scorer import DriverRiskScorer
from .predictor import IncidentPredictor
from .insight_generator import AIInsightGenerator


class AnalyticsEngine:
    """Main analytics engine that orchestrates all components."""

    def __init__(self):
        self.pattern_detector = PatternDetector()
        self.risk_scorer = DriverRiskScorer()
        self.predictor = IncidentPredictor()
        self.insight_generator = AIInsightGenerator()

    async def analyze(
        self, request: SignalAnalyticsRequest
    ) -> SignalAnalyticsResponse:
        """
        Run full analytics pipeline on the provided signals.
        
        This is the main entry point that orchestrates all analytics components.
        """
        start_time = time.time()
        errors: list[str] = []

        signals = request.signals
        patterns: Optional[PatternResult] = None
        driver_risk: Optional[DriverRiskResponse] = None
        predictions = None
        insights: Optional[InsightResponse] = None

        # 1. Pattern Detection
        if request.include_patterns:
            try:
                patterns = self.pattern_detector.detect_all(signals)
            except Exception as e:
                errors.append(f"Pattern detection error: {str(e)}")

        # 2. Driver Risk Scoring
        if request.include_risk_scores:
            try:
                driver_risk = self.risk_scorer.calculate_scores(
                    signals, request.period_days
                )
            except Exception as e:
                errors.append(f"Risk scoring error: {str(e)}")

        # 3. Predictions
        if request.include_predictions:
            try:
                predictions = self.predictor.predict_all(signals)
            except Exception as e:
                errors.append(f"Prediction error: {str(e)}")

        # 4. AI Insights (requires results from previous steps)
        if request.include_insights:
            try:
                # Prepare summary for insights
                summary = self._build_summary(signals, request.period_days)
                
                risk_scores_list = driver_risk.drivers if driver_risk else None
                
                insights = await self.insight_generator.generate_insights(
                    summary=summary,
                    patterns=patterns,
                    risk_scores=risk_scores_list,
                    predictions=predictions,
                )
            except Exception as e:
                errors.append(f"Insight generation error: {str(e)}")

        processing_time_ms = int((time.time() - start_time) * 1000)

        return SignalAnalyticsResponse(
            company_id=request.company_id,
            period_days=request.period_days,
            computed_at=datetime.utcnow().isoformat() + "Z",
            patterns=patterns,
            driver_risk=driver_risk,
            predictions=predictions,
            insights=insights,
            processing_time_ms=processing_time_ms,
            errors=errors,
        )

    def get_driver_risk(self, request: DriverRiskRequest) -> DriverRiskResponse:
        """Get driver risk scores only."""
        response = self.risk_scorer.calculate_scores(
            request.signals, request.period_days
        )
        # Limit to top_n
        response.drivers = response.drivers[: request.top_n]
        return response

    def detect_patterns(self, request: PatternRequest) -> PatternResult:
        """Detect patterns only."""
        detector = PatternDetector(
            min_correlation=request.min_correlation,
            min_cluster_size=request.min_cluster_size,
        )
        return detector.detect_all(request.signals)

    async def generate_insights(self, request: InsightRequest) -> InsightResponse:
        """Generate AI insights only."""
        # Convert dict risk scores to objects if provided
        risk_scores = None
        if request.risk_scores:
            from api.analytics_models import DriverRiskScore, RiskFactor
            risk_scores = []
            for rs in request.risk_scores:
                risk_factors = [
                    RiskFactor(**rf) for rf in rs.get("risk_factors", [])
                ]
                risk_scores.append(
                    DriverRiskScore(
                        driver_id=rs["driver_id"],
                        driver_name=rs.get("driver_name"),
                        risk_score=rs["risk_score"],
                        risk_level=rs["risk_level"],
                        signal_count=rs.get("signal_count", 0),
                        critical_count=rs.get("critical_count", 0),
                        warning_count=rs.get("warning_count", 0),
                        top_behaviors=rs.get("top_behaviors", []),
                        risk_factors=risk_factors,
                        trend=rs.get("trend", "stable"),
                        trend_delta=rs.get("trend_delta", 0),
                    )
                )

        # Convert patterns dict to object if provided
        patterns = None
        if request.patterns:
            patterns = PatternResult(**request.patterns)

        # Convert predictions dict to object if provided
        predictions = None
        if request.predictions:
            from api.analytics_models import PredictionResult
            predictions = PredictionResult(**request.predictions)

        return await self.insight_generator.generate_insights(
            summary=request.summary,
            patterns=patterns,
            risk_scores=risk_scores,
            predictions=predictions,
        )

    def _build_summary(self, signals: list[SignalData], period_days: int) -> dict:
        """Build summary statistics from signals."""
        total = len(signals)
        critical = sum(1 for s in signals if s.severity == "critical")
        warning = sum(1 for s in signals if s.severity == "warning")
        needs_review = sum(1 for s in signals if s.event_state == "needsReview")

        unique_drivers = len(set(s.driver_id for s in signals if s.driver_id))
        unique_vehicles = len(set(s.vehicle_id for s in signals if s.vehicle_id))

        return {
            "total_signals": total,
            "critical": critical,
            "critical_rate": round((critical / total) * 100, 1) if total > 0 else 0,
            "warning": warning,
            "needs_review": needs_review,
            "unique_drivers": unique_drivers,
            "unique_vehicles": unique_vehicles,
            "avg_daily": round(total / period_days, 1) if period_days > 0 else 0,
        }
