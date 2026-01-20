"""
AlertResponseBuilder: Construye respuestas estructuradas para la API.
Transforma PipelineResult en el nuevo contrato de respuesta.

NUEVO CONTRATO:
{
  "status": "success|error",
  "event_id": <int>,
  "alert_context": { ... JSON ... },          // Salida de triage (SIEMPRE objeto)
  "assessment": { ... JSON ... },             // Evaluación técnica (SIEMPRE objeto)
  "human_message": "string",                  // Mensaje final (SIEMPRE string)
  "notification_decision": { ... JSON ... },  // Decisión sin side effects
  "notification_execution": { ... JSON ... }, // Resultados de ejecución
  "execution": { ... JSON ... }               // Trazabilidad
}
"""

import json
import re
import unicodedata
from typing import Any, Dict, Optional, Union

from agents.schemas import PipelineResult, AgentResult, ToolResult


# ============================================================================
# ENCODING FIX UTILITIES
# ============================================================================
# Hay un bug conocido en LiteLLM/ADK donde los caracteres UTF-8 se corrompen
# cuando se usa output_schema. Estos patrones corrigen los casos más comunes.
# Ref: https://github.com/BerriAI/litellm/issues/16533

def _fix_corrupted_encoding(text: str) -> str:
    """
    Intenta reparar texto con encoding corrupto.
    
    Patrones conocidos de corrupción:
    - Secuencias hexadecimales parciales donde deberían haber acentos
    - Caracteres UTF-8 mal interpretados como ASCII
    - Secuencias de escape Unicode no decodificadas
    
    Args:
        text: Texto potencialmente corrupto
        
    Returns:
        Texto con encoding corregido
    """
    if not text or not isinstance(text, str):
        return text
    
    # 1. Intentar decodificar secuencias de escape Unicode (\uXXXX)
    try:
        # Decodificar escapes unicode si existen
        if '\\u' in text:
            text = text.encode('utf-8').decode('unicode_escape')
    except (UnicodeDecodeError, UnicodeEncodeError):
        pass
    
    # 2. Intentar reparar UTF-8 mal interpretado como Latin-1
    try:
        # Si el texto parece tener caracteres Latin-1 que deberían ser UTF-8
        if any(ord(c) > 127 and ord(c) < 256 for c in text):
            # Intentar re-interpretar como UTF-8
            fixed = text.encode('latin-1').decode('utf-8', errors='ignore')
            if fixed and len(fixed) > len(text) * 0.5:  # Solo si recuperamos algo razonable
                text = fixed
    except (UnicodeDecodeError, UnicodeEncodeError):
        pass
    
    # 3. Reemplazar patrones conocidos de corrupción en español
    # Estos son patrones que hemos visto en la práctica donde
    # caracteres acentuados se convierten en secuencias numéricas
    corruption_patterns = {
        # Patrones donde números reemplazan acentos
        '3dtico': 'ático',
        '3dnico': 'ánico',
        '3dtico': 'ático',
        'p3dnico': 'pánico',
        'cr3dtico': 'crítico',
        'cr3dtica': 'crítica',
        'informaci22n': 'información',
        'informaci33n': 'información',
        'evaluaci22n': 'evaluación',
        'evaluaci33n': 'evaluación',
        'c3dmara': 'cámara',
        'bot22n': 'botón',
        'bot33n': 'botón',
        'acci22n': 'acción',
        'acci33n': 'acción',
        'situaci22n': 'situación',
        'situaci33n': 'situación',
        'manipulaci22n': 'manipulación',
        'manipulaci33n': 'manipulación',
        'manipulaci342n': 'manipulación',
        'verificaci22n': 'verificación',
        'verificaci33n': 'verificación',
        'intervenci22n': 'intervención',
        'intervenci33n': 'intervención',
        'an22lisis': 'análisis',
        'an33lisis': 'análisis',
        'Veh3dc2culo': 'Vehículo',
        'veh3dc2culo': 'vehículo',
        # Patrones con 'd' como separador (UTF-8 byte fragments)
        '3d': 'á',  # Solo si está en contexto de palabra
        '33d': 'í',
        '22': 'ó',  # Cuidado con este, es muy genérico
    }
    
    # Aplicar reemplazos de patrones largos primero (más específicos)
    sorted_patterns = sorted(corruption_patterns.items(), key=lambda x: -len(x[0]))
    for corrupted, fixed in sorted_patterns:
        if len(corrupted) > 3:  # Solo patrones largos para evitar falsos positivos
            text = text.replace(corrupted, fixed)
    
    # 4. Normalizar Unicode (NFC)
    try:
        text = unicodedata.normalize('NFC', text)
    except Exception:
        pass
    
    return text


