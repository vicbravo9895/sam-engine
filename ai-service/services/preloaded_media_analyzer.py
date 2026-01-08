"""
Servicio para analizar imágenes pre-cargadas desde Laravel.

Laravel ya pre-carga las URLs de las imágenes de Samsara en el payload.
Este servicio analiza esas imágenes con Vision AI ANTES de que el
investigador empiece, para que no tenga que llamar a get_camera_media.

Esto evita:
1. Llamadas duplicadas a la API de Samsara
2. Errores de "End time cannot be in the future"
3. Latencia adicional
"""

import base64
import json
import logging
from typing import Any, Dict, List, Optional

import httpx
from litellm import acompletion

from config import OpenAIConfig

logger = logging.getLogger(__name__)


async def analyze_preloaded_media(payload: Dict[str, Any]) -> Optional[Dict[str, Any]]:
    """
    Analiza las imágenes pre-cargadas desde Laravel con Vision AI.
    
    Busca imágenes en:
    - preloaded_data.camera_media.items (procesamiento inicial)
    - revalidation_data.camera_media_since_last_check.items (revalidaciones)
    
    Args:
        payload: El payload del evento con datos pre-cargados
        
    Returns:
        Dict con análisis de las imágenes, o None si no hay imágenes
    """
    # Extraer imágenes pre-cargadas
    preloaded = payload.get('preloaded_data', {})
    revalidation = payload.get('revalidation_data', {})
    
    # Buscar items de cámara
    camera_items = []
    
    # Primero buscar en revalidation_data (prioridad para revalidaciones)
    if revalidation:
        reval_camera = revalidation.get('camera_media_since_last_check', {})
        if reval_camera and reval_camera.get('items'):
            camera_items = reval_camera['items']
            logger.info(f"Found {len(camera_items)} camera items in revalidation_data")
    
    # Si no hay en revalidation, buscar en preloaded_data
    if not camera_items and preloaded:
        preload_camera = preloaded.get('camera_media', {})
        if preload_camera and preload_camera.get('items'):
            camera_items = preload_camera['items']
            logger.info(f"Found {len(camera_items)} camera items in preloaded_data")
    
    if not camera_items:
        logger.info("No camera items found in preloaded data")
        return None
    
    # Filtrar solo imágenes (no videos)
    image_items = [
        item for item in camera_items 
        if item.get('media_type') == 'image' or 'image' in str(item.get('url', ''))
    ]
    
    if not image_items:
        logger.info("No image items found (all items may be videos)")
        return None
    
    logger.info(f"Analyzing {len(image_items)} preloaded images with Vision AI")
    
    # Analizar imágenes
    analyses = await _analyze_images(image_items)
    
    return {
        "total_images_analyzed": len(analyses),
        "analyses": analyses,
        "source": "preloaded_data"
    }


