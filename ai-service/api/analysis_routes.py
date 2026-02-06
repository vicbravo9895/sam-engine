"""
Rutas de la API para análisis on-demand de flota.
Endpoint: POST /analysis/on-demand
"""

import time

from fastapi import APIRouter, HTTPException

from core.structured_logging import get_logger, get_trace_id
from .analysis_models import AnalysisRequest, AnalysisResponse, RiskLevel
from services.fleet_analyzer import FleetAnalyzer

logger = get_logger(__name__)

# ============================================================================
# ROUTER
# ============================================================================
analysis_router = APIRouter(prefix="/analysis", tags=["analysis"])


# ============================================================================
# ENDPOINT: POST /analysis/on-demand
# ============================================================================
@analysis_router.post("/on-demand", response_model=AnalysisResponse)
async def run_on_demand_analysis(request: AnalysisRequest):
    """
    Ejecuta un análisis on-demand de datos de flota.

    Flujo:
    1. Recibe datos pre-obtenidos desde Laravel (safety_events, vehicle_stats, trips, etc.)
    2. Ejecuta análisis determinista (Python) según analysis_type
    3. Interpreta resultados con LLM (GPT-4o-mini)
    4. Retorna métricas, hallazgos e insights estructurados
    """
    start_time = time.time()
    trace_id = get_trace_id()

    logger.info("On-demand analysis requested", context={
        "analysis_type": request.analysis_type.value,
        "company_id": request.company_id,
        "parameters": request.parameters,
        "raw_data_keys": list(request.raw_data.keys()),
        "trace_id": trace_id,
    })

    try:
        analyzer = FleetAnalyzer()
        response = await analyzer.execute(
            analysis_type=request.analysis_type,
            company_id=request.company_id,
            parameters=request.parameters,
            raw_data=request.raw_data,
        )

        duration_ms = round((time.time() - start_time) * 1000, 2)

        logger.info("On-demand analysis completed", context={
            "analysis_type": request.analysis_type.value,
            "company_id": request.company_id,
            "status": response.status,
            "risk_level": response.risk_level.value if response.risk_level else "unknown",
            "metrics_count": len(response.metrics),
            "findings_count": len(response.findings),
            "duration_ms": duration_ms,
            "trace_id": trace_id,
        })

        return response

    except Exception as e:
        duration_ms = round((time.time() - start_time) * 1000, 2)

        logger.error("On-demand analysis failed", context={
            "analysis_type": request.analysis_type.value,
            "company_id": request.company_id,
            "error": str(e),
            "error_type": type(e).__name__,
            "duration_ms": duration_ms,
            "trace_id": trace_id,
        })

        return AnalysisResponse(
            status="error",
            analysis_type=request.analysis_type.value,
            title="Error en Analisis",
            summary=f"Error interno al procesar el analisis",
            risk_level=RiskLevel.LOW,
            error=str(e),
        )
