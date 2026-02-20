# SAM - Samsara Alert Monitor

Sistema de monitoreo y procesamiento inteligente de alertas de flotas usando AI.

---

## Reglas de Desarrollo

### Organización de Código
- Componentes pequeños, con una sola responsabilidad
- Preferir composición frente a configuraciones complejas
- Evita abstracciones prematuras
- El código compartido debe vivir en carpetas claras como `components`, `layouts`, `lib` o `utils`

### TypeScript
- Evita `any` y `unknown`
- Preferir siempre que se pueda inferencia de tipos
- Si los tipos no están claros, parar y aclarar antes de continuar

### UI y Estilos
- Tailwind es la única solución de estilos
- No duplicar clases si se puede extraer un componente
- Priorizar legibilidad frente a micro-optimizaciones visuales
- Accesibilidad no es opcional: HTML semántico, roles ARIA cuando aplique y foco gestionado

### HTTP en Frontend (Inertia)
- **NO usar axios directamente**
- Usar `useForm` de `@inertiajs/react` para formularios
- Usar `router.visit()`, `router.post()`, `router.put()`, `router.delete()` para navegación y peticiones
- Esto mantiene sincronización cliente-servidor, manejo automático de errores y preservación de scroll

### Comportamiento del Agente AI
- Si una petición no está clara, hacer preguntas concretas antes de ejecutar
- Tareas simples y bien definidas se ejecutan directamente
- Cambios complejos (refactors, nuevas features, decisiones de arquitectura) requieren confirmar entendimiento antes de actuar
- No asumir requisitos implícitos. Si falta información, se pide

---

## Stack Tecnológico

| Capa | Tecnología | Versión |
|------|------------|---------|
| **Backend** | Laravel + PHP | 12.x / 8.2 |
| **Frontend** | React + Inertia + TypeScript | 19.x / 2.x |
| **AI Service** | Python + FastAPI + Google ADK | 3.11 / 1.18+ |
| **Copilot** | Neuron AI (PHP) | - |
| **Base de datos** | PostgreSQL | 18 |
| **Queue** | Redis + Horizon | - |
| **LLM** | OpenAI GPT-4o / GPT-4o-mini | - |
| **Telematics** | Samsara SDK | 4.1.0 |
| **Notificaciones** | Twilio (SMS, WhatsApp, Voice) | 9.x |
| **Observability** | Langfuse + ClickHouse + Sentry | 3.x |
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
                        ┌──────────────────┐     ┌─────────────────┐
                        │  React Frontend  │────▶│   FleetAgent    │
                        │  (Inertia SSR)   │     │  (Neuron/PHP)   │
                        └──────────────────┘     └─────────────────┘
                                                        │
                                                        ▼
                                                 ┌─────────────┐
                                                 │   OpenAI    │
                                                 └─────────────┘
