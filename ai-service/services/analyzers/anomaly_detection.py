"""
Analizador de detección de anomalías.
Busca patrones inusuales en: tampering, obstrucción de cámara,
desconexiones, horarios atípicos y conductas repetitivas.
"""

import logging
from collections import Counter, defaultdict
from typing import Any, Dict, List, Tuple

from api.analysis_models import RiskLevel, TrendDirection
from .base import BaseAnalyzer

logger = logging.getLogger(__name__)

# Tipos de eventos sospechosos
TAMPERING_KEYWORDS = {
    "unplugged", "desconectado", "jamming", "spoofing", "tamper",
    "device_unplugged", "tampering",
}

CAMERA_OBSTRUCTION_KEYWORDS = {
    "obstructed", "obstruida", "covered", "tapada", "camera",
    "obstruction", "camera_obstruction", "camera_covered",
}

CONNECTIVITY_KEYWORDS = {
    "connection_lost", "offline", "poor_signal", "desconexion",
}


class AnomalyDetectionAnalyzer(BaseAnalyzer):
    """Detecta anomalías y patrones sospechosos en datos de flota."""

    def analyze(
        self,
        raw_data: Dict[str, Any],
        parameters: Dict[str, Any],
    ) -> Dict[str, Any]:
        safety_events = raw_data.get("safety_events", [])
        days_back = parameters.get("days_back", 7)

        all_events = self._flatten_events(safety_events)

        # Clasificar eventos
        tampering = self._filter_events(all_events, TAMPERING_KEYWORDS)
        camera = self._filter_events(all_events, CAMERA_OBSTRUCTION_KEYWORDS)
        connectivity = self._filter_events(all_events, CONNECTIVITY_KEYWORDS)
        total_anomalies = len(tampering) + len(camera) + len(connectivity)

        # Análisis de repeat offenders
        repeat_offenders = self._find_repeat_offenders(all_events)

        # Horas atípicas (fuera de 6:00-22:00)
        off_hours = self._off_hours_events(all_events)

        # Patrones de clustering (múltiples eventos del mismo vehículo en corto tiempo)
        clusters = self._detect_event_clusters(all_events)

        # Métricas
        metrics = [
            self.metric("total_anomalies", "Anomalias Detectadas", total_anomalies, unit="eventos",
                         severity=RiskLevel.HIGH if total_anomalies > 5 else
                                  RiskLevel.MEDIUM if total_anomalies > 0 else RiskLevel.LOW),
            self.metric("tampering_events", "Eventos de Tampering", len(tampering), unit="eventos"),
            self.metric("camera_obstructions", "Obstrucciones de Camara", len(camera), unit="eventos"),
            self.metric("connectivity_issues", "Problemas de Conectividad", len(connectivity), unit="eventos"),
            self.metric("off_hours_events", "Eventos en Horario Atipico", len(off_hours), unit="eventos"),
            self.metric("repeat_offenders", "Reincidentes", len(repeat_offenders), unit="vehiculos/conductores"),
        ]

        if clusters:
            metrics.append(self.metric(
                "event_clusters", "Clusters Detectados", len(clusters), unit="grupos",
                severity=RiskLevel.HIGH if len(clusters) > 2 else RiskLevel.MEDIUM,
            ))

        # Hallazgos
        findings = self._generate_findings(
            tampering, camera, connectivity, off_hours,
            repeat_offenders, clusters, days_back,
        )

        # Risk level
        risk_level = self._determine_risk(
            total_anomalies, len(tampering), len(clusters), len(repeat_offenders),
        )

        return {
            "title": "Deteccion de Anomalias en la Flota",
            "summary": self._build_summary(total_anomalies, len(tampering), len(camera), days_back),
            "metrics": metrics,
            "findings": findings,
            "risk_level": risk_level,
            "data_window": self.build_data_window(parameters),
            "methodology": (
                "Deteccion de patrones anomalos mediante clasificacion de eventos "
                "(tampering, obstruccion, conectividad), analisis de horarios atipicos, "
                "identificacion de reincidentes y clustering temporal."
            ),
            "_analysis_detail": {
                "tampering_count": len(tampering),
                "camera_count": len(camera),
                "connectivity_count": len(connectivity),
                "off_hours_count": len(off_hours),
                "clusters": len(clusters),
                "repeat_offenders": [
                    {"name": name, "count": count}
                    for name, count in repeat_offenders
                ],
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

    def _get_event_type_raw(self, event: Dict) -> str:
        """Obtiene el tipo de evento en formato raw (para matching de keywords)."""
        labels = event.get("behaviorLabels", [])
        if labels and isinstance(labels, list):
            first = labels[0]
            return (first.get("label") or first.get("name") or "").lower()
        label = event.get("behaviorLabel", {})
        if isinstance(label, dict):
            return (label.get("label") or label.get("name") or "").lower()
        return (event.get("type") or event.get("type_description") or "").lower()

    def _filter_events(self, events: List[Dict], keywords: set) -> List[Dict]:
        result = []
        for event in events:
            event_type = self._get_event_type_raw(event)
            if any(kw in event_type for kw in keywords):
                result.append(event)
        return result

    def _find_repeat_offenders(self, events: List[Dict]) -> List[Tuple[str, int]]:
        """Encuentra vehículos/conductores con múltiples eventos."""
        counter = Counter()
        for event in events:
            asset = event.get("asset", {})
            name = asset.get("name") or event.get("vehicle", {}).get("name")
            driver = event.get("driver", {})
            driver_name = driver.get("name") if isinstance(driver, dict) else None

            key = driver_name or name or "Desconocido"
            counter[key] += 1

        # Solo reincidentes (3+ eventos)
        return [(name, count) for name, count in counter.most_common() if count >= 3]

    def _off_hours_events(self, events: List[Dict]) -> List[Dict]:
        """Eventos fuera de horario operativo (22:00 - 06:00)."""
        result = []
        for event in events:
            ts = event.get("createdAtTime") or event.get("timestamp") or event.get("time")
            hour = self.get_hour_from_timestamp(ts)
            if hour is not None and (hour >= 22 or hour < 6):
                result.append(event)
        return result

    def _detect_event_clusters(self, events: List[Dict]) -> List[Dict]:
        """
        Detecta clusters: 3+ eventos del mismo vehículo en 30 minutos.
        """
        # Agrupar por vehículo
        by_vehicle: Dict[str, List] = defaultdict(list)
        for event in events:
            asset = event.get("asset", {})
            vid = asset.get("id") or event.get("vehicle", {}).get("id") or "unknown"
            ts = event.get("createdAtTime") or event.get("timestamp") or event.get("time")
            dt = self.parse_iso_timestamp(ts)
            if dt:
                by_vehicle[vid].append({"event": event, "dt": dt})

        clusters = []
        for vid, vehicle_events in by_vehicle.items():
            if len(vehicle_events) < 3:
                continue

            sorted_events = sorted(vehicle_events, key=lambda x: x["dt"])
            # Ventana deslizante de 30 min
            for i in range(len(sorted_events)):
                cluster_events = [sorted_events[i]]
                for j in range(i + 1, len(sorted_events)):
                    diff = (sorted_events[j]["dt"] - sorted_events[i]["dt"]).total_seconds()
                    if diff <= 1800:  # 30 minutos
                        cluster_events.append(sorted_events[j])
                    else:
                        break

                if len(cluster_events) >= 3:
                    vehicle_name = (
                        cluster_events[0]["event"].get("asset", {}).get("name")
                        or vid
                    )
                    clusters.append({
                        "vehicle_id": vid,
                        "vehicle_name": vehicle_name,
                        "event_count": len(cluster_events),
                        "window_minutes": 30,
                    })
                    break  # Solo un cluster por vehículo

        return clusters

    def _generate_findings(
        self, tampering, camera, connectivity, off_hours,
        repeat_offenders, clusters, days,
    ) -> List:
        findings = []

        total = len(tampering) + len(camera) + len(connectivity)
        if total == 0 and not off_hours and not repeat_offenders and not clusters:
            findings.append(self.finding(
                title="Sin anomalias detectadas",
                description=f"No se identificaron patrones anomalos en los ultimos {days} dias.",
                severity=RiskLevel.LOW,
                category="seguridad",
            ))
            return findings

        # Tampering
        if tampering:
            findings.append(self.finding(
                title="Eventos de tampering detectados",
                description=f"Se detectaron {len(tampering)} eventos de manipulacion de dispositivo. "
                            "Puede indicar intento de sabotaje o robo.",
                severity=RiskLevel.CRITICAL if len(tampering) > 3 else RiskLevel.HIGH,
                category="seguridad",
            ))

        # Camera obstructions
        if camera:
            findings.append(self.finding(
                title="Obstrucciones de camara",
                description=f"{len(camera)} eventos de obstruccion de camara. "
                            "Verificar si son intencionales.",
                severity=RiskLevel.HIGH if len(camera) > 2 else RiskLevel.MEDIUM,
                category="seguridad",
            ))

        # Connectivity
        if connectivity:
            findings.append(self.finding(
                title="Problemas de conectividad",
                description=f"{len(connectivity)} eventos de perdida de conexion.",
                severity=RiskLevel.MEDIUM,
                category="operativo",
            ))

        # Off hours
        if off_hours:
            pct = round(len(off_hours) / max(total + len(off_hours), 1) * 100, 1)
            findings.append(self.finding(
                title="Actividad en horario atipico",
                description=f"{len(off_hours)} eventos entre 22:00 y 06:00 ({pct}% del total). "
                            "Verificar si corresponden a operaciones autorizadas.",
                severity=RiskLevel.MEDIUM if len(off_hours) < 5 else RiskLevel.HIGH,
                category="operativo",
            ))

        # Repeat offenders
        if repeat_offenders:
            top = repeat_offenders[:3]
            findings.append(self.finding(
                title="Reincidentes identificados",
                description="Vehiculos/conductores con multiples eventos: "
                            + ", ".join(f"{n} ({c})" for n, c in top) + ".",
                severity=RiskLevel.HIGH,
                category="seguridad",
                evidence=[f"{n}: {c} eventos" for n, c in repeat_offenders],
            ))

        # Clusters
        if clusters:
            findings.append(self.finding(
                title="Clusters de eventos detectados",
                description=f"{len(clusters)} vehiculo(s) con 3+ eventos en 30 minutos. "
                            "Puede indicar situacion de emergencia o patron sospechoso.",
                severity=RiskLevel.HIGH,
                category="seguridad",
                evidence=[
                    f"{c['vehicle_name']}: {c['event_count']} eventos en {c['window_minutes']}min"
                    for c in clusters
                ],
            ))

        return findings

    def _determine_risk(
        self, total: int, tampering: int, clusters: int, repeat: int,
    ) -> RiskLevel:
        if tampering > 3 or clusters > 2:
            return RiskLevel.CRITICAL
        if tampering > 0 or clusters > 0 or repeat > 2:
            return RiskLevel.HIGH
        if total > 5:
            return RiskLevel.MEDIUM
        return RiskLevel.LOW

    def _build_summary(self, total: int, tampering: int, camera: int, days: int) -> str:
        if total == 0:
            return f"Sin anomalias detectadas en los ultimos {days} dias."
        parts = [f"{total} anomalias en {days} dias"]
        if tampering > 0:
            parts.append(f"{tampering} de tampering")
        if camera > 0:
            parts.append(f"{camera} obstrucciones de camara")
        return ", ".join(parts) + "."
