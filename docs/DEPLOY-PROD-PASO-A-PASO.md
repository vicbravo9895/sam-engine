# SAM – Despliegue a producción (paso a paso)

Guía para llevar a **producción** la rama actual desde **master**. Es un cambio grande: modelo Alert como fuente de verdad, usage (medición de uso para pricing in-house), notificaciones v2, Reverb, etc.

---

## 1. Resumen de cambios vs master

| Área | En master | En esta rama |
|------|-----------|--------------|
| **Eventos/Alertas** | `samsara_events` + `ProcessSamsaraEventJob` | `signals` + `alerts` + `ProcessAlertJob`; tablas `samsara_event_*` renombradas a `alert_*` |
| **API/Rutas** | `/api/events/...`, `SamsaraEventController` | `/api/alerts/...`, `AlertController` / `AlertReviewController` |
| **Webhook** | Crea `SamsaraEvent` | Crea `Signal` + `Alert` y encola `ProcessAlertJob` |
| **Revalidación** | `RevalidateSamsaraEventJob` | `RevalidateAlertJob` |
| **Notificaciones** | Trazabilidad básica | `notification_delivery_events`, `notification_acks`, callbacks Twilio verificados |
| **Billing / Usage** | No | `usage_events`, `usage_daily_summaries`, comando `sam:aggregate-usage` (pricing in-house después) |
| **Feature flags** | No (o mínimos) | Pennant: `ledger-v1`, `notifications-v2`, `metering-v1`, `attention-engine-v1` |
| **Frontend** | Listado/detalle por evento | Mismo flujo pero datos vienen de Alert (payload compatible) |
| **Copilot** | Reverb opcional | Reverb integrado; variables `REVERB_*` y `VITE_REVERB_*` en build |
| **Pulse** | Básico | Recorders SAM (alertas, AI, notificaciones, tokens, copilot) |

---

## 2. Prerrequisitos

