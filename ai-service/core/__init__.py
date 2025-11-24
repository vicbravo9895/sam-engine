"""
Módulo core del servicio.
Contiene la lógica central de runtime y procesamiento.
"""

from .runtime import runner, session_service

__all__ = [
    "runner",
    "session_service"
]