```

### Dos Sistemas de AI

1. **AI Service (Python/ADK)**: Procesa alertas automáticamente vía webhooks
2. **FleetAgent (PHP/Neuron)**: Copilot interactivo para consultas de flota en tiempo real

---

## Estructura de Carpetas

### Laravel Backend (`/`)

```
app/
├── Http/Controllers/
│   ├── SamsaraWebhookController.php   # Recibe webhooks de Samsara
│   ├── AlertController.php             # API y vistas de alertas
│   ├── AlertReviewController.php       # Revisión humana de alertas
│   ├── CopilotController.php          # Chat con FleetAgent
│   ├── FleetReportController.php      # Reportes de flota
│   ├── ContactController.php          # Gestión de contactos
│   ├── CompanyController.php          # Configuración de empresa
│   ├── TwilioCallbackController.php   # Callbacks de llamadas Twilio
│   └── SuperAdmin/                    # Panel de super admin
│       ├── CompanyController.php
│       ├── DashboardController.php
│       └── UserController.php
├── Jobs/
│   ├── ProcessAlertJob.php            # Procesa alertas nuevas
│   ├── RevalidateAlertJob.php         # Re-evalúa alertas en monitoreo
│   ├── SendNotificationJob.php        # Envía notificaciones (SMS, WhatsApp, llamada)
│   └── ProcessCopilotMessageJob.php   # Procesa mensajes del copilot
├── Models/
│   ├── Signal.php                     # Evento raw de Samsara (webhook/stream)
│   ├── Alert.php                      # Alerta procesada por AI
│   ├── AlertAi.php                    # Datos AI de la alerta (assessment, tokens)
│   ├── AlertMetrics.php               # Métricas de pipeline por alerta
│   ├── AlertSource.php                # Origen de la alerta (webhook, stream)
│   ├── AlertActivity.php              # Timeline de actividades
│   ├── AlertComment.php               # Comentarios de revisión
│   ├── User.php                       # Usuarios del sistema
│   ├── Company.php                    # Multi-tenancy
│   ├── Contact.php                    # Contactos para notificaciones
│   ├── Vehicle.php                    # Vehículos cacheados
│   ├── Driver.php                     # Conductores cacheados
│   ├── VehicleStat.php                # Stats históricas
│   ├── Tag.php                        # Tags/grupos de vehículos
│   ├── Conversation.php               # Conversaciones del copilot
│   ├── ChatMessage.php                # Mensajes del copilot
│   └── TokenUsage.php                 # Tracking de tokens LLM
├── Neuron/                            # Sistema Copilot (FleetAgent)
│   ├── FleetAgent.php                 # Agente principal
│   ├── CompanyContext.php             # Contexto multi-tenant
│   ├── Observers/
│   │   └── TokenTrackingObserver.php
│   └── Tools/                         # Tools del copilot
│       ├── GetVehicles.php
│       ├── GetVehicleStats.php
│       ├── GetFleetStatus.php
│       ├── GetDashcamMedia.php
│       ├── GetSafetyEvents.php
│       ├── GetTrips.php
│       ├── GetTags.php
│       ├── GetDrivers.php
│       └── Concerns/
│           ├── UsesCompanyContext.php
│           └── FlexibleVehicleSearch.php
├── Samsara/Client/
│   └── SamsaraClient.php              # API general (Neuron Tools, sync, daemon)
└── Services/
    ├── SamsaraClient.php              # Pre-carga para pipeline de alertas (Jobs)
    ├── ContactResolver.php            # Resuelve contactos para notificaciones
    └── StreamingService.php           # WebSocket streaming para copilot (Reverb)

