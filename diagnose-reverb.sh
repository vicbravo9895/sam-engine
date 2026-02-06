#!/bin/bash
# Script de diagnóstico para Reverb WebSocket en Dokploy
# Ejecutar en el servidor: bash diagnose-reverb.sh

set -e

echo "🔍 Diagnóstico de Reverb WebSocket"
echo "=================================="
echo ""

# 1. Verificar que los contenedores existen y están corriendo
echo "1️⃣  Estado de contenedores..."
if docker ps | grep -q sam-reverb; then
    echo "   ✅ sam-reverb está corriendo"
else
    echo "   ❌ sam-reverb NO está corriendo"
    exit 1
fi

if docker ps | grep -q traefik; then
    echo "   ✅ traefik está corriendo"
else
    echo "   ❌ traefik NO está corriendo (Dokploy usa Traefik como proxy)"
    exit 1
fi

echo ""

# 2. Verificar que Reverb está escuchando
echo "2️⃣  Logs de Reverb (últimas 10 líneas)..."
docker logs sam-reverb --tail 10 | grep -E 'Starting server|error|8080' || echo "   ⚠️  No se encontró mensaje de inicio"
echo ""

# 3. Verificar labels de Traefik
echo "3️⃣  Labels de Traefik en sam-reverb..."
LABELS=$(docker inspect sam-reverb --format '{{range $k, $v := .Config.Labels}}{{$k}}={{$v}}{{"\n"}}{{end}}' | grep traefik || echo "")
if [ -z "$LABELS" ]; then
    echo "   ❌ NO hay labels de traefik"
    echo "   → Dokploy no está usando los labels del docker-compose.prod.yml"
    echo "   → Solución: usar subdomain o configurar en Dokploy UI"
else
    echo "   ✅ Labels encontrados:"
    echo "$LABELS" | sed 's/^/      /'
fi
echo ""

# 4. Verificar redes
echo "4️⃣  Redes de Docker..."
REVERB_NETWORKS=$(docker inspect sam-reverb --format '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}')
TRAEFIK_NETWORKS=$(docker inspect traefik --format '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' 2>/dev/null || echo "N/A")

echo "   sam-reverb está en: $REVERB_NETWORKS"
echo "   traefik está en: $TRAEFIK_NETWORKS"

# Verificar si comparten al menos una red
SHARED=false
for net in $REVERB_NETWORKS; do
    if echo "$TRAEFIK_NETWORKS" | grep -q "$net"; then
        echo "   ✅ Comparten la red: $net"
        SHARED=true
        break
    fi
done

if [ "$SHARED" = false ]; then
    echo "   ❌ NO comparten ninguna red"
    echo "   → Conectar Traefik a sam-network:"
    echo "      docker network connect sam-network traefik"
fi
echo ""

# 5. Test de conectividad interna
echo "5️⃣  Test de conectividad interna..."
if docker exec sam-app sh -c 'nc -zv reverb 8080' 2>&1 | grep -q succeeded; then
    echo "   ✅ sam-app puede conectar a reverb:8080"
else
    echo "   ❌ sam-app NO puede conectar a reverb:8080"
    echo "   → Verificar que reverb está escuchando en 0.0.0.0:8080"
fi
echo ""

# 6. Logs de Traefik (buscar errores relacionados con reverb)
echo "6️⃣  Logs de Traefik (búsqueda de 'reverb' o errores)..."
TRAEFIK_LOGS=$(docker logs traefik --tail 50 2>&1 | grep -iE 'reverb|error.*8080' || echo "")
if [ -z "$TRAEFIK_LOGS" ]; then
    echo "   ⚠️  No se encontraron menciones de 'reverb' en logs de Traefik"
    echo "   → Posiblemente Traefik no ha detectado el servicio"
else
    echo "   Logs relevantes:"
    echo "$TRAEFIK_LOGS" | sed 's/^/      /'
fi
echo ""

# 7. Resumen y recomendaciones
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📋 RESUMEN Y RECOMENDACIONES"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

if [ -z "$LABELS" ]; then
    echo "🔧 Problema principal: Traefik no tiene labels de routing"
    echo ""
    echo "   Solución recomendada: usar SUBDOMAIN"
    echo ""
    echo "   1. Añadir DNS: reverb.copilot.delapengineering.com → IP del servidor"
    echo "   2. En .env de producción:"
    echo "      REVERB_HOST=reverb.copilot.delapengineering.com"
    echo "      REVERB_PORT=443"
    echo "      REVERB_SCHEME=https"
    echo ""
    echo "   3. Modificar labels en docker-compose.prod.yml (ver DOKPLOY_REVERB_SETUP.md sección 8)"
    echo "   4. Redeploy: docker compose up -d --force-recreate reverb"
    echo ""
elif [ "$SHARED" = false ]; then
    echo "🔧 Problema principal: Traefik y Reverb no están en la misma red"
    echo ""
    echo "   Solución: conectar Traefik a la red de SAM"
    echo "   $ docker network connect sam-network traefik"
    echo "   $ docker restart traefik"
    echo ""
else
    echo "✅ Configuración parece correcta"
    echo ""
    echo "   Verificar en el navegador (F12 → Network → WS):"
    echo "   - URL: wss://copilot.delapengineering.com/app/..."
    echo "   - Estado esperado: 101 Switching Protocols"
    echo ""
    echo "   Si sigue fallando, compartir:"
    echo "   - docker logs traefik --tail 100"
    echo "   - Screenshot del error en navegador"
fi
echo ""
