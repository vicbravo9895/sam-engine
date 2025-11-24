# Arquitectura FastAPI con Laravel Queue

## 🔄 Cambios Realizados

### Nuevo Flujo

```
Laravel Webhook → Crea SamsaraEvent → Encola Job
                                         ↓
                                    Redis Queue
                                         ↓
                                    Worker procesa
                                         ↓
                                POST /alerts/ingest (FastAPI)
                                         ↓
                                Pipeline de Agentes
                                         ↓
                                Retorna resultados
                                         ↓
                                Laravel guarda en DB
```

## 📡 Endpoints

### POST /alerts/ingest

**Propósito**: Procesar alertas de forma síncrona para el Job de Laravel.

**Request:**
```json
{
  "event_id": 123,
  "payload": {
    "alertType": "panic_button",
    "vehicle": {"id": "456", "name": "Camión ABC"},
    "driver": {"id": "789", "name": "Juan Pérez"},
    "severity": "critical"
  }
}
```

**Response (Success):**
```json
{
  "status": "success",
  "event_id": 123,
  "assessment": {
    "likelihood": "high",
    "verdict": "real_panic",
    "reasoning": "...",
    "supporting_evidence": {...}
  },
  "message": "🚨 ALERTA CRÍTICA - Botón de Pánico\n\n..."
}
```

**Response (Error):**
```json
{
  "status": "error",
  "event_id": 123,
  "error": "Error message"
}
```

### GET /health

Health check del servicio.

## 🧪 Testing

### 1. Probar endpoint de ingesta directamente

```bash
curl -X POST http://localhost:8000/alerts/ingest \
  -H "Content-Type: application/json" \
  -d '{
    "event_id": 1,
    "payload": {
      "alertType": "panic_button",
      "vehicle": {"id": "123", "name": "Camión 1234-ABC"},
      "driver": {"id": "456", "name": "Juan Pérez"},
      "severity": "critical",
      "time": "2024-01-15T14:32:00Z"
    }
  }'
```

### 2. Probar flujo completo con Laravel

```bash
# 1. Iniciar FastAPI
cd ai-service
poetry run python main.py

# 2. En otra terminal, iniciar Laravel queue worker
cd ..
php artisan queue:work redis --queue=samsara-events -vvv

# 3. En otra terminal, enviar webhook
curl -X POST http://localhost:8000/api/webhooks/samsara \
  -H "Content-Type: application/json" \
  -d '{
    "alertType": "panic_button",
    "vehicle": {"id": "123", "name": "Camión 1234-ABC"},
    "driver": {"id": "456", "name": "Juan Pérez"},
    "severity": "critical"
  }'

# 4. Ver el evento en la DB
php artisan tinker
>>> App\Models\SamsaraEvent::latest()->first()
```

## 📊 Monitoreo

### Ver logs de FastAPI

```bash
# Los logs mostrarán cada procesamiento
tail -f ai-service/logs/app.log
```

### Ver logs de Laravel

```bash
# Ver procesamiento del job
tail -f storage/logs/laravel.log
```

### Ver queue en Redis

```bash
redis-cli
> LLEN queues:samsara-events
> LRANGE queues:samsara-events 0 -1
```

## 🔧 Configuración

### Laravel .env

```env
# AI Service
AI_ENGINE_URL=http://localhost:8000

# Queue
QUEUE_CONNECTION=redis
REDIS_QUEUE=samsara-events
```

### FastAPI .env

```env
# OpenAI
OPENAI_API_KEY=sk-...

# Service
SERVICE_HOST=0.0.0.0
SERVICE_PORT=8000
```

## 🎯 Ventajas de esta Arquitectura

✅ **Respuesta rápida a Samsara**: Laravel responde 202 inmediatamente  
✅ **Procesamiento asíncrono**: No bloquea el webhook  
✅ **Retry automático**: Laravel queue reintenta 3 veces si falla  
✅ **Escalable**: Múltiples workers pueden procesar en paralelo  
✅ **Trazabilidad**: Todo queda registrado en DB  
✅ **Monitoreable**: Logs claros en cada paso  

## 🚨 Manejo de Errores

### Si FastAPI está caído

- El job fallará y se reintentará automáticamente
- Después de 3 intentos, se marca como `failed` en DB
- Puedes reintentar manualmente: `php artisan queue:retry <job-id>`

### Si el procesamiento falla

- El error se guarda en `ai_error` del evento
- El status queda en `failed`
- Puedes ver el error en la DB o logs

### Si Redis está caído

- Los webhooks fallarán al encolar
- Laravel retornará 500 a Samsara
- Samsara reintentará el webhook automáticamente

## 📝 Próximos Pasos

1. ✅ Configurar Redis en Laravel
2. ✅ Ejecutar migración
3. ✅ Iniciar queue worker
4. ✅ Iniciar FastAPI
5. ⏳ Probar flujo completo
6. ⏳ Configurar Supervisor para workers en producción
