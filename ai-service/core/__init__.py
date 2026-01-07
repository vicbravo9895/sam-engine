"""
Módulo core del servicio.
Contiene la lógica central de runtime y procesamiento.
"""

from .runtime import runner, session_service
from .concurrency import (
    acquire_slot,
    get_concurrency_stats,
    ConcurrencyLimitExceeded,
)

__all__ = [
    "runner",
    "session_service",
    "acquire_slot",
    "get_concurrency_stats",
    "ConcurrencyLimitExceeded",
]
