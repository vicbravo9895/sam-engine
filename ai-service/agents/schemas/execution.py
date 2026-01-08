"""
Schemas para tracking de ejecución del pipeline.
Usados internamente para registrar la ejecución de agentes y tools.

ACTUALIZADO: Nuevo contrato con alert_context, assessment, human_message, etc.
"""

from dataclasses import dataclass, field
from typing import Optional, List, Dict, Any


@dataclass
class ToolResult:
    """Resultado de ejecución de una tool."""
    name: str
    status: str = "success"
    duration_ms: int = 0
    summary: str = ""
    media_urls: Optional[List[str]] = None


@dataclass
class AgentResult:
    """Resultado de ejecución de un agente."""
    name: str
    started_at: str = ""
    completed_at: str = ""
    duration_ms: int = 0
    summary: str = ""
    tools: List[ToolResult] = field(default_factory=list)


@dataclass
class PipelineResult:
    """
    Resultado completo de la ejecución del pipeline.
    
    ACTUALIZADO: Nuevo contrato con campos separados:
    - alert_context (antes: triage)
    - assessment
    - human_message (string, antes: message)
    - notification_decision
    - notification_execution (ejecutado por código, no LLM)
    - camera_analysis (análisis de imágenes pre-cargadas)
    """
    success: bool = True
    
    # Outputs de los agentes
    alert_context: Optional[Dict[str, Any]] = None  # Triage result
    assessment: Optional[Dict[str, Any]] = None     # Investigation result
    human_message: Optional[str] = None             # Final message (STRING)
    notification_decision: Optional[Dict[str, Any]] = None  # Decision (sin side effects)
    notification_execution: Optional[Dict[str, Any]] = None  # Execution results
    
    # Análisis de imágenes pre-cargadas (para que Laravel las persista)
    camera_analysis: Optional[Dict[str, Any]] = None  # Resultado de analyze_preloaded_media
    
    # Metadatos de ejecución
    agents: List[AgentResult] = field(default_factory=list)
    total_duration_ms: int = 0
    total_tools_called: int = 0
    error: Optional[str] = None
    
    # Aliases para compatibilidad
    @property
    def triage(self) -> Optional[Dict[str, Any]]:
        """Alias para alert_context (compatibilidad)."""
        return self.alert_context
    
    @triage.setter
    def triage(self, value: Optional[Dict[str, Any]]):
        self.alert_context = value
    
    @property
    def message(self) -> Optional[str]:
        """Alias para human_message (compatibilidad)."""
        return self.human_message
    
    @message.setter
    def message(self, value: Optional[str]):
        self.human_message = value
    
    @property
    def notification(self) -> Optional[Dict[str, Any]]:
        """Alias para notification_decision (compatibilidad)."""
        return self.notification_decision
    
    @notification.setter
    def notification(self, value: Optional[Dict[str, Any]]):
        self.notification_decision = value

