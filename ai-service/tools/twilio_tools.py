"""
Twilio tools para el agente de notificaciones.
Proporciona funciones para SMS, WhatsApp y llamadas de voz.
"""

import os
from typing import Optional
from twilio.rest import Client
from twilio.base.exceptions import TwilioRestException

from config import TwilioConfig


# ============================================================================
# TWILIO CLIENT
# ============================================================================
def _get_twilio_client() -> Optional[Client]:
    """Create and return Twilio client if configured."""
    if not TwilioConfig.is_configured():
        print("[TWILIO] Not configured - credentials missing")
        return None
    
    return Client(TwilioConfig.ACCOUNT_SID, TwilioConfig.AUTH_TOKEN)


# ============================================================================
# SMS TOOL
# ============================================================================
def send_sms(to: str, message: str) -> dict:
    """
    Envía un mensaje SMS usando Twilio.
    
    Args:
        to: Número de teléfono destino en formato E.164 (ej: +5218117658890)
        message: Contenido del mensaje SMS (máximo ~160 caracteres por segmento)
    
    Returns:
        dict con status, sid del mensaje, y detalles
    """
    client = _get_twilio_client()
    
    if not client:
        return {
            "success": False,
            "channel": "sms",
            "error": "Twilio no está configurado. Credenciales faltantes.",
            "simulated": True,
            "to": to,
            "message_preview": message[:100]
        }
    
    try:
        msg = client.messages.create(
            body=message,
            from_=TwilioConfig.PHONE_NUMBER,
            to=to
        )
        
        return {
            "success": True,
            "channel": "sms",
            "sid": msg.sid,
            "to": to,
            "status": msg.status,
            "message_preview": message[:100]
        }
    except TwilioRestException as e:
        return {
            "success": False,
            "channel": "sms",
            "error": str(e),
            "to": to
        }


# ============================================================================
# WHATSAPP TOOL
# ============================================================================
def send_whatsapp(to: str, message: str) -> dict:
    """
    Envía un mensaje de WhatsApp usando Twilio.
    
    Args:
        to: Número de teléfono destino en formato E.164 (ej: +5218117658890)
           Se agregará prefijo 'whatsapp:' automáticamente
        message: Contenido del mensaje WhatsApp
    
    Returns:
        dict con status, sid del mensaje, y detalles
    """
    client = _get_twilio_client()
    
    # Ensure whatsapp: prefix
    whatsapp_to = f"whatsapp:{to}" if not to.startswith("whatsapp:") else to
    
    if not client:
        return {
            "success": False,
            "channel": "whatsapp",
            "error": "Twilio no está configurado. Credenciales faltantes.",
            "simulated": True,
            "to": whatsapp_to,
            "message_preview": message[:100]
        }
    
    if not TwilioConfig.WHATSAPP_NUMBER:
        return {
            "success": False,
            "channel": "whatsapp",
            "error": "Número de WhatsApp de Twilio no configurado.",
            "to": whatsapp_to
        }
    
    try:
        msg = client.messages.create(
            body=message,
            from_=TwilioConfig.WHATSAPP_NUMBER,
            to=whatsapp_to
        )
        
        return {
            "success": True,
            "channel": "whatsapp",
            "sid": msg.sid,
            "to": whatsapp_to,
            "status": msg.status,
            "message_preview": message[:100]
        }
    except TwilioRestException as e:
        return {
            "success": False,
            "channel": "whatsapp",
            "error": str(e),
            "to": whatsapp_to
        }


# ============================================================================
# VOICE CALL - SIMPLE (TTS only, no callback)
# ============================================================================
def make_call_simple(to: str, message: str) -> dict:
    """
    Realiza una llamada telefónica con un mensaje de texto a voz (TTS).
    La llamada reproduce el mensaje y termina sin esperar respuesta.
    
    Args:
        to: Número de teléfono destino en formato E.164 (ej: +5218117658890)
        message: Mensaje que se reproducirá con TTS en español
    
    Returns:
        dict con status, sid de la llamada, y detalles
    """
    client = _get_twilio_client()
    
    if not client:
        return {
            "success": False,
            "channel": "call_simple",
            "error": "Twilio no está configurado. Credenciales faltantes.",
            "simulated": True,
            "to": to,
            "message_preview": message[:100]
        }
    
    # TwiML for simple TTS
    twiml = f'<Response><Say language="es-MX" voice="Polly.Mia">{message}</Say></Response>'
    
    try:
        call = client.calls.create(
            twiml=twiml,
            from_=TwilioConfig.PHONE_NUMBER,
            to=to
        )
        
        return {
            "success": True,
            "channel": "call_simple",
            "sid": call.sid,
            "to": to,
            "status": call.status,
            "message_preview": message[:100]
        }
    except TwilioRestException as e:
        return {
            "success": False,
            "channel": "call_simple",
            "error": str(e),
            "to": to
        }


# ============================================================================
# VOICE CALL - WITH CALLBACK (waits for operator input)
# ============================================================================
def make_call_with_callback(to: str, message: str, event_id: int) -> dict:
    """
    Realiza una llamada telefónica con opción de confirmar.
    El operador puede presionar 1 para confirmar la recepción de la alerta.
    La respuesta se envía al webhook de Laravel para registrar en BD.
    
    Args:
        to: Número de teléfono destino en formato E.164 (ej: +5218117658890)
        message: Mensaje de alerta que se reproducirá
        event_id: ID del evento en la base de datos de Laravel
    
    Returns:
        dict con status, sid de la llamada, y detalles
    """
    client = _get_twilio_client()
    
    if not client:
        return {
            "success": False,
            "channel": "call_callback",
            "error": "Twilio no está configurado. Credenciales faltantes.",
            "simulated": True,
            "to": to,
            "event_id": event_id,
            "message_preview": message[:100]
        }
    
    if not TwilioConfig.CALLBACK_BASE_URL:
        return {
            "success": False,
            "channel": "call_callback",
            "error": "URL de callback no configurada. Use TWILIO_CALLBACK_URL.",
            "to": to,
            "event_id": event_id
        }
    
    # Callback URL for when operator responds
    callback_url = f"{TwilioConfig.CALLBACK_BASE_URL}/voice-callback?event_id={event_id}"
    status_callback_url = f"{TwilioConfig.CALLBACK_BASE_URL}/voice-status"
    
    # TwiML with Gather to capture keypress
    twiml = f'''<Response>
        <Say language="es-MX" voice="Polly.Mia">{message}</Say>
        <Gather numDigits="1" action="{callback_url}" method="POST" timeout="10">
            <Say language="es-MX" voice="Polly.Mia">
                Presione 1 para confirmar recepción de la alerta.
                Presione 2 para escalar a supervisión.
            </Say>
        </Gather>
        <Say language="es-MX" voice="Polly.Mia">
            No se recibió respuesta. La alerta quedará pendiente.
        </Say>
    </Response>'''
    
    try:
        call = client.calls.create(
            twiml=twiml,
            from_=TwilioConfig.PHONE_NUMBER,
            to=to,
            status_callback=status_callback_url,
            status_callback_event=['completed', 'busy', 'no-answer', 'failed', 'canceled']
        )
        
        return {
            "success": True,
            "channel": "call_callback",
            "sid": call.sid,
            "to": to,
            "status": call.status,
            "event_id": event_id,
            "callback_url": callback_url,
            "message_preview": message[:100]
        }
    except TwilioRestException as e:
        return {
            "success": False,
            "channel": "call_callback",
            "error": str(e),
            "to": to,
            "event_id": event_id
        }
