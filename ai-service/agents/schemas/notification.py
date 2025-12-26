"""
Schema para el output del Notification Decision Agent.

ACTUALIZADO: Separación entre decisión y ejecución.
- NotificationDecision: Lo que decide el agente (sin side effects)
- NotificationExecution: Resultados de la ejecución real (hecha por código)
"""

from typing import Optional, List, Literal
from pydantic import BaseModel, Field


class NotificationRecipient(BaseModel):
    """Destinatario de una notificación."""
    recipient_type: Literal["operator", "monitoring_team", "supervisor", "emergency", "dispatch"] = Field(
        ...,
        description="Tipo de destinatario"
    )
    phone: Optional[str] = Field(
        None,
        description="Número de teléfono para SMS/llamada"
    )
    whatsapp: Optional[str] = Field(
        None,
        description="Número de WhatsApp"
    )
    priority: int = Field(
        default=1,
        description="Prioridad del destinatario (1=más alta)"
    )


class NotificationDecision(BaseModel):
    """
    Decisión de notificación del agente (sin side effects).
    Este schema representa 'notification_decision' en el contrato.
    """
    
    # =========================================================================
    # Decisión
    # =========================================================================
    should_notify: bool = Field(
        ...,
        description="Si se debe enviar notificaciones"
    )
    escalation_level: Literal["critical", "high", "low", "none"] = Field(
        ...,
        description="Nivel de escalación"
    )
    
    # =========================================================================
    # Canales y destinatarios
    # =========================================================================
    channels_to_use: List[Literal["sms", "whatsapp", "call"]] = Field(
        default_factory=list,
        description="Lista de canales a usar"
    )
    recipients: List[NotificationRecipient] = Field(
        default_factory=list,
        description="Lista de destinatarios con sus datos"
    )
    
    # =========================================================================
    # Contenido
    # =========================================================================
    message_text: str = Field(
        ...,
        description="Mensaje para SMS/WhatsApp (el human_message)"
    )
    call_script: Optional[str] = Field(
        None,
        description="Script corto para TTS en llamadas (máx 200 chars)"
    )
    
    # =========================================================================
    # Deduplicación y razón
    # =========================================================================
    dedupe_key: str = Field(
        ...,
        description="Clave de deduplicación del assessment"
    )
    reason: str = Field(
        ...,
        description="Explicación de la decisión"
    )


class NotificationResult(BaseModel):
    """Resultado de una notificación individual ejecutada."""
    
    channel: Literal["sms", "whatsapp", "call"] = Field(
        ...,
        description="Canal usado para la notificación"
    )
    to: str = Field(
        ...,
        description="Número de teléfono destino"
    )
    recipient_type: str = Field(
        ...,
        description="Tipo de destinatario"
    )
    success: bool = Field(
        ...,
        description="Si la notificación se envió correctamente"
    )
    error: Optional[str] = Field(
        None,
        description="Mensaje de error si falló"
    )
    call_sid: Optional[str] = Field(
        None,
        description="SID de Twilio para llamadas"
    )
    message_sid: Optional[str] = Field(
        None,
        description="SID de Twilio para SMS/WhatsApp"
    )


class NotificationExecution(BaseModel):
    """
    Resultados de la ejecución real de notificaciones (hecha por código).
    Este schema representa 'notification_execution' en el contrato.
    """
    
    attempted: bool = Field(
        ...,
        description="Si se intentó enviar notificaciones"
    )
    results: List[NotificationResult] = Field(
        default_factory=list,
        description="Resultados de cada notificación enviada"
    )
    timestamp_utc: str = Field(
        ...,
        description="Timestamp UTC de la ejecución en formato ISO"
    )
    dedupe_key: str = Field(
        ...,
        description="Clave de deduplicación usada"
    )
    throttled: bool = Field(
        default=False,
        description="Si se aplicó throttling"
    )
    throttle_reason: Optional[str] = Field(
        None,
        description="Razón del throttling si aplica"
    )

