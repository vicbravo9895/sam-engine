#!/bin/bash

# Script de prueba completa del flujo Laravel → FastAPI
# Asegúrate de tener Redis, Laravel y FastAPI corriendo

echo "🧪 Test Completo: Laravel Queue + FastAPI"
echo "=========================================="
echo ""

# Colores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# 1. Verificar que Redis está corriendo
echo -e "${YELLOW}1. Verificando Redis...${NC}"
if redis-cli ping > /dev/null 2>&1; then
    echo -e "${GREEN}✓ Redis está corriendo${NC}"
else
    echo -e "${RED}✗ Redis no está corriendo. Ejecuta: brew services start redis${NC}"
    exit 1
fi
echo ""

# 2. Verificar que FastAPI está corriendo
echo -e "${YELLOW}2. Verificando FastAPI...${NC}"
if curl -s http://localhost:8000/health > /dev/null 2>&1; then
    echo -e "${GREEN}✓ FastAPI está corriendo${NC}"
else
    echo -e "${RED}✗ FastAPI no está corriendo. Ejecuta: cd ai-service && poetry run python main.py${NC}"
    exit 1
fi
echo ""

# 3. Verificar que Laravel está corriendo
echo -e "${YELLOW}3. Verificando Laravel...${NC}"
if curl -s http://localhost:8000/api/health > /dev/null 2>&1; then
    echo -e "${GREEN}✓ Laravel está corriendo${NC}"
else
    echo -e "${YELLOW}⚠ Laravel podría no estar corriendo. Ejecuta: php artisan serve${NC}"
fi
echo ""

# 4. Limpiar queue de Redis
echo -e "${YELLOW}4. Limpiando queue de Redis...${NC}"
redis-cli DEL queues:samsara-events > /dev/null 2>&1
echo -e "${GREEN}✓ Queue limpiada${NC}"
echo ""

# 5. Enviar webhook de prueba
echo -e "${YELLOW}5. Enviando webhook de prueba a Laravel...${NC}"
RESPONSE=$(curl -s -X POST http://localhost:8000/api/webhooks/samsara \
  -H "Content-Type: application/json" \
  -d '{
    "alertType": "panic_button",
    "vehicle": {
      "id": "TEST-123",
      "name": "Camión de Prueba ABC"
    },
    "driver": {
      "id": "DRIVER-456",
      "name": "Juan Pérez Test"
    },
    "severity": "critical",
    "time": "2024-01-15T14:32:00Z"
  }')

echo "$RESPONSE" | jq '.'

# Extraer event_id
EVENT_ID=$(echo "$RESPONSE" | jq -r '.event_id')

if [ "$EVENT_ID" != "null" ] && [ -n "$EVENT_ID" ]; then
    echo -e "${GREEN}✓ Evento creado con ID: $EVENT_ID${NC}"
else
    echo -e "${RED}✗ Error al crear evento${NC}"
    exit 1
fi
echo ""

# 6. Verificar que el job está en la queue
echo -e "${YELLOW}6. Verificando job en Redis queue...${NC}"
QUEUE_LENGTH=$(redis-cli LLEN queues:samsara-events)
echo -e "Jobs en queue: ${QUEUE_LENGTH}"
if [ "$QUEUE_LENGTH" -gt 0 ]; then
    echo -e "${GREEN}✓ Job encolado correctamente${NC}"
else
    echo -e "${RED}✗ No hay jobs en la queue${NC}"
fi
echo ""

# 7. Instrucciones para procesar el job
echo -e "${YELLOW}7. Para procesar el job:${NC}"
echo -e "   ${GREEN}php artisan queue:work redis --queue=samsara-events --once${NC}"
echo ""

# 8. Instrucciones para ver el resultado
echo -e "${YELLOW}8. Para ver el resultado:${NC}"
echo -e "   ${GREEN}curl http://localhost:8000/api/events/${EVENT_ID}${NC}"
echo ""

# 9. Instrucciones para ver el stream SSE
echo -e "${YELLOW}9. Para ver el stream SSE (desde el frontend):${NC}"
echo -e "   ${GREEN}curl http://localhost:8000/api/events/${EVENT_ID}/stream${NC}"
echo ""

echo -e "${GREEN}=========================================="
echo -e "✓ Test completado"
echo -e "==========================================${NC}"
