"""
Lógica de creación de breadcrumbs desde eventos ADK.
Convierte eventos del Runner en breadcrumbs estructurados para SSE.
"""

import uuid
from typing import Any, Dict, Optional
from datetime import datetime

from config import BreadcrumbConfig


def create_breadcrumb(
    event: Any,
    order: int,
    author: Optional[str] = None
) -> Dict[str, Any]:
    """
    Convierte un evento del Runner ADK en un breadcrumb estructurado.
    
    Args:
        event: Evento del Runner ADK
        order: Número de orden del breadcrumb
        author: Nombre del agente que generó el evento
        
    Returns:
        Dict con el breadcrumb estructurado para SSE
    """
    # Estructura base del breadcrumb
    breadcrumb = {
        "id": str(uuid.uuid4()),
        "order": order,
        "author": author or "system",
        "event_type": type(event).__name__,
        "logical_step": "internal",
        "tool_name": None,
        "tool_status": None,
        "tool_input_preview": None,
        "tool_output_preview": None,
        "model_text": None,
        "mini_summary": "Evento interno del sistema",
        "is_final": False,
        "timestamp": datetime.utcnow().isoformat()
    }
    
    # Actualizar autor si está disponible en el evento
    if hasattr(event, 'author') and event.author:
        breadcrumb["author"] = event.author
        author = event.author
    
    # Procesar según el tipo de evento
    _process_tool_request(event, breadcrumb, author)
    _process_tool_response(event, breadcrumb, author)
    _process_model_output(event, breadcrumb, author)
    _process_final_response(event, breadcrumb)
    
    return breadcrumb


def _process_tool_request(event: Any, breadcrumb: Dict[str, Any], author: Optional[str]) -> None:
    """Procesa eventos de tool request (inicio de llamada a tool)."""
    if not (hasattr(event, 'tool_requests') and event.tool_requests):
        return
    
    tool_req = event.tool_requests[0]
    tool_name = tool_req.name if hasattr(tool_req, 'name') else "unknown_tool"
    
    breadcrumb["logical_step"] = "tool_call"
    breadcrumb["tool_name"] = tool_name
    breadcrumb["tool_status"] = "started"
    
    # Extraer input preview
    if hasattr(tool_req, 'input') and tool_req.input:
        input_str = str(tool_req.input)[:BreadcrumbConfig.MAX_PREVIEW_LENGTH]
        breadcrumb["tool_input_preview"] = input_str
    
    # Mini summary
    if author == "panic_investigator":
        breadcrumb["mini_summary"] = f"{BreadcrumbConfig.EMOJI_TOOL_CALL} Tool: {tool_name} (ejecución iniciada)"
    else:
        breadcrumb["mini_summary"] = f"Tool: {tool_name} iniciado"


def _process_tool_response(event: Any, breadcrumb: Dict[str, Any], author: Optional[str]) -> None:
    """Procesa eventos de tool response (resultado de llamada a tool)."""
    if not (hasattr(event, 'tool_responses') and event.tool_responses):
        return
    
    tool_resp = event.tool_responses[0]
    tool_name = tool_resp.name if hasattr(tool_resp, 'name') else "unknown_tool"
    
    breadcrumb["logical_step"] = "tool_result"
    breadcrumb["tool_name"] = tool_name
    breadcrumb["tool_status"] = "finished"
    
    # Extraer output preview
    if hasattr(tool_resp, 'response') and tool_resp.response:
        output_str = str(tool_resp.response)[:BreadcrumbConfig.MAX_PREVIEW_LENGTH]
        breadcrumb["tool_output_preview"] = output_str
    
    # Mini summary
    if author == "panic_investigator":
        breadcrumb["mini_summary"] = f"{BreadcrumbConfig.EMOJI_TOOL_RESULT} Tool: {tool_name} (ejecución terminada)"
    else:
        breadcrumb["mini_summary"] = f"Tool: {tool_name} completado"


def _process_model_output(event: Any, breadcrumb: Dict[str, Any], author: Optional[str]) -> None:
    """Procesa eventos de model output (texto generado por el modelo)."""
    if not (hasattr(event, 'text') and event.text):
        return
    
    model_text = event.text.strip()
    breadcrumb["model_text"] = model_text
    
    # Determinar logical_step y mini_summary según el agente
    if author == "ingestion_agent":
        breadcrumb["logical_step"] = "ingestion"
        breadcrumb["mini_summary"] = (
            f"{BreadcrumbConfig.EMOJI_INGESTION} Agente: ingestion_agent | "
            "Entendiendo el payload de alerta..."
        )
    elif author == "panic_investigator":
        breadcrumb["logical_step"] = "investigation"
        breadcrumb["mini_summary"] = (
            f"{BreadcrumbConfig.EMOJI_INVESTIGATION} Agente: panic_investigator | "
            "Analizando stats/historial/cámaras..."
        )
    elif author == "final_agent":
        breadcrumb["logical_step"] = "finalization"
        breadcrumb["mini_summary"] = (
            f"{BreadcrumbConfig.EMOJI_FINALIZATION} Agente: final_agent | "
            "Generando mensaje final para monitoreo..."
        )
    else:
        breadcrumb["logical_step"] = "model_output"
        preview = model_text[:50] + "..." if len(model_text) > 50 else model_text
        breadcrumb["mini_summary"] = f"Modelo generó texto: {preview}"


def _process_final_response(event: Any, breadcrumb: Dict[str, Any]) -> None:
    """Procesa eventos finales del pipeline."""
    if hasattr(event, 'is_final_response') and callable(event.is_final_response):
        if event.is_final_response():
            breadcrumb["is_final"] = True
            breadcrumb["mini_summary"] = f"{BreadcrumbConfig.EMOJI_COMPLETE} Pipeline completado"
