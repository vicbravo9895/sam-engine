# SAM - Samsara Alert Monitor

Sistema de monitoreo y procesamiento inteligente de alertas de flotas usando AI.

## Stack Tecnológico

| Capa | Tecnología | Versión |
|------|------------|---------|
| **Backend** | Laravel + PHP | 12.x / 8.2 |
| **Frontend** | React + Inertia + TypeScript | 19.x / 2.x |
| **AI Service** | Python + FastAPI + Google ADK | 3.11 / 1.18+ |
| **Base de datos** | PostgreSQL | 18 |
| **Queue** | Redis + Horizon | - |
| **LLM** | OpenAI GPT-4o via LiteLLM | - |
| **Telematics** | Samsara SDK | 4.1.0 |
| **Notificaciones** | Twilio (SMS, WhatsApp, Voice) | 9.x |
| **Observability** | Langfuse + ClickHouse | 3.x |
| **Container** | Docker + Laravel Sail | - |
| **UI Components** | Radix UI + Tailwind CSS | 4.x |

---

## Arquitectura del Sistema

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│   Samsara API   │────▶│  Laravel Backend │────▶│   AI Service    │
│   (Webhooks)    │     │  (Queue Jobs)    │     │  (FastAPI/ADK)  │
└─────────────────┘     └──────────────────┘     └─────────────────┘
                               │                        │
                               ▼                        ▼
                        ┌──────────────┐         ┌─────────────┐
                        │  PostgreSQL  │         │   Langfuse  │
                        │    Redis     │         │ (Tracing)   │
                        └──────────────┘         └─────────────┘
                               │
                               ▼
                        ┌──────────────────┐
                        │  React Frontend  │
                        │  (Inertia SSR)   │
                        └──────────────────┘
```

---

## Estructura de Carpetas

### Laravel Backend (`/`)

```
app/
├── Http/Controllers/      # Controladores HTTP
│   ├── SamsaraWebhookController.php   # Recibe webhooks de Samsara
│   ├── SamsaraEventController.php     # API y vistas de eventos
│   └── TwilioCallbackController.php   # Callbacks de llamadas Twilio
├── Jobs/                  # Queue Jobs (Redis/Horizon)
│   ├── ProcessSamsaraEventJob.php     # Procesa alertas nuevas
│   └── RevalidateSamsaraEventJob.php  # Re-evalúa alertas en monitoreo
├── Models/
│   └── SamsaraEvent.php   # Modelo principal de eventos
└── Business/Engine/       # Lógica de negocio (si aplica)

routes/
├── api.php               # Endpoints API (webhooks, eventos)
├── web.php               # Rutas web (Inertia)
└── settings.php          # Rutas de configuración usuario

config/
└── services.php          # Configuración de AI Service URL