routes/
├── api.php               # Endpoints API (webhooks, eventos, revisión)
├── web.php               # Rutas web (Inertia)
└── settings.php          # Rutas de configuración usuario
```

**Dos clientes Samsara:** `App\Services\SamsaraClient` = preload/revalidación (Jobs). `App\Samsara\Client\SamsaraClient` = API general (Neuron Tools, sync, daemon). Ambos son intencionales y activos con responsabilidades separadas.

### AI Service (`/ai-service`)

```
ai-service/
├── main.py                   # Entry point FastAPI
├── agents/
│   ├── agent_definitions.py  # Definición de agentes ADK
│   ├── prompts/              # System prompts por agente
│   │   ├── triage.py
│   │   ├── investigator.py
│   │   ├── final_message.py
│   │   └── notification.py
│   └── schemas/              # Pydantic schemas de output
│       ├── triage.py
│       ├── investigation.py
│       ├── execution.py
│       └── notification.py
├── api/
│   ├── routes.py             # Endpoints: /alerts/ingest, /alerts/revalidate
│   └── models.py             # Request/Response models
├── config/
│   ├── settings.py           # Configuración (env vars)
│   └── langfuse.py           # Config de observability
├── core/
│   ├── runtime.py            # ADK Runner y Session
│   ├── context.py            # Context vars (Langfuse spans)
│   ├── concurrency.py        # Control de concurrencia
│   └── structured_logging.py # Logging JSON
├── services/
│   ├── pipeline_executor.py      # Ejecutor del pipeline de agentes
│   ├── response_builder.py       # Constructor de respuestas
│   ├── notification_executor.py  # Ejecuta notificaciones decididas
│   └── preloaded_media_analyzer.py # Análisis de imágenes con Vision
├── tools/
│   ├── samsara_tools.py      # Tools de Samsara (legacy, no usadas)
│   └── twilio_tools.py       # Tools de Twilio (legacy, no usadas)
├── pyproject.toml            # Dependencias Python (Poetry)
└── Dockerfile
```

### Frontend (`/resources/js`)

```
resources/js/
├── app.tsx                   # Entry point React
├── pages/
│   ├── dashboard.tsx         # Dashboard principal
│   ├── copilot.tsx           # Chat con FleetAgent
│   ├── auth/                 # Autenticación (Fortify)
│   │   ├── login.tsx
│   │   ├── forgot-password.tsx
│   │   ├── reset-password.tsx
│   │   ├── verify-email.tsx
│   │   └── two-factor-challenge.tsx
│   ├── samsara/events/       # Gestión de alertas
│   │   ├── index.tsx         # Listado Kanban
│   │   └── show.tsx          # Detalle de alerta
│   ├── fleet-report/
│   │   └── index.tsx         # Reportes de flota
│   ├── contacts/             # Gestión de contactos
│   │   ├── index.tsx
│   │   ├── create.tsx
│   │   └── edit.tsx
│   ├── users/                # Gestión de usuarios
│   ├── company/              # Configuración empresa
│   ├── settings/             # Configuración usuario
│   │   ├── profile.tsx
│   │   ├── password.tsx
│   │   ├── appearance.tsx
│   │   └── two-factor.tsx
│   └── super-admin/          # Panel super admin
│       ├── dashboard.tsx
│       ├── companies/
│       └── users/
├── components/
│   ├── ui/                   # Componentes base (shadcn/radix)
│   ├── samsara/              # Componentes de alertas
│   │   ├── event-card.tsx
│   │   ├── kanban-board.tsx
│   │   ├── kanban-column.tsx
│   │   ├── review-panel.tsx
│   │   └── event-quick-view-modal.tsx
│   ├── rich-cards/           # Cards del copilot
│   │   ├── location-card.tsx
│   │   ├── vehicle-stats-card.tsx
│   │   ├── safety-events-card.tsx
│   │   ├── trips-card.tsx
│   │   ├── dashcam-media-card.tsx
│   │   ├── fleet-status-card.tsx
│   │   └── fleet-report-card.tsx
│   ├── pwa/                  # PWA components
│   └── ...                   # Otros componentes compartidos
├── layouts/                  # Layouts de la app
├── hooks/                    # Custom hooks
│   ├── use-mobile.tsx
│   ├── use-appearance.tsx
│   ├── use-clipboard.ts
│   ├── use-pwa.ts
│   └── ...
├── lib/
│   └── utils.ts              # Utilidades (cn, etc.)
└── types/
    ├── index.d.ts            # Types globales
    └── samsara.ts            # Types de Samsara
```

---

## Pipeline de Agentes AI (Alert Processing)

El AI Service usa **Google ADK** con un `SequentialAgent` que ejecuta 4 agentes en orden.

**NOTA**: Los datos se pre-cargan desde Laravel antes de ejecutar el pipeline. Los agentes NO usan tools, solo analizan datos.

### 1. `triage_agent` (GPT-4o-mini)
- **Propósito**: Clasifica la alerta y genera estrategia de investigación
- **Input**: Payload de Samsara + datos pre-cargados
- **Output**: `TriageResult` con alert_type, severity, investigation_strategy
- **Sin tools**

### 2. `investigator_agent` (GPT-4o)
- **Propósito**: Analiza datos pre-cargados y genera evaluación técnica
- **Datos disponibles** (pre-cargados desde Laravel):
  - `preloaded_data.vehicle_info` - Información del vehículo
  - `preloaded_data.driver_assignment` - Conductor asignado
  - `preloaded_data.vehicle_stats` - Estadísticas del vehículo
  - `preloaded_data.safety_events_correlation` - Eventos de seguridad
  - `preloaded_camera_analysis` - Análisis de Vision AI
- **Output**: `AlertAssessment` con verdict, likelihood, requires_monitoring
- **Sin tools** (todo pre-cargado)

### 3. `final_agent` (GPT-4o-mini)
- **Propósito**: Genera mensaje en español para operadores
- **Output**: `human_message` (texto formateado)
- **Sin tools**

### 4. `notification_decision_agent` (GPT-4o-mini)
- **Propósito**: Decide qué notificaciones enviar (NO las ejecuta)
- **Output**: `NotificationDecision` con canales y destinatarios
- **Sin tools** - La ejecución la hace código determinista

### Pipeline de Revalidación
Para alertas en monitoreo, se salta el triage (ya clasificada):
```
investigator_agent → final_agent → notification_decision_agent
```

---

## FleetAgent (Copilot)

Sistema de chat interactivo basado en **Neuron AI** (PHP) para consultas de flota.

### Arquitectura
```
Usuario → CopilotController → ProcessCopilotMessageJob → FleetAgent → OpenAI
                                      ↓
                               Tools (Samsara API)
                                      ↓
                               Rich Cards Response
