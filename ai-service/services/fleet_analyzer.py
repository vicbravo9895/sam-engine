"""
FleetAnalyzer: Orquestador principal de análisis on-demand.
Selecciona el analizador correcto, ejecuta el análisis determinista,
luego pasa los resultados al intérprete LLM y compone la respuesta final.
"""

import logging
import time
from typing import Any, Dict

from api.analysis_models import (
    AnalysisFinding,
    AnalysisMetric,
    AnalysisResponse,
    AnalysisType,
    RiskLevel,
)
from config import langfuse_client
from .analyzers import (
    AnomalyDetectionAnalyzer,
    DriverRiskAnalyzer,
    FleetSafetyAnalyzer,
    OperationalEfficiencyAnalyzer,
    VehicleHealthAnalyzer,
)
from .analysis_interpreter import interpret_analysis

logger = logging.getLogger(__name__)

# Mapa de tipo de análisis a analizador
ANALYZER_MAP = {
    AnalysisType.DRIVER_RISK_PROFILE: DriverRiskAnalyzer,
    AnalysisType.FLEET_SAFETY_OVERVIEW: FleetSafetyAnalyzer,
    AnalysisType.VEHICLE_HEALTH: VehicleHealthAnalyzer,
    AnalysisType.OPERATIONAL_EFFICIENCY: OperationalEfficiencyAnalyzer,
    AnalysisType.ANOMALY_DETECTION: AnomalyDetectionAnalyzer,
}


