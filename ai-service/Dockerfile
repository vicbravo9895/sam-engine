FROM python:3.11-slim

WORKDIR /app

# Variables de entorno para Poetry y pip (con timeouts extendidos para evitar errores de red)
ENV PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DEFAULT_TIMEOUT=300 \
    PIP_RETRIES=5 \
    POETRY_NO_INTERACTION=1 \
    POETRY_VENV_IN_PROJECT=1 \
    POETRY_CACHE_DIR=/tmp/poetry_cache \
    POETRY_HTTP_TIMEOUT=300 \
    POETRY_REQUESTS_TIMEOUT=300 \
    POETRY_INSTALLER_PARALLEL=false \
    POETRY_INSTALLER_MAX_WORKERS=1 \
    POETRY_VERSION=2.2.1

# Instalar dependencias del sistema y Poetry
# Keep curl for health checks
RUN apt-get update && \
    apt-get install -y --no-install-recommends curl && \
    pip install --upgrade pip && \
    pip install poetry==${POETRY_VERSION} && \
    poetry --version && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

# Configurar Poetry: no crear venv virtual (instalar directamente en el sistema)
RUN poetry config virtualenvs.create false

# Copiar archivos de configuración de Poetry primero (para mejor cacheo de Docker)
COPY pyproject.toml poetry.lock* ./

# Instalar dependencias (esta capa se cachea si no cambian pyproject.toml o poetry.lock)
RUN poetry install --only main --no-interaction --no-ansi && \
    rm -rf $POETRY_CACHE_DIR

# Copiar el código de la aplicación (último paso para mejor cacheo)
COPY . .

# Exponer el puerto interno
EXPOSE 8000

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=15s --retries=3 \
    CMD curl -f http://localhost:8000/health || exit 1

# Production: Run with limited workers to avoid OOM
# Each worker consumes ~300-500MB with LiteLLM + ADK
# Use --limit-max-requests to recycle workers and prevent memory leaks
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "2", "--limit-max-requests", "100"]
