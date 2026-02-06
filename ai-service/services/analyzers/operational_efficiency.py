"""
Analizador de eficiencia operativa.
Evalúa utilización de flota, tiempos muertos, eficiencia de viajes
y patrones de consumo de combustible.
"""

import logging
from collections import defaultdict
from typing import Any, Dict, List, Optional

from api.analysis_models import RiskLevel, TrendDirection
from .base import BaseAnalyzer

logger = logging.getLogger(__name__)


class OperationalEfficiencyAnalyzer(BaseAnalyzer):
    """Analiza la eficiencia operativa de la flota basado en viajes y estadísticas."""

    def analyze(
        self,
        raw_data: Dict[str, Any],
        parameters: Dict[str, Any],
    ) -> Dict[str, Any]:
        trips_data = raw_data.get("trips", [])
        vehicle_stats = raw_data.get("vehicle_stats", {})
        days_back = parameters.get("days_back", 7)

        # Extraer viajes
        trips = self._flatten_trips(trips_data)
        total_trips = len(trips)

        # Métricas de viajes
        trip_metrics = self._analyze_trips(trips, days_back)

        # Métricas de utilización
        utilization = self._analyze_utilization(trips, vehicle_stats, days_back)

        # Componer métricas
        metrics = [
            self.metric("total_trips", "Total de Viajes", total_trips, unit="viajes"),
            self.metric(
                "trips_per_day", "Viajes por Dia",
                round(self.safe_div(total_trips, days_back), 1), unit="viajes/dia",
            ),
        ]

        if trip_metrics.get("avg_duration_min") is not None:
            metrics.append(self.metric(
                "avg_trip_duration", "Duracion Promedio",
                round(trip_metrics["avg_duration_min"], 1), unit="min",
            ))

        if trip_metrics.get("total_distance_km") is not None:
            metrics.append(self.metric(
                "total_distance", "Distancia Total",
                round(trip_metrics["total_distance_km"], 1), unit="km",
            ))

        if trip_metrics.get("idle_ratio") is not None:
            idle_pct = round(trip_metrics["idle_ratio"] * 100, 1)
            metrics.append(self.metric(
                "idle_ratio", "Tiempo en Ralenti",
                idle_pct, unit="%",
                severity=RiskLevel.HIGH if idle_pct > 30 else
                          RiskLevel.MEDIUM if idle_pct > 15 else RiskLevel.LOW,
            ))

        if utilization.get("active_vehicles") is not None:
            metrics.append(self.metric(
                "active_vehicles", "Vehiculos Activos",
                utilization["active_vehicles"], unit="vehiculos",
            ))

        if utilization.get("utilization_rate") is not None:
            metrics.append(self.metric(
                "utilization_rate", "Tasa de Utilizacion",
                round(utilization["utilization_rate"], 1), unit="%",
            ))

        # Hallazgos
        findings = self._generate_findings(trip_metrics, utilization, total_trips, days_back)

        # Risk level basado en eficiencia
        risk_level = self._determine_risk(trip_metrics, utilization)

        return {
            "title": "Eficiencia Operativa de la Flota",
            "summary": self._build_summary(total_trips, days_back, trip_metrics, utilization),
            "metrics": metrics,
            "findings": findings,
            "risk_level": risk_level,
            "data_window": self.build_data_window(parameters),
            "methodology": (
                "Analisis de viajes completados, tiempos de ralenti, "
                "tasa de utilizacion de vehiculos y patrones de operacion."
            ),
            "_analysis_detail": {
                "trip_metrics": trip_metrics,
                "utilization": utilization,
            },
        }

    # =========================================================================
    # PRIVATE
    # =========================================================================

    def _flatten_trips(self, trips_data: Any) -> List[Dict]:
        trips = []
        if isinstance(trips_data, dict):
            data = trips_data.get("data", trips_data.get("trips", []))
            if isinstance(data, list):
                trips = data
        elif isinstance(trips_data, list):
            for item in trips_data:
                if isinstance(item, dict) and "trips" in item:
                    trips.extend(item["trips"])
                else:
                    trips.append(item)
        return trips

    def _analyze_trips(self, trips: List[Dict], days: int) -> Dict[str, Any]:
        if not trips:
            return {}

        durations = []
        distances = []
        idle_times = []
        statuses: Dict[str, int] = defaultdict(int)
        vehicles: set = set()

        for trip in trips:
            # Duración
            duration = trip.get("duration_minutes") or trip.get("durationMs")
            if duration is not None:
                if isinstance(duration, (int, float)) and duration > 1000:
                    # Probablemente en ms
                    duration = duration / 60000
                durations.append(float(duration))

            # Distancia
            dist = trip.get("distance_km") or trip.get("distanceMeters")
            if dist is not None:
                dist = float(dist)
                if dist > 1000:
                    dist = dist / 1000  # metros a km
                distances.append(dist)

            # Idle time
            idle = trip.get("idleDurationMs") or trip.get("idle_minutes")
            if idle is not None:
                idle = float(idle)
                if idle > 1000:
                    idle = idle / 60000
                idle_times.append(idle)

            # Status
            status = trip.get("tripState") or trip.get("status") or trip.get("status_description") or "unknown"
            statuses[status] += 1

            # Vehicle tracking
            asset = trip.get("asset", {})
            vid = asset.get("id") or trip.get("vehicle_id")
            if vid:
                vehicles.add(vid)

        result: Dict[str, Any] = {
            "vehicles_with_trips": len(vehicles),
            "status_distribution": dict(statuses),
        }

        if durations:
            result["avg_duration_min"] = sum(durations) / len(durations)
            result["total_duration_min"] = sum(durations)

        if distances:
            result["total_distance_km"] = sum(distances)
            result["avg_distance_km"] = sum(distances) / len(distances)

        if idle_times and durations:
            total_idle = sum(idle_times)
            total_dur = sum(durations) if sum(durations) > 0 else 1
            result["total_idle_min"] = total_idle
            result["idle_ratio"] = total_idle / total_dur

        return result

    def _analyze_utilization(
        self, trips: List[Dict], vehicle_stats: Any, days: int,
    ) -> Dict[str, Any]:
        """Calcula tasa de utilización de la flota."""
        result: Dict[str, Any] = {}

        # Contar vehículos activos (con al menos un viaje)
        active_vehicles = set()
        for trip in trips:
            asset = trip.get("asset", {})
            vid = asset.get("id") or trip.get("vehicle_id")
            if vid:
                active_vehicles.add(vid)

        result["active_vehicles"] = len(active_vehicles)

        # Si tenemos datos de flota total
        total_vehicles = 0
        if isinstance(vehicle_stats, dict):
            fleet = vehicle_stats.get("fleet_size") or vehicle_stats.get("total_vehicles")
            if fleet:
                total_vehicles = int(fleet)

        if total_vehicles > 0:
            result["total_vehicles"] = total_vehicles
            result["utilization_rate"] = (len(active_vehicles) / total_vehicles) * 100
        elif len(active_vehicles) > 0:
            result["utilization_rate"] = 100.0  # all known vehicles are active

        return result

    def _generate_findings(
        self, trip_metrics: Dict, utilization: Dict, total_trips: int, days: int,
    ) -> List:
        findings = []

        if total_trips == 0:
            findings.append(self.finding(
                title="Sin actividad de viajes",
                description=f"No se registraron viajes en los ultimos {days} dias.",
                severity=RiskLevel.MEDIUM,
                category="operativo",
            ))
            return findings

        # Idle time alto
        idle_ratio = trip_metrics.get("idle_ratio")
        if idle_ratio is not None and idle_ratio > 0.2:
            pct = round(idle_ratio * 100, 1)
            findings.append(self.finding(
                title="Tiempo de ralenti elevado",
                description=f"El {pct}% del tiempo de operacion se pasa en ralenti. "
                            "Esto impacta en combustible y desgaste del motor.",
                severity=RiskLevel.HIGH if idle_ratio > 0.3 else RiskLevel.MEDIUM,
                category="operativo",
                evidence=[f"Ralenti total: {round(trip_metrics.get('total_idle_min', 0), 1)} min"],
            ))

        # Baja utilización
        util_rate = utilization.get("utilization_rate")
        if util_rate is not None and util_rate < 60:
            findings.append(self.finding(
                title="Baja utilizacion de la flota",
                description=f"Solo el {round(util_rate, 1)}% de los vehiculos registraron viajes.",
                severity=RiskLevel.MEDIUM,
                category="operativo",
            ))

        # Eficiencia general
        avg_dur = trip_metrics.get("avg_duration_min")
        if avg_dur is not None:
            if avg_dur < 5:
                findings.append(self.finding(
                    title="Viajes muy cortos",
                    description=f"Duracion promedio de {round(avg_dur, 1)} min. "
                                "Muchos viajes cortos pueden indicar ineficiencia de rutas.",
                    severity=RiskLevel.LOW,
                    category="operativo",
                ))

        return findings

    def _determine_risk(self, trip_metrics: Dict, utilization: Dict) -> RiskLevel:
        idle = trip_metrics.get("idle_ratio", 0)
        util = utilization.get("utilization_rate", 100)

        if idle > 0.35 or util < 40:
            return RiskLevel.HIGH
        if idle > 0.2 or util < 60:
            return RiskLevel.MEDIUM
        return RiskLevel.LOW

    def _build_summary(
        self, total: int, days: int, trip_metrics: Dict, utilization: Dict,
    ) -> str:
        parts = [f"{total} viajes en {days} dias"]

        avg_dur = trip_metrics.get("avg_duration_min")
        if avg_dur is not None:
            parts.append(f"duracion promedio {round(avg_dur, 1)} min")

        idle = trip_metrics.get("idle_ratio")
        if idle is not None:
            parts.append(f"ralenti {round(idle * 100, 1)}%")

        util = utilization.get("utilization_rate")
        if util is not None:
            parts.append(f"utilizacion {round(util, 1)}%")

        return ". ".join(parts) + "."
