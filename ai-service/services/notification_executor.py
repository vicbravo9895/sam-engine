"""
NotificationExecutor: Servicio que ejecuta notificaciones de forma determinista.

Este servicio:
- Recibe la decisión del notification_decision_agent
- Aplica idempotencia por dedupe_key (usando Redis para persistencia)
- Aplica throttling por vehicle_id/driver_id (usando Redis para persistencia)
- Ejecuta las notificaciones en orden: call > whatsapp > sms
- Retorna notification_execution con resultados

IMPORTANTE: Este código ejecuta los side effects, no el LLM.

ACTUALIZADO: Ahora usa Redis para dedupe/throttle persistente.
"""

import asyncio
import hashlib
import os
from datetime import datetime, timedelta
from typing import Dict, List, Optional, Any

import redis
from structlog import get_logger

from tools.twilio_tools import send_sms, send_whatsapp, make_call_simple, make_call_with_callback
from agents.schemas import NotificationDecision, NotificationExecution, NotificationResult

logger = get_logger(__name__)

# ============================================================================
# REDIS CONNECTION
# ============================================================================
# Redis connection for persistent dedupe/throttle storage
_redis_client: Optional[redis.Redis] = None

def _get_redis() -> Optional[redis.Redis]:
    """Get Redis client (lazy initialization)."""
    global _redis_client
    
    if _redis_client is None:
        redis_url = os.getenv("REDIS_URL", "redis://localhost:6379/0")
        try:
            _redis_client = redis.from_url(redis_url, decode_responses=True)
            # Test connection
            _redis_client.ping()
            logger.info("notification_executor.redis_connected", url=redis_url)
        except redis.RedisError as e:
            logger.warning("notification_executor.redis_connection_failed", error=str(e))
            _redis_client = None
    
    return _redis_client


# ============================================================================
# FALLBACK IN-MEMORY STORES (used when Redis is not available)
# ============================================================================
_dedupe_store: Dict[str, datetime] = {}
_throttle_store: Dict[str, List[datetime]] = {}

# Configuración
DEDUPE_TTL_HOURS = 24
THROTTLE_WINDOW_MINUTES = 30
THROTTLE_MAX_NOTIFICATIONS = 5

# Redis key prefixes
REDIS_DEDUPE_PREFIX = "notification:dedupe:"
REDIS_THROTTLE_PREFIX = "notification:throttle:"


# ============================================================================
# IDEMPOTENCIA (Redis-backed with in-memory fallback)
# ============================================================================
def _check_dedupe(dedupe_key: str) -> bool:
    """
    Verifica si ya se procesó este dedupe_key.
    Uses Redis if available, falls back to in-memory.
    
    Returns:
        True si ya existe (duplicado), False si es nuevo
    """
    if not dedupe_key:
        return False
    
    redis_client = _get_redis()
    
    if redis_client:
        return _check_dedupe_redis(dedupe_key, redis_client)
    else:
        return _check_dedupe_memory(dedupe_key)


def _check_dedupe_redis(dedupe_key: str, redis_client: redis.Redis) -> bool:
    """Check dedupe using Redis."""
    redis_key = f"{REDIS_DEDUPE_PREFIX}{dedupe_key}"
    
    try:
        # Check if exists
        if redis_client.exists(redis_key):
            # Update last_seen and increment count
            redis_client.hset(redis_key, "last_seen_at", datetime.utcnow().isoformat())
            redis_client.hincrby(redis_key, "count", 1)
            logger.debug("notification_executor.dedupe_hit", dedupe_key=dedupe_key)
            return True
        
        # Create new entry
        redis_client.hset(redis_key, mapping={
            "first_seen_at": datetime.utcnow().isoformat(),
            "last_seen_at": datetime.utcnow().isoformat(),
            "count": 1
        })
        redis_client.expire(redis_key, DEDUPE_TTL_HOURS * 3600)
        logger.debug("notification_executor.dedupe_created", dedupe_key=dedupe_key)
        return False
        
    except redis.RedisError as e:
        logger.warning("notification_executor.redis_dedupe_error", error=str(e), dedupe_key=dedupe_key)
        # Fall back to in-memory
        return _check_dedupe_memory(dedupe_key)


def _check_dedupe_memory(dedupe_key: str) -> bool:
    """Check dedupe using in-memory store (fallback)."""
    now = datetime.utcnow()
    
    # Limpiar entradas expiradas
    expired_keys = [
        k for k, v in _dedupe_store.items()
        if now - v > timedelta(hours=DEDUPE_TTL_HOURS)
    ]
    for k in expired_keys:
        del _dedupe_store[k]
    
    # Verificar si existe
    if dedupe_key in _dedupe_store:
        return True
    
    # Marcar como procesado
    _dedupe_store[dedupe_key] = now
    return False


