"""
Runtime de ADK: Runner y SessionService.
Inicializa la infraestructura necesaria para ejecutar agentes.

OPTIMIZACIÓN: Dos runners disponibles:
- runner: Pipeline completo (triage + investigator + final + notification)
- revalidation_runner: Pipeline sin triage (investigator + final + notification)
"""

from google.adk.sessions import InMemorySessionService
from google.adk.runners import Runner

from config import ServiceConfig
from agents import root_agent, revalidation_agent


# ============================================================================
# SESSION SERVICE
# ============================================================================
# En producción, considera usar un SessionService persistente (Redis, DB, etc.)
session_service = InMemorySessionService()


# ============================================================================
# RUNNER - Pipeline Completo (con Triage)
# ============================================================================
# Para procesamiento inicial de alertas nuevas
runner = Runner(
    agent=root_agent,
    app_name=ServiceConfig.APP_NAME,
    session_service=session_service,
)


# ============================================================================
# REVALIDATION RUNNER - Pipeline sin Triage
# ============================================================================
# OPTIMIZACIÓN: Para revalidaciones, saltamos el triage porque ya conocemos
# el tipo de alerta del procesamiento inicial. Esto ahorra ~2 minutos.
revalidation_runner = Runner(
    agent=revalidation_agent,
    app_name=ServiceConfig.APP_NAME,
    session_service=session_service,
)