```

### Tools Disponibles
| Tool | Descripción | Card Output |
|------|-------------|-------------|
| `GetVehicles` | Buscar/listar vehículos | - |
| `GetVehicleStats` | Stats en tiempo real | `:::location`, `:::vehicleStats` |
| `GetFleetStatus` | Estado de toda la flota | `:::fleetStatus` |
| `GetSafetyEvents` | Eventos de seguridad | `:::safetyEvents` |
| `GetTrips` | Viajes recientes | `:::trips` |
| `GetDashcamMedia` | Imágenes de dashcam | `:::dashcamMedia` |
| `GetTags` | Tags y jerarquía | - |
| `GetDrivers` | Información de conductores | - |

### Rich Cards
El copilot responde con cards estructuradas:
```markdown
Aquí tienes el estado de T-012021:

:::location
{"latitude": 19.4326, "longitude": -99.1332, ...}
:::

:::vehicleStats
{"speed": 45, "engineState": "On", ...}
:::
```

---

## Flujo de Datos

### Procesamiento de Alertas
1. **Webhook recibido** → `SamsaraWebhookController::handle()`
2. **Signal + Alert creados en BD** → `Signal::create()` + `Alert::create()`
3. **Pre-carga de datos** → Laravel obtiene stats, driver, camera media
4. **Job encolado** → `ProcessAlertJob::dispatch()`
5. **Job ejecuta** → Llama a `POST /alerts/ingest` del AI Service
6. **Pipeline AI ejecuta** → 4 agentes secuenciales
7. **Resultado guardado** → `Alert::markAsCompleted()` o `markAsInvestigating()`, datos AI en `AlertAi`
8. **Notificaciones** → `SendNotificationJob` ejecuta SMS/WhatsApp/llamadas
9. **Si requiere monitoreo** → `RevalidateAlertJob::dispatch()` con delay

### Copilot
1. **Usuario envía mensaje** → `CopilotController::send()`
2. **Job encolado** → `ProcessCopilotMessageJob::dispatch()`
3. **FleetAgent procesa** → Ejecuta tools según necesidad
4. **SSE Streaming** → Respuesta en tiempo real al frontend
5. **Rich Cards** → Frontend renderiza cards interactivas

#### Streaming con WebSockets (Laravel Reverb)
- **Canal por conversación**: `copilot.{threadId}` (canal privado de Reverb/WebSockets).
- **Job** publica cada chunk/evento via `broadcast(CopilotStreamEvent)` (chunk, tool_start, tool_end, stream_end, stream_error).
- **Frontend** escucha el canal WebSocket en tiempo real → baja latencia, sin polling.
- **Hash Redis** `copilot:stream:{threadId}` se mantiene como fallback para estado (status, content) y endpoint `streamProgress` para reconexión/polling HTTP.

---

## Pipeline metrics (T1 — instrumentación mínima)

Métricas por alerta en `alerts` + `alert_metrics` (por `company_id`):

| Métrica | Origen |
|--------|--------|
| **time_webhook_received** | `created_at` |
| **time_ai_started** | `pipeline_time_ai_started_at` |
| **time_ai_finished** | `pipeline_time_ai_finished_at` |
| **time_notifications_sent** | `notification_sent_at` |
| **pipeline_latency_ms** | webhook → fin AI (ms) |
| **ai_tokens** | opcional, desde AI Service `execution.total_tokens` |
| **ai_cost_estimate** | opcional, desde AI Service `execution.cost_estimate` |

### Estado de salud por tenant

- **Última alerta procesada**: evento más reciente con `ai_status` en `completed`, `investigating` o `failed`.
- **Fallos últimas 24h**: conteo de `ai_status = 'failed'` con `ai_processed_at` en las últimas 24h.

### Consultas SQL (gates)

**P95 latencia hoy por company (PostgreSQL):**

```sql
SELECT
  am.company_id,
  PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY am.pipeline_latency_ms) AS p95_latency_ms
