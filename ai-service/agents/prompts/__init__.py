"""
Prompts para los agentes del pipeline.
"""

from .triage import TRIAGE_AGENT_PROMPT
from .investigator import INVESTIGATOR_AGENT_PROMPT
from .final_message import FINAL_AGENT_PROMPT, FINAL_AGENT_REVALIDATION_PROMPT
from .notification import NOTIFICATION_DECISION_PROMPT
from .correlation import CORRELATION_AGENT_PROMPT

__all__ = [
    "TRIAGE_AGENT_PROMPT",
    "INVESTIGATOR_AGENT_PROMPT",
    "FINAL_AGENT_PROMPT",
    "FINAL_AGENT_REVALIDATION_PROMPT",
    "NOTIFICATION_DECISION_PROMPT",
    "CORRELATION_AGENT_PROMPT",
]