database/migrations/      # Migraciones de BD
```

### AI Service (`/ai-service`)

```
ai-service/
├── main.py               # Entry point FastAPI
├── agents/
│   ├── agent_definitions.py  # Definición de agentes ADK
│   ├── prompts.py            # System prompts de cada agente
│   └── schemas.py            # Pydantic schemas de output
├── api/
│   ├── routes.py             # Endpoints: /alerts/ingest, /alerts/revalidate
│   └── models.py             # Request/Response models
├── config/
│   ├── settings.py           # Configuración (env vars)
│   └── langfuse.py           # Config de observability
├── core/
│   ├── runtime.py            # ADK Runner y Session
│   └── context.py            # Context vars (Langfuse spans)
├── services/
│   ├── pipeline_executor.py  # Ejecutor del pipeline de agentes
│   └── response_builder.py   # Constructor de respuestas
├── tools/
│   ├── samsara_tools.py      # Tools: get_vehicle_stats, get_camera_media, etc.
│   └── twilio_tools.py       # Tools: send_sms, send_whatsapp, make_call
├── pyproject.toml            # Dependencias Python (Poetry)
└── Dockerfile
```

### Frontend (`/resources/js`)

```
resources/js/
├── app.tsx               # Entry point React
├── pages/
│   ├── dashboard.tsx
│   ├── welcome.tsx
│   ├── auth/             # Páginas de autenticación
│   └── samsara/events/
│       ├── index.tsx     # Listado de alertas (Kanban)
│       └── show.tsx      # Detalle de alerta
├── components/
│   ├── ui/               # Componentes base (shadcn/radix)
│   └── samsara/          # Componentes específicos de alertas
│       ├── event-card.tsx
│       ├── kanban-board.tsx
│       └── kanban-column.tsx
├── layouts/              # Layouts de la app
├── hooks/                # Custom hooks
├── lib/                  # Utilidades
└── types/                # TypeScript types
```

---

## Pipeline de Agentes AI

El sistema usa **Google ADK** con un `SequentialAgent` que ejecuta 4 agentes en orden:

### 1. `ingestion_agent` (GPT-4o-mini)
- **Propósito**: Extrae y estructura datos del payload de Samsara
- **Output**: `CaseData` con alert_type, vehicle_id, severity, etc.
- **Sin tools**

### 2. `panic_investigator` (GPT-4o)
- **Propósito**: Investiga alertas usando APIs externas
- **Tools disponibles**:
  - `get_vehicle_stats()` - Estadísticas históricas del vehículo
  - `get_vehicle_info()` - Información estática (VIN, modelo)
  - `get_driver_assignment()` - Conductor asignado
  - `get_safety_events()` - Eventos de seguridad en ventana de tiempo
  - `get_camera_media()` - Imágenes de dashcam + análisis con Vision
- **Output**: `PanicAssessment` con verdict, likelihood, requires_monitoring

### 3. `final_agent` (GPT-4o-mini)
- **Propósito**: Genera mensaje en español para operadores
- **Sin tools**
- **Output**: Texto formateado para humanos

### 4. `notification_decision_agent` (GPT-4o-mini)
- **Propósito**: Decide y ejecuta notificaciones según nivel de alerta
- **Tools disponibles**:
  - `send_sms()` - Envía SMS via Twilio
  - `send_whatsapp()` - Envía WhatsApp via Twilio
  - `make_call_simple()` - Llamada con TTS
  - `make_call_with_callback()` - Llamada con respuesta de operador
- **Output**: `NotificationDecision` con canales usados y resultados

---

## Flujo de Datos

1. **Webhook recibido** → `SamsaraWebhookController::handle()`
2. **Evento creado en BD** → `SamsaraEvent::create()`
3. **Job encolado** → `ProcessSamsaraEventJob::dispatch()`
4. **Job ejecuta** → Llama a `POST /alerts/ingest` del AI Service
5. **Pipeline AI ejecuta** → 4 agentes secuenciales
6. **Resultado guardado** → `SamsaraEvent::markAsCompleted()` o `markAsInvestigating()`
7. **Si requiere monitoreo** → `RevalidateSamsaraEventJob::dispatch()` con delay

---

## Comandos de Desarrollo

```bash
# Levantar todo el stack
sail up -d

# Ver logs de todos los servicios
sail logs -f

# Ejecutar migraciones
sail artisan migrate

# Ejecutar queue worker
sail artisan horizon

# Frontend dev
sail npm run dev

# AI Service logs
docker logs ai-service -f

# Ejecutar tests Laravel
sail artisan test

# Linting Python
cd ai-service && poetry run ruff check .

