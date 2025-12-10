"""
Definición de los agentes ADK.
Cada agente está configurado con su modelo, tools y system instruction.
Usa OpenAI GPT-4o a través de LiteLLM integrado en ADK.
"""

from google.adk.agents import LlmAgent, SequentialAgent
from google.adk.models.lite_llm import LiteLlm
from config import OpenAIConfig

from tools import (
    get_vehicle_stats,
    get_vehicle_info,
    get_driver_assignment,
    get_camera_media,
    get_safety_events,
    send_sms,
    send_whatsapp,
    make_call_simple,
    make_call_with_callback
)
from .prompts import (
    INGESTION_AGENT_PROMPT,
    PANIC_INVESTIGATOR_PROMPT,
    FINAL_AGENT_PROMPT,
    NOTIFICATION_DECISION_PROMPT
)
from .schemas import CaseData, PanicAssessment, NotificationDecision

# ============================================================================
# INGESTION AGENT
# ============================================================================
# Usa GPT-4o-mini para extracción rápida de datos
ingestion_agent = LlmAgent(
    name="ingestion_agent",
    model=LiteLlm(model=OpenAIConfig.MODEL_GPT4O_MINI),
    instruction=INGESTION_AGENT_PROMPT,
    description="Extrae y estructura información básica del payload de alerta de Samsara",
    output_key="case",  # Stores structured case data in state['case']
    output_schema=CaseData
)


# ============================================================================
# PANIC INVESTIGATOR AGENT
# ============================================================================
# Usa GPT-4o para análisis complejo con tools
panic_investigator = LlmAgent(
    name="panic_investigator",
    model=LiteLlm(model=OpenAIConfig.MODEL_GPT4O),
    tools=[
        get_vehicle_stats,
        get_vehicle_info,
        get_driver_assignment,
        get_camera_media,
        get_safety_events
    ],
    instruction=PANIC_INVESTIGATOR_PROMPT,
    description="Investiga alertas de pánico usando tools y genera evaluación técnica",
    output_key="panic_assessment",  # Stores assessment in state['panic_assessment']
    output_schema=PanicAssessment
)


# ============================================================================
# FINAL AGENT
# ============================================================================
# Usa GPT-4o-mini para generación rápida de mensajes
final_agent = LlmAgent(
    name="final_agent",
    model=LiteLlm(model=OpenAIConfig.MODEL_GPT4O_MINI),
    instruction=FINAL_AGENT_PROMPT,
    description="Genera mensaje final en español para el equipo de monitoreo",
    output_key="human_message"  # Stores final message in state['human_message']
)


# ============================================================================
# NOTIFICATION DECISION AGENT
# ============================================================================
# Usa GPT-4o-mini para decisión de notificación y ejecución de envíos
notification_decision_agent = LlmAgent(
    name="notification_decision_agent",
    model=LiteLlm(model=OpenAIConfig.MODEL_GPT4O_MINI),
    tools=[
        send_sms,
        send_whatsapp,
        make_call_simple,
        make_call_with_callback
    ],
    instruction=NOTIFICATION_DECISION_PROMPT,
    description="Decide y ejecuta notificaciones SMS, WhatsApp y llamadas según nivel de escalación",
    output_key="notification_decision",  # Stores decision in state['notification_decision']
    output_schema=NotificationDecision
)


# ============================================================================
# ROOT AGENT (Sequential Pipeline)
# ============================================================================
root_agent = SequentialAgent(
    name="alert_pipeline",
    sub_agents=[
        ingestion_agent,
        panic_investigator,
        final_agent,
        notification_decision_agent
    ],
    description="Pipeline secuencial para procesar alertas de Samsara"
)


# ============================================================================
# AGENT REGISTRY
# ============================================================================
AGENTS_BY_NAME = {
    "ingestion_agent": ingestion_agent,
    "panic_investigator": panic_investigator,
    "final_agent": final_agent,
    "notification_decision_agent": notification_decision_agent
}