def _fix_dict_encoding(data: Dict[str, Any]) -> Dict[str, Any]:
    """
    Aplica corrección de encoding recursivamente a un diccionario.
    
    Args:
        data: Diccionario con valores potencialmente corruptos
        
    Returns:
        Diccionario con valores corregidos
    """
    if not isinstance(data, dict):
        return data
    
    result = {}
    for key, value in data.items():
        if isinstance(value, str):
            result[key] = _fix_corrupted_encoding(value)
        elif isinstance(value, dict):
            result[key] = _fix_dict_encoding(value)
        elif isinstance(value, list):
            result[key] = [
                _fix_corrupted_encoding(item) if isinstance(item, str)
                else _fix_dict_encoding(item) if isinstance(item, dict)
                else item
                for item in value
            ]
        else:
            result[key] = value
    
    return result


# ============================================================================
# HELPER FUNCTIONS
# ============================================================================
def _build_tool_response(tool: ToolResult) -> Dict[str, Any]:
    """Construye el objeto de respuesta para una tool."""
    result = {
        "name": tool.name,
        "duration_ms": tool.duration_ms,
        "summary": tool.summary
    }
    if tool.media_urls:
        result["media_urls"] = tool.media_urls
    return result


def _build_agent_response(agent: AgentResult) -> Dict[str, Any]:
    """Construye el objeto de respuesta para un agente."""
    result = {
        "name": agent.name,
        "duration_ms": agent.duration_ms,
        "summary": agent.summary
    }
    if agent.tools:
        result["tools"] = [_build_tool_response(t) for t in agent.tools]
    return result


def _clean_markdown(text: str) -> str:
    """Limpia bloques de código markdown del texto."""
    if not text:
        return text
    
    # Remover bloques de código markdown (```json ... ``` o ``` ... ```)
    # Patrón para capturar el contenido entre los bloques
    pattern = r'```(?:json|JSON)?\s*\n?(.*?)\n?```'
    match = re.search(pattern, text, re.DOTALL)
    if match:
        # Si encontramos un bloque de código, usar solo el contenido
        text = match.group(1).strip()
    else:
        # Si no hay bloques, intentar limpiar cualquier ``` residual
        text = re.sub(r'^```\w*\s*', '', text, flags=re.MULTILINE)
        text = re.sub(r'\s*```$', '', text, flags=re.MULTILINE)
        text = text.strip()
    
    return text


def _ensure_object(value: Any) -> Optional[Dict[str, Any]]:
    """Asegura que el valor sea un objeto JSON, no un string."""
    if value is None:
        return None
    if isinstance(value, dict):
        return value
    if isinstance(value, str):
        try:
            # Limpiar markdown antes de parsear
            clean_text = _clean_markdown(value)
            parsed = json.loads(clean_text)
            if isinstance(parsed, dict):
                return parsed
        except:
            pass
    return None


def _ensure_string(value: Any) -> str:
    """Asegura que el valor sea un string, no JSON."""
    if value is None:
        return ""
    if isinstance(value, str):
        return value
    if isinstance(value, dict):
        # Si es dict, convertir a string legible
        return str(value)
    return str(value)