FROM alert_metrics am
WHERE am.pipeline_latency_ms IS NOT NULL
  AND am.ai_finished_at >= CURRENT_DATE
  AND am.ai_finished_at < CURRENT_DATE + INTERVAL '1 day'
GROUP BY am.company_id;
```

**Tasa de fallos por tipo de alerta (últimas 24h):**

```sql
SELECT
  s.event_type,
  COUNT(*) FILTER (WHERE a.ai_status = 'failed') AS failed_count,
  COUNT(*) AS total_count,
  ROUND(100.0 * COUNT(*) FILTER (WHERE a.ai_status = 'failed') / NULLIF(COUNT(*), 0), 2) AS failure_rate_pct
FROM alerts a
JOIN signals s ON s.id = a.signal_id
WHERE a.ai_processed_at >= NOW() - INTERVAL '24 hours'
GROUP BY s.event_type;
```

**Última alerta procesada por tenant:**

```sql
SELECT DISTINCT ON (company_id)
  company_id,
  id AS last_alert_id,
  ai_processed_at AS last_processed_at,
  ai_status
FROM alerts
WHERE ai_status IN ('completed', 'investigating', 'failed')
ORDER BY company_id, ai_processed_at DESC NULLS LAST;
```

**Conteo de failed últimas 24h por company:**

```sql
SELECT
  company_id,
  COUNT(*) AS failed_last_24h
FROM alerts
WHERE ai_status = 'failed'
  AND ai_processed_at >= NOW() - INTERVAL '24 hours'
GROUP BY company_id;
```

---

## Fuente de verdad única (T3 — recommended_actions / investigation_steps)

**Objetivo**: Eliminar bugs por leer `recommended_actions` / `investigation_plan` desde JSON vs tablas.

**Estrategia (Opción A)**: Tablas normalizadas como source of truth.

| Dato | Tabla | Lectura | Escritura |
|------|--------|---------|-----------|
| Acciones recomendadas | `event_recommended_actions` | UI y API solo vía `getRecommendedActionsArray()` (tabla → fallback JSON) | `ProcessAlertJob` / `RevalidateAlertJob` → `saveRecommendedActions()` |
| Pasos de investigación | `event_investigation_steps` | API vía `getInvestigationStepsArray()` (tabla → fallback `alert_context.investigation_plan`) | Mismos jobs → `saveInvestigationSteps()` |

- **Backend**: Controlador envía `recommended_actions` e `investigation_steps` en el payload de la alerta desde las tablas (no desde `ai_assessment` / `alert_context` para display).
- **Frontend**: `show.tsx` usa solo `event.recommended_actions` (y opcionalmente `event.investigation_steps` si se muestra).
- **JSON** (`alert_ai.ai_assessment`, `alert_context`) se mantiene como snapshot raw; la UI no lo usa para acciones/pasos.

**Gate**: Un solo camino de lectura/escritura — búsqueda en repo por `recommended_actions` e `investigation_steps` debe mostrar que la UI lee solo desde el payload enviado por el controlador (origen en tablas). **Test snapshot**: el mismo evento debe renderizarse igual antes y después de T3 (misma lista de acciones y pasos).

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

## QA / Replay de webhooks (solo dev)

Para validar el pipeline de alertas (enriquecimiento, AI, notificaciones) sin depender de Samsara en vivo se usa **synthetic event injection**: un simulador que reenvía payloads tipo Samsara al webhook a intervalos configurables.

**Buenas prácticas aplicadas:**
- **Solo en dev**: el comando `alerts:replay` se ejecuta únicamente si `APP_ENV=local` o `WEBHOOK_SIMULATOR_ENABLED=true`.
- **Fixtures realistas**: los JSON en `storage/app/fixtures/samsara_webhooks/` se obtienen con `sail artisan webhooks:export-fixtures --force`, que extrae `signals.raw_payload` tal cual (sin anonimizar) para replicar todo el contexto en las pruebas.
- **IDs únicos por envío**: cada replay usa un `eventId` nuevo para no chocar con la deduplicación.
- **Vehículo de la empresa**: el payload se rehidrata con un vehículo existente de la empresa elegida para que el webhook enrute bien.

```bash
# Un webhook cada 2 minutos, perfil botón de pánico
sail artisan alerts:replay --interval=120 --profile=panic