def _generate_throttle_key(vehicle_id: Optional[str], driver_id: Optional[str]) -> str:
    """Genera clave para throttling."""
    parts = []
    if vehicle_id:
        parts.append(f"v:{vehicle_id}")
    if driver_id:
        parts.append(f"d:{driver_id}")
    
    if not parts:
        return "global"
    
    return ":".join(parts)


# ============================================================================
# THROTTLING (Redis-backed with in-memory fallback)
# ============================================================================
def _check_throttle(throttle_key: str) -> tuple[bool, Optional[str]]:
    """
    Verifica si se debe aplicar throttling.
    Uses Redis if available, falls back to in-memory.
    
    Returns:
        (should_throttle, reason)
    """
    redis_client = _get_redis()
    
    if redis_client:
        return _check_throttle_redis(throttle_key, redis_client)
    else:
        return _check_throttle_memory(throttle_key)


def _check_throttle_redis(throttle_key: str, redis_client: redis.Redis) -> tuple[bool, Optional[str]]:
    """Check throttle using Redis sorted set."""
    redis_key = f"{REDIS_THROTTLE_PREFIX}{throttle_key}"
    now = datetime.utcnow()
    now_ts = now.timestamp()
    window_start_ts = (now - timedelta(minutes=THROTTLE_WINDOW_MINUTES)).timestamp()
    
    try:
        # Remove old entries outside the window
        redis_client.zremrangebyscore(redis_key, "-inf", window_start_ts)
        
        # Count entries in window
        count = redis_client.zcard(redis_key)
        
        if count >= THROTTLE_MAX_NOTIFICATIONS:
            reason = f"Límite de {THROTTLE_MAX_NOTIFICATIONS} notificaciones en {THROTTLE_WINDOW_MINUTES} minutos alcanzado"
            logger.debug("notification_executor.throttle_hit", throttle_key=throttle_key, count=count)
            return True, reason
        
        # Add current timestamp
        redis_client.zadd(redis_key, {str(now_ts): now_ts})
        redis_client.expire(redis_key, THROTTLE_WINDOW_MINUTES * 60 * 2)  # 2x window for safety
        
        return False, None
        
    except redis.RedisError as e:
        logger.warning("notification_executor.redis_throttle_error", error=str(e), throttle_key=throttle_key)
        # Fall back to in-memory
        return _check_throttle_memory(throttle_key)


def _check_throttle_memory(throttle_key: str) -> tuple[bool, Optional[str]]:
    """Check throttle using in-memory store (fallback)."""
    now = datetime.utcnow()
    window_start = now - timedelta(minutes=THROTTLE_WINDOW_MINUTES)
    
    # Limpiar entradas antiguas
    if throttle_key in _throttle_store:
        _throttle_store[throttle_key] = [
            ts for ts in _throttle_store[throttle_key]
            if ts > window_start
        ]
    else:
        _throttle_store[throttle_key] = []
    
    # Contar en ventana
    count = len(_throttle_store[throttle_key])
    
    if count >= THROTTLE_MAX_NOTIFICATIONS:
        return True, f"Límite de {THROTTLE_MAX_NOTIFICATIONS} notificaciones en {THROTTLE_WINDOW_MINUTES} minutos alcanzado"
    
    # Registrar nuevo
    _throttle_store[throttle_key].append(now)
    return False, None


