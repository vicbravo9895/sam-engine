"""
Definición de rutas de la API FastAPI.
Contiene los endpoints del servicio.
"""

import time
from datetime import datetime
from typing import Optional

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

from services import PipelineExecutor, AlertResponseBuilder
from core import acquire_slot, get_concurrency_stats, ConcurrencyLimitExceeded
from core.structured_logging import get_logger, get_trace_id, set_event_id, set_company_id
from .models import AlertRequest, HealthResponse

logger = get_logger(__name__)


# ============================================================================
# ROUTER
# ============================================================================
router = APIRouter()


# ============================================================================
# REQUEST MODELS (específicos de revalidation)
# ============================================================================
class RevalidateRequest(BaseModel):
    """Request body para el endpoint de revalidación."""
    
    event_id: int = Field(
        ...,
        description="ID del evento en la base de datos de Laravel"
    )
    
    payload: dict = Field(
        ...,
        description="Payload completo de la alerta de Samsara"
    )
    
    context: Optional[dict] = Field(
        default=None,
        description="Contexto temporal para revalidación (previous_assessment, investigation_history, etc.)"
    )


# ============================================================================
# ENDPOINT: POST /alerts/ingest
# ============================================================================
@router.post("/alerts/ingest")
async def ingest_alert(request: AlertRequest):
    """
    Procesa una alerta de Samsara de forma síncrona.
    
    Este endpoint es llamado por el Job de Laravel (ProcessSamsaraEventJob).
    Ejecuta el pipeline de agentes y retorna los resultados para que
    Laravel los guarde en la base de datos.
    """
    start_time = time.time()
    trace_id = get_trace_id()
    
    # Registrar contexto multi-tenant para todos los logs de esta request
    set_event_id(request.event_id)
    company_id = _extract_company_id(request.payload) if request.payload else None
    set_company_id(company_id)
    
    logger.info("Alert ingest started", context={
        "payload_keys": list(request.payload.keys()) if request.payload else [],
        "has_preloaded_data": "preloaded_data" in request.payload if request.payload else False,
        "company_id": company_id,
    })
    
    try:
        # Adquirir slot del semáforo antes de procesar
        async with acquire_slot():
            logger.debug("Slot acquired, executing pipeline", context={
                "event_id": request.event_id,
            })
            
            executor = PipelineExecutor()
            result = await executor.execute(
                payload=request.payload,
                event_id=request.event_id,
                is_revalidation=False
            )
            
            duration_ms = round((time.time() - start_time) * 1000, 2)
            
            logger.info("Alert ingest completed", context={
                "event_id": request.event_id,
                "duration_ms": duration_ms,
                "verdict": result.get("assessment", {}).get("verdict", "unknown"),
                "risk_escalation": result.get("assessment", {}).get("risk_escalation", "unknown"),
                "requires_monitoring": result.get("assessment", {}).get("requires_monitoring", False),
            })
            
            return AlertResponseBuilder.build(result, event_id=request.event_id)
    
    except ConcurrencyLimitExceeded as e:
        duration_ms = round((time.time() - start_time) * 1000, 2)
        logger.warning("Alert ingest rejected: Service at capacity", context={
            "event_id": request.event_id,
            "duration_ms": duration_ms,
            "stats": get_concurrency_stats(),
        })
        raise HTTPException(
            status_code=503,
            detail={
                "error": "service_at_capacity",
                "message": "AI service is at maximum capacity. Please retry later.",
                "stats": get_concurrency_stats(),
                "trace_id": trace_id,
            }
        )
        
    except Exception as e:
        duration_ms = round((time.time() - start_time) * 1000, 2)
        logger.error("Alert ingest failed", context={
            "event_id": request.event_id,
            "duration_ms": duration_ms,
            "error": str(e),
            "error_type": type(e).__name__,
        })
        raise HTTPException(
            status_code=500,
            detail=AlertResponseBuilder.build_error(request.event_id, str(e))
        )


