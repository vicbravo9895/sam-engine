# Resumen de Cambios: Arquitectura con Queue

## ✅ Decisión Final: Modelo Unificado `SamsaraEvent`

**Por qué unificar SafetyEvents y Alerts:**
- Mismo procesamiento de IA para ambos
- Queries más simples
- Evita duplicación de código
- Frontend consume una sola API

## 🏗️ Arquitectura Implementada

### Laravel (Backend)

**1. Base de Datos**
- ✅ Migración: `create_samsara_events_table`
  - Campos de evento (type, vehicle, driver, severity)
  - Campos de IA (status, assessment, message, error)
  - Índices optimizados

**2. Modelo**
- ✅ `SamsaraEvent` con:
  - Constantes de estado y severidad
  - Scopes para filtrar (pending, processing, completed, failed)
  - Métodos helper (markAsProcessing, markAsCompleted, markAsFailed)

**3. Job**
- ✅ `ProcessSamsaraEventJob`:
  - Queue: `samsara-events` en Redis
  - 3 reintentos con backoff [30s, 60s, 120s]
  - Timeout: 5 minutos
  - Llama a FastAPI `/alerts/ingest`
  - Guarda resultados en DB

**4. Controllers**
- ✅ `SamsaraWebhookController`:
  - Recibe webhook de Samsara
  - Crea evento en DB
  - Encola job
  - Responde 202 Accepted inmediatamente

- ✅ `SamsaraEventController` (para frontend):
  - `GET /api/events` - Listar con filtros
  - `GET /api/events/{id}` - Ver evento específico
  - `GET /api/events/{id}/stream` - SSE en tiempo real
  - `GET /api/events/{id}/status` - Status simple

**5. Rutas API**
```php
POST /api/webhooks/samsara          // Webhook de Samsara
GET  /api/events                    // Listar eventos
GET  /api/events/{id}               // Ver evento
GET  /api/events/{id}/stream        // SSE stream
GET  /api/events/{id}/status        // Status
```

### FastAPI (AI Service)

**1. Endpoint Principal**
- ✅ `POST /alerts/ingest`:
  - Recibe `event_id` + `payload`
  - Ejecuta pipeline de agentes síncronamente
  - Retorna `assessment` + `message`
  - Laravel guarda en DB

**2. Modelos Actualizados**
- ✅ `AlertRequest` ahora incluye `event_id`

**3. Procesamiento**
- Mismo pipeline de 3 agentes:
  1. `ingestion_agent` (GPT-4o-mini)
  2. `panic_investigator` (GPT-4o con tools)
  3. `final_agent` (GPT-4o-mini)

## 🔄 Flujo Completo

```
1. Samsara → Webhook → Laravel
2. Laravel → Crea SamsaraEvent (status: pending)
3. Laravel → Dispatch Job → Redis Queue
4. Laravel → Responde 202 a Samsara
---
5. Worker → Toma Job
6. Worker → Actualiza status: processing
7. Worker → POST /alerts/ingest (FastAPI)
8. FastAPI → Ejecuta agentes
9. FastAPI → Retorna resultados
10. Worker → Guarda en DB (status: completed)
---
11. Frontend → GET /api/events/{id}/stream (SSE)
12. Frontend → Ve progreso en tiempo real
```

## 📦 Archivos Creados/Modificados

### Laravel
- `database/migrations/2025_11_20_211631_create_samsara_events_table.php`
- `app/Models/SamsaraEvent.php`
- `app/Jobs/ProcessSamsaraEventJob.php`
- `app/Http/Controllers/SamsaraWebhookController.php`
- `app/Http/Controllers/SamsaraEventController.php`
- `routes/api.php`
- `REDIS_QUEUE_SETUP.md`

### FastAPI
- `ai-service/api/routes.py` (modificado)
- `ai-service/api/models.py` (modificado)
- `ai-service/README.md` (actualizado)
- `ai-service/FASTAPI_QUEUE_INTEGRATION.md` (nuevo)

### Testing
- `test-queue-flow.sh` (script de prueba)

## 🚀 Próximos Pasos

1. **Configurar Redis**
   ```bash
   # .env
   QUEUE_CONNECTION=redis
   AI_ENGINE_URL=http://localhost:8000
   ```

2. **Ejecutar Migración**
   ```bash
   php artisan migrate
   ```

3. **Iniciar Servicios**
   ```bash
   # Terminal 1: FastAPI
   cd ai-service
   poetry run python main.py
   
   # Terminal 2: Laravel Queue Worker
   php artisan queue:work redis --queue=samsara-events -vvv
   
   # Terminal 3: Laravel (si no está corriendo)
   php artisan serve
   ```

4. **Probar**
   ```bash
   ./test-queue-flow.sh
   ```

## 💡 Ventajas de esta Arquitectura

✅ **Respuesta inmediata**: Samsara recibe 202 en <100ms  
✅ **Procesamiento asíncrono**: No bloquea el webhook  
✅ **Retry automático**: 3 intentos con backoff  
✅ **Escalable**: Múltiples workers en paralelo  
✅ **Trazabilidad**: Todo en DB con timestamps  
✅ **Frontend en tiempo real**: SSE para ver progreso  
✅ **Modelo unificado**: Un solo modelo para todo  
✅ **Fácil de monitorear**: Logs + Redis + DB  

## 🎯 Diferencias con Arquitectura Anterior

| Aspecto | Antes | Ahora |
|---------|-------|-------|
| **Webhook** | Bloqueante (espera IA) | Inmediato (202) |
| **Procesamiento** | Síncrono | Asíncrono (queue) |
| **SSE** | Laravel → FastAPI | Laravel → DB |
| **Persistencia** | No | Sí (DB completa) |
| **Retry** | No | Sí (3 intentos) |
| **Escalabilidad** | Limitada | Alta (workers) |
| **Modelos** | Separados? | Unificado |
