"""
Configuración centralizada del servicio.
Todas las variables de entorno y constantes se definen aquí.
"""

import os
from dotenv import load_dotenv

# Cargar variables de entorno
load_dotenv()


# ============================================================================
# CONFIGURACIÓN DE CONCURRENCIA Y ESCALABILIDAD
# ============================================================================
class ConcurrencyConfig:
    """Configuración para control de concurrencia del servicio."""
    
    # Máximo de peticiones procesándose simultáneamente
    # Esto protege contra sobrecarga de memoria y rate limits de OpenAI
    MAX_CONCURRENT_REQUESTS = int(os.getenv("MAX_CONCURRENT_REQUESTS", "5"))
    
    # Timeout para adquirir el semáforo (segundos)
    # Si se excede, retorna 503 Service Unavailable
    SEMAPHORE_TIMEOUT = float(os.getenv("SEMAPHORE_TIMEOUT", "30.0"))
    
    # Habilitar/deshabilitar el rate limiting
    RATE_LIMITING_ENABLED = os.getenv("RATE_LIMITING_ENABLED", "true").lower() == "true"


# ============================================================================
# CONFIGURACIÓN DE SAMSARA API
# ============================================================================
class SamsaraConfig:
    """Configuración para la API de Samsara."""
    
    API_BASE = os.getenv("SAMSARA_API_BASE", "https://api.samsara.com/v1")
    API_TOKEN = os.getenv("SAMSARA_API_TOKEN", "")
    REQUEST_TIMEOUT = 15.0  # segundos


# ============================================================================
# CONFIGURACIÓN DE OPENAI (vía LiteLLM)
# ============================================================================
class OpenAIConfig:
    """Configuración para OpenAI usando LiteLLM en ADK."""
    
    API_KEY = os.getenv("OPENAI_API_KEY", "")
    
    # Modelos a usar
    MODEL_GPT4O = "openai/gpt-4o"           # Modelo principal (más potente)
    MODEL_GPT4O_MINI = "gpt-4o-mini" # Modelo rápido y económico


# ============================================================================
# CONFIGURACIÓN DEL SERVICIO
# ============================================================================
class ServiceConfig:
    """Configuración general del servicio FastAPI."""
    
    HOST = os.getenv("SERVICE_HOST", "0.0.0.0")
    PORT = int(os.getenv("SERVICE_PORT", "8000"))
    
    # Nombre de la aplicación ADK
    APP_NAME = "alert_app"
    
    # Usuario por defecto para sesiones
    DEFAULT_USER_ID = "monitor"

    APP_VERSION = "0.1.0"


# ============================================================================
# CONFIGURACIÓN DE BREADCRUMBS
# ============================================================================
class BreadcrumbConfig:
    """Configuración para los breadcrumbs SSE."""
    
    # Longitud máxima de previews
    MAX_PREVIEW_LENGTH = 200
    
    # Emojis para mini_summary
    EMOJI_INGESTION = "📥"
    EMOJI_INVESTIGATION = "🔍"
    EMOJI_FINALIZATION = "📝"
    EMOJI_TOOL_CALL = "🔧"
    EMOJI_TOOL_RESULT = "✅"
    EMOJI_COMPLETE = "✅"
    EMOJI_ERROR = "❌"


# ============================================================================
# CONFIGURACIÓN DE TWILIO
# ============================================================================
class TwilioConfig:
    """Configuración para Twilio SMS, WhatsApp y Voice."""
    
    # Autenticación estándar (Account SID + Auth Token)
    ACCOUNT_SID = os.getenv("TWILIO_ACCOUNT_SID", "")
    AUTH_TOKEN = os.getenv("TWILIO_AUTH_TOKEN", "")
    
    # Phone numbers (E.164 format)
    PHONE_NUMBER = os.getenv("TWILIO_PHONE_NUMBER", "")
    WHATSAPP_NUMBER = os.getenv("TWILIO_WHATSAPP_NUMBER", "")
    
    # Callback URL for voice calls (Laravel endpoint)
    CALLBACK_BASE_URL = os.getenv("TWILIO_CALLBACK_URL", "")
    
    @classmethod
    def is_configured(cls) -> bool:
        """Check if Twilio credentials are configured."""
        return bool(cls.ACCOUNT_SID and cls.AUTH_TOKEN and cls.PHONE_NUMBER)
