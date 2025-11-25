"""
Contexto global para compartir el span actual de Langfuse.
Permite que las tools accedan al span activo para crear sub-spans.
"""

from contextvars import ContextVar
from typing import Optional, Any, Dict

# ContextVar para almacenar el span actual de Langfuse
# Tipo Any porque no queremos importar langfuse aquí para evitar dependencias circulares
current_langfuse_span: ContextVar[Optional[Any]] = ContextVar("current_langfuse_span", default=None)

# ContextVar para rastrear metadata del agente actual y registrar tool calls
# Se guarda un dict con referencias a ai_actions y current_agent_actions
current_tool_tracker: ContextVar[Optional[Dict[str, Any]]] = ContextVar("current_tool_tracker", default=None)
