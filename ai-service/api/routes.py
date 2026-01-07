"""
Definición de rutas de la API FastAPI.
Contiene los endpoints del servicio.
"""

import logging
from datetime import datetime
from typing import Optional

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

from services import PipelineExecutor, AlertResponseBuilder
from core import acquire_slot, get_concurrency_stats, ConcurrencyLimitExceeded
from .models import AlertRequest, HealthResponse

logger = logging.getLogger(__name__)


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
    
    El endpoint usa un semáforo para controlar la concurrencia y evitar
    sobrecargar el servicio con demasiadas peticiones simultáneas.
    
    Args:
        request: AlertRequest con event_id y payload de Samsara
        
    Returns:
        JSON con assessment y message para guardar en DB
        
    Raises:
        503: Si el servicio está a máxima capacidad
    """
    try:
        # Adquirir slot del semáforo antes de procesar
        async with acquire_slot():
            logger.info(f"Processing alert event_id={request.event_id}")
            
            executor = PipelineExecutor()
            result = await executor.execute(
                payload=request.payload,
                event_id=request.event_id,
                is_revalidation=False
            )
            
            return AlertResponseBuilder.build(result, event_id=request.event_id)
    
    except ConcurrencyLimitExceeded as e:
        logger.warning(f"Service at capacity: {e}")
        raise HTTPException(
            status_code=503,
            detail={
                "error": "service_at_capacity",
                "message": "AI service is at maximum capacity. Please retry later.",
                "stats": get_concurrency_stats(),
            }
        )
        
    except Exception as e:
        logger.error(f"Error processing alert event_id={request.event_id}: {e}")
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
    
    El endpoint usa un semáforo para controlar la concurrencia y evitar
    sobrecargar el servicio con demasiadas peticiones simultáneas.
    
    Args:
        request: RevalidateRequest con event_id, payload y context
        
    Returns:
        JSON con assessment actualizado y decisión de monitoreo
        
    Raises:
        503: Si el servicio está a máxima capacidad
    """
    try:
        # Adquirir slot del semáforo antes de procesar
        async with acquire_slot():
            logger.info(f"Revalidating alert event_id={request.event_id}")
            
            executor = PipelineExecutor()
            result = await executor.execute(
                payload=request.payload,
                event_id=request.event_id,
                is_revalidation=True,
                context=request.context
            )
            
            return AlertResponseBuilder.build(result, event_id=request.event_id)
    
    except ConcurrencyLimitExceeded as e:
        logger.warning(f"Service at capacity for revalidation: {e}")
        raise HTTPException(
            status_code=503,
            detail={
                "error": "service_at_capacity",
                "message": "AI service is at maximum capacity. Please retry later.",
                "stats": get_concurrency_stats(),
            }
        )
        
    except Exception as e:
        logger.error(f"Error revalidating alert event_id={request.event_id}: {e}")
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
    
    Incluye estadísticas de concurrencia para monitoreo:
    - max_concurrent: Límite máximo configurado
    - active_requests: Peticiones procesándose ahora
    - pending_requests: Peticiones esperando slot
    - available_slots: Slots disponibles
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
