"""
Módulo core del servicio.
Contiene la lógica central de runtime y procesamiento.
"""

from .runtime import runner, revalidation_runner, session_service
from .concurrency import (
    acquire_slot,
    get_concurrency_stats,
    ConcurrencyLimitExceeded,
)
from .structured_logging import (
    setup_logging,
    get_logger,
    get_trace_id,
    set_trace_id,
    get_event_id,
    set_event_id,
    get_company_id,
    set_company_id,
    set_request_context,
)

__all__ = [
    "runner",
    "revalidation_runner",
    "session_service",
    "acquire_slot",
    "get_concurrency_stats",
    "ConcurrencyLimitExceeded",
    "setup_logging",
    "get_logger",
    "get_trace_id",
    "set_trace_id",
    "get_event_id",
    "set_event_id",
    "get_company_id",
    "set_company_id",
    "set_request_context",
]
