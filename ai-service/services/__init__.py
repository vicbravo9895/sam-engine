"""
Servicios de negocio para el procesamiento de alertas.
Encapsula la lógica de ejecución del pipeline ADK y construcción de respuestas.

ACTUALIZADO: Nuevo contrato con NotificationExecutor.
"""

from .pipeline_executor import PipelineExecutor
from .response_builder import AlertResponseBuilder
from .notification_executor import NotificationExecutor, execute_notifications

# Re-export PipelineResult desde schemas para compatibilidad
from agents.schemas import PipelineResult

__all__ = [
    "PipelineExecutor",
    "PipelineResult",
    "AlertResponseBuilder",
    "NotificationExecutor",
    "execute_notifications",
]