# ============================================================================
# ALERT RESPONSE BUILDER
# ============================================================================
class AlertResponseBuilder:
    """
    Construye respuestas estructuradas y limpias para la API.
    
    Implementa el NUEVO CONTRATO:
    - alert_context: SIEMPRE objeto JSON
    - assessment: SIEMPRE objeto JSON  
    - human_message: SIEMPRE string
    - notification_decision: objeto JSON (decisión)
    - notification_execution: objeto JSON (resultados de ejecución)
    - execution: objeto JSON (trazabilidad)
    """
    
    @staticmethod
    def build(
        result: PipelineResult,
        event_id: int
    ) -> Dict[str, Any]:
        """
        Construye la respuesta final de la API.
        
        Args:
            result: Resultado del pipeline
            event_id: ID del evento
            
        Returns:
            Dict conforme al nuevo contrato
        """
        if not result.success:
            return {
                "status": "error",
                "event_id": event_id,
                "error": result.error or "Unknown error"
            }
        
        # Construir respuesta base
        response = {
            "status": "success",
            "event_id": event_id,
        }
        
        # alert_context - SIEMPRE objeto, nunca string
        # Aplicar corrección de encoding para caracteres corruptos
        alert_context = _ensure_object(result.alert_context)
        if alert_context:
            response["alert_context"] = _fix_dict_encoding(alert_context)
        else:
            # Proveer estructura mínima si no existe
            response["alert_context"] = {}
        
        # assessment - SIEMPRE objeto, nunca string
        # Aplicar corrección de encoding para caracteres corruptos
        assessment = _ensure_object(result.assessment)
        if assessment:
            response["assessment"] = _fix_dict_encoding(assessment)
        else:
            response["assessment"] = {}
        
        # human_message - SIEMPRE string, nunca JSON
        # Aplicar corrección de encoding para caracteres corruptos
        human_message = _ensure_string(result.human_message) or "Procesamiento completado"
        response["human_message"] = _fix_corrupted_encoding(human_message)
        
        # notification_decision - objeto JSON (decisión sin side effects)
        # Aplicar corrección de encoding para campos de texto
        notification_decision = _ensure_object(result.notification_decision)
        if notification_decision:
            response["notification_decision"] = _fix_dict_encoding(notification_decision)
        else:
            response["notification_decision"] = {
                "should_notify": False,
                "escalation_level": "none",
                "channels_to_use": [],
                "recipients": [],
                "message_text": "",
                "dedupe_key": "",
                "reason": "Sin decisión de notificación"
            }
        
        # notification_execution - objeto JSON (resultados de ejecución)
        notification_execution = _ensure_object(result.notification_execution)
        if notification_execution:
            response["notification_execution"] = notification_execution
        else:
            response["notification_execution"] = {
                "attempted": False,
                "results": [],
                "timestamp_utc": "",
                "dedupe_key": "",
                "throttled": False,
                "throttle_reason": None
            }
        
        # execution - trazabilidad
        response["execution"] = {
            "total_duration_ms": result.total_duration_ms,
            "total_tools_called": result.total_tools_called,
            "agents": [_build_agent_response(a) for a in result.agents]
        }
        
        # camera_analysis - URLs de imágenes para que Laravel las persista
        # Aplicar corrección de encoding para descripciones de escenas
        if result.camera_analysis:
            # Extraer las URLs de las imágenes analizadas
            media_urls = []
            analyses = result.camera_analysis.get("analyses", [])
            for analysis in analyses:
                url = analysis.get("samsara_url")
                if url:
                    media_urls.append(url)
            
            if media_urls:
                # Corregir encoding en los análisis que contienen descripciones
                fixed_analyses = [_fix_dict_encoding(a) if isinstance(a, dict) else a for a in analyses]
                response["camera_analysis"] = {
                    "total_images": result.camera_analysis.get("total_images_analyzed", 0),
                    "media_urls": media_urls,
                    "analyses": fixed_analyses
                }
        
        return response
    
    @staticmethod
    def build_error(event_id: int, error: str) -> Dict[str, Any]:
        """Construye una respuesta de error."""
        return {
            "status": "error",
            "event_id": event_id,
            "error": error
        }
    
    @staticmethod
    def extract_for_laravel(response: Dict[str, Any]) -> Dict[str, Any]:
        """
        Extrae los campos relevantes para guardar en Laravel.
        
        Returns:
            Dict con campos listos para el modelo SamsaraEvent
        """
        assessment = response.get("assessment", {})
        
        return {
            "ai_status": "completed" if response.get("status") == "success" else "failed",
            "ai_assessment": assessment,
            "ai_message": response.get("human_message", ""),
            "alert_context": response.get("alert_context", {}),
            "notification_decision": response.get("notification_decision", {}),
            "notification_execution": response.get("notification_execution", {}),
            "ai_actions": response.get("execution", {}),
            
            # Campos operativos del assessment
            "dedupe_key": assessment.get("dedupe_key"),
            "risk_escalation": assessment.get("risk_escalation"),
            "proactive_flag": response.get("alert_context", {}).get("proactive_flag", False),
            "data_consistency": assessment.get("supporting_evidence", {}).get("data_consistency", {}),
            "recommended_actions": assessment.get("recommended_actions", []),
            
            # Campos de monitoreo
            "requires_monitoring": assessment.get("requires_monitoring", False),
            "next_check_minutes": assessment.get("next_check_minutes"),
            "monitoring_reason": assessment.get("monitoring_reason"),
        }

