"""
Runtime de ADK: Runner y SessionService.
Inicializa la infraestructura necesaria para ejecutar agentes.
"""

from google.adk.sessions import InMemorySessionService
from google.adk.runners import Runner

from config import ServiceConfig
from agents import root_agent


# ============================================================================
# SESSION SERVICE
# ============================================================================
# En producción, considera usar un SessionService persistente (Redis, DB, etc.)
session_service = InMemorySessionService()


# ============================================================================
# RUNNER
# ============================================================================
# Runner global que ejecuta el pipeline de alertas
runner = Runner(
    agent=root_agent,
    app_name=ServiceConfig.APP_NAME,
    session_service=session_service,
)
