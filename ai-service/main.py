"""
Punto de entrada principal del servicio FastAPI.
Inicializa la aplicación y registra las rutas.
"""

from fastapi import FastAPI

from config import ServiceConfig
from api import router


# ============================================================================
# FASTAPI APP
# ============================================================================
app = FastAPI(
    title="Samsara Alert AI Service",
    description="Microservicio que procesa alertas de Samsara usando Google ADK",
    version=ServiceConfig.APP_VERSION
)

# Registrar rutas
app.include_router(router)


# ============================================================================
# MAIN (para desarrollo local)
# ============================================================================
if __name__ == "__main__":
    import uvicorn
    uvicorn.run(
        "main:app",
        host=ServiceConfig.HOST,
        port=ServiceConfig.PORT,
        reload=True
    )