# ============================================================================
# EXECUTOR
# ============================================================================
class NotificationExecutor:
    """
    Ejecuta notificaciones de forma determinista con idempotencia y throttling.
    """
    
    def __init__(self, event_id: int, vehicle_id: Optional[str] = None, driver_id: Optional[str] = None):
        self.event_id = event_id
        self.vehicle_id = vehicle_id
        self.driver_id = driver_id
    
    async def execute(self, decision: Dict[str, Any]) -> Dict[str, Any]:
        """
        Ejecuta las notificaciones según la decisión.
        
        Args:
            decision: Dict con la decisión del agente (NotificationDecision)
            
        Returns:
            Dict con los resultados (NotificationExecution)
        """
        now = datetime.utcnow()
        
        # Si no debe notificar, retornar sin hacer nada
        if not decision.get("should_notify", False):
            return {
                "attempted": False,
                "results": [],
                "timestamp_utc": now.isoformat() + "Z",
                "dedupe_key": decision.get("dedupe_key", ""),
                "throttled": False,
                "throttle_reason": None
            }
        
        dedupe_key = decision.get("dedupe_key", "")
        
        # Verificar idempotencia
        if _check_dedupe(dedupe_key):
            return {
                "attempted": False,
                "results": [],
                "timestamp_utc": now.isoformat() + "Z",
                "dedupe_key": dedupe_key,
                "throttled": False,
                "throttle_reason": "Notificación duplicada (dedupe_key ya procesado)"
            }
        
        # Verificar throttling
        throttle_key = _generate_throttle_key(self.vehicle_id, self.driver_id)
        should_throttle, throttle_reason = _check_throttle(throttle_key)
        
        if should_throttle:
            return {
                "attempted": False,
                "results": [],
                "timestamp_utc": now.isoformat() + "Z",
                "dedupe_key": dedupe_key,
                "throttled": True,
                "throttle_reason": throttle_reason
            }
        
        # Ejecutar notificaciones
        results = await self._send_notifications(decision)
        
        return {
            "attempted": True,
            "results": results,
            "timestamp_utc": now.isoformat() + "Z",
            "dedupe_key": dedupe_key,
            "throttled": False,
            "throttle_reason": None
        }
    
    async def _send_notifications(self, decision: Dict[str, Any]) -> List[Dict[str, Any]]:
        """Envía las notificaciones en orden: call > whatsapp > sms."""
        results = []
        
        channels = decision.get("channels_to_use", [])
        recipients = decision.get("recipients", [])
        message_text = decision.get("message_text", "")
        call_script = decision.get("call_script", message_text[:200])
        escalation_level = decision.get("escalation_level", "low")
        
        # Ordenar recipients por prioridad
        sorted_recipients = sorted(recipients, key=lambda r: r.get("priority", 999))
        
        # Ejecutar en orden: call > whatsapp > sms
        for channel in ["call", "whatsapp", "sms"]:
            if channel not in channels:
                continue
            
            for recipient in sorted_recipients:
                result = await self._send_to_recipient(
                    channel=channel,
                    recipient=recipient,
                    message=message_text,
                    call_script=call_script,
                    escalation_level=escalation_level
                )
                if result:
                    results.append(result)
        
        return results
    
    async def _send_to_recipient(
        self,
        channel: str,
        recipient: Dict[str, Any],
        message: str,
        call_script: str,
        escalation_level: str
    ) -> Optional[Dict[str, Any]]:
        """
        Envía una notificación a un destinatario específico.
        
        Las funciones de Twilio son síncronas, por lo que usamos
        asyncio.to_thread() para no bloquear el event loop.
        """
        
        recipient_type = recipient.get("recipient_type", "unknown")
        phone = recipient.get("phone")
        whatsapp = recipient.get("whatsapp")
        
        try:
            if channel == "call" and phone:
                # Usar callback para escalation critical/high
                if escalation_level in ["critical", "high"]:
                    response = await asyncio.to_thread(
                        make_call_with_callback,
                        to=phone,
                        message=call_script,
                        event_id=self.event_id
                    )
                else:
                    response = await asyncio.to_thread(
                        make_call_simple,
                        to=phone,
                        message=call_script
                    )
                
                return {
                    "channel": "call",
                    "to": phone,
                    "recipient_type": recipient_type,
                    "success": response.get("success", False),
                    "error": response.get("error"),
                    "call_sid": response.get("sid"),
                    "message_sid": None
                }
            
            elif channel == "whatsapp" and (whatsapp or phone):
                target = whatsapp or phone
                response = await asyncio.to_thread(
                    send_whatsapp,
                    to=target,
                    message=message
                )
                
                return {
                    "channel": "whatsapp",
                    "to": target,
                    "recipient_type": recipient_type,
                    "success": response.get("success", False),
                    "error": response.get("error"),
                    "call_sid": None,
                    "message_sid": response.get("sid")
                }
            
            elif channel == "sms" and phone:
                response = await asyncio.to_thread(
                    send_sms,
                    to=phone,
                    message=message
                )
                
                return {
                    "channel": "sms",
                    "to": phone,
                    "recipient_type": recipient_type,
                    "success": response.get("success", False),
                    "error": response.get("error"),
                    "call_sid": None,
                    "message_sid": response.get("sid")
                }
        
        except Exception as e:
            return {
                "channel": channel,
                "to": phone or whatsapp or "unknown",
                "recipient_type": recipient_type,
                "success": False,
                "error": str(e),
                "call_sid": None,
                "message_sid": None
            }
        
        return None


# ============================================================================
# FUNCIÓN HELPER
# ============================================================================
async def execute_notifications(
    decision: Dict[str, Any],
    event_id: int,
    vehicle_id: Optional[str] = None,
    driver_id: Optional[str] = None
) -> Dict[str, Any]:
    """
    Función helper para ejecutar notificaciones.
    
    Args:
        decision: Decisión del notification_decision_agent
        event_id: ID del evento en Laravel
        vehicle_id: ID del vehículo para throttling
        driver_id: ID del conductor para throttling
        
    Returns:
        notification_execution dict
    """
    executor = NotificationExecutor(
        event_id=event_id,
        vehicle_id=vehicle_id,
        driver_id=driver_id
    )
    return await executor.execute(decision)



