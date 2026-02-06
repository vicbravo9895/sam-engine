"""
Clase base para todos los analizadores de flota.
Define la interfaz común y utilidades compartidas.
"""

from abc import ABC, abstractmethod
from typing import Any, Dict, List, Optional
from datetime import datetime, timezone

from api.analysis_models import (
    AnalysisFinding,
    AnalysisMetric,
    RiskLevel,
    TrendDirection,
)


class BaseAnalyzer(ABC):
    """Clase base para analizadores deterministas de datos de flota."""

    @abstractmethod
    def analyze(
        self,
        raw_data: Dict[str, Any],
        parameters: Dict[str, Any],
    ) -> Dict[str, Any]:
        """
        Ejecuta el análisis determinista.

        Args:
            raw_data: Datos crudos de Samsara pre-obtenidos.
            parameters: Parámetros del análisis (days_back, vehicle_ids, etc.)

        Returns:
            Dict con: title, summary, metrics, findings, risk_level, data_window, methodology
        """
        ...

    # =========================================================================
    # UTILIDADES COMPARTIDAS
    # =========================================================================

    @staticmethod
    def metric(
        key: str,
        label: str,
        value: Any,
        unit: Optional[str] = None,
        trend: Optional[TrendDirection] = None,
        trend_value: Optional[str] = None,
        severity: Optional[RiskLevel] = None,
    ) -> AnalysisMetric:
        """Construye un AnalysisMetric de forma concisa."""
        return AnalysisMetric(
            key=key,
            label=label,
            value=value,
            unit=unit,
            trend=trend,
            trend_value=trend_value,
            severity=severity,
        )

    @staticmethod
    def finding(
        title: str,
        description: str,
        severity: RiskLevel,
        category: str,
        evidence: Optional[List[str]] = None,
    ) -> AnalysisFinding:
        """Construye un AnalysisFinding de forma concisa."""
        return AnalysisFinding(
            title=title,
            description=description,
            severity=severity,
            category=category,
            evidence=evidence,
        )

    @staticmethod
    def compute_risk_level(score: float) -> RiskLevel:
        """Convierte un score numérico (0-100) a un nivel de riesgo."""
        if score >= 75:
            return RiskLevel.CRITICAL
        elif score >= 50:
            return RiskLevel.HIGH
        elif score >= 25:
            return RiskLevel.MEDIUM
        return RiskLevel.LOW

    @staticmethod
    def safe_div(numerator: float, denominator: float, default: float = 0.0) -> float:
        """División segura que evita division by zero."""
        if denominator == 0:
            return default
        return numerator / denominator

    @staticmethod
    def parse_iso_timestamp(ts: Optional[str]) -> Optional[datetime]:
        """Parsea un timestamp ISO 8601 de forma segura."""
        if not ts:
            return None
        try:
            # Handle various ISO formats
            ts = ts.replace("Z", "+00:00")
            return datetime.fromisoformat(ts)
        except (ValueError, TypeError):
            return None

    @staticmethod
    def get_hour_from_timestamp(ts: Optional[str]) -> Optional[int]:
        """Obtiene la hora (0-23) de un timestamp ISO."""
        dt = BaseAnalyzer.parse_iso_timestamp(ts)
        if dt:
            return dt.hour
        return None

    @staticmethod
    def build_data_window(parameters: Dict[str, Any]) -> Dict[str, Any]:
        """Construye la ventana temporal del análisis."""
        days_back = parameters.get("days_back", 7)
        now = datetime.now(timezone.utc)
        return {
            "days_back": days_back,
            "end": now.isoformat(),
            "description": f"Ultimos {days_back} dias",
        }
