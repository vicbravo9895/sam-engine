"""
Definición de rutas de la API FastAPI.
Contiene los endpoints del servicio.
"""

from datetime import datetime
from typing import Optional

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

from services import PipelineExecutor, AlertResponseBuilder
from .models import AlertRequest, HealthResponse


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
    
    Args:
        request: AlertRequest con event_id y payload de Samsara
        
    Returns:
        JSON con assessment y message para guardar en DB
    """
    executor = PipelineExecutor()
    
    try:
        result = await executor.execute(
            payload=request.payload,
            event_id=request.event_id,
            is_revalidation=False
        )
        
        return AlertResponseBuilder.build(result, event_id=request.event_id)
        
    except Exception as e:
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
    
    Args:
        request: RevalidateRequest con event_id, payload y context
        
    Returns:
        JSON con assessment actualizado y decisión de monitoreo
    """
    executor = PipelineExecutor()
    
    try:
        result = await executor.execute(
            payload=request.payload,
            event_id=request.event_id,
            is_revalidation=True,
            context=request.context
        )
        
        return AlertResponseBuilder.build(result, event_id=request.event_id)
        
    except Exception as e:
        raise HTTPException(
            status_code=500,
            detail=AlertResponseBuilder.build_error(request.event_id, str(e))
        )


# ============================================================================
# ENDPOINT: GET /health
# ============================================================================
@router.get("/health", response_model=HealthResponse)
async def health_check():
    """Endpoint de salud del servicio."""
    return HealthResponse(
        status="healthy",
        service="samsara-alert-ai",
        timestamp=datetime.utcnow().isoformat()
    )