# Un solo envío, empresa concreta
sail artisan alerts:replay --once --company=1 --profile=harsh_braking

# Rotar entre todos los perfiles cada 60s, máximo 10 envíos
sail artisan alerts:replay --interval=60 --profile=mixed --max=10
```

Opciones: `--interval`, `--profile` (panic, harsh_braking, speeding, mixed, all), `--company`, `--once`, `--max`, `--url`, `--fixture-dir`.  
Si el comando corre dentro de Sail y la app es `http://laravel.test`, usa `WEBHOOK_SIMULATOR_TARGET_URL=http://laravel.test` en `.env`.

Para **generar fixtures desde datos reales** (dump o señales ya ingestadas):

```bash
sail artisan webhooks:export-fixtures --force
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
| MinIO API (S3) | 9090 | http://localhost:9090 |
| MinIO Console | 9091 | http://localhost:9091 |
| Reverb (WebSocket) | 8080 | ws://localhost:8080 |

---

## Variables de Entorno Clave

### Laravel (`.env`)
```env
AI_SERVICE_BASE_URL=http://ai-service:8000
DB_CONNECTION=pgsql
QUEUE_CONNECTION=redis
SAMSARA_API_KEY=samsara_api_...

# Sentry (error monitoring, logs, tracing, profiling)
SENTRY_LARAVEL_DSN=https://...
SENTRY_TRACES_SAMPLE_RATE=1.0
SENTRY_PROFILES_SAMPLE_RATE=1.0
SENTRY_ENABLE_LOGS=true
```

### Dev: Reverb y MinIO al arrancar Sail
En `compose.yaml`, los servicios Laravel (laravel.test, horizon, reverb) tienen `REDIS_HOST=redis` y `DB_HOST=pgsql` inyectados para que funcionen dentro de Docker aunque en `.env` tengas `127.0.0.1`. Reverb espera a que Redis esté healthy antes de arrancar. MinIO usa un healthcheck compatible con la imagen oficial (sin `mc`) y el volumen `sail-minio-data` persiste los datos entre reinicios.

### Persistir imágenes (evidence, dashcam) en MinIO en dev
1. En `.env`: `MEDIA_DISK=s3`, y las variables AWS_* con endpoint `http://minio:9000` y `AWS_URL=http://localhost:9090/sam-media` (puerto 9090 en el host).
2. Credenciales: `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` pueden usar `${MINIO_ROOT_USER}` y `${MINIO_ROOT_PASSWORD}`.
3. El bucket `sam-media` se crea automáticamente en el primer upload. Los archivos quedan en el volumen `sail-minio-data`.

### AI Service y Sentry (Dokploy / un solo .env)

**Todo en el .env raíz.** Compose inyecta las vars a cada servicio:
- **Laravel** usa `SENTRY_LARAVEL_DSN`
- **AI Service** usa `AI_SENTRY_DSN` si existe; si no, `SENTRY_LARAVEL_DSN`

Mismo proyecto: solo `SENTRY_LARAVEL_DSN`. Dos proyectos: añadir `AI_SENTRY_DSN`.

### AI Service (`ai-service/.env` - solo dev local)

En dev con Sail puedes override en `ai-service/.env`:

