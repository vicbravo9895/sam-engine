"""
Tools para interactuar con la API de Samsara.
Estas funciones son usadas por el panic_investigator agent.
"""

import httpx
import functools
import json
from typing import Dict, Any, List, Optional
from datetime import datetime, timedelta

from config import SamsaraConfig
from core.context import current_langfuse_span


def trace_tool(func):
    """
    Decorador para rastrear la ejecución de tools en Langfuse.
    Crea un span hijo del span actual del agente.
    """
    @functools.wraps(func)
    async def wrapper(*args, **kwargs):
        span = current_langfuse_span.get()
        if not span:
            return await func(*args, **kwargs)
        
        # Crear nombre del span basado en la función
        tool_name = func.__name__
        
        # Preparar input para el span
        input_data = {
            "args": args,
            "kwargs": kwargs
        }
        
        # Iniciar span de tool
        tool_span = span.span(
            name=f"tool_{tool_name}",
            input=input_data,
            metadata={
                "tool_name": tool_name,
                "type": "tool_execution"
            }
        )
        
        try:
            # Ejecutar la tool
            result = await func(*args, **kwargs)
            
            # Registrar output exitoso
            tool_span.end(output=result)
            return result
            
        except Exception as e:
            # Registrar error
            tool_span.end(
                level="ERROR",
                status_message=str(e),
                output={"error": str(e)}
            )
            raise e
            
    return wrapper


@trace_tool
async def get_vehicle_stats(vehicle_id: str) -> Dict[str, Any]:
    """
    Obtiene estadísticas actuales del vehículo.
    
    Args:
        vehicle_id: ID del vehículo en Samsara
        
    Returns:
        Dict con estadísticas del vehículo:
        {
            "vehicle_id": "...",
            "current_location": {"lat": ..., "lon": ...},
            "speed_kmh": ...,
            "engine_status": "on | off",
            "fuel_level_percent": ...,
            "last_updated": "ISO timestamp"
        }
    """
    async with httpx.AsyncClient(timeout=SamsaraConfig.REQUEST_TIMEOUT) as client:
        try:
            url = f"{SamsaraConfig.API_BASE}/fleet/vehicles/{vehicle_id}/stats"
            headers = {
                "Authorization": f"Bearer {SamsaraConfig.API_TOKEN}",
                "Content-Type": "application/json"
            }
            
            response = await client.get(url, headers=headers)
            
            # Si la API real no está disponible, devolver datos simulados
            if response.status_code == 404 or not SamsaraConfig.API_TOKEN:
                return _get_simulated_vehicle_stats(vehicle_id)
            
            response.raise_for_status()
            return response.json()
            
        except Exception as e:
            return _get_simulated_vehicle_stats(vehicle_id, error=str(e))


@trace_tool
async def get_vehicle_event_history(
    vehicle_id: str, 
    minutes_back: int = 60
) -> List[Dict[str, Any]]:
    """
    Obtiene el historial de eventos del vehículo en los últimos N minutos.
    
    Args:
        vehicle_id: ID del vehículo
        minutes_back: Minutos hacia atrás para buscar eventos (default: 60)
        
    Returns:
        Lista de eventos con tipo, timestamp, severidad, ubicación y detalles.
    """
    async with httpx.AsyncClient(timeout=SamsaraConfig.REQUEST_TIMEOUT) as client:
        try:
            since_time = datetime.utcnow() - timedelta(minutes=minutes_back)
            since_iso = since_time.isoformat()
            
            url = f"{SamsaraConfig.API_BASE}/fleet/vehicles/{vehicle_id}/events"
            headers = {
                "Authorization": f"Bearer {SamsaraConfig.API_TOKEN}",
                "Content-Type": "application/json"
            }
            params = {
                "since": since_iso,
                "limit": 100
            }
            
            response = await client.get(url, headers=headers, params=params)
            
            # Si la API real no está disponible, devolver datos simulados
            if response.status_code == 404 or not SamsaraConfig.API_TOKEN:
                return _get_simulated_event_history(vehicle_id)
            
            response.raise_for_status()
            return response.json().get("events", [])
            
        except Exception as e:
            return _get_simulated_event_history(vehicle_id, error=str(e))


