"""
Definición de los agentes ADK.
Arquitectura refactorizada para manejar múltiples tipos de alertas:
- Botones de pánico
- Safety events (comportamiento, cámara, pasajeros)
- Tampering/interferencia
- Conectividad

ACTUALIZADO: Nuevo contrato de respuesta.
- notification_decision_agent ya NO tiene tools (solo decide)
- La ejecución de notificaciones la hace código determinista
"""

from google.adk.agents import LlmAgent, SequentialAgent
from google.adk.models.lite_llm import LiteLlm
from config import OpenAIConfig

# NOTA: Las tools ya NO se usan porque Laravel pre-carga todos los datos.
# Se mantienen los imports solo por si se necesitan en el futuro como fallback.
# from tools import (
#     get_vehicle_stats,
#     get_vehicle_info,
#     get_driver_assignment,
#     get_camera_media,
#     get_safety_events,
# )
from .prompts import (
    TRIAGE_AGENT_PROMPT,
    INVESTIGATOR_AGENT_PROMPT,
    FINAL_AGENT_PROMPT,
    NOTIFICATION_DECISION_PROMPT,
)
from .schemas import TriageResult, AlertAssessment, NotificationDecision

# Compatibilidad con imports existentes
from .schemas.investigation import PanicAssessment

# ============================================================================
# MODELOS DISPONIBLES
# ============================================================================
# GPT-4o: Modelo principal para razonamiento complejo
# GPT-4o-mini: Modelo rápido y económico para tareas simples
# ============================================================================


# ============================================================================
# TRIAGE AGENT (antes: ingestion_agent)
# ============================================================================
# Propósito: Clasificar la alerta y preparar instrucciones para el investigador
# Modelo: GPT-4o-mini (task simple: extracción y clasificación)
# Tools: Ninguna (solo analiza el payload)
# Output: alert_context (JSON estructurado)
# ============================================================================
triage_agent = LlmAgent(
    name="triage_agent",
    model=LiteLlm(model=OpenAIConfig.MODEL_GPT5_MINI),
    instruction=TRIAGE_AGENT_PROMPT,
    description="Clasifica alertas de Samsara, extrae datos y genera estrategia de investigación",
    output_key="alert_context",  # Stores structured triage data in state['alert_context']
    output_schema=TriageResult
)


# ============================================================================
# INVESTIGATOR AGENT (antes: panic_investigator)
# ============================================================================
# Propósito: Investigar alertas usando datos PRE-CARGADOS y generar evaluación técnica
# Modelo: GPT-4o (reasoning complejo)
# Tools: NINGUNA - Todos los datos vienen pre-cargados desde Laravel:
#   - preloaded_data.vehicle_info
#   - preloaded_data.driver_assignment
#   - preloaded_data.vehicle_stats
#   - preloaded_data.safety_events_correlation / safety_event_detail
#   - preloaded_camera_analysis (análisis de Vision AI)
# Output: assessment (JSON estructurado)
# ============================================================================
investigator_agent = LlmAgent(
    name="investigator_agent",
    model=LiteLlm(model=OpenAIConfig.MODEL_GPT5),
    tools=[],  # SIN TOOLS - Todos los datos vienen pre-cargados desde Laravel
    instruction=INVESTIGATOR_AGENT_PROMPT,
    description="Investiga alertas usando datos pre-cargados y genera evaluación técnica basada en evidencia",
    output_key="assessment",  # Stores assessment in state['assessment']
    output_schema=AlertAssessment
)


# ============================================================================
# FINAL AGENT
# ============================================================================
# Propósito: Generar mensaje en español para operadores
# Modelo: GPT-4o-mini (síntesis de texto, no requiere reasoning complejo)
# Tools: Ninguna
# Output: human_message (STRING, no JSON)
# ============================================================================
final_agent = LlmAgent(
    name="final_agent",
    model=LiteLlm(model=OpenAIConfig.MODEL_GPT5_MINI),
    instruction=FINAL_AGENT_PROMPT,
    description="Genera mensaje final en español para el equipo de monitoreo",
    output_key="human_message"  # Stores final message in state['human_message']
    # Sin output_schema porque es texto libre
)


# ============================================================================
# NOTIFICATION DECISION AGENT
# ============================================================================
# Propósito: Decidir qué notificaciones enviar (SIN ejecutar)
# Modelo: GPT-4o-mini (reglas claras, decisión estructurada)
# Tools: NINGUNA - Solo decide, la ejecución la hace código
# Output: notification_decision (JSON estructurado)
# ============================================================================
notification_decision_agent = LlmAgent(
    name="notification_decision_agent",
    model=LiteLlm(model=OpenAIConfig.MODEL_GPT5_MINI),
    tools=[],  # SIN TOOLS - Solo decide
    instruction=NOTIFICATION_DECISION_PROMPT,
    description="Decide qué notificaciones enviar según nivel de escalación (no ejecuta)",
    output_key="notification_decision",  # Stores decision in state['notification_decision']
    output_schema=NotificationDecision
)


# ============================================================================
# ROOT AGENT (Sequential Pipeline)
# ============================================================================
# Flujo de ejecución:
# 1. triage_agent → Clasifica la alerta y genera alert_context
# 2. investigator_agent → Ejecuta investigación y genera assessment
# 3. final_agent → Genera human_message (string)
# 4. notification_decision_agent → Genera notification_decision (sin ejecutar)
# 
# La ejecución de notificaciones (notification_execution) la hace código
# después del pipeline con idempotencia y throttling.
# ============================================================================
root_agent = SequentialAgent(
    name="alert_pipeline",
    sub_agents=[
        triage_agent,
        investigator_agent,
        final_agent,
        notification_decision_agent
    ],
    description="Pipeline secuencial para procesar alertas de Samsara de todos los tipos"
)


# ============================================================================
# AGENT REGISTRY
# ============================================================================
AGENTS_BY_NAME = {
    "triage_agent": triage_agent,
    "investigator_agent": investigator_agent,
    "final_agent": final_agent,
    "notification_decision_agent": notification_decision_agent,
    # Aliases para compatibilidad
    "ingestion_agent": triage_agent,
    "panic_investigator": investigator_agent,
}


# ============================================================================
# LEGACY EXPORTS (Compatibilidad)
# ============================================================================
# Para mantener compatibilidad con código existente
ingestion_agent = triage_agent
panic_investigator = investigator_agent


# ============================================================================
# HELPER: Selección de modelo por contexto
# ============================================================================
def get_recommended_model(task_type: str) -> str:
    """
    Recomienda el modelo adecuado según el tipo de tarea.
    
    Args:
        task_type: Tipo de tarea ('classification', 'investigation', 
                   'synthesis', 'tool_execution')
    
    Returns:
        Nombre del modelo recomendado para LiteLLM
    """
    model_map = {
        "classification": OpenAIConfig.MODEL_GPT5_MINI,  # Triage
        "investigation": OpenAIConfig.MODEL_GPT5,         # Reasoning complejo
        "synthesis": OpenAIConfig.MODEL_GPT5_MINI,        # Generación de mensajes
        "decision": OpenAIConfig.MODEL_GPT5_MINI,         # Decisiones estructuradas
    }
    return model_map.get(task_type, OpenAIConfig.MODEL_GPT5_MINI)