```env
OPENAI_API_KEY=sk-...
SAMSARA_API_TOKEN=samsara_api_...
SENTRY_DSN=   # opcional; en prod viene del root .env vía compose
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

### Nueva Tool para FleetAgent (Copilot)

1. Crear clase en `app/Neuron/Tools/` extendiendo `Tool`
2. Implementar `handle()` con la lógica
3. Usar trait `UsesCompanyContext` para multi-tenancy
4. Retornar `_cardData` si hay visualización especial
5. Agregar a `FleetAgent::tools()`

```php
<?php

namespace App\Neuron\Tools;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class GetMyData extends Tool
{
    use Concerns\UsesCompanyContext;

    public function __construct()
    {
        parent::__construct(
            name: 'GetMyData',
            description: 'Descripción para el LLM de cuándo usar esta tool'
        );
        
        $this->addProperty(
            new ToolProperty(
                name: 'param',
                type: 'string',
                description: 'Descripción del parámetro',
                required: true
            )
        );
    }

    public function handle(string $param): array
    {
        $context = $this->getCompanyContext();
        // Implementación...
        
        return [
            'data' => [...],
            '_cardData' => ['myCard' => [...]]  // Para rich card
        ];
    }
}
```

### Nueva Card para el Copilot

1. Crear componente en `resources/js/components/rich-cards/`
2. Exportar desde `index.ts`
3. Agregar case en `markdown-content.tsx` para parsear el bloque `:::`

```tsx
// my-card.tsx
export function MyCard({ data }: { data: MyCardData }) {
  return (
    <Card>
      {/* Renderizado de la card */}
    </Card>
  );
}
```

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
4. **No usar axios** - usar `useForm` o `router` de Inertia

---

## Modelo de Datos Principal

### `signals` (evento raw)

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | bigint | PK |
| `company_id` | bigint | FK a companies (multi-tenant) |
| `source` | string | Origen: `webhook`, `stream` |
| `samsara_event_id` | string | ID único de Samsara |
| `event_type` | string | Tipo de alerta (AlertIncident, etc.) |
| `event_description` | string | Descripción (Botón de pánico, etc.) |
| `vehicle_id` | string | ID del vehículo |
| `vehicle_name` | string | Nombre/placa |
| `driver_id` | string | ID del conductor |
| `driver_name` | string | Nombre del conductor |
| `severity` | string | info, warning, critical |
| `occurred_at` | datetime | Timestamp del evento |
| `payload` | json | Payload completo de Samsara |

### `alerts` (alerta procesada)

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | bigint | PK |
| `company_id` | bigint | FK a companies (multi-tenant) |
| `signal_id` | bigint | FK a signals |
| `severity` | enum | info, warning, critical |
| `ai_status` | enum | pending, processing, investigating, completed, failed |
| `ai_message` | text | Mensaje generado por final_agent |
| `ai_processed_at` | datetime | Timestamp de procesamiento AI |
| `notification_status` | string | Estado de notificaciones |
| `notification_channels` | json | Canales usados |

### `alert_ai` (datos AI detallados)

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `alert_id` | bigint | FK a alerts |
| `ai_assessment` | json | Resultado del investigator |
| `ai_actions` | json | Metadata de ejecución del pipeline |
| `investigation_count` | int | Número de revalidaciones |
| `next_check_minutes` | int | Minutos hasta próxima revalidación |
| `investigation_history` | json | Historial de revalidaciones |
| `total_tokens` | int | Tokens consumidos |
| `cost_estimate` | decimal | Costo estimado |

### `conversations` / `chat_messages`

| Tabla | Campo | Descripción |
|-------|-------|-------------|
| conversations | `id`, `user_id`, `title` | Conversaciones del copilot |
| chat_messages | `thread_id`, `role`, `content` | Mensajes (user/assistant) |

---

## Testing

```bash
# Tests Laravel
sail artisan test

# Tests específicos
sail artisan test --filter=AttentionEngineTest

# Tests AI Service
cd ai-service && poetry run pytest

