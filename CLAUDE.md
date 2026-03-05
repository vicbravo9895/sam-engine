# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

SAM (Samsara Alert Monitor) is an intelligent fleet monitoring and alert processing system. It receives webhooks from Samsara telematics, processes them through an AI pipeline, and executes multi-channel notifications (SMS, WhatsApp, voice calls). The system includes an interactive FleetAgent copilot for real-time fleet queries.

**Tech Stack**: Laravel 12 (PHP 8.2) + React 19 + Inertia.js + TypeScript + Python FastAPI + Google ADK + PostgreSQL + Redis

## Development Commands

### Running the Application

```bash
# Start all services (Laravel, PostgreSQL, Redis, AI Service, Langfuse, etc.)
sail up -d

# Start development environment (server, queue, logs, vite in parallel)
composer dev

# Start with SSR
composer dev:ssr

# View logs
sail logs -f

# View specific service logs
sail logs laravel.test -f
docker logs ai-service -f
```

### Database

```bash
# Run migrations
sail artisan migrate

# Fresh migration with seeders
sail artisan migrate:fresh --seed

# Database console
sail artisan db

# Create testing database (already configured in compose.yaml)
```

### Queue Workers

```bash
# Start Horizon dashboard and workers
sail artisan horizon

# Monitor queue status
sail artisan horizon:status

# Manually process queue (for debugging)
sail artisan queue:listen --tries=1
```

### Frontend Development

```bash
# Start Vite dev server with HMR
sail npm run dev

# Build for production
sail npm run build

# Build with SSR
sail npm run build:ssr

# Start SSR server
sail artisan inertia:start-ssr

# Type checking
sail npm run types

# Linting
sail npm run lint

# Format code
sail npm run format
```

### Testing

```bash
# Run all tests
sail artisan test

# Run specific test file
sail artisan test tests/Feature/AlertProcessingTest.php

# Run with coverage
sail artisan test --coverage

# Run specific test method
sail artisan test --filter=test_webhook_creates_signal_and_alert

# Python AI Service tests
cd ai-service && poetry run pytest
```

### Code Quality

```bash
# Laravel Pint (PHP formatter)
sail artisan pint

# Check Pint without fixing
sail artisan pint --test

# TypeScript type checking
sail npm run types

# ESLint
sail npm run lint

# Prettier
sail npm run format:check
```

### Webhook Testing (Dev Only)

```bash
# Replay synthetic webhooks for testing
sail artisan alerts:replay --interval=120 --profile=panic

# Single webhook test
sail artisan alerts:replay --once --profile=harsh_braking

# Export fixtures from real signals
sail artisan webhooks:export-fixtures --force
```

### AI Service

```bash
# Start AI Service (runs automatically with sail up)
docker logs ai-service -f

# Rebuild AI Service
docker compose build ai-service

# Access AI Service shell
docker exec -it ai-service sh

# Python linting
cd ai-service && poetry run ruff check .

# Type checking
cd ai-service && poetry run mypy .
```

### Observability

```bash
# Access monitoring dashboards
# Laravel Pulse: http://localhost/pulse (requires super_admin)
# Laravel Telescope: http://localhost/telescope
# Laravel Horizon: http://localhost/horizon
# Langfuse: http://localhost:3030

# Process Pulse data (if using Redis driver)
sail artisan pulse:work

# Monitor server metrics
sail artisan pulse:check

# Test Sentry integration
sail artisan sentry:test

# View logs
sail artisan pail --timeout=0
```

## Architecture

### Two Samsara Clients (Intentional)

The codebase has **two separate** Samsara client implementations with different purposes:

1. **`App\Services\SamsaraClient`** (app/Services/SamsaraClient.php)
   - Used by alert processing jobs (ProcessAlertJob, RevalidateAlertJob)
   - Pre-loads data before sending to AI Service
   - Handles vehicle stats, driver assignments, safety events, camera media

