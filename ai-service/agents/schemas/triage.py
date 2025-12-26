"""
Schema para el output del Triage Agent.
El triage clasifica la alerta y proporciona instrucciones al investigador.

Actualizado para incluir campos de estrategia de investigación:
- alert_kind para clasificación semántica
- proactive_flag para alertas preventivas
- required_tools para control estricto de herramientas
- investigation_plan para orden de pasos
"""

from typing import Optional, List, Literal
from pydantic import BaseModel, Field

from .alert_types import AlertType, AlertCategory


class ContactInfo(BaseModel):
    """Información de un contacto para notificaciones."""
    
    name: Optional[str] = Field(
        None,
        description="Nombre del contacto"
    )
    role: Optional[str] = Field(
        None,
        description="Rol del contacto (Operador, Supervisor, etc.)"
    )
    phone: Optional[str] = Field(
        None,
        description="Número de teléfono para llamadas/SMS"
    )
    whatsapp: Optional[str] = Field(
        None,
        description="Número de WhatsApp"
    )
    email: Optional[str] = Field(
        None,
        description="Correo electrónico"
    )
    priority: Optional[int] = Field(
        None,
        description="Prioridad del contacto (menor = más prioritario)"
    )


class NotificationContacts(BaseModel):
    """Contactos disponibles para notificaciones."""
    
    operator: Optional[ContactInfo] = Field(
        None,
        description="Contacto del operador/conductor"
    )
    monitoring_team: Optional[ContactInfo] = Field(
        None,
        description="Contacto del equipo de monitoreo"
    )
    supervisor: Optional[ContactInfo] = Field(
        None,
        description="Contacto del supervisor"
    )


class TimeWindowConfig(BaseModel):
    """Configuración de ventana temporal para la investigación."""
    
    correlation_window_minutes: int = Field(
        default=20,
        description="Minutos antes del evento para correlacionar eventos"
    )
    media_window_seconds: int = Field(
        default=120,
        description="Segundos antes/después para búsqueda de media"
    )
    safety_events_before_minutes: int = Field(
        default=30,
        description="Minutos antes del evento para buscar safety events"
    )
    safety_events_after_minutes: int = Field(
        default=10,
        description="Minutos después del evento para buscar safety events"
    )


class TriageResult(BaseModel):
    """
    Resultado del Triage Agent.
    Clasifica la alerta y proporciona contexto estructurado para el investigador.
    
    Este schema representa el 'alert_context' en el contrato de respuesta.
    """
    
    # =========================================================================
    # Campos base (requeridos)
    # =========================================================================
    alert_type: str = Field(
        ...,
        description="Tipo específico de alerta (panic_button, harsh_braking, etc.)"
    )
    alert_id: str = Field(
        ...,
        description="ID único de la alerta de Samsara"
    )
    vehicle_id: str = Field(
        ...,
        description="ID del vehículo"
    )
    vehicle_name: str = Field(
        ...,
        description="Nombre o placa del vehículo"
    )
    driver_id: Optional[str] = Field(
        None,
        description="ID del conductor si está disponible en el payload"
    )
    driver_name: Optional[str] = Field(
        None,
        description="Nombre del conductor si está disponible en el payload"
    )
    event_time_utc: str = Field(
        ...,
        description="Timestamp UTC del evento en formato ISO"
    )
    severity_level: Literal["info", "warning", "critical"] = Field(
        ...,
        description="Nivel de severidad: info, warning, critical"
    )
    
    # =========================================================================
    # Campos nuevos para estrategia
    # =========================================================================
    alert_kind: Literal["panic", "safety", "tampering", "connectivity", "unknown"] = Field(
        ...,
        description="Clasificación semántica de la alerta"
    )
    alert_category: AlertCategory = Field(
        ...,
        description="Categoría principal de la alerta (enum)"
    )
    proactive_flag: bool = Field(
        default=False,
        description="True si es una alerta proactiva/preventiva (tampering, obstrucción)"
    )
    
    # =========================================================================
    # Configuración de investigación
    # =========================================================================
    time_window: TimeWindowConfig = Field(
        default_factory=TimeWindowConfig,
        description="Configuración de ventanas temporales para investigación"
    )
    required_tools: List[str] = Field(
        default_factory=list,
        description="Lista de nombres EXACTOS de tools que el investigador debe usar"
    )
    investigation_plan: List[str] = Field(
        default_factory=list,
        description="Lista ordenada de pasos para la investigación"
    )
    
    # =========================================================================
    # Contexto adicional del payload
    # =========================================================================
    behavior_label: Optional[str] = Field(
        None,
        description="Label del comportamiento detectado (para safety events)"
    )
    severity_from_samsara: Optional[str] = Field(
        None,
        description="Severidad reportada por Samsara si existe"
    )
    location_description: Optional[str] = Field(
        None,
        description="Descripción de la ubicación si está disponible"
    )
    
    # =========================================================================
    # Notas y estrategia
    # =========================================================================
    investigation_strategy: str = Field(
        ...,
        description="Instrucciones específicas para la estrategia de investigación"
    )
    triage_notes: Optional[str] = Field(
        None,
        description="Notas adicionales del triage para contexto"
    )
    
    # =========================================================================
    # Contactos de notificación
    # =========================================================================
    notification_contacts: Optional[NotificationContacts] = Field(
        None,
        description="Contactos para notificaciones extraídos del payload"
    )

