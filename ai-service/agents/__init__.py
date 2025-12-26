"""
Módulo de agentes ADK.
Incluye definiciones de agentes, schemas y prompts.
"""

from .agent_definitions import (
    # Nuevos nombres (arquitectura refactorizada)
    triage_agent,
    investigator_agent,
    final_agent,
    notification_decision_agent,
    root_agent,
    AGENTS_BY_NAME,
    get_recommended_model,
    # Aliases para compatibilidad
    ingestion_agent,
    panic_investigator,
)

from .schemas import (
    # Alert Types
    AlertType,
    AlertCategory,
    # Triage
    TriageResult,
    TimeWindowConfig,
    # Investigation
    AlertAssessment,
    SupportingEvidence,
    PanicAssessment,  # Alias para compatibilidad
    # Notification
    NotificationDecision,
    NotificationResult,
    # Execution
    ToolResult,
    AgentResult,
    PipelineResult,
)

from .schemas.alert_types import detect_alert_type, SAMSARA_EVENT_TYPE_MAP

__all__ = [
    # Agents
    "triage_agent",
    "investigator_agent",
    "final_agent",
    "notification_decision_agent",
    "root_agent",
    "AGENTS_BY_NAME",
    "get_recommended_model",
    # Compatibility aliases
    "ingestion_agent",
    "panic_investigator",
    # Alert Types
    "AlertType",
    "AlertCategory",
    "detect_alert_type",
    "SAMSARA_EVENT_TYPE_MAP",
    # Schemas
    "TriageResult",
    "TimeWindowConfig",
    "AlertAssessment",
    "SupportingEvidence",
    "PanicAssessment",
    "NotificationDecision",
    "NotificationResult",
    "ToolResult",
    "AgentResult",
    "PipelineResult",
]