class FleetAnalyzer:
    """
    Orquestador de análisis de flota.

    1. Selecciona el analizador determinista según analysis_type
    2. Ejecuta el análisis con datos crudos
    3. Pasa resultados al LLM para interpretación ejecutiva/operativa
    4. Compone la respuesta final
    """

    async def execute(
        self,
        analysis_type: AnalysisType,
        company_id: int,
        parameters: Dict[str, Any],
        raw_data: Dict[str, Any],
    ) -> AnalysisResponse:
        """
        Ejecuta un análisis on-demand completo.

        Args:
            analysis_type: Tipo de análisis a ejecutar
            company_id: ID de la empresa
            parameters: Parámetros del análisis
            raw_data: Datos crudos pre-obtenidos desde Samsara

        Returns:
            AnalysisResponse con métricas, hallazgos e insights
        """
        start_time = time.time()
        trace = None

        try:
            # Crear trace de Langfuse
            if langfuse_client:
                trace = langfuse_client.trace(
                    name="fleet_analysis_on_demand",
                    metadata={
                        "analysis_type": analysis_type.value,
                        "company_id": company_id,
                        "parameters": parameters,
                    },
                    tags=["analysis", "on_demand", analysis_type.value],
                )

            # ===================================================================
            # PASO 1: Análisis determinista
            # ===================================================================
            logger.info(f"Starting deterministic analysis", extra={
                "context": {
                    "analysis_type": analysis_type.value,
                    "company_id": company_id,
                }
            })

            analyzer_class = ANALYZER_MAP.get(analysis_type)
            if not analyzer_class:
                return AnalysisResponse(
                    status="error",
                    analysis_type=analysis_type.value,
                    title="Error",
                    summary="Tipo de analisis no soportado",
                    risk_level=RiskLevel.LOW,
                    error=f"Tipo de analisis no soportado: {analysis_type.value}",
                )

            analyzer = analyzer_class()
            deterministic_result = analyzer.analyze(raw_data, parameters)

            det_duration = round((time.time() - start_time) * 1000, 2)
            logger.info(f"Deterministic analysis completed in {det_duration}ms", extra={
                "context": {
                    "analysis_type": analysis_type.value,
                    "metrics_count": len(deterministic_result.get("metrics", [])),
                    "findings_count": len(deterministic_result.get("findings", [])),
                    "risk_level": deterministic_result.get("risk_level", "unknown"),
                }
            })

            if trace:
                trace.span(
                    name="deterministic_analysis",
                    input={"parameters": parameters, "data_keys": list(raw_data.keys())},
                    output={
                        "metrics_count": len(deterministic_result.get("metrics", [])),
                        "findings_count": len(deterministic_result.get("findings", [])),
                        "risk_level": str(deterministic_result.get("risk_level", "")),
                    },
                    metadata={"duration_ms": det_duration},
                ).end()

            # ===================================================================
            # PASO 2: Interpretación LLM
            # ===================================================================
            llm_start = time.time()

            # Serializar métricas y hallazgos para el LLM
            metrics_dicts = [
                m.model_dump() if hasattr(m, "model_dump") else m
                for m in deterministic_result.get("metrics", [])
            ]
            findings_dicts = [
                f.model_dump() if hasattr(f, "model_dump") else f
                for f in deterministic_result.get("findings", [])
            ]

            risk_level = deterministic_result.get("risk_level", RiskLevel.LOW)
            risk_str = risk_level.value if hasattr(risk_level, "value") else str(risk_level)

            interpretation = await interpret_analysis(
                analysis_type=analysis_type.value,
                title=deterministic_result.get("title", "Analisis"),
                summary=deterministic_result.get("summary", ""),
                metrics=metrics_dicts,
                findings=findings_dicts,
                risk_level=risk_str,
                analysis_detail=deterministic_result.get("_analysis_detail", {}),
                data_window=deterministic_result.get("data_window", {}),
            )

            llm_duration = round((time.time() - llm_start) * 1000, 2)
            logger.info(f"LLM interpretation completed in {llm_duration}ms", extra={
                "context": {
                    "analysis_type": analysis_type.value,
                    "insights_length": len(interpretation.get("insights", "")),
                    "recommendations_count": len(interpretation.get("recommendations", [])),
                }
            })

            if trace:
                trace.span(
                    name="llm_interpretation",
                    input={"metrics_count": len(metrics_dicts), "findings_count": len(findings_dicts)},
                    output={
                        "insights_length": len(interpretation.get("insights", "")),
                        "recommendations_count": len(interpretation.get("recommendations", [])),
                    },
                    metadata={"duration_ms": llm_duration},
                ).end()

            # ===================================================================
            # PASO 3: Componer respuesta final
            # ===================================================================
            total_duration = round((time.time() - start_time) * 1000, 2)

            # Merge recommendations from deterministic + LLM
            det_recommendations = []
            for f in deterministic_result.get("findings", []):
                if hasattr(f, "description"):
                    sev = f.severity.value if hasattr(f.severity, "value") else f.severity
                else:
                    sev = f.get("severity", "low")
                if sev in ("high", "critical"):
                    desc = f.description if hasattr(f, "description") else f.get("description", "")
                    if desc:
                        det_recommendations.append(desc)

            llm_recommendations = interpretation.get("recommendations", [])
            # LLM recommendations first, then deterministic ones as fallback
            all_recommendations = llm_recommendations or det_recommendations

            response = AnalysisResponse(
                status="success",
                analysis_type=analysis_type.value,
                title=deterministic_result.get("title", "Analisis"),
                summary=deterministic_result.get("summary", ""),
                metrics=deterministic_result.get("metrics", []),
                findings=deterministic_result.get("findings", []),
                insights=interpretation.get("insights", ""),
                recommendations=all_recommendations[:6],
                risk_level=deterministic_result.get("risk_level", RiskLevel.LOW),
                data_window=deterministic_result.get("data_window", {}),
                methodology=deterministic_result.get("methodology", ""),
            )

            if trace:
                trace.update(output={
                    "status": "success",
                    "risk_level": risk_str,
                    "total_duration_ms": total_duration,
                })
                langfuse_client.flush()

            logger.info(f"Fleet analysis completed in {total_duration}ms", extra={
                "context": {
                    "analysis_type": analysis_type.value,
                    "company_id": company_id,
                    "total_duration_ms": total_duration,
                    "det_duration_ms": det_duration,
                    "llm_duration_ms": llm_duration,
                }
            })

            return response

        except Exception as e:
            total_duration = round((time.time() - start_time) * 1000, 2)
            logger.error(f"Fleet analysis failed: {e}", extra={
                "context": {
                    "analysis_type": analysis_type.value,
                    "company_id": company_id,
                    "error": str(e),
                    "error_type": type(e).__name__,
                    "duration_ms": total_duration,
                }
            })

            if trace:
                trace.update(
                    level="ERROR",
                    status_message=str(e),
                )
                langfuse_client.flush()

            return AnalysisResponse(
                status="error",
                analysis_type=analysis_type.value,
                title="Error en Analisis",
                summary=f"Error al ejecutar el analisis: {str(e)}",
                risk_level=RiskLevel.LOW,
                error=str(e),
            )