2. **`App\Samsara\Client\SamsaraClient`** (app/Samsara/Client/SamsaraClient.php)
   - Used by Neuron AI Tools (FleetAgent copilot)
   - Handles general API calls for interactive queries
   - Used by sync/daemon processes

Both are active and intentional - **do not consolidate them**.

### Alert Processing Pipeline

```
Samsara Webhook → SamsaraWebhookController
                       ↓
              Signal + Alert created in DB
                       ↓
              Data pre-loading (Laravel)
              (stats, driver, camera media)
                       ↓
              ProcessAlertJob dispatched
                       ↓
              POST /alerts/ingest → AI Service
                       ↓
         AI Pipeline (4 sequential agents):
         1. triage_agent (GPT-4o-mini)
         2. investigator_agent (GPT-4o)
         3. final_agent (GPT-4o-mini)
         4. notification_decision_agent (GPT-4o-mini)
                       ↓
              Results saved to DB
              (Alert::markAsCompleted/markAsInvestigating)
                       ↓
              SendNotificationJob dispatched
              (SMS, WhatsApp, Voice via Twilio)
                       ↓
       If requires_monitoring: RevalidateAlertJob scheduled
```

**Important**: Agents do NOT use tools. All data is pre-loaded by Laravel before the pipeline runs. This is intentional for performance and reliability.

### FleetAgent Copilot Architecture

```
User Message → CopilotController
                    ↓
        ProcessCopilotMessageJob
                    ↓
              FleetAgent (Neuron AI)
                    ↓
        Tools execute (Samsara API calls)
        using App\Samsara\Client\SamsaraClient
                    ↓
        Response with Rich Cards
                    ↓
        WebSocket Streaming (Laravel Reverb)
        to frontend via copilot.{threadId} channel
```

**Rich Cards**: The copilot returns structured markdown blocks like `:::location`, `:::vehicleStats`, etc. The frontend parses these and renders interactive components.

### Multi-Tenancy

The system is multi-tenant via `company_id` on all major models:
- Signals, Alerts, Contacts, Vehicles, Drivers, Conversations
- FleetAgent tools use `UsesCompanyContext` trait
- User's `company_id` is injected into context automatically

## Key Directory Structure

### Backend (Laravel)

