"""
Punto de entrada principal del servicio FastAPI.
Inicializa la aplicación y registra las rutas.
"""

import os
import time
import uuid
from contextlib import asynccontextmanager

from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse

from config import ServiceConfig
from api import router
from core.structured_logging import (
    setup_logging,
    get_logger,
    set_trace_id,
    get_trace_id,
    set_request_context,
)


# ============================================================================
# LOGGING SETUP
# ============================================================================
# Initialize structured logging before anything else
setup_logging(
    service="ai-service",
    environment=os.getenv("ENVIRONMENT", "production"),
    log_level=os.getenv("LOG_LEVEL", "INFO"),
    log_file=os.getenv("LOG_FILE", "/app/logs/ai-service.json"),
)

logger = get_logger(__name__)


# ============================================================================
# LIFESPAN (startup/shutdown)
# ============================================================================
@asynccontextmanager
async def lifespan(app: FastAPI):
    """Application lifespan handler for startup and shutdown."""
    logger.info("AI Service starting", context={
        "version": ServiceConfig.APP_VERSION,
        "environment": os.getenv("ENVIRONMENT", "production"),
        "host": ServiceConfig.HOST,
        "port": ServiceConfig.PORT,
    })
    yield
    logger.info("AI Service shutting down")


# ============================================================================
# FASTAPI APP
# ============================================================================
app = FastAPI(
    title="Samsara Alert AI Service",
    description="Microservicio que procesa alertas de Samsara usando Google ADK",
    version=ServiceConfig.APP_VERSION,
    lifespan=lifespan,
)


# ============================================================================
# MIDDLEWARE: Trace ID and Request Logging
# ============================================================================
@app.middleware("http")
async def trace_and_logging_middleware(request: Request, call_next):
    """
    Middleware that:
    1. Generates/propagates X-Trace-ID for distributed tracing
    2. Logs request start and completion
    3. Handles exceptions with proper logging
    """
    # Get or generate trace ID
    trace_id = request.headers.get("X-Trace-ID")
    if not trace_id:
        # Generate new trace ID: {hex_timestamp}-{random}
        timestamp_hex = hex(int(time.time() * 1000))[2:]
        trace_id = f"{timestamp_hex}-{uuid.uuid4().hex[:12]}"
    
    # Set trace ID in context for all logs in this request
    set_trace_id(trace_id)
    
    # Skip logging for health checks
    if request.url.path in ["/health", "/stats", "/up"]:
        response = await call_next(request)
        response.headers["X-Trace-ID"] = trace_id
        return response
    
    start_time = time.time()
    
    # Log request start
    logger.debug("Request started", context={
        "method": request.method,
        "path": request.url.path,
        "query": str(request.query_params),
        "client_ip": request.client.host if request.client else "unknown",
    })
    
    try:
        response = await call_next(request)
        
        duration_ms = round((time.time() - start_time) * 1000, 2)
        
        # Determine log level based on status code
        if response.status_code >= 500:
            log_level = "error"
        elif response.status_code >= 400:
            log_level = "warning"
        else:
            log_level = "info"
        
        # Log request completion
        getattr(logger, log_level)("Request completed", context={
            "method": request.method,
            "path": request.url.path,
            "status": response.status_code,
            "duration_ms": duration_ms,
        })
        
        # Add trace ID to response headers
        response.headers["X-Trace-ID"] = trace_id
        
        return response
        
    except Exception as e:
        duration_ms = round((time.time() - start_time) * 1000, 2)
        
        logger.error("Request failed", context={
            "method": request.method,
            "path": request.url.path,
            "duration_ms": duration_ms,
            "error": str(e),
            "error_type": type(e).__name__,
        })
        
        # Return error response with trace ID
        return JSONResponse(
            status_code=500,
            content={
                "error": "internal_server_error",
                "message": str(e),
                "trace_id": trace_id,
            },
            headers={"X-Trace-ID": trace_id},
        )


# ============================================================================
# ROUTES
# ============================================================================
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