# Coverage
sail artisan test --coverage
```

---

## Observabilidad

- **Sentry**: Error monitoring, **logs**, **tracing** y profiling en Laravel y AI Service. Puede reemplazar o complementar Grafana/Loki para logs y trazas.
  - **Logs**: Con `SENTRY_ENABLE_LOGS=true` el canal `sentry_logs` se añade al stack de Laravel; los logs se envían a Sentry (sección Logs). En AI Service, `enable_logs=True` y `before_send_log` filtran ruido (p. ej. health).
  - **Trazas completas**: Laravel envía `sentry-trace` y `baggage` a las peticiones salientes (p. ej. al AI Service). `trace_propagation_targets` en `config/sentry.php` incluye por defecto `ai-service`, `localhost`, `127.0.0.1`. En Sentry Performance se ve la transacción end-to-end (web/job → HTTP client → AI Service).
  - Profiling requiere extensión Excimer (`pecl install excimer`).
- **Laravel Pulse** (`/pulse`): Dashboard de monitoreo en tiempo real
- **Langfuse** (`localhost:3030`): Traces de ejecución de agentes AI Service
- **Laravel Telescope** (`/telescope`): Requests, jobs, queries
- **Laravel Horizon** (`/horizon`): Dashboard de queues
- **TokenUsage model**: Tracking de tokens del copilot
- **Logs**: `storage/logs/laravel.log` y `docker logs ai-service`

### Verificar Sentry

```bash
sail artisan sentry:test
```

### Laravel Pulse - Métricas SAM

Pulse está configurado con cards personalizadas para métricas específicas de SAM:

| Card | Descripción | Métricas |
|------|-------------|----------|
| **Alertas Procesadas** | Procesamiento de alertas AI | Por tipo, severidad, verdict, status |
| **AI Performance** | Rendimiento del AI Service | Latencia, éxito/fallo, códigos HTTP |
| **Notificaciones** | Estado de notificaciones | Por canal (SMS, WhatsApp, Call), tasa de éxito |
| **Copilot Usage** | Uso del FleetAgent | Mensajes, tools, usuarios top |
| **Token Consumption** | Consumo de tokens LLM | Por modelo, tipo de request, usuarios |

#### Recorders Personalizados

```
app/Pulse/
├── Recorders/
│   ├── AlertProcessingRecorder.php  # Métricas de procesamiento de alertas
│   ├── AiServiceRecorder.php        # Llamadas al AI Service (FastAPI)
│   ├── NotificationRecorder.php     # Envío de notificaciones Twilio
│   ├── TokenUsageRecorder.php       # Consumo de tokens OpenAI
│   └── CopilotRecorder.php          # Uso del Copilot/FleetAgent
└── Cards/
    ├── AlertsProcessed.php          # Card de alertas procesadas
    ├── AiPerformance.php            # Card de performance AI
    ├── NotificationStatus.php       # Card de notificaciones
    ├── TokenConsumption.php         # Card de tokens
    └── CopilotUsage.php             # Card de copilot
```

#### Configuración

```bash
# Variables de entorno principales
PULSE_ENABLED=true
PULSE_INGEST_DRIVER=redis      # Usar Redis para alto rendimiento
PULSE_STORAGE_KEEP="14 days"   # Retención de datos
PULSE_DB_CONNECTION=pgsql      # Usar PostgreSQL

# Recorders personalizados SAM
PULSE_ALERT_PROCESSING_ENABLED=true
PULSE_TOKEN_USAGE_ENABLED=true
PULSE_NOTIFICATION_ENABLED=true
PULSE_AI_SERVICE_ENABLED=true
PULSE_COPILOT_ENABLED=true
```

#### Comandos

```bash
# Ver dashboard (requiere super_admin)
# URL: http://localhost/pulse

# Procesar entradas de Redis (para PULSE_INGEST_DRIVER=redis)
sail artisan pulse:work

# Monitoreo de servidor (CPU, memoria, disco)
sail artisan pulse:check

# Reiniciar workers de Pulse
sail artisan pulse:restart
```

#### Acceso al Dashboard

El dashboard de Pulse está disponible en `/pulse` y requiere que el usuario sea `is_super_admin = true`. La autorización está configurada en `AppServiceProvider`.
