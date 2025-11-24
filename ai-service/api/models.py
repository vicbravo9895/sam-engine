"""
Modelos de datos para la API.
Define los schemas de request/response usando Pydantic.
"""

from typing import Dict, Any, Optional
from pydantic import BaseModel, Field
from datetime import datetime


# ============================================================================
# REQUEST MODELS
# ============================================================================

class AlertRequest(BaseModel):
    """Request body para el endpoint de alertas."""
    
    event_id: int = Field(
        ...,
        description="ID del evento en la base de datos de Laravel"
    )
    
    payload: Dict[str, Any] = Field(
        ...,
        description="Payload completo de la alerta de Samsara"
    )


# ============================================================================
# RESPONSE MODELS
# ============================================================================

class HealthResponse(BaseModel):
    """Response del health check."""
    
    status: str = Field(..., description="Estado del servicio")
    service: str = Field(..., description="Nombre del servicio")
    timestamp: str = Field(..., description="Timestamp UTC")


class BreadcrumbItem(BaseModel):
    """Modelo de un breadcrumb individual en el stream SSE."""
    
    id: str = Field(..., description="UUID único del breadcrumb")
    order: int = Field(..., description="Número de orden en la secuencia")
    author: str = Field(..., description="Nombre del agente o 'system'")
    event_type: str = Field(..., description="Tipo de evento ADK")
    logical_step: str = Field(
        ..., 
        description="Paso lógico: ingestion | investigation | tool_call | tool_result | finalization | internal"
    )
    tool_name: Optional[str] = Field(None, description="Nombre del tool si aplica")
    tool_status: Optional[str] = Field(None, description="Estado del tool: started | finished")
    tool_input_preview: Optional[str] = Field(None, description="Preview del input del tool")
    tool_output_preview: Optional[str] = Field(None, description="Preview del output del tool")
    model_text: Optional[str] = Field(None, description="Texto generado por el modelo")
    mini_summary: str = Field(..., description="Resumen breve y legible del paso")
    is_final: bool = Field(False, description="Indica si es el último breadcrumb")
    timestamp: str = Field(..., description="Timestamp UTC del evento")
    error: Optional[str] = Field(None, description="Mensaje de error si aplica")
