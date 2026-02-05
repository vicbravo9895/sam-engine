"""
Driver Risk Scorer for Safety Signals.

Calculates a risk score (0-100) for each driver based on:
- Frequency of events (normalized by active days)
- Weighted severity (critical=10, warning=3, info=1)
- Recent trend (improving/worsening)
- Diversity of risky behaviors
- Coaching completion rate
"""

from collections import defaultdict
from datetime import datetime, timedelta
from typing import Optional

from api.analytics_models import (
    SignalData,
    DriverRiskScore,
    DriverRiskResponse,
    RiskFactor,
)


class DriverRiskScorer:
    """Calculates risk scores for drivers based on safety signals."""

    # Severity weights
    SEVERITY_WEIGHTS = {
        "critical": 10,
        "warning": 3,
        "info": 1,
    }

    # High-risk behaviors (extra weight)
    HIGH_RISK_BEHAVIORS = {
        "Crash": 15,
        "NearCollision": 10,
        "SevereSpeeding": 8,
        "HeavySpeeding": 7,
        "MobileUsage": 6,
        "Drowsy": 8,
        "NoSeatbelt": 5,
        "RanRedLight": 10,
    }

    def __init__(self):
        pass

    def calculate_scores(
        self, signals: list[SignalData], days: int = 30
    ) -> DriverRiskResponse:
        """Calculate risk scores for all drivers in the signal set."""
        if not signals:
            return DriverRiskResponse(
                drivers=[],
                fleet_avg_score=0,
                high_risk_count=0,
                computed_at=datetime.utcnow().isoformat() + "Z",
            )

        # Group signals by driver
        driver_signals: dict[str, list[SignalData]] = defaultdict(list)
        for signal in signals:
            if signal.driver_id:
                driver_signals[signal.driver_id].append(signal)

        # Calculate score for each driver
        scores: list[DriverRiskScore] = []
        for driver_id, driver_signal_list in driver_signals.items():
            score = self._calculate_driver_score(driver_id, driver_signal_list, days)
            if score:
                scores.append(score)

        # Sort by risk score descending
        scores.sort(key=lambda x: x.risk_score, reverse=True)

        # Calculate fleet average
        fleet_avg = sum(s.risk_score for s in scores) / len(scores) if scores else 0
        high_risk_count = sum(1 for s in scores if s.risk_level in ("high", "critical"))

        return DriverRiskResponse(
            drivers=scores,
            fleet_avg_score=round(fleet_avg, 1),
            high_risk_count=high_risk_count,
            computed_at=datetime.utcnow().isoformat() + "Z",
        )

    def _calculate_driver_score(
        self, driver_id: str, signals: list[SignalData], days: int
    ) -> Optional[DriverRiskScore]:
        """Calculate risk score for a single driver."""
        if not signals:
            return None

        driver_name = signals[0].driver_name

        # Sort by time
        sorted_signals = sorted(signals, key=lambda s: s.occurred_at)

        # Count by severity
        critical_count = sum(1 for s in sorted_signals if s.severity == "critical")
        warning_count = sum(1 for s in sorted_signals if s.severity == "warning")
        info_count = sum(1 for s in sorted_signals if s.severity == "info")

        # Risk factors
        risk_factors: list[RiskFactor] = []

        # 1. Frequency factor (0-25 points)
        signals_per_day = len(sorted_signals) / max(days, 1)
        frequency_score = min(25, signals_per_day * 10)
        risk_factors.append(
            RiskFactor(
                factor="frequency",
                weight=0.25,
                value=signals_per_day,
                contribution=frequency_score,
                description=f"{signals_per_day:.1f} señales por día",
            )
        )

        # 2. Severity factor (0-35 points)
        severity_weighted = (
            critical_count * self.SEVERITY_WEIGHTS["critical"]
            + warning_count * self.SEVERITY_WEIGHTS["warning"]
            + info_count * self.SEVERITY_WEIGHTS["info"]
        )
        max_severity = len(sorted_signals) * 10  # Max if all were critical
        severity_score = (severity_weighted / max(max_severity, 1)) * 35 if max_severity > 0 else 0
        risk_factors.append(
            RiskFactor(
                factor="severity",
                weight=0.35,
                value=severity_weighted,
                contribution=severity_score,
                description=f"{critical_count} críticos, {warning_count} advertencias",
            )
        )

        # 3. Behavior diversity factor (0-20 points)
        behaviors = set(s.primary_behavior_label for s in sorted_signals if s.primary_behavior_label)
        high_risk_behaviors = behaviors.intersection(self.HIGH_RISK_BEHAVIORS.keys())
        diversity_score = min(20, len(high_risk_behaviors) * 5)
        risk_factors.append(
            RiskFactor(
                factor="behavior_diversity",
                weight=0.20,
                value=len(high_risk_behaviors),
                contribution=diversity_score,
                description=f"{len(high_risk_behaviors)} comportamientos de alto riesgo",
            )
        )

        # 4. Trend factor (0-20 points, can be negative for improvement)
        trend, trend_delta = self._calculate_trend(sorted_signals)
        if trend == "worsening":
            trend_score = min(20, trend_delta * 10)
        elif trend == "improving":
            trend_score = max(-10, -trend_delta * 5)  # Bonus for improving
        else:
            trend_score = 0

        risk_factors.append(
            RiskFactor(
                factor="trend",
                weight=0.20,
                value=trend_delta,
                contribution=trend_score,
                description=self._describe_trend(trend, trend_delta),
            )
        )

        # Calculate total score (0-100)
        total_score = max(
            0,
            min(100, frequency_score + severity_score + diversity_score + trend_score),
        )

        # Determine risk level
        risk_level = self._score_to_level(total_score)

        # Top behaviors
        behavior_counts: dict[str, int] = defaultdict(int)
        for s in sorted_signals:
            if s.primary_behavior_label:
                behavior_counts[s.primary_behavior_label] += 1
        top_behaviors = sorted(
            behavior_counts.keys(), key=lambda b: behavior_counts[b], reverse=True
        )[:5]

        return DriverRiskScore(
            driver_id=driver_id,
            driver_name=driver_name,
            risk_score=round(total_score, 1),
            risk_level=risk_level,
            signal_count=len(sorted_signals),
            critical_count=critical_count,
            warning_count=warning_count,
            top_behaviors=top_behaviors,
            risk_factors=risk_factors,
            trend=trend,
            trend_delta=round(trend_delta, 2),
        )

    def _calculate_trend(
        self, signals: list[SignalData]
    ) -> tuple[str, float]:
        """
        Calculate trend by comparing severity in first half vs second half.
        Returns (trend, delta) where delta is the change magnitude.
        """
        if len(signals) < 4:
            return ("stable", 0.0)

        mid = len(signals) // 2
        first_half = signals[:mid]
        second_half = signals[mid:]

        def weighted_avg(sigs: list[SignalData]) -> float:
            if not sigs:
                return 0
            total = sum(self.SEVERITY_WEIGHTS.get(s.severity, 1) for s in sigs)
            return total / len(sigs)

        first_avg = weighted_avg(first_half)
        second_avg = weighted_avg(second_half)

        delta = second_avg - first_avg

        if delta > 0.5:
            return ("worsening", delta)
        elif delta < -0.5:
            return ("improving", abs(delta))
        return ("stable", abs(delta))

    def _score_to_level(self, score: float) -> str:
        """Convert numeric score to risk level."""
        if score >= 75:
            return "critical"
        elif score >= 50:
            return "high"
        elif score >= 25:
            return "medium"
        return "low"

    def _describe_trend(self, trend: str, delta: float) -> str:
        """Generate human-readable trend description."""
        if trend == "worsening":
            return f"Tendencia negativa: severidad aumentando ({delta:.1f})"
        elif trend == "improving":
            return f"Tendencia positiva: severidad disminuyendo ({delta:.1f})"
        return "Tendencia estable"

    def get_top_risk_drivers(
        self, signals: list[SignalData], days: int = 30, top_n: int = 10
    ) -> list[DriverRiskScore]:
        """Get top N drivers by risk score."""
        response = self.calculate_scores(signals, days)
        return response.drivers[:top_n]
