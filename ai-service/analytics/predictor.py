"""
Incident Predictor for Safety Signals.

Uses heuristics and simple statistical methods to:
- Predict probability of critical incident per driver
- Forecast signal volume trends
- Identify at-risk drivers
"""

from collections import defaultdict
from datetime import datetime, timedelta
from typing import Optional
import math

from api.analytics_models import (
    SignalData,
    DriverPrediction,
    VolumeForecast,
    PredictionResult,
)


class IncidentPredictor:
    """Predicts incidents and trends based on safety signals."""

    # Weights for incident probability calculation
    BEHAVIOR_RISK_WEIGHTS = {
        "Crash": 0.95,
        "NearCollision": 0.70,
        "SevereSpeeding": 0.50,
        "HeavySpeeding": 0.40,
        "MobileUsage": 0.45,
        "Drowsy": 0.60,
        "NoSeatbelt": 0.30,
        "RanRedLight": 0.55,
        "FollowingDistanceSevere": 0.40,
        "HarshBraking": 0.25,
        "HarshAcceleration": 0.20,
    }

    def __init__(self):
        pass

    def predict_all(
        self, signals: list[SignalData], days_ahead: int = 7
    ) -> PredictionResult:
        """Run all prediction algorithms."""
        if not signals:
            return PredictionResult(
                at_risk_drivers=[],
                volume_forecast=VolumeForecast(
                    current_avg_daily=0,
                    predicted_avg_daily=0,
                    trend="stable",
                    confidence=0,
                    forecast_days=[],
                ),
                alerts=[],
            )

        at_risk = self.identify_at_risk_drivers(signals)
        forecast = self.forecast_volume(signals, days_ahead)
        alerts = self._generate_alerts(at_risk, forecast)

        return PredictionResult(
            at_risk_drivers=at_risk,
            volume_forecast=forecast,
            alerts=alerts,
        )

    def identify_at_risk_drivers(
        self, signals: list[SignalData], top_n: int = 10
    ) -> list[DriverPrediction]:
        """
        Identify drivers with high probability of future incidents.
        Uses a combination of historical patterns and risk factors.
        """
        # Group by driver
        driver_signals: dict[str, list[SignalData]] = defaultdict(list)
        for signal in signals:
            if signal.driver_id:
                driver_signals[signal.driver_id].append(signal)

        predictions: list[DriverPrediction] = []

        for driver_id, driver_signal_list in driver_signals.items():
            if len(driver_signal_list) < 2:
                continue

            prediction = self._predict_driver(driver_id, driver_signal_list)
            if prediction and prediction.incident_probability > 0.2:
                predictions.append(prediction)

        # Sort by incident probability
        predictions.sort(key=lambda x: x.incident_probability, reverse=True)
        return predictions[:top_n]

    def _predict_driver(
        self, driver_id: str, signals: list[SignalData]
    ) -> Optional[DriverPrediction]:
        """Calculate incident prediction for a single driver."""
        if not signals:
            return None

        driver_name = signals[0].driver_name
        sorted_signals = sorted(signals, key=lambda s: s.occurred_at)

        # Calculate current risk score (simplified version)
        critical_count = sum(1 for s in sorted_signals if s.severity == "critical")
        warning_count = sum(1 for s in sorted_signals if s.severity == "warning")
        total = len(sorted_signals)

        severity_ratio = (critical_count * 10 + warning_count * 3) / (total * 10) if total > 0 else 0
        current_risk = min(100, severity_ratio * 100)

        # Calculate incident probability based on:
        # 1. Historical severity pattern
        # 2. High-risk behaviors present
        # 3. Recent acceleration in events

        # Factor 1: Severity pattern contribution
        severity_prob = critical_count / total if total > 0 else 0

        # Factor 2: High-risk behaviors
        high_risk_behaviors = set()
        behavior_risk_sum = 0
        for s in sorted_signals:
            if s.primary_behavior_label in self.BEHAVIOR_RISK_WEIGHTS:
                high_risk_behaviors.add(s.primary_behavior_label)
                behavior_risk_sum += self.BEHAVIOR_RISK_WEIGHTS[s.primary_behavior_label]

        behavior_prob = min(1.0, behavior_risk_sum / total) if total > 0 else 0

        # Factor 3: Recent acceleration (more events in second half)
        mid = len(sorted_signals) // 2
        first_half_count = mid
        second_half_count = len(sorted_signals) - mid

        acceleration_factor = 1.0
        if first_half_count > 0:
            ratio = second_half_count / first_half_count
            if ratio > 1.5:
                acceleration_factor = min(1.5, ratio)

        # Combined probability (weighted average with acceleration boost)
        base_prob = (severity_prob * 0.4 + behavior_prob * 0.6)
        incident_probability = min(0.95, base_prob * acceleration_factor)

        # Predict future risk (assuming trend continues)
        predicted_risk_7d = min(100, current_risk * acceleration_factor)

        # Calculate confidence based on sample size
        confidence = min(0.9, 0.3 + (total / 50) * 0.6)

        # Warning signals
        warning_signals = []
        if acceleration_factor > 1.2:
            warning_signals.append("Aumento reciente en frecuencia de eventos")
        if severity_prob > 0.3:
            warning_signals.append("Alta proporción de eventos críticos")
        if len(high_risk_behaviors) >= 3:
            warning_signals.append(f"Múltiples comportamientos de alto riesgo ({len(high_risk_behaviors)})")

        # Recommendation
        recommendation = self._generate_recommendation(
            incident_probability, warning_signals, list(high_risk_behaviors)
        )

        return DriverPrediction(
            driver_id=driver_id,
            driver_name=driver_name,
            current_risk_score=round(current_risk, 1),
            predicted_risk_7d=round(predicted_risk_7d, 1),
            incident_probability=round(incident_probability, 3),
            confidence=round(confidence, 2),
            warning_signals=warning_signals,
            recommendation=recommendation,
        )

    def forecast_volume(
        self, signals: list[SignalData], days_ahead: int = 7
    ) -> VolumeForecast:
        """Forecast signal volume for the next N days."""
        if not signals:
            return VolumeForecast(
                current_avg_daily=0,
                predicted_avg_daily=0,
                trend="stable",
                confidence=0,
                forecast_days=[],
            )

        # Group by date
        daily_counts: dict[str, int] = defaultdict(int)
        for signal in signals:
            try:
                dt = datetime.fromisoformat(signal.occurred_at.replace("Z", "+00:00"))
                date_str = dt.strftime("%Y-%m-%d")
                daily_counts[date_str] += 1
            except (ValueError, AttributeError):
                continue

        if not daily_counts:
            return VolumeForecast(
                current_avg_daily=0,
                predicted_avg_daily=0,
                trend="stable",
                confidence=0,
                forecast_days=[],
            )

        # Sort dates and get counts
        sorted_dates = sorted(daily_counts.keys())
        counts = [daily_counts[d] for d in sorted_dates]

        # Calculate current average
        current_avg = sum(counts) / len(counts)

        # Simple linear regression for trend
        n = len(counts)
        if n >= 3:
            x_mean = (n - 1) / 2
            y_mean = current_avg

            numerator = sum((i - x_mean) * (counts[i] - y_mean) for i in range(n))
            denominator = sum((i - x_mean) ** 2 for i in range(n))

            slope = numerator / denominator if denominator > 0 else 0

            # Predict future
            predicted_avg = max(0, current_avg + slope * days_ahead / 2)

            # Determine trend
            if slope > 0.5:
                trend = "increasing"
            elif slope < -0.5:
                trend = "decreasing"
            else:
                trend = "stable"

            # Confidence based on R-squared approximation
            ss_tot = sum((c - y_mean) ** 2 for c in counts)
            ss_res = sum((counts[i] - (y_mean + slope * (i - x_mean))) ** 2 for i in range(n))
            r_squared = 1 - (ss_res / ss_tot) if ss_tot > 0 else 0
            confidence = max(0.3, min(0.9, r_squared))
        else:
            predicted_avg = current_avg
            trend = "stable"
            slope = 0
            confidence = 0.3

        # Generate forecast days
        forecast_days = []
        last_date = datetime.strptime(sorted_dates[-1], "%Y-%m-%d") if sorted_dates else datetime.now()

        for i in range(1, days_ahead + 1):
            forecast_date = last_date + timedelta(days=i)
            predicted_count = max(0, round(current_avg + slope * (n + i - 1 - (n - 1) / 2)))
            forecast_days.append({
                "date": forecast_date.strftime("%Y-%m-%d"),
                "predicted_count": predicted_count,
            })

        return VolumeForecast(
            current_avg_daily=round(current_avg, 1),
            predicted_avg_daily=round(predicted_avg, 1),
            trend=trend,
            confidence=round(confidence, 2),
            forecast_days=forecast_days,
        )

    def _generate_recommendation(
        self,
        probability: float,
        warning_signals: list[str],
        behaviors: list[str],
    ) -> str:
        """Generate actionable recommendation for a driver."""
        if probability > 0.7:
            return f"URGENTE: Intervención inmediata recomendada. Reunión con supervisor y revisión de capacitación en: {', '.join(behaviors[:3])}"
        elif probability > 0.5:
            return f"Programar sesión de coaching enfocada en: {', '.join(behaviors[:2])}"
        elif probability > 0.3:
            return "Monitorear de cerca en las próximas semanas. Considerar refuerzo de capacitación."
        else:
            return "Mantener seguimiento regular."

    def _generate_alerts(
        self, at_risk: list[DriverPrediction], forecast: VolumeForecast
    ) -> list[str]:
        """Generate system alerts based on predictions."""
        alerts = []

        # High-risk driver alerts
        critical_drivers = [d for d in at_risk if d.incident_probability > 0.6]
        if len(critical_drivers) >= 3:
            alerts.append(
                f"⚠️ {len(critical_drivers)} conductores con probabilidad de incidente >60%"
            )

        # Volume trend alert
        if forecast.trend == "increasing" and forecast.predicted_avg_daily > forecast.current_avg_daily * 1.3:
            alerts.append(
                f"📈 Se pronostica aumento de {((forecast.predicted_avg_daily / max(forecast.current_avg_daily, 0.1)) - 1) * 100:.0f}% en señales"
            )

        # Specific driver alert
        if at_risk and at_risk[0].incident_probability > 0.75:
            alerts.append(
                f"🚨 {at_risk[0].driver_name or at_risk[0].driver_id}: {at_risk[0].incident_probability * 100:.0f}% probabilidad de incidente crítico"
            )

        return alerts
