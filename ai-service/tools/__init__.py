"""
Módulo de tools para los agentes ADK.
"""

from .samsara_tools import (
    get_vehicle_stats,
    get_vehicle_info,
    get_driver_assignment,
    get_camera_media,
    get_safety_events
)

from .twilio_tools import (
    send_sms,
    send_whatsapp,
    make_call_simple,
    make_call_with_callback
)

__all__ = [
    "get_vehicle_stats",
    "get_vehicle_info",
    "get_driver_assignment",
    "get_camera_media",
    "get_safety_events",
    "send_sms",
    "send_whatsapp",
    "make_call_simple",
    "make_call_with_callback"
]

