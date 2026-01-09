# SAM - Guía de Deploy en Dokploy

Esta guía detalla el proceso para desplegar SAM (Samsara Alert Monitor) en un servidor usando Dokploy.

## Prerequisitos

- Servidor con Dokploy instalado (16GB+ RAM, 8+ vCPU recomendado)
- Dominio configurado apuntando al servidor
- Cuenta en Langfuse Cloud (https://cloud.langfuse.com)
- Credenciales de Samsara, OpenAI y Twilio

## Arquitectura de Producción

```
┌─────────────────────────────────────────────────────────────┐
│                     Dokploy Server                          │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────┐  │
│  │   Laravel   │  │   Horizon   │  │    AI Service       │  │
│  │  (nginx +   │  │   (Queue    │  │   (FastAPI +        │  │
│  │  PHP-FPM)   │  │   Worker)   │  │   Uvicorn)          │  │
│  │   :80       │  │             │  │   :8000 (interno)   │  │
│  └──────┬──────┘  └──────┬──────┘  └──────────┬──────────┘  │
│         │                │                     │             │
│         └────────────────┼─────────────────────┘             │
│                          │                                   │
│         ┌────────────────┴────────────────┐                 │
│         │                                 │                 │
│  ┌──────▼──────┐                  ┌───────▼──────┐          │
│  │ PostgreSQL  │                  │    Redis     │          │
│  │   :5432     │                  │    :6379     │          │
│  └─────────────┘                  └──────────────┘          │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

## Opción A: Deploy con Docker Compose (Recomendado)

### Paso 1: Crear Proyecto en Dokploy

1. Accede a tu panel de Dokploy
2. Click en **"Create Project"**
3. Nombra el proyecto: `sam-production`

### Paso 2: Crear Servicio de Tipo Compose

1. Dentro del proyecto, click en **"Add Service"**
2. Selecciona **"Docker Compose"**
3. Configura la fuente:
   - **Provider**: GitHub (o tu provider)
   - **Repository**: tu-usuario/sam
   - **Branch**: main (o master)
   - **Compose Path**: `docker-compose.prod.yml`

### Paso 3: Configurar Variables de Entorno

En la sección **"Environment Variables"** de Dokploy, agrega todas las variables del archivo `.env.production.example`:

```env
# Críticas - CAMBIAR ESTOS VALORES
APP_KEY=base64:GENERA_UNA_NUEVA_KEY
APP_URL=https://sam.tudominio.com
DB_PASSWORD=una_contraseña_muy_segura

# APIs Externas
OPENAI_API_KEY=sk-...
SAMSARA_API_TOKEN=samsara_api_...
TWILIO_ACCOUNT_SID=AC...
TWILIO_AUTH_TOKEN=...
TWILIO_PHONE_NUMBER=+1...
TWILIO_WHATSAPP_NUMBER=whatsapp:+1...

# Langfuse Cloud
LANGFUSE_PUBLIC_KEY=pk-lf-...
LANGFUSE_SECRET_KEY=sk-lf-...
LANGFUSE_HOST=https://cloud.langfuse.com
```

### Paso 4: Configurar Dominio

1. En la sección **"Domains"** del servicio `app`
2. Agrega tu dominio: `sam.tudominio.com`
3. Habilita **"HTTPS"** (Dokploy usa Let's Encrypt automáticamente)

### Paso 5: Deploy

1. Click en **"Deploy"**
2. Monitorea los logs para verificar que todo inicia correctamente
3. Una vez desplegado, ejecuta las migraciones (ver sección siguiente)

---

## Opción B: Servicios Individuales

Si prefieres más control, puedes crear cada servicio por separado:

### Servicios a Crear

| Servicio | Tipo en Dokploy | Configuración |
|----------|-----------------|---------------|
| **app** | Application | Dockerfile: `./Dockerfile`, Puerto: 80, Dominio: sí |
| **horizon** | Application | Dockerfile: `./Dockerfile.horizon`, Puerto: ninguno |
| **ai-service** | Application | Dockerfile: `./ai-service/Dockerfile`, Puerto: 8000 (interno) |
| **pgsql** | Database | Imagen: `postgres:18-alpine`, Volumen persistente |
| **redis** | Database | Imagen: `redis:7-alpine`, Volumen persistente |

### Configuración de Red

Asegúrate de que todos los servicios estén en la misma red interna de Dokploy para que puedan comunicarse entre sí usando los nombres de servicio como hostnames.

---

## Post-Deploy: Migraciones y Setup

### Ejecutar Migraciones Iniciales

Desde el panel de Dokploy, accede a la terminal del contenedor `app` y ejecuta:

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Crear Usuario Administrador (Opcional)

```bash
php artisan tinker
>>> User::create(['name' => 'Admin', 'email' => 'admin@tudominio.com', 'password' => bcrypt('tu-password')]);
```

### Generar APP_KEY (Si no tienes una)

```bash
php artisan key:generate --show
```

Copia el resultado y pégalo en las variables de entorno de Dokploy.

---

## Health Checks

Los servicios tienen health checks configurados:

| Servicio | Endpoint | Método |
|----------|----------|--------|
| Laravel | `/up` | HTTP GET |
| AI Service | `/health` | HTTP GET |
| PostgreSQL | `pg_isready` | CLI |
| Redis | `redis-cli ping` | CLI |

Dokploy monitoreará estos endpoints automáticamente.

---

## Webhooks de Samsara

Configura el webhook de Samsara para apuntar a:

```
https://sam.tudominio.com/api/samsara/webhook
```

---

## Monitoreo y Logs

### Ver Logs en Dokploy

- Accede al servicio → pestaña **"Logs"**
- Los logs de todos los servicios se pueden ver en tiempo real

### Horizon Dashboard

Accede a las colas de trabajo en:
```
https://sam.tudominio.com/horizon
```

### Langfuse (Observability)

Los traces de los agentes AI están en tu cuenta de Langfuse Cloud:
```
https://cloud.langfuse.com
```

---

## Escalado

### Escalar Horizon Workers

Edita `config/horizon.php` para ajustar el número de workers según la carga.

### Escalar AI Service

En `docker-compose.prod.yml`, ajusta la variable de entorno:
```yaml
AI_MAX_CONCURRENT_REQUESTS: 10  # Aumentar según capacidad
```

O para escalar horizontalmente, edita los workers de uvicorn en `ai-service/Dockerfile`:
```dockerfile
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "8"]
```

---

## Troubleshooting

### El contenedor no inicia

1. Revisa los logs en Dokploy
2. Verifica que todas las variables de entorno estén configuradas
3. Asegúrate de que PostgreSQL y Redis estén healthy antes de iniciar app/horizon

### Error de conexión a la base de datos

1. Verifica que `DB_HOST=pgsql` (nombre del servicio en Docker)
2. Confirma que la red interna esté configurada correctamente
3. Revisa las credenciales

### AI Service no responde

1. Verifica que `AI_SERVICE_BASE_URL=http://ai-service:8000`
2. Revisa los logs del contenedor ai-service
3. Confirma que las API keys de OpenAI y Samsara estén configuradas