# ============================================================================
# ENDPOINT: POST /alerts/revalidate
# ============================================================================
@router.post("/alerts/revalidate")
async def revalidate_alert(request: RevalidateRequest):
    """
    Revalida una alerta existente con contexto temporal adicional.
    
    Este endpoint es llamado por RevalidateSamsaraEventJob para
    reanalizar eventos que requieren monitoreo continuo.
    """
    start_time = time.time()
    trace_id = get_trace_id()
    
    # Registrar contexto multi-tenant para todos los logs de esta request
    set_event_id(request.event_id)
    company_id = _extract_company_id(request.payload) if request.payload else None
    set_company_id(company_id)
    
    investigation_count = request.context.get("investigation_count", 0) if request.context else 0
    
    logger.info("Alert revalidation started", context={
        "investigation_count": investigation_count,
        "has_context": request.context is not None,
        "previous_verdict": request.context.get("previous_assessment", {}).get("verdict") if request.context else None,
        "company_id": company_id,
    })
    
    try:
        # Adquirir slot del semáforo antes de procesar
        async with acquire_slot():
            logger.debug("Slot acquired for revalidation", context={
                "event_id": request.event_id,
            })
            
            executor = PipelineExecutor()
            result = await executor.execute(
                payload=request.payload,
                event_id=request.event_id,
                is_revalidation=True,
                context=request.context
            )
            
            duration_ms = round((time.time() - start_time) * 1000, 2)
            
            logger.info("Alert revalidation completed", context={
                "event_id": request.event_id,
                "duration_ms": duration_ms,
                "investigation_count": investigation_count,
                "verdict": result.get("assessment", {}).get("verdict", "unknown"),
                "risk_escalation": result.get("assessment", {}).get("risk_escalation", "unknown"),
                "requires_monitoring": result.get("assessment", {}).get("requires_monitoring", False),
                "next_check_minutes": result.get("assessment", {}).get("next_check_minutes"),
            })
            
            return AlertResponseBuilder.build(result, event_id=request.event_id)
    
    except ConcurrencyLimitExceeded as e:
        duration_ms = round((time.time() - start_time) * 1000, 2)
        logger.warning("Alert revalidation rejected: Service at capacity", context={
            "event_id": request.event_id,
            "duration_ms": duration_ms,
            "stats": get_concurrency_stats(),
        })
        raise HTTPException(
            status_code=503,
            detail={
                "error": "service_at_capacity",
                "message": "AI service is at maximum capacity. Please retry later.",
                "stats": get_concurrency_stats(),
                "trace_id": trace_id,
            }
        )
        
    except Exception as e:
        duration_ms = round((time.time() - start_time) * 1000, 2)
        logger.error("Alert revalidation failed", context={
            "event_id": request.event_id,
            "duration_ms": duration_ms,
            "error": str(e),
            "error_type": type(e).__name__,
        })
        raise HTTPException(
            status_code=500,
            detail=AlertResponseBuilder.build_error(request.event_id, str(e))
        )


# ============================================================================
# ENDPOINT: GET /health
# ============================================================================
@router.get("/health")
async def health_check():
    """
    Endpoint de salud del servicio.
    
    Incluye estadísticas de concurrencia para monitoreo.
    """
    stats = get_concurrency_stats()
    
    # Determinar estado basado en capacidad
    if stats["available_slots"] == 0:
        status = "degraded"  # A máxima capacidad
    elif stats["available_slots"] <= stats["max_concurrent"] * 0.2:
        status = "busy"  # Más del 80% de capacidad
    else:
        status = "healthy"
    
    return {
        "status": status,
        "service": "samsara-alert-ai",
        "timestamp": datetime.utcnow().isoformat(),
        "concurrency": stats,
    }


# ============================================================================
# ENDPOINT: GET /stats
# ============================================================================
@router.get("/stats")
async def get_stats():
    """
    Endpoint de estadísticas detalladas del servicio.
    """
    stats = get_concurrency_stats()
    
    return {
        "service": "samsara-alert-ai",
        "timestamp": datetime.utcnow().isoformat(),
        "concurrency": stats,
        "trace_id": get_trace_id(),
    }


# ============================================================================
# HELPER FUNCTIONS
# ============================================================================
def _extract_company_id(payload: dict) -> Optional[int]:
    """
    Extrae el company_id del payload si está disponible.
    
    El company_id puede estar en diferentes lugares del payload:
    - Directamente en el payload (agregado por Laravel)
    - En preloaded_data
    """
    if not payload:
        return None
    
    # Intentar obtener directamente
    company_id = payload.get("company_id")
    if company_id:
        return int(company_id)
    
    # Intentar desde preloaded_data
    preloaded = payload.get("preloaded_data", {})
    company_id = preloaded.get("company_id")
    if company_id:
        return int(company_id)
    
    return None
