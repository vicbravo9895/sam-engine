"""
Analizador de seguridad general de flota.
Produce una vista ejecutiva de los eventos de seguridad a nivel de toda la flota:
top ofensores, distribución por tipo y tendencia general.
"""

import logging
from collections import Counter, defaultdict
from typing import Any, Dict, List, Tuple

from api.analysis_models import RiskLevel, TrendDirection
from .base import BaseAnalyzer

logger = logging.getLogger(__name__)


class FleetSafetyAnalyzer(BaseAnalyzer):
    """Genera un resumen ejecutivo de seguridad de toda la flota."""

    def analyze(
        self,
        raw_data: Dict[str, Any],
        parameters: Dict[str, Any],
    ) -> Dict[str, Any]:
        safety_events = raw_data.get("safety_events", [])
        days_back = parameters.get("days_back", 7)

        all_events = self._flatten_events(safety_events)
        total = len(all_events)

        # Distribuciones
        by_type = self._count_by_field(all_events, self._get_event_type)
        by_vehicle = self._count_by_field(all_events, self._get_vehicle_name)
        by_driver = self._count_by_field(all_events, self._get_driver_name)
        hour_dist = self._hour_distribution(all_events)

        # Top ofensores
        top_vehicles = by_vehicle[:5]
        top_drivers = by_driver[:5]

        # Tendencia
        trend = self._compute_trend(all_events, days_back)

        # Risk level
        events_per_day = self.safe_div(total, days_back)
        risk_level = self._fleet_risk(events_per_day, by_type)

        # Métricas
        metrics = [
            self.metric("total_events", "Total de Eventos", total, unit="eventos",
                         trend=trend["direction"], trend_value=trend.get("label")),
            self.metric("events_per_day", "Promedio Diario", round(events_per_day, 1), unit="eventos/dia"),
            self.metric("vehicles_involved", "Vehiculos Involucrados", len(by_vehicle), unit="vehiculos"),
            self.metric("drivers_involved", "Conductores Involucrados", len(by_driver), unit="conductores"),
            self.metric("event_types", "Tipos de Evento", len(by_type), unit="tipos"),
        ]

        if top_vehicles:
            metrics.append(self.metric(
                "top_vehicle", "Vehiculo con Mas Eventos",
                top_vehicles[0][0],
                unit=f"{top_vehicles[0][1]} eventos",
                severity=RiskLevel.HIGH if top_vehicles[0][1] > 5 else RiskLevel.MEDIUM,
            ))

        # Hallazgos
        findings = self._generate_findings(
            total, days_back, by_type, top_vehicles, top_drivers, trend, hour_dist,
        )

        return {
            "title": "Resumen de Seguridad de la Flota",
            "summary": self._build_summary(total, days_back, len(by_vehicle), risk_level),
            "metrics": metrics,
            "findings": findings,
            "risk_level": risk_level,
            "data_window": self.build_data_window(parameters),
            "methodology": (
                "Analisis agregado de eventos de seguridad a nivel de flota: "
                "distribucion por tipo, vehiculo, conductor y hora. "
                "Deteccion de patrones y tendencias temporales."
            ),
            "_analysis_detail": {
                "by_type": dict(by_type),
                "by_vehicle": dict(top_vehicles),
                "by_driver": dict(top_drivers),
                "hour_distribution": hour_dist,
                "trend": trend,
            },
        }

    # =========================================================================
    # PRIVATE
    # =========================================================================

    def _flatten_events(self, safety_events: Any) -> List[Dict]:
        events = []
        if isinstance(safety_events, dict):
            data = safety_events.get("data", safety_events.get("events", []))
            if isinstance(data, list):
                events = data
        elif isinstance(safety_events, list):
            for item in safety_events:
                if isinstance(item, dict) and "events" in item:
                    events.extend(item["events"])
                else:
                    events.append(item)
        return events

    def _count_by_field(self, events: List[Dict], extractor) -> List[Tuple[str, int]]:
        counter = Counter()
        for event in events:
            val = extractor(event)
            if val:
                counter[val] += 1
        return counter.most_common()

    def _get_event_type(self, event: Dict) -> str:
        labels = event.get("behaviorLabels", [])
        if labels and isinstance(labels, list):
            first = labels[0]
            return first.get("name") or first.get("label") or "Desconocido"
        label = event.get("behaviorLabel", {})
        if isinstance(label, dict):
            return label.get("name") or label.get("label") or "Desconocido"
        return event.get("type_description") or event.get("type") or "Desconocido"

    def _get_vehicle_name(self, event: Dict) -> str:
        asset = event.get("asset", {})
        if asset and asset.get("name"):
            return asset["name"]
        vehicle = event.get("vehicle", {})
        if isinstance(vehicle, dict):
            return vehicle.get("name") or "Desconocido"
        return "Desconocido"

    def _get_driver_name(self, event: Dict) -> str:
        driver = event.get("driver", {})
        if isinstance(driver, dict) and driver.get("name"):
            return driver["name"]
        return "Sin conductor asignado"

    def _hour_distribution(self, events: List[Dict]) -> Dict[int, int]:
        hours: Dict[int, int] = defaultdict(int)
        for event in events:
            ts = event.get("createdAtTime") or event.get("timestamp") or event.get("time")
            hour = self.get_hour_from_timestamp(ts)
            if hour is not None:
                hours[hour] += 1
        return dict(hours)

    def _compute_trend(self, events: List[Dict], days: int) -> Dict[str, Any]:
        if len(events) < 4 or days < 2:
            return {"direction": TrendDirection.STABLE, "label": "Datos insuficientes"}

        timestamps = []
        for event in events:
            ts = event.get("createdAtTime") or event.get("timestamp") or event.get("time")
            dt = self.parse_iso_timestamp(ts)
            if dt:
                timestamps.append(dt)

        if len(timestamps) < 4:
            return {"direction": TrendDirection.STABLE, "label": "Datos insuficientes"}

        timestamps.sort()
        mid = len(timestamps) // 2
        half_days = max(days / 2, 1)
        rate_first = mid / half_days
        rate_second = (len(timestamps) - mid) / half_days

        if rate_first == 0:
            return {"direction": TrendDirection.UP, "label": "En aumento"} if rate_second > 0 \
                else {"direction": TrendDirection.STABLE, "label": "Estable"}

        change = ((rate_second - rate_first) / rate_first) * 100
        if change > 20:
            return {"direction": TrendDirection.UP, "label": f"+{round(change)}%"}
        elif change < -20:
            return {"direction": TrendDirection.DOWN, "label": f"{round(change)}%"}
        return {"direction": TrendDirection.STABLE, "label": "Estable"}

    def _fleet_risk(self, events_per_day: float, by_type: List[Tuple[str, int]]) -> RiskLevel:
        """Determina riesgo de flota en base a tasa diaria y tipos criticos."""
        critical_keywords = {"colision", "choque", "somnolencia", "bebiendo", "crash"}
        has_critical = any(
            any(kw in t.lower() for kw in critical_keywords)
            for t, _ in by_type
        )
        if has_critical or events_per_day > 10:
            return RiskLevel.CRITICAL if events_per_day > 15 else RiskLevel.HIGH
        if events_per_day > 5:
            return RiskLevel.MEDIUM
        return RiskLevel.LOW

    def _generate_findings(
        self, total, days, by_type, top_vehicles, top_drivers, trend, hour_dist,
    ) -> List:
        findings = []

        if total == 0:
            findings.append(self.finding(
                title="Sin eventos de seguridad",
                description=f"No se registraron eventos de seguridad en los ultimos {days} dias.",
                severity=RiskLevel.LOW,
                category="seguridad",
            ))
            return findings

        # Top event types
        if by_type:
            top3 = by_type[:3]
            findings.append(self.finding(
                title="Tipos de eventos mas frecuentes",
                description=f"Los eventos mas comunes son: "
                            + ", ".join(f"{t} ({c})" for t, c in top3) + ".",
                severity=RiskLevel.MEDIUM,
                category="seguridad",
                evidence=[f"{t}: {c}" for t, c in by_type[:5]],
            ))

        # Top offender vehicles
        if top_vehicles and top_vehicles[0][1] > 3:
            findings.append(self.finding(
                title="Vehiculos con mayor incidencia",
                description=f"{top_vehicles[0][0]} lidera con {top_vehicles[0][1]} eventos.",
                severity=RiskLevel.HIGH if top_vehicles[0][1] > 5 else RiskLevel.MEDIUM,
                category="operativo",
                evidence=[f"{v}: {c} eventos" for v, c in top_vehicles[:5]],
            ))

        # Top offender drivers
        if top_drivers and top_drivers[0][1] > 3:
            named = [(d, c) for d, c in top_drivers if d != "Sin conductor asignado"]
            if named:
                findings.append(self.finding(
                    title="Conductores con mayor incidencia",
                    description=f"{named[0][0]} lidera con {named[0][1]} eventos.",
                    severity=RiskLevel.HIGH if named[0][1] > 5 else RiskLevel.MEDIUM,
                    category="seguridad",
                    evidence=[f"{d}: {c}" for d, c in named[:5]],
                ))

        # Trend
        if trend["direction"] == TrendDirection.UP:
            findings.append(self.finding(
                title="Tendencia al alza en la flota",
                description=f"Los eventos de seguridad muestran incremento ({trend.get('label', '')}).",
                severity=RiskLevel.MEDIUM,
                category="seguridad",
            ))
        elif trend["direction"] == TrendDirection.DOWN:
            findings.append(self.finding(
                title="Tendencia a la baja en la flota",
                description=f"Los eventos de seguridad estan disminuyendo ({trend.get('label', '')}).",
                severity=RiskLevel.LOW,
                category="seguridad",
            ))

        # Hour patterns
        if hour_dist:
            peak_hours = sorted(hour_dist.keys(), key=lambda h: hour_dist[h], reverse=True)[:3]
            findings.append(self.finding(
                title="Horas de mayor incidencia",
                description=f"Las horas con mas eventos son: "
                            + ", ".join(f"{h}:00 ({hour_dist[h]})" for h in peak_hours) + ".",
                severity=RiskLevel.MEDIUM,
                category="operativo",
            ))

        return findings

    def _build_summary(self, total: int, days: int, vehicles: int, risk: RiskLevel) -> str:
        level_text = {
            RiskLevel.LOW: "bajo",
            RiskLevel.MEDIUM: "moderado",
            RiskLevel.HIGH: "alto",
            RiskLevel.CRITICAL: "critico",
        }
        return (
            f"{total} eventos de seguridad en {days} dias involucrando {vehicles} vehiculos. "
            f"Nivel de riesgo general: {level_text[risk]}."
        )