async def _analyze_images(image_items: List[Dict]) -> List[Dict]:
    """
    Analiza una lista de imágenes con GPT-4o Vision.
    
    Args:
        image_items: Lista de items de imagen con URLs
        
    Returns:
        Lista de análisis por imagen
    """
    if not OpenAIConfig.API_KEY:
        logger.warning("OPENAI_API_KEY not configured, skipping image analysis")
        return []
    
    analyses = []
    
    async with httpx.AsyncClient(timeout=30.0) as http_client:
        for idx, item in enumerate(image_items):
            try:
                # Extraer URL
                url = item.get('url') or item.get('download_url')
                if not url:
                    continue
                
                camera_input = item.get('camera_type') or item.get('input', 'unknown')
                captured_at = item.get('captured_at') or item.get('startTime', 'unknown')
                
                # Descargar imagen
                response = await http_client.get(url)
                if response.status_code != 200:
                    logger.warning(f"Failed to download image {idx}: status {response.status_code}")
                    continue
                
                image_data = response.content
                image_size_kb = len(image_data) / 1024
                
                # Convertir a base64
                base64_image = base64.b64encode(image_data).decode('utf-8')
                
                # Determinar tipo de cámara
                camera_type = "interior (hacia el conductor)" if "driver" in str(camera_input).lower() else "exterior (hacia el camino)"
                
                prompt = _get_vision_prompt(camera_type, camera_input)
                
                # Llamar a Vision API
                vision_response = await acompletion(
                    model=OpenAIConfig.MODEL_GPT4O,
                    messages=[
                        {
                            "role": "user",
                            "content": [
                                {"type": "text", "text": prompt},
                                {
                                    "type": "image_url",
                                    "image_url": {
                                        "url": f"data:image/jpeg;base64,{base64_image}"
                                    }
                                }
                            ]
                        }
                    ],
                    api_key=OpenAIConfig.API_KEY
                )
                
                analysis_text = vision_response.choices[0].message.content
                
                # Intentar parsear JSON
                analysis_structured = None
                try:
                    clean_text = analysis_text.strip()
                    if clean_text.startswith("```"):
                        lines = clean_text.split("\n")
                        clean_text = "\n".join(lines[1:-1] if lines[-1].strip() == "```" else lines[1:])
                    analysis_structured = json.loads(clean_text)
                except json.JSONDecodeError:
                    logger.warning(f"Vision response for image {idx} is not valid JSON")
                
                analysis = {
                    "input": camera_input,
                    "timestamp": captured_at,
                    "analysis": analysis_text,
                    "analysis_structured": analysis_structured,
                    "samsara_url": url,
                    "image_size_kb": round(image_size_kb, 2)
                }
                
                # Extraer campos clave
                if analysis_structured:
                    analysis["alert_level"] = analysis_structured.get("alert_level")
                    analysis["recommendation"] = analysis_structured.get("recommendation")
                    analysis["scene_description"] = analysis_structured.get("scene_description")
                
                analyses.append(analysis)
                
                logger.info(f"Analyzed image {idx + 1}/{len(image_items)}: {camera_input}")
                
            except Exception as e:
                logger.error(f"Error analyzing image {idx}: {e}")
                analyses.append({
                    "error": str(e),
                    "input": item.get('camera_type', 'unknown')
                })
    
    return analyses


def _get_vision_prompt(camera_type: str, camera_input: str) -> str:
    """Genera el prompt para Vision AI."""
    return f"""Eres un analista de seguridad de flotas vehiculares. Esta imagen proviene de una dashcam {camera_type} de un vehículo comercial que activó una alerta de seguridad (posible botón de pánico, evento de seguridad, o incidente).

Tu tarea es proporcionar un ANÁLISIS OBJETIVO en formato JSON para ayudar al equipo de monitoreo a decidir si la alerta requiere intervención inmediata o puede considerarse un falso positivo.

IMPORTANTE: NO identifiques personas ni proporciones datos personales. Enfócate en el ESTADO SITUACIONAL y CONTEXTO.

Responde ÚNICAMENTE con un JSON válido (sin bloques de código markdown) con esta estructura:

{{
  "alert_level": "NORMAL | ATENCION | ALERTA | CRITICO",
  "scene_description": "Descripción breve de la escena en máximo 2 líneas",
  "security_indicators": {{
    "driver_state": "{"Estado aparente del conductor: relajado, tenso, concentrado, ausente, etc." if "driver" in str(camera_input).lower() else "null - cámara exterior"}",
    "anomalous_interaction": "{"Descripción de interacción anómala si existe, o null" if "driver" in str(camera_input).lower() else "null - cámara exterior"}",
    "road_conditions": "{"null - cámara interior" if "driver" in str(camera_input).lower() else "Condiciones del camino y tráfico visible"}",
    "vehicle_state": "en_movimiento | detenido | maniobra | indeterminado"
  }},
  "decision_evidence": {{
    "emergency_signals": ["Lista de señales que sugieren emergencia real, o vacía si no hay"],
    "false_positive_signals": ["Lista de señales que sugieren falso positivo, o vacía si no hay"],
    "inconclusive_elements": ["Elementos que requieren más contexto, o vacía si no hay"]
  }},
  "recommendation": {{
    "action": "INTERVENIR | MONITOREAR | DESCARTAR",
    "reason": "Justificación en una línea"
  }},
  "image_quality": {{
    "is_usable": true | false,
    "issues": ["Lista de problemas: borrosa, oscura, obstruida, etc. o vacía si es clara"]
  }}
}}

Responde SOLO con el JSON, sin texto adicional antes o después."""