### Webhooks de Samsara no llegan

1. Verifica que el dominio tenga SSL válido
2. Revisa los logs de nginx en el contenedor app
3. Confirma la URL del webhook en el panel de Samsara

---

## Backups

### PostgreSQL

Configura backups automáticos en Dokploy o usa:

```bash
# Desde el contenedor pgsql
pg_dump -U sam sam > /backup/sam_$(date +%Y%m%d).sql
```

### Redis

Redis con `appendonly yes` ya persiste datos. Para backup manual:

```bash
# Desde el contenedor redis
redis-cli BGSAVE
```

---

## Actualizaciones

Para actualizar la aplicación:

1. Haz push de los cambios a tu repositorio
2. En Dokploy, click en **"Redeploy"**
3. Dokploy hará build de las nuevas imágenes y reiniciará los servicios
4. Ejecuta migraciones si hay cambios en la base de datos:
   ```bash
   php artisan migrate --force
   ```

---

## Recursos Estimados

| Servicio | RAM | CPU |
|----------|-----|-----|
| Laravel (nginx+php-fpm) | 512MB-1GB | 1 core |
| Horizon | 256MB-512MB | 0.5 core |
| AI Service | 1GB | 1-2 cores |
| PostgreSQL | 1GB-2GB | 1 core |
| Redis | 128MB | 0.25 core |
| **Total** | **~4-5GB** | **~4-5 cores** |

Con un servidor de 16GB+ RAM y 8+ cores tienes margen suficiente para escalar.
