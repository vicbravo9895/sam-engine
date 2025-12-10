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
    POETRY_VERSION=1.8.0

# Instalar dependencias del sistema y Poetry
RUN apt-get update && \
    apt-get install -y --no-install-recommends curl && \
    pip install --upgrade pip && \
    pip install poetry==${POETRY_VERSION} && \
    poetry --version && \
    apt-get purge -y curl && \
    apt-get autoremove -y && \
    rm -rf /var/lib/apt/lists/*

# Configurar Poetry: no crear venv virtual (instalar directamente en el sistema)
RUN poetry config virtualenvs.create false

# Copiar archivos de configuración de Poetry primero (para mejor cacheo de Docker)
COPY pyproject.toml poetry.lock* ./

# Instalar dependencias (esta capa se cachea si no cambian pyproject.toml o poetry.lock)
RUN poetry install --no-dev --no-interaction --no-ansi && \
    rm -rf $POETRY_CACHE_DIR

# Copiar el código de la aplicación (último paso para mejor cacheo)
COPY . .

# Exponer el puerto interno
EXPOSE 8000

# Comando para levantar FastAPI con Uvicorn
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000", "--reload"]
