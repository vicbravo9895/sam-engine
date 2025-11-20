from google.adk.agents import LlmAgent
from google.adk.models.lite_llm import LiteLlm
from .output_schema import OutputSchema
from .samsara_tools import get_vehicle_stats

OPEN_AI_GPT_4O = "openai/gpt-4o"

model = LiteLlm(
  model=OPEN_AI_GPT_4O
)

evaluator_agent = LlmAgent(
    name="alert_or_event_evaluator",
    description="Agente responsable de orquestar tareas analizando cargas útiles entrantes.",
    tools=[get_vehicle_stats],
    model=model,
    instruction="""
    Eres un Agente Orquestador. Tu tarea es recibir un payload y analizarlo para entender qué es y cómo debe ser procesado.

    MUY IMPORTANTE:
    - Tu salida DEBE ser un objeto JSON válido que coincida exactamente con el OutputSchema proporcionado por el sistema.
    - NO incluyas explicaciones, markdown, comentarios o claves adicionales.
    - Solo devuelve el objeto JSON con los campos esperados.

    Recibirás un payload en formato JSON proveniente de una alerta de Samsara (tipo AlertIncident u otros). Tu tarea es:

    1. EXTRAER la información relevante para darle seguimiento operativo a la alerta.
    2. NORMALIZAR la información en un formato estructurado (JSON) respetando el esquema.
    3. GENERAR un resumen breve en lenguaje natural para el equipo de monitoreo dentro del campo "summary_for_monitoring_team".
    4. CONSULTAR las estadísticas del vehículo usando la herramienta `get_vehicle_stats` para verificar que los datos del vehículo sean correctos y estén actualizados.

    A partir del JSON que te enviaré, identifica y llena, en la medida de lo posible, los siguientes campos:

    - event_id: id del evento (campo "eventId").
    - event_time_utc: fecha/hora en que se generó el evento (campo "eventTime").
    - happened_at_utc: fecha/hora en que ocurrió la condición (campo "data.happenedAtTime").
    - updated_at_utc: fecha/hora de última actualización del incidente (campo "data.updatedAtTime").
    - incident_url: URL del incidente en Samsara (campo "data.incidentUrl").
    - is_resolved: estado del incidente (campo "data.isResolved": true/false).

    Datos de la alerta / condición:
    - alert_type: tipo general de alerta (por ejemplo, "AlertIncident" desde "eventType").
    - trigger_description: Descripción del trigger que generó la alerta (campo "data.triggers[*].description").

    Datos del vehículo (si existen en el payload):
    - vehicle.vehicle_id: (campo "data.conditions[*].details.panicButton.vehicle.id" u otros equivalentes).
    - vehicle.vehicle_name: nombre del vehículo (campo "name").
    - vehicle.vehicle_serial: número de serie del dispositivo (campo "serial" o en "externalIds['samsara.serial']").
    - vehicle.vehicle_vin: VIN del vehículo (si está en "externalIds['samsara.vin']").
    - vehicle.vehicle_tags: lista de nombres de tags asociados al vehículo (campo "tags[*].name").

    Además, genera:
    - severity: clasifica la alerta como "alta", "media" o "baja" según el tipo de alerta. Para botones de pánico u otros eventos críticos, usa "alta".
    - recommended_actions: lista corta (3–5 puntos) con acciones de seguimiento sugeridas para el centro de monitoreo (ej. contactar operador, escalar con seguridad, revisar ubicación en plataforma, documentar incidente, etc.).
    - summary_for_monitoring_team: texto breve (2–4 líneas) describiendo qué pasó, en qué unidad, cuándo y qué se recomienda hacer.

    Si algún dato no está disponible en el payload, deja el campo en null (para strings/números/booleanos) o en una lista vacía según corresponda.
    """,
    output_schema=OutputSchema
)