@trace_tool
async def get_vehicle_camera_snapshot(
    vehicle_id: str, 
    at_time_utc: Optional[str] = None
) -> Dict[str, Any]:
    """
    Solicita un snapshot de la cámara del vehículo en un momento específico.
    
    Args:
        vehicle_id: ID del vehículo
        at_time_utc: Timestamp UTC en formato ISO (opcional, default: ahora)
        
    Returns:
        Dict con información del snapshot (URL, timestamp, tipo de cámara, estado).
    """
    async with httpx.AsyncClient(timeout=SamsaraConfig.REQUEST_TIMEOUT) as client:
        try:
            if not at_time_utc:
                at_time_utc = datetime.utcnow().isoformat()
            
            url = f"{SamsaraConfig.API_BASE}/media/request_snapshot"
            headers = {
                "Authorization": f"Bearer {SamsaraConfig.API_TOKEN}",
                "Content-Type": "application/json"
            }
            payload = {
                "vehicle_id": vehicle_id,
                "timestamp": at_time_utc,
                "camera_type": "dashcam"
            }
            
            response = await client.post(url, headers=headers, json=payload)
            
            # Si la API real no está disponible, devolver datos simulados
            if response.status_code == 404 or not SamsaraConfig.API_TOKEN:
                return _get_simulated_camera_snapshot(vehicle_id, at_time_utc)
            
            response.raise_for_status()
            return response.json()
            
        except Exception as e:
            return _get_simulated_camera_snapshot(vehicle_id, at_time_utc, error=str(e))


# ============================================================================
# FUNCIONES AUXILIARES PARA DATOS SIMULADOS
# ============================================================================

def _get_simulated_vehicle_stats(vehicle_id: str, error: str = None) -> Dict[str, Any]:
    """Genera estadísticas simuladas del vehículo."""
    return {
        "vehicle_id": vehicle_id,
        "current_location": {"lat": 19.4326, "lon": -99.1332},
        "speed_kmh": 45.5,
        "engine_status": "on",
        "fuel_level_percent": 67.3,
        "last_updated": datetime.utcnow().isoformat(),
        "simulated": True,
        **({"error": error} if error else {})
    }


def _get_simulated_event_history(vehicle_id: str, error: str = None) -> List[Dict[str, Any]]:
    """Genera historial de eventos simulado."""
    events = [
        {
            "event_type": "harsh_braking",
            "timestamp_utc": (datetime.utcnow() - timedelta(minutes=15)).isoformat(),
            "severity": "warning",
            "location": {"lat": 19.4326, "lon": -99.1332},
            "details": {"deceleration_g": 0.8}
        },
        {
            "event_type": "panic_button",
            "timestamp_utc": (datetime.utcnow() - timedelta(minutes=5)).isoformat(),
            "severity": "critical",
            "location": {"lat": 19.4330, "lon": -99.1340},
            "details": {"button_pressed": True}
        }
    ]
    
    if error:
        events.append({
            "event_type": "error",
            "timestamp_utc": datetime.utcnow().isoformat(),
            "severity": "info",
            "simulated": True,
            "error": error
        })
    
    return events


def _get_simulated_camera_snapshot(
    vehicle_id: str, 
    at_time_utc: str, 
    error: str = None
) -> Dict[str, Any]:
    """Genera snapshot de cámara simulado."""
    return {
        "vehicle_id": vehicle_id,
        "snapshot_url": f"https://placeholder.example.com/snapshot_{vehicle_id}.jpg" if not error else None,
        "timestamp_utc": at_time_utc,
        "camera_type": "dashcam",
        "status": "available" if not error else "unavailable",
        "analysis": "Simulación: Vehículo en zona urbana, tráfico moderado",
        "simulated": True,
        **({"error": error} if error else {})
    }