- **app/Http/Controllers/** - API and web controllers
  - `SamsaraWebhookController.php` - Receives Samsara webhooks
  - `AlertController.php` - Alert management
  - `CopilotController.php` - FleetAgent chat interface
  - `SuperAdmin/` - Super admin panel controllers

- **app/Jobs/** - Background job processors
  - `ProcessAlertJob.php` - Main alert processing job
  - `RevalidateAlertJob.php` - Re-evaluates alerts in monitoring
  - `SendNotificationJob.php` - Sends notifications (SMS/WhatsApp/Voice)
  - `ProcessCopilotMessageJob.php` - Copilot message processing

- **app/Models/** - Eloquent models
  - `Signal.php` - Raw Samsara webhook/stream events
  - `Alert.php` - AI-processed alerts
  - `AlertAi.php` - AI assessment data (tokens, cost, verdict)
  - `AlertMetrics.php` - Pipeline performance metrics
  - `Conversation.php` / `ChatMessage.php` - Copilot conversations

- **app/Neuron/** - FleetAgent (Neuron AI) copilot system
  - `FleetAgent.php` - Main agent definition
  - `Tools/` - Samsara API tools for the copilot
    - Each tool uses `UsesCompanyContext` trait for multi-tenancy
    - Tools return `_cardData` for rich card rendering

- **app/Services/** - Business logic services
  - `SamsaraClient.php` - Pre-loading client for alert pipeline
  - `ContactResolver.php` - Resolves notification contacts
  - `StreamingService.php` - WebSocket streaming for copilot

- **app/Samsara/Client/** - General Samsara API client
  - Used by Neuron Tools, sync, and daemon processes

- **app/Pulse/** - Custom Laravel Pulse metrics
  - `Recorders/` - Metric collectors
  - `Cards/` - Dashboard cards

### Frontend (React + Inertia)

- **resources/js/pages/** - Inertia pages
  - `dashboard.tsx` - Main dashboard
  - `copilot.tsx` - FleetAgent chat interface
  - `samsara/events/` - Alert management (Kanban board, detail views)
  - `auth/` - Authentication (Fortify)
  - `settings/` - User settings

- **resources/js/components/**
  - `ui/` - Base components (shadcn/Radix UI primitives)
  - `samsara/` - Alert-specific components (Kanban, review panels)
  - `rich-cards/` - Copilot rich card components
  - `pwa/` - PWA components

- **resources/js/hooks/** - Custom React hooks
- **resources/js/lib/** - Utilities (cn, etc.)
- **resources/js/types/** - TypeScript definitions

### AI Service (Python + FastAPI)

- **ai-service/main.py** - FastAPI entry point
- **ai-service/agents/** - Google ADK agent definitions
  - `agent_definitions.py` - Agent configurations
  - `prompts/` - System prompts per agent
  - `schemas/` - Pydantic schemas for structured outputs
- **ai-service/api/** - FastAPI routes
  - `routes.py` - `/alerts/ingest`, `/alerts/revalidate`
- **ai-service/services/** - Business logic
  - `pipeline_executor.py` - Orchestrates agent pipeline
  - `preloaded_media_analyzer.py` - Vision AI for camera images
- **ai-service/config/** - Configuration
  - `settings.py` - Environment variables
  - `langfuse.py` - Observability configuration

## HTTP Client Usage in Frontend

**IMPORTANT**: Do NOT use axios directly in React components. Always use Inertia's built-in methods:

- **Forms**: Use `useForm()` from `@inertiajs/react`
- **Navigation**: Use `router.visit()`, `router.post()`, `router.put()`, `router.delete()`

This ensures proper client-server state sync, automatic error handling, and scroll preservation.

## Data Models

### Core Models Relationship

```
Signal (raw webhook) → Alert (processed) → AlertAi (AI data)
                                        → AlertMetrics (performance)
                                        → AlertActivity (timeline)
                                        → AlertComment (reviews)
```

### Signal Fields
- `source`: 'webhook' or 'stream'
- `samsara_event_id`: Unique Samsara ID
- `event_type`: AlertIncident, etc.
- `payload`: Full Samsara JSON

### Alert Fields
- `severity`: info, warning, critical
- `ai_status`: pending, processing, investigating, completed, failed
- `ai_message`: Human-readable message from final_agent
- `ai_processed_at`: When AI finished

### AlertAi Fields
- `ai_assessment`: Investigator verdict (JSON)
- `investigation_count`: Number of revalidations
- `total_tokens`: Consumed tokens
- `cost_estimate`: Estimated cost

## Environment Variables

### Laravel (.env)

```env
AI_SERVICE_BASE_URL=http://ai-service:8000
DB_CONNECTION=pgsql
QUEUE_CONNECTION=redis
SAMSARA_API_KEY=samsara_api_...
TWILIO_ACCOUNT_SID=AC...
TWILIO_AUTH_TOKEN=...
TWILIO_PHONE_NUMBER=+1...
TWILIO_WHATSAPP_NUMBER=whatsapp:+1...

# Sentry (errors, logs, tracing, profiling)
SENTRY_LARAVEL_DSN=https://...
SENTRY_TRACES_SAMPLE_RATE=1.0
SENTRY_PROFILES_SAMPLE_RATE=1.0
SENTRY_ENABLE_LOGS=true

# MinIO (S3-compatible storage for media)
MEDIA_DISK=s3
AWS_ENDPOINT=http://minio:9000
AWS_URL=http://localhost:9090/sam-media
AWS_BUCKET=sam-media

# Laravel Pulse
PULSE_ENABLED=true
PULSE_INGEST_DRIVER=redis
```

### AI Service

In production, variables are injected from root `.env` via compose.yaml. For local dev, you can override in `ai-service/.env`:

```env
OPENAI_API_KEY=sk-...
SAMSARA_API_TOKEN=samsara_api_...
LANGFUSE_PUBLIC_KEY=pk-...
LANGFUSE_SECRET_KEY=sk-...
LANGFUSE_HOST=http://langfuse-web:3000
```

## Adding New Features

### New Neuron Tool for FleetAgent

1. Create class in `app/Neuron/Tools/`
2. Extend `Tool` from Neuron AI
3. Use `UsesCompanyContext` trait for multi-tenancy
4. Implement `handle()` method
5. Return `_cardData` if you want a rich card visualization
6. Add to `FleetAgent::tools()` array

Example structure:
```php
class GetMyData extends Tool
{
    use Concerns\UsesCompanyContext;

    public function handle(string $param): array
    {
        $context = $this->getCompanyContext();
        // Implementation using App\Samsara\Client\SamsaraClient

        return [
            'data' => [...],
            '_cardData' => ['myCard' => [...]]
        ];
    }
}
```

### New Rich Card for Copilot

1. Create React component in `resources/js/components/rich-cards/`
2. Export from `rich-cards/index.ts`
3. Add parsing logic in `markdown-content.tsx` for `:::myCard` blocks

### New AI Agent (Advanced)

This requires changes in the Python AI Service:
1. Add prompt in `ai-service/agents/prompts/`
2. Define schema in `ai-service/agents/schemas/`
3. Add agent in `ai-service/agents/agent_definitions.py`
4. Update pipeline in `ai-service/services/pipeline_executor.py`

## Testing Notes

- Test database is automatically created via Docker init script
- PHPUnit configured to exclude certain directories (see phpunit.xml)
- Use `QUEUE_CONNECTION=sync` in tests to avoid async issues
- AI Service base URL is mocked in tests (`http://ai-service-test:8000`)

## Ports and URLs (Local Development)

| Service | Port | URL |
|---------|------|-----|
| Laravel | 80 | http://localhost |
| Vite HMR | 5173 | http://localhost:5173 |
| AI Service | 8000 | http://localhost:8000 |
| PostgreSQL | 5432 | - |
| Redis | 6379 | - |
| Langfuse | 3030 | http://localhost:3030 |
| MinIO API | 9090 | http://localhost:9090 |
| MinIO Console | 9091 | http://localhost:9091 |
| Reverb WebSocket | 8080 | ws://localhost:8080 |

## Common Gotchas

1. **Two Samsara Clients**: Don't consolidate them - they serve different purposes
2. **No axios in frontend**: Use Inertia's `useForm()` and `router` methods
3. **AI agents don't use tools**: Data is pre-loaded by Laravel before pipeline
4. **WebSocket streaming**: Copilot uses Laravel Reverb for real-time communication
5. **Multi-tenancy**: Always check `company_id` scoping in queries
6. **MinIO in dev**: Bucket `sam-media` is auto-created on first upload
7. **Reverb requires Redis**: Check `REDIS_HOST=redis` in Docker environment

## Observability Stack

- **Sentry**: Errors, logs, distributed tracing, profiling (Laravel + AI Service)
- **Langfuse**: AI agent execution traces and debugging (http://localhost:3030)
- **Laravel Pulse**: Real-time metrics dashboard (/pulse, requires super_admin)
- **Laravel Telescope**: Request/query debugging (/telescope)
- **Laravel Horizon**: Queue monitoring (/horizon)

## Code Style Guidelines (from AGENTS.md)

- Components should be small and single-responsibility
- Prefer composition over complex configuration
- Avoid premature abstractions
- TypeScript: Avoid `any` and `unknown`, prefer type inference
- Tailwind CSS only for styling
- HTML must be semantic with proper ARIA roles
- Always use Inertia methods for HTTP requests in React

## Current Development Phase

Based on git status and recent commits, the team is actively working on:
- AI assessment display in incident details
- Notification system enhancements
- Dashboard timeline and stats improvements
- Alert controller refinements
