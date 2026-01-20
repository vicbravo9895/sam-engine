"""
Servicio para analizar imagenes pre-cargadas desde Laravel.

Laravel ya pre-carga las URLs de las imagenes de Samsara en el payload.
Este servicio analiza esas imagenes con Vision AI ANTES de que el
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
    Analiza las imagenes pre-cargadas desde Laravel con Vision AI.
    
    Busca imagenes en:
    - preloaded_data.camera_media.items (procesamiento inicial)
    - revalidation_data.camera_media_since_last_check.items (revalidaciones)
    
    Args:
        payload: El payload del evento con datos pre-cargados
        
    Returns:
        Dict con analisis de las imagenes, o None si no hay imagenes
    """
    # Extraer imagenes pre-cargadas
    preloaded = payload.get('preloaded_data', {})
    revalidation = payload.get('revalidation_data', {})
    
    # Buscar items de camara
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
    
    # Filtrar solo imagenes (no videos)
    image_items = [
        item for item in camera_items 
        if item.get('media_type') == 'image' or 'image' in str(item.get('url', ''))
    ]
    
    if not image_items:
        logger.info("No image items found (all items may be videos)")
        return None
    
    logger.info(f"Analyzing {len(image_items)} preloaded images with Vision AI")
    
    # Analizar imagenes
    analyses = await _analyze_images(image_items)
    
    return {
        "total_images_analyzed": len(analyses),
        "analyses": analyses,
        "source": "preloaded_data"
    }


async def _analyze_images(image_items: List[Dict]) -> List[Dict]:
    """
    Analiza una lista de imagenes con GPT-4o Vision EN PARALELO.
    
    Args:
        image_items: Lista de items de imagen con URLs
        
    Returns:
        Lista de analisis por imagen
    """
    import asyncio
    
    if not OpenAIConfig.API_KEY:
        logger.warning("OPENAI_API_KEY not configured, skipping image analysis")
        return []
    
    # Limitar a maximo 4 imagenes para evitar timeouts extremos
    MAX_IMAGES = 4
    if len(image_items) > MAX_IMAGES:
        logger.info(f"Limiting analysis to {MAX_IMAGES} images (had {len(image_items)})")
        image_items = image_items[:MAX_IMAGES]
    
    async def analyze_single_image(idx: int, item: Dict, http_client: httpx.AsyncClient) -> Dict:
        """Analiza una sola imagen."""
        try:
            # Extraer URL
            url = item.get('url') or item.get('download_url')
            if not url:
                return {"error": "No URL found", "input": item.get('camera_type', 'unknown')}
            
            camera_input = item.get('camera_type') or item.get('input', 'unknown')
            captured_at = item.get('captured_at') or item.get('startTime', 'unknown')
            
            # Descargar imagen
            response = await http_client.get(url)
            if response.status_code != 200:
                logger.warning(f"Failed to download image {idx}: status {response.status_code}")
                return {"error": f"Download failed: {response.status_code}", "input": camera_input}
            
            image_data = response.content
            image_size_kb = len(image_data) / 1024
            
            # Convertir a base64
            base64_image = base64.b64encode(image_data).decode('utf-8')
            
            # Determinar tipo de camara
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
            
            logger.info(f"Analyzed image {idx + 1}/{len(image_items)}: {camera_input}")
            return analysis
            
        except Exception as e:
            logger.error(f"Error analyzing image {idx}: {e}")
            return {
                "error": str(e),
                "input": item.get('camera_type', 'unknown')
            }
    
    # Ejecutar analisis EN PARALELO
    async with httpx.AsyncClient(timeout=30.0) as http_client:
        tasks = [
            analyze_single_image(idx, item, http_client)
            for idx, item in enumerate(image_items)
        ]
        analyses = await asyncio.gather(*tasks)
    
    return list(analyses)


def _get_vision_prompt(camera_type: str, camera_input: str) -> str:
    """Genera el prompt para Vision AI."""
    return f"""Eres un analista de seguridad de flotas vehiculares. Esta imagen proviene de una dashcam {camera_type} de un vehiculo comercial que activo una alerta de seguridad (posible boton de panico, evento de seguridad, o incidente).

Tu tarea es proporcionar un ANALISIS OBJETIVO en formato JSON para ayudar al equipo de monitoreo a decidir si la alerta requiere intervencion inmediata o puede considerarse un falso positivo.

IMPORTANTE: 
- NO identifiques personas ni proporciones datos personales. Enfocate en el ESTADO SITUACIONAL y CONTEXTO.
- Escribe TODO el texto en espanol SIN ACENTOS (ASCII puro). Ejemplo: "descripcion" no "descripción", "vehiculo" no "vehículo".

Responde UNICAMENTE con un JSON valido (sin bloques de codigo markdown) con esta estructura:

{{
  "alert_level": "NORMAL | ATENCION | ALERTA | CRITICO",
  "scene_description": "Descripcion breve de la escena en maximo 2 lineas",
  "security_indicators": {{
    "driver_state": "{"Estado aparente del conductor: relajado, tenso, concentrado, ausente, etc." if "driver" in str(camera_input).lower() else "null - camara exterior"}",
    "anomalous_interaction": "{"Descripcion de interaccion anomala si existe, o null" if "driver" in str(camera_input).lower() else "null - camara exterior"}",
    "road_conditions": "{"null - camara interior" if "driver" in str(camera_input).lower() else "Condiciones del camino y trafico visible"}",
    "vehicle_state": "en_movimiento | detenido | maniobra | indeterminado"
  }},
  "decision_evidence": {{
    "emergency_signals": ["Lista de senales que sugieren emergencia real, o vacia si no hay"],
    "false_positive_signals": ["Lista de senales que sugieren falso positivo, o vacia si no hay"],
    "inconclusive_elements": ["Elementos que requieren mas contexto, o vacia si no hay"]
  }},
  "recommendation": {{
    "action": "INTERVENIR | MONITOREAR | DESCARTAR",
    "reason": "Justificacion en una linea"
  }},
  "image_quality": {{
    "is_usable": true | false,
    "issues": ["Lista de problemas: borrosa, oscura, obstruida, etc. o vacia si es clara"]
  }}
}}

Responde SOLO con el JSON, sin texto adicional antes o despues."""
