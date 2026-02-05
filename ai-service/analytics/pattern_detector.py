"""
Pattern Detector for Safety Signals.

Detects statistical patterns in safety signal data:
- Behavior correlations (which behaviors occur together)
- Temporal hotspots (high-risk hours/days)
- Geographic clusters (zones with high incidence)
- Escalation patterns (drivers going from warning to critical)
"""

from collections import defaultdict
from datetime import datetime
from typing import Optional
import math

from api.analytics_models import (
    SignalData,
    BehaviorCorrelation,
    TemporalHotspot,
    GeoCluster,
    EscalationPattern,
    PatternResult,
)


class PatternDetector:
    """Detects patterns in safety signal data."""

    def __init__(self, min_correlation: float = 0.3, min_cluster_size: int = 3):
        self.min_correlation = min_correlation
        self.min_cluster_size = min_cluster_size

    def detect_all(self, signals: list[SignalData]) -> PatternResult:
        """Run all pattern detection algorithms."""
        if not signals:
            return PatternResult()

        return PatternResult(
            behavior_correlations=self.detect_behavior_correlations(signals),
            temporal_hotspots=self.detect_temporal_hotspots(signals),
            geo_clusters=self.detect_geographic_clusters(signals),
            escalation_patterns=self.detect_escalation_patterns(signals),
        )

    def detect_behavior_correlations(
        self, signals: list[SignalData]
    ) -> list[BehaviorCorrelation]:
        """
        Detect which behaviors tend to occur together.
        Uses co-occurrence analysis at driver level.
        """
        # Group behaviors by driver
        driver_behaviors: dict[str, set[str]] = defaultdict(set)
        behavior_counts: dict[str, int] = defaultdict(int)

        for signal in signals:
            if signal.driver_id and signal.primary_behavior_label:
                driver_behaviors[signal.driver_id].add(signal.primary_behavior_label)
                behavior_counts[signal.primary_behavior_label] += 1

        if len(driver_behaviors) < 3:
            return []

        # Calculate co-occurrence matrix
        behaviors = list(behavior_counts.keys())
        co_occurrence: dict[tuple[str, str], int] = defaultdict(int)

        for driver_id, driver_behavior_set in driver_behaviors.items():
            behavior_list = list(driver_behavior_set)
            for i, b1 in enumerate(behavior_list):
                for b2 in behavior_list[i + 1 :]:
                    pair = tuple(sorted([b1, b2]))
                    co_occurrence[pair] += 1

        # Calculate correlations
        correlations: list[BehaviorCorrelation] = []
        total_drivers = len(driver_behaviors)

        for (b1, b2), count in co_occurrence.items():
            if count < 2:
                continue

            # Simple correlation: co-occurrence / geometric mean of individual occurrences
            p1 = behavior_counts[b1] / total_drivers
            p2 = behavior_counts[b2] / total_drivers
            p_both = count / total_drivers

            if p1 > 0 and p2 > 0:
                expected = p1 * p2
                correlation = (p_both - expected) / max(
                    math.sqrt(p1 * p2 * (1 - p1) * (1 - p2)), 0.001
                )
                correlation = max(-1, min(1, correlation))

                if abs(correlation) >= self.min_correlation:
                    correlations.append(
                        BehaviorCorrelation(
                            behavior_a=b1,
                            behavior_b=b2,
                            correlation=round(correlation, 3),
                            co_occurrence_count=count,
                            description=self._describe_correlation(b1, b2, correlation),
                        )
                    )

        return sorted(correlations, key=lambda x: abs(x.correlation), reverse=True)[:10]

    def detect_temporal_hotspots(
        self, signals: list[SignalData]
    ) -> list[TemporalHotspot]:
        """Detect high-risk hours and days of the week."""
        hotspots: list[TemporalHotspot] = []

        # Analyze by hour
        hourly: dict[int, list[SignalData]] = defaultdict(list)
        daily: dict[int, list[SignalData]] = defaultdict(list)

        for signal in signals:
            try:
                dt = datetime.fromisoformat(signal.occurred_at.replace("Z", "+00:00"))
                hourly[dt.hour].append(signal)
                daily[dt.weekday()].append(signal)
            except (ValueError, AttributeError):
                continue

        if not hourly:
            return []

        # Find hourly hotspots
        avg_hourly = len(signals) / 24
        for hour, hour_signals in hourly.items():
            count = len(hour_signals)
            if count > avg_hourly * 1.5 and count >= 5:
                severity_breakdown = self._severity_breakdown(hour_signals)
                risk_level = self._calculate_risk_level(severity_breakdown, count)

                hotspots.append(
                    TemporalHotspot(
                        type="hour",
                        value=f"{hour:02d}:00-{(hour+1) % 24:02d}:00",
                        signal_count=count,
                        severity_breakdown=severity_breakdown,
                        risk_level=risk_level,
                        description=f"Alto volumen de señales entre {hour:02d}:00 y {(hour+1) % 24:02d}:00",
                    )
                )

        # Find daily hotspots
        day_names = [
            "Lunes",
            "Martes",
            "Miércoles",
            "Jueves",
            "Viernes",
            "Sábado",
            "Domingo",
        ]
        avg_daily = len(signals) / 7

        for day, day_signals in daily.items():
            count = len(day_signals)
            if count > avg_daily * 1.3 and count >= 5:
                severity_breakdown = self._severity_breakdown(day_signals)
                risk_level = self._calculate_risk_level(severity_breakdown, count)

                hotspots.append(
                    TemporalHotspot(
                        type="day_of_week",
                        value=day_names[day],
                        signal_count=count,
                        severity_breakdown=severity_breakdown,
                        risk_level=risk_level,
                        description=f"Mayor incidencia los {day_names[day]}",
                    )
                )

        return sorted(hotspots, key=lambda x: x.signal_count, reverse=True)[:8]

    def detect_geographic_clusters(
        self, signals: list[SignalData]
    ) -> list[GeoCluster]:
        """Detect geographic clusters of signals using simple grid-based clustering."""
        clusters: list[GeoCluster] = []

        # Filter signals with valid coordinates
        geo_signals = [
            s for s in signals if s.latitude is not None and s.longitude is not None
        ]

        if len(geo_signals) < self.min_cluster_size:
            return []

        # Simple grid-based clustering (0.01 degree ~ 1km)
        grid_size = 0.01
        grid: dict[tuple[int, int], list[SignalData]] = defaultdict(list)

        for signal in geo_signals:
            grid_x = int(signal.latitude / grid_size)
            grid_y = int(signal.longitude / grid_size)
            grid[(grid_x, grid_y)].append(signal)

        # Find clusters
        for (gx, gy), cell_signals in grid.items():
            if len(cell_signals) >= self.min_cluster_size:
                # Calculate center
                avg_lat = sum(s.latitude for s in cell_signals) / len(cell_signals)
                avg_lon = sum(s.longitude for s in cell_signals) / len(cell_signals)

                # Top behaviors in cluster
                behavior_counts: dict[str, int] = defaultdict(int)
                for s in cell_signals:
                    if s.primary_behavior_label:
                        behavior_counts[s.primary_behavior_label] += 1

                top_behaviors = sorted(
                    behavior_counts.keys(), key=lambda b: behavior_counts[b], reverse=True
                )[:3]

                clusters.append(
                    GeoCluster(
                        center_lat=round(avg_lat, 6),
                        center_lon=round(avg_lon, 6),
                        radius_km=1.5,
                        signal_count=len(cell_signals),
                        top_behaviors=top_behaviors,
                        address_hint=None,
                    )
                )

        return sorted(clusters, key=lambda x: x.signal_count, reverse=True)[:5]

    def detect_escalation_patterns(
        self, signals: list[SignalData]
    ) -> list[EscalationPattern]:
        """Detect drivers who are escalating from warning to critical."""
        driver_signals: dict[str, list[SignalData]] = defaultdict(list)

        for signal in signals:
            if signal.driver_id:
                driver_signals[signal.driver_id].append(signal)

        patterns: list[EscalationPattern] = []

        for driver_id, driver_signal_list in driver_signals.items():
            if len(driver_signal_list) < 3:
                continue

            # Sort by time
            sorted_signals = sorted(
                driver_signal_list,
                key=lambda s: s.occurred_at,
            )

            warning_count = sum(1 for s in sorted_signals if s.severity == "warning")
            critical_count = sum(1 for s in sorted_signals if s.severity == "critical")

            if warning_count == 0 and critical_count == 0:
                continue

            # Calculate escalation rate
            total = warning_count + critical_count
            escalation_rate = critical_count / total if total > 0 else 0

            # Determine trend by comparing first half to second half
            mid = len(sorted_signals) // 2
            first_half_critical = sum(
                1 for s in sorted_signals[:mid] if s.severity == "critical"
            )
            second_half_critical = sum(
                1 for s in sorted_signals[mid:] if s.severity == "critical"
            )

            if second_half_critical > first_half_critical + 1:
                trend = "worsening"
            elif first_half_critical > second_half_critical + 1:
                trend = "improving"
            else:
                trend = "stable"

            # Only report if concerning
            if escalation_rate > 0.3 or trend == "worsening":
                driver_name = sorted_signals[0].driver_name

                patterns.append(
                    EscalationPattern(
                        driver_id=driver_id,
                        driver_name=driver_name,
                        warning_count=warning_count,
                        critical_count=critical_count,
                        escalation_rate=round(escalation_rate, 3),
                        trend=trend,
                        description=self._describe_escalation(
                            driver_name or driver_id, escalation_rate, trend
                        ),
                    )
                )

        return sorted(patterns, key=lambda x: x.escalation_rate, reverse=True)[:10]

    def _severity_breakdown(self, signals: list[SignalData]) -> dict[str, int]:
        """Count signals by severity."""
        breakdown: dict[str, int] = {"info": 0, "warning": 0, "critical": 0}
        for s in signals:
            if s.severity in breakdown:
                breakdown[s.severity] += 1
        return breakdown

    def _calculate_risk_level(
        self, severity_breakdown: dict[str, int], total: int
    ) -> str:
        """Calculate risk level based on severity distribution."""
        if total == 0:
            return "low"

        critical_ratio = severity_breakdown.get("critical", 0) / total
        warning_ratio = severity_breakdown.get("warning", 0) / total

        if critical_ratio > 0.3:
            return "high"
        elif critical_ratio > 0.1 or warning_ratio > 0.5:
            return "medium"
        return "low"

    def _describe_correlation(
        self, behavior_a: str, behavior_b: str, correlation: float
    ) -> str:
        """Generate human-readable description of correlation."""
        strength = (
            "fuerte" if abs(correlation) > 0.6 else "moderada" if abs(correlation) > 0.4 else "leve"
        )

        if correlation > 0:
            return f"Correlación {strength} entre {behavior_a} y {behavior_b}: tienden a ocurrir juntos"
        else:
            return f"Correlación inversa {strength}: {behavior_a} y {behavior_b} raramente ocurren juntos"

    def _describe_escalation(
        self, driver_name: str, rate: float, trend: str
    ) -> str:
        """Generate description of escalation pattern."""
        trend_text = {
            "worsening": "con tendencia a empeorar",
            "improving": "mejorando",
            "stable": "estable",
        }

        return (
            f"{driver_name}: {rate*100:.0f}% de eventos son críticos, {trend_text.get(trend, '')}"
        )
