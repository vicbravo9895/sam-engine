# Langfuse Self-Hosted - Guía de Setup y Uso

Esta guía explica cómo configurar y usar Langfuse self-hosted para observabilidad de AI en el proyecto SAM Engine.

## 📋 Tabla de Contenidos

- [Arquitectura](#arquitectura)
- [Setup Inicial](#setup-inicial)
- [Configuración](#configuración)
- [Uso](#uso)
- [Dashboard y Métricas](#dashboard-y-métricas)
- [Troubleshooting](#troubleshooting)

## 🏗️ Arquitectura

Langfuse está integrado en el stack de Docker Compose con los siguientes servicios:

- **langfuse-web**: UI y API principal (puerto 3030)
- **langfuse-worker**: Worker asíncrono para procesamiento de eventos
- **clickhouse**: Base de datos OLAP para trazas y observaciones
- **minio**: Object storage para eventos y media
- **pgsql**: Base de datos compartida con Laravel (tabla `langfuse`)
- **redis**: Cache compartido con Laravel

## 🚀 Setup Inicial

### 1. Generar Claves de Seguridad

Antes de iniciar Langfuse, genera claves seguras:

```bash
# Generar NEXTAUTH_SECRET
openssl rand -hex 32

# Generar ENCRYPTION_KEY
openssl rand -hex 32
```

### 2. Configurar Variables de Entorno

Edita tu archivo `.env` en la raíz del proyecto:

```bash
# Langfuse Security Keys (CAMBIAR EN PRODUCCIÓN)
LANGFUSE_NEXTAUTH_SECRET=<tu-nextauth-secret-generado>
LANGFUSE_SALT=<tu-salt-personalizado>
LANGFUSE_ENCRYPTION_KEY=<tu-encryption-key-generado>

# ClickHouse
CLICKHOUSE_USER=clickhouse
CLICKHOUSE_PASSWORD=<password-seguro>

# MinIO
MINIO_ROOT_USER=minio
MINIO_ROOT_PASSWORD=<password-seguro>

# Opcional: Auto-crear usuario inicial
LANGFUSE_INIT_USER_EMAIL=admin@example.com
LANGFUSE_INIT_USER_PASSWORD=<password-seguro>
LANGFUSE_INIT_USER_NAME=Admin
LANGFUSE_INIT_ORG_NAME=SAM Technologies
LANGFUSE_INIT_PROJECT_NAME=Samsara Alerts
```

### 3. Crear Base de Datos de Langfuse

Langfuse necesita su propia base de datos en PostgreSQL:

```bash
# Conectarse a PostgreSQL
docker compose exec pgsql psql -U <DB_USERNAME> -d postgres

# Crear base de datos
CREATE DATABASE langfuse;
\q
```

### 4. Iniciar Servicios

```bash
# Iniciar todos los servicios
docker compose up -d

# Ver logs de Langfuse
docker compose logs -f langfuse-web langfuse-worker
```

Espera 2-3 minutos hasta que veas "Ready" en los logs de `langfuse-web`.

### 5. Acceder al Dashboard

Abre http://localhost:3030 en tu navegador.

- Si configuraste `LANGFUSE_INIT_USER_EMAIL`, inicia sesión con esas credenciales
- Si no, crea una cuenta manualmente en la UI

### 6. Obtener API Keys

Una vez dentro del dashboard:

1. Ve a **Settings** → **API Keys**
2. Copia el **Public Key** (empieza con `pk-lf-`)
3. Copia el **Secret Key** (empieza con `sk-lf-`)

### 7. Configurar AI Service

Edita `ai-service/.env`:

```bash
# Langfuse Observability
LANGFUSE_PUBLIC_KEY=pk-lf-...
LANGFUSE_SECRET_KEY=sk-lf-...
LANGFUSE_HOST=http://langfuse-web:3000
```

### 8. Instalar Dependencias de Python

```bash
cd ai-service
poetry install
```

### 9. Reiniciar AI Service

```bash
docker compose restart ai-service
```

## ⚙️ Configuración

### Integración Automática con LiteLLM

El módulo `config/langfuse.py` configura automáticamente callbacks de Langfuse para LiteLLM. Esto significa que **todas las llamadas a OpenAI se rastrean automáticamente** sin código adicional.

### Tracing Manual

El endpoint `/alerts/ingest` está instrumentado con tracing manual para capturar:

- **Trace completo** de procesamiento de alerta
- **Metadata contextual**: event_id, alert_type, vehicle_id, driver_name
- **Spans** para cada fase del pipeline
- **Outputs** de assessment y mensaje final
- **Errores** con stack traces

## 📊 Dashboard y Métricas

### Vistas Principales

1. **Traces**: Ver todas las ejecuciones de alertas
2. **Sessions**: Agrupar trazas por sesión
3. **Users**: Ver actividad por usuario
4. **Models**: Métricas por modelo (GPT-4o, GPT-4o-mini)
5. **Scores**: Evaluaciones y feedback

### Métricas Disponibles

- **Tokens usados** por alerta/agente/modelo
- **Costos** estimados por llamada
- **Latencia** de cada agente y del pipeline completo
- **Tasa de errores** y tipos de errores
- **Distribución** de tipos de alertas procesadas

### Queries Útiles

#### Ver alertas de pánico procesadas hoy

```
Filter by:
- Tag: "panic"
- Date: Today
```

#### Analizar costos por vehículo

```
Group by: metadata.vehicle_id
Metric: Total cost
```

#### Identificar alertas con errores

```
Filter by:
- Level: ERROR
Sort by: Timestamp (desc)
```

## 🔍 Uso en Desarrollo

### Ver Trazas en Tiempo Real

1. Envía una alerta de prueba:
   ```bash
   ./test-langfuse.sh
   ```

2. Ve al dashboard de Langfuse: http://localhost:3030

3. Navega a **Traces** → verás la nueva traza aparecer

4. Haz clic en la traza para ver:
   - Input completo (payload de Samsara)
   - Cada llamada a LLM (ingestion, investigator, final)
   - Tokens y costos por llamada
   - Output final (assessment + message)
   - Latencia total y por fase

### Debugging de Agentes

Si un agente falla o produce resultados inesperados:

1. Busca la traza en Langfuse
2. Revisa el **input** que recibió
3. Examina el **prompt** exacto enviado
4. Verifica la **respuesta** del LLM
5. Checa **tokens usados** (puede indicar truncamiento)

## 🐛 Troubleshooting

### Langfuse no aparece en logs

```bash
# Verificar que los servicios estén corriendo
docker compose ps

# Ver logs de dependencias
docker compose logs clickhouse minio
```

### "Database connection failed"

```bash
# Verificar que la base de datos langfuse exista
docker compose exec pgsql psql -U <DB_USERNAME> -l | grep langfuse

# Si no existe, crearla
docker compose exec pgsql psql -U <DB_USERNAME> -c "CREATE DATABASE langfuse;"
```

### "No traces appearing in dashboard"

1. Verifica que las API keys estén configuradas en `ai-service/.env`
2. Revisa logs del ai-service:
   ```bash
   docker compose logs ai-service | grep -i langfuse
   ```
3. Deberías ver: `✅ Langfuse inicializado: http://langfuse-web:3000`

### ClickHouse healthcheck failing

```bash
# ClickHouse puede tardar en iniciar la primera vez
docker compose logs clickhouse

# Espera hasta ver "Ready for connections"
```

### MinIO healthcheck failing

```bash
# Reiniciar MinIO
docker compose restart minio

# Verificar logs
docker compose logs minio
```

## 📈 Producción

### Consideraciones de Seguridad

1. **Cambiar todas las contraseñas** en `.env`
2. **Usar HTTPS** para Langfuse (configurar reverse proxy)
3. **Limitar acceso** a puertos de ClickHouse y MinIO (solo localhost)
4. **Backup regular** de PostgreSQL y ClickHouse

### Escalabilidad

Para alto volumen de trazas:

1. **Múltiples workers**: Escalar `langfuse-worker` horizontalmente
2. **ClickHouse cluster**: Habilitar `CLICKHOUSE_CLUSTER_ENABLED=true`
3. **Redis separado**: Usar instancia dedicada para Langfuse
4. **S3 real**: Migrar de MinIO a AWS S3/GCS/Azure Blob

### Monitoreo

Métricas a monitorear:

- **Latencia de ingesta**: Tiempo desde API call hasta trace visible
- **Uso de disco**: ClickHouse y MinIO crecen con el tiempo
- **Memoria de workers**: Ajustar según volumen
- **Conexiones a PostgreSQL**: Pool size adecuado

## 🔗 Referencias

- [Documentación oficial de Langfuse](https://langfuse.com/docs)
- [Self-hosting guide](https://langfuse.com/docs/deployment/self-host)
- [Python SDK](https://langfuse.com/docs/sdk/python)
- [LiteLLM integration](https://langfuse.com/docs/integrations/litellm)
