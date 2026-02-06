"""
Módulos de análisis determinista para datos de flota.
Cada analizador procesa datos crudos de Samsara y produce métricas + hallazgos estructurados.
"""

from .driver_risk import DriverRiskAnalyzer
from .fleet_safety import FleetSafetyAnalyzer
from .vehicle_health import VehicleHealthAnalyzer
from .operational_efficiency import OperationalEfficiencyAnalyzer
from .anomaly_detection import AnomalyDetectionAnalyzer

__all__ = [
    "DriverRiskAnalyzer",
    "FleetSafetyAnalyzer",
    "VehicleHealthAnalyzer",
    "OperationalEfficiencyAnalyzer",
    "AnomalyDetectionAnalyzer",
]