# Type checking Python
cd ai-service && poetry run mypy .
```

---

## Puertos y URLs

| Servicio | Puerto | URL Local |
|----------|--------|-----------|
| Laravel | 80 | http://localhost |
| Vite (HMR) | 5173 | http://localhost:5173 |
| AI Service | 8000 | http://localhost:8000 |
| PostgreSQL | 5432 | - |
| Redis | 6379 | - |
| Langfuse | 3030 | http://localhost:3030 |
| MinIO Console | 9091 | http://localhost:9091 |

---

## Variables de Entorno Clave

### Laravel (`.env`)
```env
AI_ENGINE_URL=http://ai-service:8000
DB_CONNECTION=pgsql
QUEUE_CONNECTION=redis
```

### AI Service (`ai-service/.env`)
```env
OPENAI_API_KEY=sk-...
SAMSARA_API_TOKEN=samsara_api_...
TWILIO_ACCOUNT_SID=AC...
TWILIO_AUTH_TOKEN=...
TWILIO_PHONE_NUMBER=+1...
TWILIO_WHATSAPP_NUMBER=whatsapp:+1...
LANGFUSE_PUBLIC_KEY=pk-...
LANGFUSE_SECRET_KEY=sk-...
LANGFUSE_HOST=http://langfuse-web:3000
```

---

## Agregar Nuevas Funcionalidades

### Nueva Tool para el AI Service

1. Crear función en `ai-service/tools/` con decorador `@trace_tool`
2. Agregar import en `ai-service/tools/__init__.py`
3. Agregar tool al agente correspondiente en `agent_definitions.py`

```python
# ai-service/tools/my_tool.py
from core.context import trace_tool

@trace_tool
async def my_new_tool(param: str) -> dict:
    """Docstring usado por el LLM para saber cuándo usar la tool."""
    # implementación
    return {"result": "..."}
```

### Nuevo Agente

1. Definir prompt en `ai-service/agents/prompts.py`
2. Definir schema de output en `ai-service/agents/schemas.py`
3. Crear `LlmAgent` en `ai-service/agents/agent_definitions.py`
4. Agregar al `root_agent.sub_agents` en el orden deseado

### Nueva Página en Frontend

1. Crear archivo en `resources/js/pages/`
2. Laravel detecta automáticamente via Inertia
3. Agregar ruta en `routes/web.php` si es necesario

```php
Route::get('mi-pagina', fn() => Inertia::render('mi-pagina'))->name('mi-pagina');
```

### Nuevo Componente UI

1. Componentes base van en `resources/js/components/ui/`
2. Componentes de dominio van en `resources/js/components/{dominio}/`
3. Usar Radix UI primitives + Tailwind para estilos

---

## Modelo de Datos Principal

### `samsara_events`

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | bigint | PK |
| `event_type` | string | Tipo de alerta (AlertIncident, etc.) |
| `event_description` | string | Descripción (Botón de pánico, etc.) |
| `samsara_event_id` | string | ID único de Samsara |
| `vehicle_id` | string | ID del vehículo |
| `vehicle_name` | string | Nombre/placa |
| `driver_id` | string | ID del conductor |
| `driver_name` | string | Nombre del conductor |
| `severity` | enum | info, warning, critical |
| `occurred_at` | datetime | Timestamp del evento |
| `raw_payload` | json | Payload completo de Samsara |
| `ai_status` | enum | pending, processing, investigating, completed, failed |
| `ai_assessment` | json | Resultado del panic_investigator |
| `ai_message` | text | Mensaje generado por final_agent |
| `ai_actions` | json | Metadata de ejecución del pipeline |
| `investigation_count` | int | Número de revalidaciones |
| `next_check_minutes` | int | Minutos hasta próxima revalidación |
| `notification_status` | string | Estado de notificaciones |
| `notification_channels` | json | Canales usados |

---

## Testing

```bash
# Tests Laravel
sail artisan test

# Tests específicos
sail artisan test --filter=SamsaraEventTest

# Tests AI Service
cd ai-service && poetry run pytest

# Coverage
sail artisan test --coverage
```

---

## Observabilidad

- **Langfuse** (`localhost:3030`): Traces de ejecución de agentes y tools
- **Laravel Telescope** (`/telescope`): Requests, jobs, queries
- **Laravel Horizon** (`/horizon`): Dashboard de queues
- **Logs**: `storage/logs/laravel.log` y `docker logs ai-service`

