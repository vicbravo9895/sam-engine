"""
Analizador de salud de vehículo.
Evalúa métricas de vehículo (combustible, batería, temperatura, motor)
para detectar anomalías y predecir necesidades de mantenimiento.
"""

import logging
from typing import Any, Dict, List, Optional

from api.analysis_models import RiskLevel, TrendDirection
from .base import BaseAnalyzer

logger = logging.getLogger(__name__)

# Umbrales de salud del vehículo
THRESHOLDS = {
    "battery_low": 12.0,          # Voltios - bajo
    "battery_critical": 11.5,     # Voltios - critico
    "coolant_high": 105.0,        # Celsius - alto
    "coolant_critical": 115.0,    # Celsius - critico
    "fuel_low": 20.0,             # Porcentaje
    "fuel_critical": 10.0,        # Porcentaje
    "engine_load_high": 85.0,     # Porcentaje
    "rpm_high": 4000,             # RPM
}


class VehicleHealthAnalyzer(BaseAnalyzer):
    """Analiza la salud de un vehículo basado en sus estadísticas."""

    def analyze(
        self,
        raw_data: Dict[str, Any],
        parameters: Dict[str, Any],
    ) -> Dict[str, Any]:
        vehicle_stats = raw_data.get("vehicle_stats", {})
        vehicle_name = parameters.get("vehicle_name", "Vehiculo")
        days_back = parameters.get("days_back", 7)

        # Extraer métricas del snapshot actual
        stats = self._extract_stats(vehicle_stats)

        # Analizar cada componente
        battery = self._analyze_battery(stats)
        coolant = self._analyze_coolant(stats)
        fuel = self._analyze_fuel(stats)
        engine = self._analyze_engine(stats)
        faults = self._analyze_faults(stats)

        # Componer métricas
        metrics = []
        all_checks = [battery, coolant, fuel, engine, faults]

        for check in all_checks:
            if check:
                metrics.extend(check.get("metrics", []))

        # Determinar riesgo general
        severities = [c["severity"] for c in all_checks if c]
        risk_level = max(severities, default=RiskLevel.LOW,
                         key=lambda s: ["low", "medium", "high", "critical"].index(s.value))

        # Hallazgos
        findings = []
        for check in all_checks:
            if check and check.get("findings"):
                findings.extend(check["findings"])

        if not findings:
            findings.append(self.finding(
                title="Vehiculo en buen estado",
                description=f"{vehicle_name} no presenta anomalias en los indicadores monitoreados.",
                severity=RiskLevel.LOW,
                category="mantenimiento",
            ))

        # Health score
        health_score = self._compute_health_score(all_checks)
        metrics.insert(0, self.metric(
            "health_score", "Indice de Salud", round(health_score),
            unit="/ 100",
            severity=self._health_score_to_risk(health_score),
        ))

        return {
            "title": f"Salud del Vehiculo: {vehicle_name}",
            "summary": self._build_summary(vehicle_name, health_score, findings),
            "metrics": metrics,
            "findings": findings,
            "risk_level": risk_level,
            "data_window": self.build_data_window(parameters),
            "methodology": (
                "Evaluacion de indicadores del vehiculo contra umbrales operativos: "
                "bateria, temperatura de refrigerante, combustible, carga del motor y fallas diagnosticas."
            ),
            "_analysis_detail": {
                "stats_snapshot": stats,
                "health_score": health_score,
            },
        }

    # =========================================================================
    # PRIVATE
    # =========================================================================

    def _extract_stats(self, vehicle_stats: Any) -> Dict[str, Any]:
        """Extrae valores numéricos del snapshot de stats."""
        if not vehicle_stats or not isinstance(vehicle_stats, dict):
            return {}

        # Los stats pueden venir en formato de Samsara directo o del Tool (español)
        stats = vehicle_stats.get("stats", vehicle_stats)

        result = {}
        # Mapeo de claves posibles
        mappings = {
            "battery_voltage": ["bateria_voltaje", "batteryMilliVolts", "battery_voltage"],
            "coolant_celsius": ["refrigerante_celsius", "engineCoolantTemperatureMilliC", "coolant_temp"],
            "fuel_percent": ["combustible_porcentaje", "fuelPercent", "fuel_percent"],
            "engine_load": ["motor_carga_porcentaje", "engineLoadPercent", "engine_load"],
            "engine_rpm": ["motor_rpm", "engineRpm", "rpm"],
            "engine_state": ["motor_estado", "engineState", "engine_state"],
            "odometer_km": ["odometro_km", "odometerMeters", "odometer"],
            "has_faults": ["tiene_fallas", "obdDtcCodes", "has_faults"],
            "ambient_temp": ["temperatura_ambiente_celsius", "ambientAirTemperatureMilliC"],
        }

        for canonical, alternatives in mappings.items():
            for alt in alternatives:
                val = stats.get(alt)
                if val is not None:
                    result[canonical] = val
                    break

        return result

    def _analyze_battery(self, stats: Dict) -> Optional[Dict]:
        voltage = stats.get("battery_voltage")
        if voltage is None:
            return None

        severity = RiskLevel.LOW
        finding = None

        if voltage < THRESHOLDS["battery_critical"]:
            severity = RiskLevel.CRITICAL
            finding = self.finding(
                title="Bateria en estado critico",
                description=f"Voltaje de bateria en {voltage}V (critico < {THRESHOLDS['battery_critical']}V). "
                            "Riesgo de falla de arranque.",
                severity=RiskLevel.CRITICAL,
                category="mantenimiento",
                evidence=[f"Voltaje actual: {voltage}V", f"Umbral critico: {THRESHOLDS['battery_critical']}V"],
            )
        elif voltage < THRESHOLDS["battery_low"]:
            severity = RiskLevel.MEDIUM
            finding = self.finding(
                title="Bateria con voltaje bajo",
                description=f"Voltaje de bateria en {voltage}V (bajo < {THRESHOLDS['battery_low']}V). "
                            "Revisar sistema de carga.",
                severity=RiskLevel.MEDIUM,
                category="mantenimiento",
            )

        return {
            "severity": severity,
            "metrics": [
                self.metric("battery_voltage", "Bateria", voltage, unit="V", severity=severity),
            ],
            "findings": [finding] if finding else [],
        }

    def _analyze_coolant(self, stats: Dict) -> Optional[Dict]:
        temp = stats.get("coolant_celsius")
        if temp is None:
            return None

        severity = RiskLevel.LOW
        finding = None

        if temp > THRESHOLDS["coolant_critical"]:
            severity = RiskLevel.CRITICAL
            finding = self.finding(
                title="Temperatura de refrigerante critica",
                description=f"Refrigerante a {temp}C (critico > {THRESHOLDS['coolant_critical']}C). "
                            "Detener el vehiculo para evitar dano al motor.",
                severity=RiskLevel.CRITICAL,
                category="mantenimiento",
                evidence=[f"Temperatura: {temp}C"],
            )
        elif temp > THRESHOLDS["coolant_high"]:
            severity = RiskLevel.HIGH
            finding = self.finding(
                title="Temperatura de refrigerante elevada",
                description=f"Refrigerante a {temp}C (elevado > {THRESHOLDS['coolant_high']}C). "
                            "Monitorear y revisar sistema de enfriamiento.",
                severity=RiskLevel.HIGH,
                category="mantenimiento",
            )

        return {
            "severity": severity,
            "metrics": [
                self.metric("coolant_temp", "Refrigerante", temp, unit="C", severity=severity),
            ],
            "findings": [finding] if finding else [],
        }

    def _analyze_fuel(self, stats: Dict) -> Optional[Dict]:
        fuel = stats.get("fuel_percent")
        if fuel is None:
            return None

        severity = RiskLevel.LOW
        finding = None

        if fuel < THRESHOLDS["fuel_critical"]:
            severity = RiskLevel.HIGH
            finding = self.finding(
                title="Combustible en nivel critico",
                description=f"Combustible al {fuel}%. Requiere recarga inmediata.",
                severity=RiskLevel.HIGH,
                category="operativo",
            )
        elif fuel < THRESHOLDS["fuel_low"]:
            severity = RiskLevel.MEDIUM
            finding = self.finding(
                title="Combustible bajo",
                description=f"Combustible al {fuel}%. Planificar recarga.",
                severity=RiskLevel.MEDIUM,
                category="operativo",
            )

        return {
            "severity": severity,
            "metrics": [
                self.metric("fuel_level", "Combustible", fuel, unit="%", severity=severity),
            ],
            "findings": [finding] if finding else [],
        }

    def _analyze_engine(self, stats: Dict) -> Optional[Dict]:
        metrics = []
        findings = []
        severity = RiskLevel.LOW

        load = stats.get("engine_load")
        if load is not None:
            if load > THRESHOLDS["engine_load_high"]:
                severity = max(severity, RiskLevel.MEDIUM,
                               key=lambda s: ["low", "medium", "high", "critical"].index(s.value))
                findings.append(self.finding(
                    title="Carga del motor elevada",
                    description=f"Motor al {load}% de carga. Puede indicar sobreesfuerzo.",
                    severity=RiskLevel.MEDIUM,
                    category="mantenimiento",
                ))
            metrics.append(self.metric("engine_load", "Carga Motor", load, unit="%"))

        rpm = stats.get("engine_rpm")
        if rpm is not None:
            metrics.append(self.metric("engine_rpm", "RPM", rpm, unit="rpm"))

        state = stats.get("engine_state")
        if state is not None:
            metrics.append(self.metric("engine_state", "Estado Motor", state))

        if not metrics:
            return None

        return {"severity": severity, "metrics": metrics, "findings": findings}

    def _analyze_faults(self, stats: Dict) -> Optional[Dict]:
        has_faults = stats.get("has_faults")
        if has_faults is None:
            return None

        is_faulty = bool(has_faults)
        severity = RiskLevel.HIGH if is_faulty else RiskLevel.LOW
        findings = []

        if is_faulty:
            findings.append(self.finding(
                title="Fallas diagnosticas detectadas",
                description="El vehiculo reporta codigos de falla activos (OBD-II/DTC). "
                            "Se recomienda revision mecanica.",
                severity=RiskLevel.HIGH,
                category="mantenimiento",
            ))

        return {
            "severity": severity,
            "metrics": [
                self.metric(
                    "faults", "Fallas", "Detectadas" if is_faulty else "Sin fallas",
                    severity=severity,
                ),
            ],
            "findings": findings,
        }

    def _compute_health_score(self, checks: List[Optional[Dict]]) -> float:
        """Calcula un score de salud 0-100 basado en los checks."""
        if not any(checks):
            return 100.0

        penalties = {
            RiskLevel.LOW: 0,
            RiskLevel.MEDIUM: 15,
            RiskLevel.HIGH: 30,
            RiskLevel.CRITICAL: 50,
        }

        total_penalty = sum(
            penalties.get(c["severity"], 0)
            for c in checks if c
        )

        return max(0.0, 100.0 - total_penalty)

    def _health_score_to_risk(self, score: float) -> RiskLevel:
        if score >= 80:
            return RiskLevel.LOW
        elif score >= 60:
            return RiskLevel.MEDIUM
        elif score >= 30:
            return RiskLevel.HIGH
        return RiskLevel.CRITICAL

    def _build_summary(self, name: str, score: float, findings: List) -> str:
        alert_count = len([f for f in findings if f.severity.value in ("high", "critical")])
        if alert_count == 0:
            return f"{name}: salud del vehiculo en buen estado ({round(score)}/100)."
        return (
            f"{name}: {alert_count} alerta(s) detectada(s), indice de salud {round(score)}/100."
        )
