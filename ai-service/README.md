# AI Service

Servicio de AI construido con FastAPI, diseñado con una arquitectura escalable y modular.

## Estructura del Proyecto

```
ai-service/
├── app/
│   ├── __init__.py
│   ├── main.py                 # Punto de entrada de la aplicación
│   │
│   ├── api/                    # Endpoints de la API
│   │   ├── __init__.py
│   │   └── v1/                 # Versión 1 de la API
│   │       ├── __init__.py     # Router principal de v1
│   │       ├── health.py       # Endpoints de health check
│   │       └── agents.py       # Endpoints de agentes
│   │
│   ├── agents/                 # Agentes de AI
│   │   ├── __init__.py
│   │   ├── base.py             # Clase base para agentes
│   │   ├── manager.py          # Gestor de agentes
│   │   └── example_agent.py    # Ejemplo de implementación
│   │
│   ├── tools/                  # Herramientas para agentes
│   │   ├── __init__.py
│   │   ├── base.py             # Clase base para herramientas
│   │   └── example_tool.py    # Ejemplo de implementación
│   │
│   ├── models/                 # Modelos de datos (Pydantic)
│   │   ├── __init__.py
│   │   └── schemas.py          # Schemas de request/response
│   │
│   ├── services/               # Servicios de negocio
│   │   └── __init__.py
│   │
│   ├── core/                   # Funcionalidades core
│   │   ├── __init__.py
│   │   ├── exceptions.py       # Excepciones personalizadas
│   │   └── middleware.py      # Middleware de la aplicación
│   │
│   ├── config/                 # Configuración
│   │   ├── __init__.py
│   │   └── settings.py         # Configuración de la aplicación
│   │
│   └── utils/                  # Utilidades
│       └── __init__.py
│
├── Dockerfile
├── pyproject.toml          # Configuración de Poetry
├── poetry.lock            # Lock file de dependencias
├── requirements.txt        # (legacy, usar Poetry)
├── README.md
└── SETUP.md               # Guía de instalación y setup
```

## Cómo Agregar un Nuevo Agente

1. Crea un nuevo archivo en `app/agents/` (ej: `my_agent.py`)
2. Hereda de `BaseAgent` e implementa el método `execute()`:

```python
from app.agents.base import BaseAgent
from typing import Dict, Any

class MyAgent(BaseAgent):
    def __init__(self):
        super().__init__(
            name="my_agent",
            description="Descripción de mi agente"
        )
    
    async def execute(self, task: Dict[str, Any]) -> Dict[str, Any]:
        # Tu lógica aquí
        return {"result": "success"}
```

3. Regístralo en `app/agents/manager.py` en el método `_register_default_agents()`:

```python
from app.agents.my_agent import MyAgent
self.register_agent(MyAgent())
```

## Cómo Agregar una Nueva Herramienta

1. Crea un nuevo archivo en `app/tools/` (ej: `my_tool.py`)
2. Hereda de `BaseTool` e implementa el método `execute()`:

```python
from app.tools.base import BaseTool
from pydantic import BaseModel
from typing import Dict, Any

class MyToolInput(BaseModel):
    param1: str
    param2: int = 1

class MyTool(BaseTool):
    def __init__(self):
        super().__init__(
            name="my_tool",
            description="Descripción de mi herramienta",
            input_schema=MyToolInput
        )
    
    async def execute(self, **kwargs) -> Dict[str, Any]:
        validated_input = self.validate_input(**kwargs)
        # Tu lógica aquí
        return {"result": "success"}
```

3. Agrega la herramienta a un agente:

```python
from app.tools.my_tool import MyTool
agent.add_tool(MyTool())
```

## Cómo Agregar un Nuevo Endpoint

1. Crea un nuevo archivo en `app/api/v1/` (ej: `my_endpoints.py`)
2. Define tus rutas:

```python
from fastapi import APIRouter
from app.models.schemas import BaseResponse

router = APIRouter()

@router.get("/my-endpoint", response_model=BaseResponse)
async def my_endpoint():
    return BaseResponse(message="Success", data={})
```

3. Inclúyelo en `app/api/v1/__init__.py`:

```python
from app.api.v1 import my_endpoints
api_router.include_router(my_endpoints.router, prefix="/my-prefix", tags=["my-tag"])
```

## Instalación y Setup

Este proyecto usa **Poetry** para el manejo de dependencias. Para instrucciones detalladas de instalación y configuración del entorno de desarrollo, consulta [SETUP.md](./SETUP.md).

### Setup Rápido

```bash
# Instalar Poetry (si no lo tienes)
curl -sSL https://install.python-poetry.org | python3 -

# Instalar dependencias
cd ai-service
poetry install

# Activar el entorno virtual
poetry shell

# O ejecutar comandos con poetry run
poetry run uvicorn app.main:app --reload
```

### Configurar el Editor para Autocompletado

**VS Code:**
1. Abre la paleta de comandos (`Cmd+Shift+P` / `Ctrl+Shift+P`)
2. Busca "Python: Select Interpreter"
3. Selecciona el intérprete del entorno virtual de Poetry:
   ```bash
   poetry env info --path
   ```

**PyCharm:**
- Settings → Project → Python Interpreter → Add → Poetry Environment

## Configuración

Las variables de entorno se cargan desde `.env`. Variables disponibles en `app/config/settings.py`.

## Ejecución

### Con Docker Compose
```bash
docker-compose up ai-service
```

### Localmente con Poetry
```bash
poetry run uvicorn app.main:app --reload
```

### Localmente (sin Poetry - legacy)
```bash
pip install -r requirements.txt
uvicorn app.main:app --reload
```

## Endpoints Disponibles

- `GET /api/v1/` - Endpoint raíz
- `GET /api/v1/health` - Health check
- `GET /api/v1/agents` - Listar agentes
- `GET /api/v1/agents/{agent_name}` - Información de un agente
- `POST /api/v1/agents/{agent_name}/execute` - Ejecutar un agente

