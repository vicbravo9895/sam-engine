"""
Analizador de riesgo de conductor.
Calcula un perfil de riesgo basado en eventos de seguridad, patrones temporales
y tendencias de comportamiento.
"""

import logging
from collections import Counter, defaultdict
from typing import Any, Dict, List

from api.analysis_models import RiskLevel, TrendDirection
from .base import BaseAnalyzer

logger = logging.getLogger(__name__)

# Pesos de severidad por tipo de evento
EVENT_SEVERITY_WEIGHTS: Dict[str, float] = {
    # Criticos (peso alto)
    "Colision/Choque": 25.0,
    "Casi colision": 15.0,
    "Somnolencia": 15.0,
    "Conductor bebiendo": 20.0,
    # Altos
    "Uso de celular": 10.0,
    "Distraccion del conductor": 8.0,
    "Conduccion distraida": 8.0,
    "Exceso de velocidad": 8.0,
    # Medios
    "Frenado brusco": 5.0,
    "Aceleracion brusca": 4.0,
    "Giro brusco": 4.0,
    "Distancia de seguimiento insegura": 6.0,
    "Violacion de carril": 5.0,
    # Bajos
    "Camara obstruida": 3.0,
    "Sin cinturon de seguridad": 3.0,
    "Fumando": 2.0,
    "Comiendo/Bebiendo": 2.0,
    "Parada incompleta": 2.0,
}

# Peso por defecto para tipos no mapeados
DEFAULT_EVENT_WEIGHT = 3.0


