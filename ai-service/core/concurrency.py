"""
Control de concurrencia para el servicio AI.

Este módulo implementa un semáforo asyncio para limitar el número de
peticiones que se procesan simultáneamente, protegiendo contra:
- Sobrecarga de memoria
- Exceso de rate limits de OpenAI
- Timeouts en cascada

El semáforo es más ligero que una cola completa y mantiene el servicio stateless.
"""

import asyncio
import logging
from contextlib import asynccontextmanager
from typing import Optional

from config import ConcurrencyConfig

logger = logging.getLogger(__name__)


# ============================================================================
# SEMÁFORO GLOBAL
# ============================================================================
_semaphore: Optional[asyncio.Semaphore] = None
_pending_requests: int = 0
_active_requests: int = 0


def get_semaphore() -> asyncio.Semaphore:
    """Obtiene o crea el semáforo global de concurrencia."""
    global _semaphore
    if _semaphore is None:
        _semaphore = asyncio.Semaphore(ConcurrencyConfig.MAX_CONCURRENT_REQUESTS)
        logger.info(
            f"Concurrency semaphore initialized with limit: "
            f"{ConcurrencyConfig.MAX_CONCURRENT_REQUESTS}"
        )
    return _semaphore


# ============================================================================
# CONTEXT MANAGER PARA PETICIONES
# ============================================================================
@asynccontextmanager
async def acquire_slot(timeout: Optional[float] = None):
    """
    Context manager que adquiere un slot del semáforo.
    
    Args:
        timeout: Tiempo máximo de espera en segundos. Si es None,
                 usa SEMAPHORE_TIMEOUT de la configuración.
    
    Raises:
        ConcurrencyLimitExceeded: Si no se puede adquirir el slot en el timeout.
    
    Usage:
        async with acquire_slot():
            # Procesar petición
            result = await process_expensive_operation()
    """
    global _pending_requests, _active_requests
    
    if not ConcurrencyConfig.RATE_LIMITING_ENABLED:
        yield
        return
    
    timeout = timeout or ConcurrencyConfig.SEMAPHORE_TIMEOUT
    semaphore = get_semaphore()
    
    _pending_requests += 1
    
    try:
        # Intentar adquirir el semáforo con timeout
        acquired = await asyncio.wait_for(
            semaphore.acquire(),
            timeout=timeout
        )
        
        if not acquired:
            raise ConcurrencyLimitExceeded(
                f"Could not acquire slot after {timeout}s. "
                f"Active: {_active_requests}, Pending: {_pending_requests}"
            )
        
        _pending_requests -= 1
        _active_requests += 1
        
        logger.debug(
            f"Slot acquired. Active: {_active_requests}/{ConcurrencyConfig.MAX_CONCURRENT_REQUESTS}, "
            f"Pending: {_pending_requests}"
        )
        
        try:
            yield
        finally:
            _active_requests -= 1
            semaphore.release()
            logger.debug(
                f"Slot released. Active: {_active_requests}/{ConcurrencyConfig.MAX_CONCURRENT_REQUESTS}"
            )
            
    except asyncio.TimeoutError:
        _pending_requests -= 1
        raise ConcurrencyLimitExceeded(
            f"Timeout waiting for slot after {timeout}s. "
            f"Service is at capacity. Active: {_active_requests}, Pending: {_pending_requests}"
        )


# ============================================================================
# ESTADÍSTICAS
# ============================================================================
def get_concurrency_stats() -> dict:
    """
    Retorna estadísticas de concurrencia actuales.
    
    Returns:
        dict con:
        - max_concurrent: Límite máximo configurado
        - active_requests: Peticiones procesándose ahora
        - pending_requests: Peticiones esperando slot
        - available_slots: Slots disponibles
        - rate_limiting_enabled: Si el rate limiting está activo
    """
    max_concurrent = ConcurrencyConfig.MAX_CONCURRENT_REQUESTS
    return {
        "max_concurrent": max_concurrent,
        "active_requests": _active_requests,
        "pending_requests": _pending_requests,
        "available_slots": max(0, max_concurrent - _active_requests),
        "rate_limiting_enabled": ConcurrencyConfig.RATE_LIMITING_ENABLED,
    }


# ============================================================================
# EXCEPCIONES
# ============================================================================
class ConcurrencyLimitExceeded(Exception):
    """
    Excepción lanzada cuando el servicio está a máxima capacidad
    y no puede aceptar más peticiones.
    """
    pass

