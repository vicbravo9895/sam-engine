"""
Servicios de negocio para el procesamiento de alertas.
Encapsula la lógica de ejecución del pipeline ADK y construcción de respuestas.
"""

from .pipeline_executor import PipelineExecutor
from .response_builder import AlertResponseBuilder

# Re-export PipelineResult desde schemas para compatibilidad
from agents.schemas import PipelineResult

__all__ = [
    "PipelineExecutor",
    "PipelineResult",
    "AlertResponseBuilder",
]