- [ ] Servidor con Docker/Dokploy (igual que en `DEPLOY.md` de master).
- [ ] Base de datos PostgreSQL y Redis.
- [ ] Dominio y SSL (p. ej. Traefik + Let's Encrypt).
- [ ] Credenciales: Samsara, OpenAI, Twilio, Langfuse.

---

## 3. Backup (obligatorio)

```bash
# En el servidor o desde un cliente con acceso a la BD de prod
pg_dump -h <DB_HOST> -U <DB_USER> -d <DB_DATABASE> -F c -f sam_backup_$(date +%Y%m%d_%H%M).dump
```

Guarda el dump en un lugar seguro. Las migraciones **renombran y modifican tablas** (`samsara_event_*` → `alert_*`, columnas eliminadas); no hay rollback automático.

---

## 4. Variables de entorno nuevas o importantes

Comparado con master, revisa/agrega en tu `.env` de producción:

```env
# ========== Ya existían en master ==========
APP_KEY=...
APP_URL=https://cloud.samglobaltechnologies.com
DB_*=
REDIS_*=
AI_SERVICE_BASE_URL=http://ai-service:8000
SAMSARA_*=
TWILIO_*=
LANGFUSE_*=

# ========== Reverb (Copilot WebSocket) ==========
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=sam
REVERB_APP_KEY=<generar-seguro>
REVERB_APP_SECRET=<generar-seguro>
REVERB_HOST=cloud.samglobaltechnologies.com
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=reverb
REVERB_SERVER_PORT=8080
REVERB_SERVER_SCHEME=http

# Para que el front (build) conecte al WebSocket correcto:
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST=cloud.samglobaltechnologies.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https

# ========== Twilio (firma de webhooks) ==========
# Si usas callbacks de voz/mensaje, Twilio envía firma; el middleware VerifyTwilioSignature la valida.
# No hace falta variable extra si TWILIO_AUTH_TOKEN está definido (se usa para validar).
```

Generar valores seguros para Reverb:

```bash
php artisan key:generate --show   # o openssl rand -hex 32 para REVERB_APP_KEY / REVERB_APP_SECRET
```

---

## 5. Corte limpio: no conservar nada de `samsara_events` (recomendado si empiezas de cero)

Si **no quieres conservar ningún dato** de los eventos antiguos (`samsara_events`, comentarios, actividades, notificaciones asociadas):

1. **Backup** (sección 3).
2. **Despliega** el código e **instala migraciones** (sección 6.2–6.4).
3. **Justo después de `migrate --force`**, ejecuta el comando de limpieza:

```bash
php artisan sam:clean-legacy-samsara-events --force
```

Ese comando:

- Vacía: `alert_comments`, `alert_activities`, `notification_delivery_events`, `notification_acks`, `notification_results`, `notification_recipients`, `notification_decisions`, `event_recommended_actions`, `event_investigation_steps`, `notification_throttle_logs`.
- Anula la referencia de `conversations.context_event_id` a `samsara_events` y elimina la FK.
- **Elimina la tabla `samsara_events`.**

A partir de ahí solo existen `signals` y `alerts`; todo lo nuevo entra por el webhook/stream con el modelo nuevo. Sin `--force` el comando pide confirmación.

---

## 5bis. Conservar datos antiguos (solo si necesitas historial)

Si en producción **sí quieres conservar** el historial y que comentarios/actividades sigan ligados a alertas, la migración `2026_02_23_000001` necesita que **ya existan filas en `alerts` con `legacy_samsara_event_id`**. Opciones:

- **Backfill desde un commit anterior** que tenga el comando `sam:backfill-alerts-v2`: ejecutarlo antes de desplegar esta rama y correr migraciones.
- **Script one-off** que rellene `signals` y `alerts` desde `samsara_events` antes de esa migración.

Si no necesitas historial, usa la sección 5 (corte limpio) y olvídate del backfill.

---

## 6. Orden recomendado de despliegue

### 6.1 Mantenimiento (opcional)

Si quieres evitar webhooks durante la migración:

```bash
# En Laravel
php artisan down --refresh=15
```

O pon el sitio en mantenimiento por tu proxy/Dokploy.

### 6.2 Código e imágenes

1. En el repo: merge (o deploy) de la rama que quieras a prod.
2. Build de imágenes con el `docker-compose.prod.yml` actual:
   - `app`, `horizon`, `reverb`, `safety-daemon`, `ai-service`, `pgsql`, `redis`, `grafana-alloy` (si lo usas).

### 6.3 Variables de entorno

1. Añade/actualiza en Dokploy (o donde definas env) todas las variables de la sección 4.
2. **Importante:** Las `VITE_REVERB_*` se embeben en el build; si construyes en CI, pásalas en el step de build. Si construyes dentro de Docker, el `Dockerfile` debe recibirlas como build-args o tener un `.env` con esos valores en el momento del `npm run build`.

### 6.4 Migraciones

Desde el contenedor de la app (o un job one-off que use la misma BD):

```bash
php artisan migrate --force
```

**Si vas a corte limpio (no conservar nada de samsara_events), justo después:**

```bash
php artisan sam:clean-legacy-samsara-events --force
```

Orden aproximado que verás en las migraciones (Laravel las ordena por fecha):

- Creación de `domain_events`, `features`, tablas de notificaciones, `signals`, `alerts`, `alert_ai`, `alert_metrics`, `alert_sources`, `usage_events`, `usage_daily_summaries`, etc.
- `2026_02_23_000001_migrate_to_alert_primary`: añade columnas a `alerts`, añade y rellena `alert_id` en tablas relacionadas, elimina FKs/columnas `samsara_event_id`, renombra tablas a `alert_comments`, `alert_activities`.
- `2026_02_23_000002_drop_legacy_samsara_event_id_from_alerts`: quita `legacy_samsara_event_id` de `alerts`.

Si algo falla, **no** hagas `migrate:rollback` a ciegas (migraciones destructivas). Restaura desde el backup y corrige.

### 6.5 Cachés y colas

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan horizon:terminate   # para que reinicie con el código nuevo
```

Reverb y safety-daemon se reinician al levantar de nuevo los contenedores.

### 6.6 Salir de mantenimiento

```bash
php artisan up
```

---

## 7. Comprobaciones post-despliegue

| Comprobación | Cómo |
|--------------|------|
| App responde | `GET https://tu-dominio/up` → 200 |
| Webhook Samsara | Enviar un evento de prueba al webhook; debe crearse Signal + Alert y encolarse `ProcessAlertJob`. Revisar logs y Horizon. |
| AI Service | Que el job procese y llame a `AI_SERVICE_BASE_URL`; revisar logs del ai-service. |
| Listado de alertas | Entrar a la pantalla de eventos/alertas; debe cargar desde `alerts`. |
| Copilot / Reverb | Abrir Copilot; que la conexión WebSocket se establezca (consola del navegador o Network). |
| Notificaciones | Revisar que callbacks Twilio (voice/message status) lleguen y no devuelvan 403 (firma). |
| Pulse | Si tienes super admin: `/pulse` y que aparezcan los recorders SAM. |
| Usage / metering | Super Admin → Usage: revisar que `usage_daily_summaries` tenga datos (tras `sam:aggregate-usage`). |

---

## 8. URLs que no cambian

- Webhook Samsara: sigue siendo `POST /api/webhooks/samsara` (o la ruta que tengas en `routes/api.php`).
- Twilio callbacks: las que tengas en `webhooks/twilio/*` (con middleware de firma).

No hace falta cambiar la URL del webhook en Samsara salvo que cambies de dominio.

---

## 9. Feature flags (AppServiceProvider)

En esta rama el modelo de alertas **ya es Alert** (no hay flag “alerts-v2” para activar; la app escribe y lee Alert). Los flags que sí puedes tocar:

| Flag | Por defecto | Uso |
|------|-------------|-----|
| `ledger-v1` | `true` | Emisión de `domain_events` (auditoría). |
| `notifications-v2` | `true` | Trazabilidad delivery/ack. |
| `metering-v1` | `false` | Si lo pones en `true`, se registra uso en `usage_events` (para pricing in-house después). |
| `attention-engine-v1` | `true` | Motor de atención (SLA, escalación). |

Para facturación y pantalla de Usage en Super Admin, activa `metering-v1` (en código o por Pennant por empresa).

---

## 10. Rollback rápido (solo si algo sale mal)

1. **No** uses `migrate:rollback` sin haber revisado qué migraciones son reversibles (muchas de esta rama no lo son).
2. Opción segura: restaurar el dump de PostgreSQL del paso 3 y volver a desplegar la imagen/rama de **master**.
3. Revertir variables de entorno y volver a construir el front si en master no usabas Reverb.

---

## 11. Checklist final

- [ ] Backup de PostgreSQL hecho y guardado.
- [ ] Variables de entorno nuevas (Reverb, VITE_REVERB_*) configuradas.
- [ ] **Corte limpio:** `php artisan migrate --force` y luego `php artisan sam:clean-legacy-samsara-events --force`.  
  **O** si conservas historial: backfill ejecutado antes de migrar (ver 5bis).
- [ ] `config:cache`, `route:cache`, `view:cache` y `horizon:terminate` ejecutados.
- [ ] Pruebas: `/up`, webhook, listado alertas, Copilot, Pulse, Twilio callbacks.
- [ ] Documentación interna actualizada (URLs, env, procedimientos).

Cuando todo esté verde, el despliegue a prod con este cambio grande queda cerrado. Si en tu entorno usas un pipeline (GitHub Actions, etc.), conviene tener estos pasos en un runbook o en el propio pipeline (con migraciones y validaciones post-deploy).
