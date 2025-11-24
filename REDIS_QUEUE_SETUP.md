# Configuración de Redis Queue para Laravel

Este documento explica cómo configurar Redis como queue driver para procesar eventos de Samsara en background.

## 📦 Instalación

### 1. Instalar Redis (si no lo tienes)

```bash
# macOS con Homebrew
brew install redis
brew services start redis

# Verificar que Redis está corriendo
redis-cli ping
# Debe responder: PONG
```

### 2. Instalar predis (cliente PHP para Redis)

```bash
composer require predis/predis
```

## ⚙️ Configuración de Laravel

### 1. Configurar `.env`

Agrega o actualiza estas líneas en tu `.env`:

```env
# Queue Configuration
QUEUE_CONNECTION=redis

# Redis Configuration
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_DB=0

# AI Service URL
AI_ENGINE_URL=http://localhost:8000
```

### 2. Configurar `config/queue.php`

Laravel ya viene con configuración de Redis por defecto. Verifica que exista:

```php
'connections' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => env('REDIS_QUEUE', 'default'),
        'retry_after' => 90,
        'block_for' => null,
    ],
],
```

### 3. Configurar `config/services.php`

Agrega la configuración del AI Engine:

```php
return [
    // ... otras configuraciones
    
    'ai_engine' => [
        'url' => env('AI_ENGINE_URL', 'http://localhost:8000'),
    ],
];
```

## 🚀 Ejecutar Queue Workers

### Desarrollo (single worker)

```bash
# Ejecutar worker en foreground
php artisan queue:work redis --queue=samsara-events --tries=3 --timeout=300

# Con verbose para ver logs
php artisan queue:work redis --queue=samsara-events --tries=3 --timeout=300 -vvv
```

### Producción (con Supervisor)

Crea un archivo de configuración de Supervisor:

```bash
sudo nano /etc/supervisor/conf.d/sam-engine-worker.conf
```

Contenido:

```ini
[program:sam-engine-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/sam-engine/artisan queue:work redis --queue=samsara-events --tries=3 --timeout=300 --sleep=3 --max-jobs=1000
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/path/to/sam-engine/storage/logs/worker.log
stopwaitsecs=3600
```

Comandos de Supervisor:

```bash
# Recargar configuración
sudo supervisorctl reread
sudo supervisorctl update

# Iniciar workers
sudo supervisorctl start sam-engine-worker:*

# Ver estado
sudo supervisorctl status

# Reiniciar workers
sudo supervisorctl restart sam-engine-worker:*

# Detener workers
sudo supervisorctl stop sam-engine-worker:*
```

## 🔍 Monitoreo de Queue

### Ver jobs en la queue

```bash
# Conectar a Redis CLI
redis-cli

# Ver todas las keys
KEYS *

# Ver jobs pendientes en la queue
LRANGE queues:samsara-events 0 -1

# Ver número de jobs en la queue
LLEN queues:samsara-events

# Ver jobs fallidos
LRANGE queues:samsara-events:failed 0 -1
```

### Laravel Horizon (Recomendado para producción)

```bash
# Instalar Horizon
composer require laravel/horizon

# Publicar assets
php artisan horizon:install

# Ejecutar Horizon
php artisan horizon

# Acceder al dashboard
# http://tu-dominio.com/horizon
```

## 🧪 Testing

### 1. Simular webhook de Samsara

```bash
curl -X POST http://localhost:8000/api/webhooks/samsara \
  -H "Content-Type: application/json" \
  -d '{
    "alertType": "panic_button",
    "vehicle": {
      "id": "123",
      "name": "Camión 1234-ABC"
    },
    "driver": {
      "id": "456",
      "name": "Juan Pérez"
    },
    "severity": "critical",
    "time": "2024-01-15T14:32:00Z"
  }'
```

### 2. Ver el evento creado

```bash
# Conectar a la DB
php artisan tinker

# Ver eventos
App\Models\SamsaraEvent::latest()->first();

# Ver eventos pendientes
App\Models\SamsaraEvent::pending()->get();

# Ver eventos procesados
App\Models\SamsaraEvent::completed()->get();
```

### 3. Ver logs

```bash
# Logs de Laravel
tail -f storage/logs/laravel.log

# Logs del worker (si usas Supervisor)
tail -f storage/logs/worker.log
```

## 📊 Comandos Útiles

```bash
# Ver jobs fallidos
php artisan queue:failed

# Reintentar un job fallido
php artisan queue:retry <job-id>

# Reintentar todos los jobs fallidos
php artisan queue:retry all

# Limpiar jobs fallidos
php artisan queue:flush

# Ver estadísticas de la queue
php artisan queue:monitor redis:samsara-events

# Reiniciar workers (después de cambios en código)
php artisan queue:restart
```

## 🔧 Troubleshooting

### Worker no procesa jobs

```bash
# Verificar que Redis está corriendo
redis-cli ping

# Verificar configuración de queue
php artisan config:cache
php artisan queue:restart

# Ver logs con verbose
php artisan queue:work redis --queue=samsara-events -vvv
```

### Jobs fallan constantemente

```bash
# Ver detalles del job fallido
php artisan queue:failed

# Ver logs
tail -f storage/logs/laravel.log

# Verificar que el AI service está corriendo
curl http://localhost:8000/health
```

### Redis connection refused

```bash
# Verificar que Redis está corriendo
brew services list | grep redis

# Iniciar Redis
brew services start redis

# Verificar puerto
redis-cli -h 127.0.0.1 -p 6379 ping
```

## 🎯 Flujo Completo

1. **Samsara envía webhook** → Laravel recibe en `SamsaraWebhookController`
2. **Laravel crea evento** → Guarda en DB con status `pending`
3. **Laravel encola job** → `ProcessSamsaraEventJob` va a Redis queue
4. **Laravel responde 202** → Samsara recibe confirmación inmediata
5. **Worker toma el job** → Procesa en background
6. **Job actualiza status** → Cambia a `processing`
7. **Job llama a FastAPI** → Envía payload al AI service
8. **FastAPI procesa** → Agentes analizan la alerta
9. **Job recibe resultado** → FastAPI retorna assessment + message
10. **Job actualiza evento** → Guarda resultados y cambia status a `completed`
11. **Frontend consulta** → Ve el resultado vía API o SSE

## 📚 Referencias

- [Laravel Queues](https://laravel.com/docs/queues)
- [Laravel Horizon](https://laravel.com/docs/horizon)
- [Redis](https://redis.io/docs/)
- [Supervisor](http://supervisord.org/)
