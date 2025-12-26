"""
System instructions (prompts) para cada agente.
Archivo de compatibilidad que re-exporta los prompts modulares.

Los prompts ahora están organizados en archivos separados:
- prompts/triage.py - Triage Agent
- prompts/investigator.py - Investigator Agent
- prompts/final_message.py - Final Agent
- prompts/notification.py - Notification Decision Agent
"""

# Re-export desde módulos
from .prompts.triage import TRIAGE_AGENT_PROMPT
from .prompts.investigator import INVESTIGATOR_AGENT_PROMPT
from .prompts.final_message import FINAL_AGENT_PROMPT
from .prompts.notification import NOTIFICATION_DECISION_PROMPT

# Legacy aliases para compatibilidad
INGESTION_AGENT_PROMPT = TRIAGE_AGENT_PROMPT
PANIC_INVESTIGATOR_PROMPT = INVESTIGATOR_AGENT_PROMPT

__all__ = [
    "TRIAGE_AGENT_PROMPT",
    "INVESTIGATOR_AGENT_PROMPT", 
    "FINAL_AGENT_PROMPT",
    "NOTIFICATION_DECISION_PROMPT",
    # Legacy
    "INGESTION_AGENT_PROMPT",
    "PANIC_INVESTIGATOR_PROMPT",
]