class DriverRiskAnalyzer(BaseAnalyzer):
    """Analiza el perfil de riesgo de un conductor basado en eventos de seguridad."""

    def analyze(
        self,
        raw_data: Dict[str, Any],
        parameters: Dict[str, Any],
    ) -> Dict[str, Any]:
        safety_events = raw_data.get("safety_events", [])
        days_back = parameters.get("days_back", 7)
        driver_name = parameters.get("driver_name", "Conductor")

        # Extraer eventos
        all_events = self._flatten_events(safety_events)
        total_events = len(all_events)

        # Calcular score de riesgo (0-100)
        risk_score = self._calculate_risk_score(all_events, days_back)

        # Distribución por tipo
        type_distribution = self._event_type_distribution(all_events)

        # Distribución por hora del día
        hour_distribution = self._hour_distribution(all_events)
        peak_hours = self._find_peak_hours(hour_distribution)

        # Distribución por severidad
        severity_dist = self._severity_distribution(all_events)

        # Tendencia (si hay suficientes datos)
        trend = self._calculate_trend(all_events, days_back)

        # Métricas
        risk_level = self.compute_risk_level(risk_score)
        events_per_day = round(self.safe_div(total_events, days_back), 1)

        metrics = [
            self.metric(
                "risk_score", "Indice de Riesgo", round(risk_score, 1),
                unit="/ 100", severity=risk_level,
            ),
            self.metric(
                "total_events", "Eventos Totales", total_events,
                unit="eventos",
                trend=trend["direction"],
                trend_value=trend.get("label"),
            ),
            self.metric(
                "events_per_day", "Promedio Diario", events_per_day,
                unit="eventos/dia",
            ),
            self.metric(
                "top_event_type", "Evento Mas Frecuente",
                type_distribution[0][0] if type_distribution else "Ninguno",
                unit=f"{type_distribution[0][1]} veces" if type_distribution else None,
            ),
        ]

        if peak_hours:
            metrics.append(
                self.metric(
                    "peak_hours", "Horas de Mayor Riesgo",
                    ", ".join(f"{h}:00" for h in peak_hours[:3]),
                )
            )

        # Hallazgos
        findings = []

        if risk_score >= 60:
            findings.append(self.finding(
                title="Conductor con riesgo elevado",
                description=f"{driver_name} presenta un indice de riesgo de {round(risk_score)}/100 "
                            f"con {total_events} eventos en {days_back} dias.",
                severity=RiskLevel.HIGH if risk_score < 75 else RiskLevel.CRITICAL,
                category="seguridad",
                evidence=[f"{t}: {c} eventos" for t, c in type_distribution[:5]],
            ))

        # Patrones de hora
        if peak_hours:
            findings.append(self.finding(
                title="Patron horario detectado",
                description=f"Mayor concentracion de eventos entre las "
                            f"{peak_hours[0]}:00 y {peak_hours[-1]}:00.",
                severity=RiskLevel.MEDIUM,
                category="operativo",
                evidence=[
                    f"{h}:00 - {hour_distribution.get(h, 0)} eventos"
                    for h in peak_hours[:5]
                ],
            ))

        # Eventos criticos
        critical_types = [t for t, _ in type_distribution
                          if EVENT_SEVERITY_WEIGHTS.get(t, 0) >= 15]
        if critical_types:
            findings.append(self.finding(
                title="Eventos criticos recurrentes",
                description=f"Se detectaron eventos de alta severidad: "
                            f"{', '.join(critical_types)}.",
                severity=RiskLevel.HIGH,
                category="seguridad",
            ))

        # Tendencia
        if trend["direction"] == TrendDirection.UP:
            findings.append(self.finding(
                title="Tendencia al alza",
                description=f"Los eventos de seguridad muestran tendencia creciente ({trend.get('label', '')}).",
                severity=RiskLevel.MEDIUM,
                category="seguridad",
            ))

        return {
            "title": f"Perfil de Riesgo: {driver_name}",
            "summary": self._build_summary(driver_name, risk_score, total_events, days_back),
            "metrics": metrics,
            "findings": findings,
            "risk_level": risk_level,
            "data_window": self.build_data_window(parameters),
            "methodology": (
                "Analisis determinista de eventos de seguridad con ponderacion por severidad, "
                "deteccion de patrones temporales y calculo de tendencias."
            ),
            # Datos extra para el LLM
            "_analysis_detail": {
                "type_distribution": dict(type_distribution),
                "hour_distribution": hour_distribution,
                "severity_distribution": severity_dist,
                "trend": trend,
                "risk_score": risk_score,
            },
        }

    # =========================================================================
    # PRIVATE
    # =========================================================================

    def _flatten_events(self, safety_events: Any) -> List[Dict]:
        """Extrae una lista plana de eventos desde la estructura de Samsara."""
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

    def _calculate_risk_score(self, events: List[Dict], days: int) -> float:
        """
        Calcula score de riesgo (0-100).
        Factores: cantidad, severidad ponderada, frecuencia, diversidad.
        """
        if not events:
            return 0.0

        # Puntuación ponderada total
        weighted_sum = 0.0
        for event in events:
            event_type = self._get_event_type(event)
            weight = EVENT_SEVERITY_WEIGHTS.get(event_type, DEFAULT_EVENT_WEIGHT)
            weighted_sum += weight

        # Normalizar: un conductor "perfecto" tiene 0, uno peligroso > 100
        # Base: 5 puntos por día como umbral normal
        daily_threshold = 5.0 * days
        raw_score = (weighted_sum / max(daily_threshold, 1.0)) * 100.0

        # Cap at 100
        return min(raw_score, 100.0)

    def _event_type_distribution(self, events: List[Dict]) -> List[tuple]:
        """Distribución de eventos por tipo, ordenada por frecuencia."""
        counter = Counter()
        for event in events:
            event_type = self._get_event_type(event)
            counter[event_type] += 1
        return counter.most_common()

    def _hour_distribution(self, events: List[Dict]) -> Dict[int, int]:
        """Distribución de eventos por hora del día."""
        hours: Dict[int, int] = defaultdict(int)
        for event in events:
            ts = event.get("createdAtTime") or event.get("timestamp") or event.get("time")
            hour = self.get_hour_from_timestamp(ts)
            if hour is not None:
                hours[hour] += 1
        return dict(hours)

    def _find_peak_hours(self, hour_dist: Dict[int, int]) -> List[int]:
        """Encuentra las horas con más eventos."""
        if not hour_dist:
            return []
        avg = sum(hour_dist.values()) / max(len(hour_dist), 1)
        peak = [h for h, count in hour_dist.items() if count > avg]
        return sorted(peak)

    def _severity_distribution(self, events: List[Dict]) -> Dict[str, int]:
        """Distribución de eventos por severidad."""
        counter: Dict[str, int] = defaultdict(int)
        for event in events:
            severity = event.get("severity") or event.get("eventState") or "unknown"
            counter[severity] += 1
        return dict(counter)

    def _calculate_trend(self, events: List[Dict], days: int) -> Dict[str, Any]:
        """
        Calcula la tendencia comparando primera mitad vs segunda mitad del período.
        """
        if len(events) < 4 or days < 2:
            return {"direction": TrendDirection.STABLE, "label": "Datos insuficientes"}

        # Separar en dos mitades por timestamp
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
        first_half = mid
        second_half = len(timestamps) - mid

        # Calcular tasas
        half_days = max(days / 2, 1)
        rate_first = first_half / half_days
        rate_second = second_half / half_days

        if rate_first == 0:
            if rate_second > 0:
                return {"direction": TrendDirection.UP, "label": "En aumento"}
            return {"direction": TrendDirection.STABLE, "label": "Estable"}

        change_pct = ((rate_second - rate_first) / rate_first) * 100

        if change_pct > 20:
            return {"direction": TrendDirection.UP, "label": f"+{round(change_pct)}%"}
        elif change_pct < -20:
            return {"direction": TrendDirection.DOWN, "label": f"{round(change_pct)}%"}
        return {"direction": TrendDirection.STABLE, "label": "Estable"}

    def _get_event_type(self, event: Dict) -> str:
        """Obtiene el tipo de evento en formato legible."""
        # Intentar behaviorLabels (formato stream)
        labels = event.get("behaviorLabels", [])
        if labels and isinstance(labels, list):
            first = labels[0]
            return first.get("name") or first.get("label") or "Desconocido"
        # Intentar behaviorLabel (formato legacy)
        label = event.get("behaviorLabel", {})
        if isinstance(label, dict):
            return label.get("name") or label.get("label") or "Desconocido"
        # Fallback
        return event.get("type_description") or event.get("type") or "Desconocido"

    def _build_summary(self, name: str, score: float, total: int, days: int) -> str:
        """Genera un resumen ejecutivo de una línea."""
        level = self.compute_risk_level(score)
        level_text = {
            RiskLevel.LOW: "bajo",
            RiskLevel.MEDIUM: "moderado",
            RiskLevel.HIGH: "alto",
            RiskLevel.CRITICAL: "critico",
        }
        return (
            f"{name}: riesgo {level_text[level]} ({round(score)}/100) "
            f"con {total} eventos en {days} dias."
        )
