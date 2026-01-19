"""
Schemas Pydantic para los agentes.
Organizados por dominio para mejor mantenibilidad.

ACTUALIZADO: Nuevo contrato con separación decisión/ejecución.
"""

from .alert_types import AlertType, AlertCategory
from .triage import TriageResult, TimeWindowConfig, ContactInfo, NotificationContacts
from .investigation import (
    AlertAssessment,
    SupportingEvidence,
    DriverInfo,
    CameraEvidence,
    DataConsistency,
    InvestigationVerdict,
    # Alias para compatibilidad
    PanicAssessment,
)
from .notification import (
    NotificationDecision,
    NotificationRecipient,
    NotificationResult,
    NotificationExecution,
)
from .correlation import (
    CorrelationResult,
    RelatedEvent,
)
from .execution import ToolResult, AgentResult, PipelineResult

__all__ = [
    # Alert Types
    "AlertType",
    "AlertCategory",
    # Triage
    "TriageResult",
    "TimeWindowConfig",
    "ContactInfo",
    "NotificationContacts",
    # Investigation
    "AlertAssessment",
    "SupportingEvidence",
    "DriverInfo",
    "CameraEvidence",
    "DataConsistency",
    "InvestigationVerdict",
    "PanicAssessment",  # Alias
    # Notification
    "NotificationDecision",
    "NotificationRecipient",
    "NotificationResult",
    "NotificationExecution",
    # Correlation
    "CorrelationResult",
    "RelatedEvent",
    # Execution
    "ToolResult",
    "AgentResult",
    "PipelineResult",
]

