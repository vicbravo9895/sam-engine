# Samsara Alert AI Service

Microservicio FastAPI que procesa alertas de Samsara usando **OpenAI GPT-4o** (vía LiteLLM) con Google ADK.

**Integrado con Laravel** mediante Redis Queue para procesamiento asíncrono en background.

## 📁 Estructura del Proyecto

```
ai-service/
├── main.py                      # Punto de entrada de FastAPI
├── requirements.txt             # Dependencias del proyecto
├── .env.example                 # Template de variables de entorno
│
├── config/                      # Configuración centralizada
│   ├── __init__.py
│   └── settings.py              # Settings: Samsara, OpenAI, Service, Breadcrumbs
│
├── agents/                      # Agentes ADK
│   ├── __init__.py
│   ├── prompts.py               # System instructions de cada agente
│   └── agent_definitions.py     # Definición de los 4 agentes (ingestion, panic, final, root)
│
├── tools/                       # Tools para agentes
│   ├── __init__.py
│   └── samsara_tools.py         # Tools de Samsara API (stats, events, camera)
│
├── core/                        # Lógica central del servicio
│   ├── __init__.py
│   └── runtime.py               # Runner y SessionService de ADK
│
└── api/                         # API FastAPI
    ├── __init__.py
    ├── routes.py                # Endpoints (stream, health)
    ├── models.py                # Pydantic models (request/response)
    └── breadcrumbs.py           # Lógica de creación de breadcrumbs
```

## 🎯 Separación de Responsabilidades

### 📂 `config/`
- **Propósito**: Configuración centralizada
- **Archivos**:
  - `settings.py`: Todas las variables de entorno y constantes
- **Responsabilidad**: Gestionar configuración del servicio

### 📂 `agents/`
- **Propósito**: Definición de agentes ADK
- **Archivos**:
  - `prompts.py`: System instructions separadas por agente
  - `agent_definitions.py`: Configuración de LlmAgent y SequentialAgent
- **Responsabilidad**: Lógica de negocio de los agentes

### 📂 `tools/`
- **Propósito**: Herramientas para los agentes
- **Archivos**:
  - `samsara_tools.py`: Funciones async para interactuar con Samsara API
- **Responsabilidad**: Integración con APIs externas

### 📂 `core/`
- **Propósito**: Infraestructura central de ADK
- **Archivos**:
  - `runtime.py`: Inicialización de Runner y SessionService
- **Responsabilidad**: Runtime de ejecución de agentes

### 📂 `api/`
- **Propósito**: Capa de API HTTP
- **Archivos**:
  - `routes.py`: Definición de endpoints FastAPI
  - `models.py`: Schemas Pydantic para request/response
  - `breadcrumbs.py`: Conversión de eventos ADK a breadcrumbs SSE
- **Responsabilidad**: Interfaz HTTP y streaming

### 📄 `main.py`
- **Propósito**: Punto de entrada
- **Responsabilidad**: Inicializar FastAPI y registrar rutas

### Flujo de datos

```
Samsara Webhook → Laravel → Crea SamsaraEvent → Redis Queue
                                                      ↓
                                                 Worker procesa
                                                      ↓
                                            FastAPI POST /alerts/ingest
                                                      ↓
                                            Sequential Agent Pipeline:
                                              1. ingestion_agent
                                              2. panic_investigator (con tools)
                                              3. final_agent
                                                      ↓
                                            Retorna assessment + message
                                                      ↓
                                            Laravel guarda resultados en DB

Frontend → Laravel API → GET /api/events/{id}/stream (SSE)
```

## 🚀 Instalación

### Con Poetry (recomendado)

```bash
# Instalar dependencias con Poetry
poetry install

# Activar el entorno virtual
poetry shell

# Configurar variables de entorno
cp .env.example .env
# Editar .env con tu OPENAI_API_KEY
```

### Con pip (alternativo)

```bash
# Generar requirements.txt desde Poetry
poetry export -f requirements.txt --output requirements.txt --without-hashes

# Instalar con pip
pip install -r requirements.txt
```

> 📖 **Nota**: Ver [OPENAI_SETUP.md](OPENAI_SETUP.md) para guía completa de configuración de OpenAI y LiteLLM.

## 🏃 Ejecución

```bash
# Con Poetry (recomendado)
poetry run python main.py

# O con uvicorn directamente
poetry run uvicorn main:app --reload --host 0.0.0.0 --port 8000

# Si ya estás en el shell de Poetry (poetry shell)
python main.py
# o
uvicorn main:app --reload
```

## 📡 Endpoints

### POST /alerts/ingest

Procesa una alerta de Samsara de forma síncrona (llamado por Laravel Job).

**Request:**
```json
{
  "event_id": 123,
  "payload": {
    "alertType": "panic",
    "vehicle": {"id": "123", "name": "Camión 1234-ABC"},
    "driver": {"id": "456", "name": "Juan Pérez"},
    "severity": "critical"
  }
}
```

**Response:**
```json
{
  "status": "success",
  "event_id": 123,
  "assessment": {...},
  "message": "🚨 ALERTA CRÍTICA..."
}
```

### GET /health

Health check del servicio.

## 🧪 Pruebas

```bash
# Enviar alerta de prueba
curl -X POST http://localhost:8000/alerts/ai/stream \
  -H "Content-Type: application/json" \
  -d '{
    "payload": {
      "alertType": "panic",
      "vehicle": {"id": "123", "name": "Camión 1234-ABC"},
      "driver": {"id": "456", "name": "Juan Pérez"},
      "time": "2024-01-15T14:32:00Z",
      "severity": "critical"
    }
  }'
```

## 🔧 Ventajas de esta Estructura

✅ **Modularidad**: Cada módulo tiene una responsabilidad clara  
✅ **Mantenibilidad**: Fácil encontrar y modificar código específico  
✅ **Testabilidad**: Cada módulo puede testearse independientemente  
✅ **Escalabilidad**: Fácil agregar nuevos agentes, tools o endpoints
### 1. **Configuración Centralizada** (`config/`)
- Todas las variables de entorno en un solo lugar
- Clases organizadas: `SamsaraConfig`, `OpenAIConfig`, `ServiceConfig`, `BreadcrumbConfig`
- Usa OpenAI GPT-4o y GPT-4o-mini vía LiteLLM
- Fácil de mantener y modificar

## 📝 Dónde Modificar Cada Cosa

| Necesito...                          | Archivo a modificar                |
|--------------------------------------|------------------------------------|
| Cambiar un prompt de agente          | `agents/prompts.py`                |
| Agregar un nuevo agente              | `agents/agent_definitions.py`      |
| Agregar una nueva tool               | `tools/samsara_tools.py`           |
| Cambiar configuración de API         | `config/settings.py`               |
| Agregar un nuevo endpoint            | `api/routes.py`                    |
| Modificar formato de breadcrumbs     | `api/breadcrumbs.py`               |
| Cambiar modelos de request/response  | `api/models.py`                    |
| Ajustar el Runner                    | `core/runtime.py`                  |
