"""
Módulo API del servicio.
Contiene rutas, modelos y lógica de breadcrumbs.
"""

from .routes import router
from .analytics_routes import router as analytics_router
from .analysis_routes import analysis_router

__all__ = ["router", "analytics_router", "analysis_router"]
