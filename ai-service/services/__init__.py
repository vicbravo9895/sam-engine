"""
Servicios de negocio para el procesamiento de alertas.
Encapsula la lógica de ejecución del pipeline ADK y construcción de respuestas.
"""

from .pipeline_executor import PipelineExecutor, PipelineResult
from .response_builder import AlertResponseBuilder

__all__ = [
    "PipelineExecutor",
    "PipelineResult",
    "AlertResponseBuilder",
]
