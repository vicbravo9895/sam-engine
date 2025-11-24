#!/bin/bash

# ============================================================================
# Test Script: Langfuse Integration
# ============================================================================
# Este script prueba la integración de Langfuse enviando una alerta de prueba
# al servicio de AI y verificando que aparezca en el dashboard de Langfuse.

set -e  # Exit on error

echo "🧪 Testing Langfuse Integration"
echo "================================"
echo ""

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# ============================================================================
# 1. Verificar que los servicios estén corriendo
# ============================================================================
echo "📋 Step 1: Checking services..."

if ! docker compose ps | grep -q "langfuse-web.*running"; then
    echo -e "${RED}❌ langfuse-web is not running${NC}"
    echo "   Run: docker compose up -d langfuse-web"
    exit 1
fi

if ! docker compose ps | grep -q "ai-service.*running"; then
    echo -e "${RED}❌ ai-service is not running${NC}"
    echo "   Run: docker compose up -d ai-service"
    exit 1
fi

echo -e "${GREEN}✅ All services are running${NC}"
echo ""

# ============================================================================
# 2. Verificar configuración de Langfuse
# ============================================================================
echo "📋 Step 2: Checking Langfuse configuration..."

if ! docker compose exec -T ai-service sh -c 'grep -q "LANGFUSE_PUBLIC_KEY=pk-lf-" .env 2>/dev/null'; then
    echo -e "${YELLOW}⚠️  LANGFUSE_PUBLIC_KEY not configured in ai-service/.env${NC}"
    echo "   Please configure Langfuse API keys first"
    echo "   See: ai-service/LANGFUSE_SETUP.md"
    echo ""
    echo "   Continuing anyway (traces won't be sent)..."
else
    echo -e "${GREEN}✅ Langfuse API keys configured${NC}"
fi

echo ""

# ============================================================================
# 3. Payload de prueba
# ============================================================================
PAYLOAD=$(cat <<'EOF'
{
  "event_id": "test-langfuse-001",
  "payload": {
    "alertType": "panic",
    "time": "2025-11-22T21:00:00Z",
    "vehicle": {
      "id": "vehicle-test-123",
      "name": "Truck 42"
    },
    "driver": {
      "id": "driver-test-456",
      "name": "John Doe"
    },
    "location": {
      "latitude": 19.4326,
      "longitude": -99.1332,
      "address": "Ciudad de México, CDMX"
    },
    "severity": "high"
  }
}
EOF
)

# ============================================================================
# 4. Enviar alerta al AI service
# ============================================================================
echo "📋 Step 3: Sending test alert to AI service..."
echo ""

RESPONSE=$(curl -s -X POST http://localhost:8000/alerts/ingest \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD")

echo "Response:"
echo "$RESPONSE" | jq '.' 2>/dev/null || echo "$RESPONSE"
echo ""

# Verificar respuesta
if echo "$RESPONSE" | jq -e '.status == "success"' > /dev/null 2>&1; then
    echo -e "${GREEN}✅ Alert processed successfully${NC}"
else
    echo -e "${RED}❌ Alert processing failed${NC}"
    exit 1
fi

echo ""

# ============================================================================
# 5. Instrucciones para verificar en Langfuse
# ============================================================================
echo "📋 Step 4: Verify in Langfuse Dashboard"
echo ""
echo "1. Open Langfuse dashboard: ${YELLOW}http://localhost:3030${NC}"
echo "2. Navigate to: ${YELLOW}Traces${NC}"
echo "3. Look for trace with:"
echo "   - Name: ${YELLOW}samsara_alert_processing${NC}"
echo "   - Metadata:"
echo "     - event_id: ${YELLOW}test-langfuse-001${NC}"
echo "     - alert_type: ${YELLOW}panic${NC}"
echo "     - vehicle_id: ${YELLOW}vehicle-test-123${NC}"
echo "4. Click on the trace to see:"
echo "   - Full input payload"
echo "   - LLM calls (ingestion_agent, panic_investigator, final_agent)"
echo "   - Tokens and costs per call"
echo "   - Final output (assessment + message)"
echo ""

# ============================================================================
# 6. Métricas esperadas
# ============================================================================
echo "📊 Expected Metrics:"
echo ""
echo "- ${YELLOW}Trace name:${NC} samsara_alert_processing"
echo "- ${YELLOW}Tags:${NC} samsara, alert, panic"
echo "- ${YELLOW}LLM calls:${NC} 3 (ingestion, investigator, final)"
echo "- ${YELLOW}Models used:${NC}"
echo "  - gpt-4o-mini (ingestion + final)"
echo "  - gpt-4o (investigator with tools)"
echo "- ${YELLOW}Total tokens:${NC} ~2000-4000 (depends on response)"
echo "- ${YELLOW}Estimated cost:${NC} ~$0.01-0.03"
echo ""

echo -e "${GREEN}✅ Test completed!${NC}"
echo ""
echo "Next steps:"
echo "1. Check the Langfuse dashboard"
echo "2. Explore the trace details"
echo "3. Review metrics and costs"
echo ""
