"""
Configuración de Langfuse para observabilidad de AI.
Integra Langfuse con LiteLLM para tracking automático de llamadas a LLM.
"""

import os
from typing import Optional
from langfuse import Langfuse


# ============================================================================
# LANGFUSE CLIENT
# ============================================================================
class LangfuseConfig:
    """Configuración centralizada de Langfuse."""
    
    # Credenciales de Langfuse (self-hosted)
    PUBLIC_KEY = os.getenv("LANGFUSE_PUBLIC_KEY", "")
    SECRET_KEY = os.getenv("LANGFUSE_SECRET_KEY", "")
    # Support both LANGFUSE_HOST and LANGFUSE_BASE_URL for compatibility
    HOST = os.getenv("LANGFUSE_HOST") or os.getenv("LANGFUSE_BASE_URL", "http://langfuse-web:3000")
    
    # Cliente singleton
    _client: Optional[Langfuse] = None
    
    @classmethod
    def get_client(cls) -> Optional[Langfuse]:
        """
        Obtiene el cliente de Langfuse (singleton).
        Retorna None si las credenciales no están configuradas.
        """
        if not cls.PUBLIC_KEY or not cls.SECRET_KEY:
            print("⚠️  Langfuse no configurado - observabilidad deshabilitada")
            print("   Configure LANGFUSE_PUBLIC_KEY y LANGFUSE_SECRET_KEY en .env")
            return None
        
        if cls._client is None:
            cls._client = Langfuse(
                public_key=cls.PUBLIC_KEY,
                secret_key=cls.SECRET_KEY,
                host=cls.HOST
            )
            print(f"✅ Langfuse inicializado: {cls.HOST}")
        
        return cls._client
    
    @classmethod
    def is_enabled(cls) -> bool:
        """Verifica si Langfuse está habilitado."""
        return bool(cls.PUBLIC_KEY and cls.SECRET_KEY)


# ============================================================================
# LITELLM CALLBACK CONFIGURATION
# ============================================================================
def configure_litellm_callbacks():
    """
    Configura callbacks de Langfuse para LiteLLM.
    Esto permite tracking automático de todas las llamadas a LLM.
    """
    if not LangfuseConfig.is_enabled():
        return
    
    try:
        import litellm
        
        # Configurar variables de entorno para LiteLLM
        os.environ["LANGFUSE_PUBLIC_KEY"] = LangfuseConfig.PUBLIC_KEY
        os.environ["LANGFUSE_SECRET_KEY"] = LangfuseConfig.SECRET_KEY
        os.environ["LANGFUSE_HOST"] = LangfuseConfig.HOST
        
        # Habilitar callback de Langfuse en LiteLLM
        litellm.success_callback = ["langfuse"]
        litellm.failure_callback = ["langfuse"]
        
        print("✅ LiteLLM callbacks configurados para Langfuse")
        
    except ImportError:
        print("⚠️  LiteLLM no disponible - callbacks no configurados")


# Inicializar cliente al importar el módulo
langfuse_client = LangfuseConfig.get_client()

# Configurar callbacks de LiteLLM
configure_litellm_callbacks()
