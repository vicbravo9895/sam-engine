"""
Schema para el output del Notification Decision Agent.

The AI decides WHAT to notify (recipient types, channels, escalation level).
Phone numbers are NEVER resolved by the AI — Laravel's ContactResolver handles that.
"""

from typing import Optional, List, Literal
from pydantic import BaseModel, Field


class NotificationRecipient(BaseModel):
    """Who to notify. Only the type and priority; phone resolution is backend-only."""
    recipient_type: Literal["operator", "monitoring_team", "supervisor", "emergency", "dispatch"] = Field(
        ...,
        description="Tipo de destinatario"
    )
    priority: int = Field(
        default=1,
        description="Prioridad del destinatario (1=más alta)"
    )


class NotificationDecision(BaseModel):
    """
    Decisión de notificación del agente (sin side effects).
    Phone/whatsapp numbers are resolved by Laravel, not the AI.
    """
    
    should_notify: bool = Field(
        ...,
        description="Si se debe enviar notificaciones"
    )
    escalation_level: Literal["critical", "high", "low", "none"] = Field(
        ...,
        description="Nivel de escalación"
    )
    
    channels_to_use: List[Literal["sms", "whatsapp", "call"]] = Field(
        default_factory=list,
        description="Lista de canales a usar"
    )
    recipients: List[NotificationRecipient] = Field(
        default_factory=list,
        description="Lista de destinatarios (solo tipo y prioridad)"
    )
    
    message_text: str = Field(
        ...,
        description="Mensaje para SMS/WhatsApp (el human_message)"
    )
    call_script: Optional[str] = Field(
        None,
        description="Script corto para TTS en llamadas (máx 200 chars)"
    )
    
    dedupe_key: str = Field(
        ...,
        description="Clave de deduplicación del assessment"
    )
    reason: str = Field(
        ...,
        description="Explicación de la decisión"
    )

