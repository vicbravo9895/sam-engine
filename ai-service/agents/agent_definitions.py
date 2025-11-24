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
    get_vehicle_event_history,
    get_vehicle_camera_snapshot
)
from .prompts import (
    INGESTION_AGENT_PROMPT,
    PANIC_INVESTIGATOR_PROMPT,
    FINAL_AGENT_PROMPT
)

# ============================================================================
# INGESTION AGENT
# ============================================================================
# Usa GPT-4o-mini para extracción rápida de datos
ingestion_agent = LlmAgent(
    name="ingestion_agent",
    model=LiteLlm(model=OpenAIConfig.MODEL_GPT4O_MINI),
    instruction=INGESTION_AGENT_PROMPT,
    description="Extrae y estructura información básica del payload de alerta de Samsara"
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
        get_vehicle_event_history,
        get_vehicle_camera_snapshot
    ],
    instruction=PANIC_INVESTIGATOR_PROMPT,
    description="Investiga alertas de pánico usando tools y genera evaluación técnica"
)


# ============================================================================
# FINAL AGENT
# ============================================================================
# Usa GPT-4o-mini para generación rápida de mensajes
final_agent = LlmAgent(
    name="final_agent",
    model=LiteLlm(model=OpenAIConfig.MODEL_GPT4O_MINI),
    instruction=FINAL_AGENT_PROMPT,
    description="Genera mensaje final en español para el equipo de monitoreo"
)


# ============================================================================
# ROOT AGENT (Sequential Pipeline)
# ============================================================================
root_agent = SequentialAgent(
    name="alert_pipeline",
    sub_agents=[
        ingestion_agent,
        panic_investigator,
        final_agent
    ],
    description="Pipeline secuencial para procesar alertas de Samsara"
)


# ============================================================================
# AGENT REGISTRY
# ============================================================================
AGENTS_BY_NAME = {
    "ingestion_agent": ingestion_agent,
    "panic_investigator": panic_investigator,
    "final_agent": final_agent
}
