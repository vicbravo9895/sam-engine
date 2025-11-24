"""
Contexto global para compartir el span actual de Langfuse.
Permite que las tools accedan al span activo para crear sub-spans.
"""

from contextvars import ContextVar
from typing import Optional, Any

# ContextVar para almacenar el span actual de Langfuse
# Tipo Any porque no queremos importar langfuse aquí para evitar dependencias circulares
current_langfuse_span: ContextVar[Optional[Any]] = ContextVar("current_langfuse_span", default=None)
