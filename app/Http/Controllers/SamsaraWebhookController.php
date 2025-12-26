<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessSamsaraEventJob;
use App\Models\SamsaraEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SamsaraWebhookController extends Controller
{
    /**
     * Maneja el webhook de Samsara
     * 
     * Este endpoint debe responder rápidamente a Samsara,
     * por lo que solo crea el evento y encola el procesamiento.
     */
    public function handle(Request $request)
    {
        $payload = $request->all();

        Log::info('Samsara webhook received', [
            'payload_keys' => array_keys($payload),
        ]);

        try {
            // Extraer información básica del payload
            $eventData = $this->extractEventData($payload);

            // Crear el evento en la base de datos
            $event = SamsaraEvent::create($eventData);

            // Encolar el procesamiento con IA (Redis queue)
            ProcessSamsaraEventJob::dispatch($event);

            Log::info('Samsara event created and queued', [
                'event_id' => $event->id,
                'event_type' => $event->event_type,
            ]);

            // Responder inmediatamente a Samsara
            return response()->json([
                'status' => 'success',
                'message' => 'Event received and queued for processing',
                'event_id' => $event->id,
            ], 202); // 202 Accepted

        } catch (\Exception $e) {
            Log::error('Failed to process Samsara webhook', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process webhook',
            ], 500);
        }
    }

    /**
     * Extrae la información relevante del payload de Samsara
     */
    private function extractEventData(array $payload): array
    {
        // Determinar el tipo de evento
        $eventType = $this->determineEventType($payload);
        $vehicleInfo = $this->extractVehicleInfo($payload);
        $eventDescription = $this->extractEventDescription($payload);

        // Traducir description si es necesario
        if ($eventDescription) {
            $eventDescription = $this->translateEventDescription($eventDescription);
        }

        return [
            'event_type' => $eventType,
            'event_description' => $eventDescription,
            'samsara_event_id' => $payload['eventId'] ?? $payload['id'] ?? null,
            'vehicle_id' => $vehicleInfo['id'] ?? null,
            'vehicle_name' => $vehicleInfo['name'] ?? null,
            'driver_id' => $payload['driver']['id'] ?? $payload['driverId'] ?? null,
            'driver_name' => $payload['driver']['name'] ?? $payload['driverName'] ?? null,
            'severity' => $this->determineSeverity($payload),
            'occurred_at' => $payload['data']['happenedAtTime'] ?? $payload['eventTime'] ?? $payload['time'] ?? now(),
            'raw_payload' => $payload,
            'ai_status' => SamsaraEvent::STATUS_PENDING,
        ];
    }

    /**
     * Extrae la información del vehículo buscando en estructuras anidadas
     */
    private function extractVehicleInfo(array $payload): array
    {
        // 1. Verificar nivel superior (estructura simple)
        if (isset($payload['vehicle'])) {
            return $payload['vehicle'];
        }

        if (isset($payload['vehicleId'])) {
            return [
                'id' => $payload['vehicleId'],
                'name' => $payload['vehicleName'] ?? null,
            ];
        }

        // 2. Verificar dentro de data.conditions (AlertIncident)
        if (isset($payload['data']['conditions']) && is_array($payload['data']['conditions'])) {
            foreach ($payload['data']['conditions'] as $condition) {
                // Estructura: details -> [triggerType] -> vehicle
                foreach ($condition['details'] ?? [] as $detail) {
                    if (isset($detail['vehicle'])) {
                        return $detail['vehicle'];
                    }
                }
            }
        }

        return [];
    }

    /**
     * Determina el tipo de evento basado en el payload
     */
    private function determineEventType(array $payload): string
    {
        // Lógica para determinar el tipo de evento
        if (isset($payload['alertType'])) {
            return $payload['alertType'];
        }

        if (isset($payload['eventType'])) {
            return $payload['eventType'];
        }

        if (isset($payload['type'])) {
            return $payload['type'];
        }

        return 'unknown';
    }

    /**
     * Extrae la descripción del evento desde conditions
     */
    private function extractEventDescription(array $payload): ?string
    {
        // Buscar en data.conditions[].description
        if (isset($payload['data']['conditions']) && is_array($payload['data']['conditions'])) {
            foreach ($payload['data']['conditions'] as $condition) {
                if (isset($condition['description'])) {
                    return $condition['description'];
                }
            }
        }

        return null;
    }

    /**
     * Traduce descripciones de eventos comunes al español.
     * 
     * Esta traducción se aplica al webhook inicial. El Job también puede
     * actualizar la descripción si encuentra un behavior_name más específico.
     */
    private function translateEventDescription(string $description): string
    {
        $translations = [
            // Panic Button
            'Panic Button' => 'Botón de pánico',
            'panic button' => 'Botón de pánico',
            'PANIC BUTTON' => 'Botón de pánico',
            
            // Safety Event genérico (se enriquece después en el Job)
            'A safety event occurred' => 'Evento de seguridad',
            'a safety event occurred' => 'Evento de seguridad',
            
            // Eventos de manejo brusco
            'Hard Braking' => 'Frenado brusco',
            'hard braking' => 'Frenado brusco',
            'Harsh Braking' => 'Frenado brusco',
            'Harsh Acceleration' => 'Aceleración brusca',
            'harsh acceleration' => 'Aceleración brusca',
            'Hard Acceleration' => 'Aceleración brusca',
            'Sharp Turn' => 'Giro brusco',
            'sharp turn' => 'Giro brusco',
            'Harsh Turn' => 'Giro brusco',
            
            // Comportamiento del conductor
            'Distracted Driving' => 'Conducción distraída',
            'distracted driving' => 'Conducción distraída',
            'Drowsiness' => 'Somnolencia',
            'Cell Phone Use' => 'Uso de celular',
            'Cell Phone' => 'Uso de celular',
            'No Seatbelt' => 'Sin cinturón de seguridad',
            
            // Detecciones de cámara
            'Passenger Detection' => 'Detección de pasajero',
            'Driver Detection' => 'Detección de conductor',
            'No Driver Detected' => 'Conductor no detectado',
            'Obstructed Camera' => 'Cámara obstruida',
            
            // Eventos de seguridad vial
            'Following Distance' => 'Distancia de seguimiento',
            'following distance' => 'Distancia de seguimiento',
            'Speeding' => 'Exceso de velocidad',
            'speeding' => 'Exceso de velocidad',
            'Stop Sign Violation' => 'Violación de señal de alto',
            'stop sign violation' => 'Violación de señal de alto',
            'Lane Departure' => 'Salida de carril',
            'Forward Collision Warning' => 'Advertencia de colisión frontal',
            'Rolling Stop' => 'Alto sin detenerse',
            
            // Colisiones
            'Collision' => 'Colisión',
            'Near Collision' => 'Casi colisión',
        ];

        return $translations[$description] ?? $description;
    }

    /**
     * Determina la severidad del evento
     */
    private function determineSeverity(array $payload): string
    {
        // 1. Primero verificar si viene explícitamente en el payload
        $severity = $payload['severity'] ?? $payload['level'] ?? null;

        if ($severity) {
            // Normalizar a nuestros valores
            $severity = strtolower($severity);

            if (in_array($severity, ['critical', 'high', 'panic'])) {
                return SamsaraEvent::SEVERITY_CRITICAL;
            }

            if (in_array($severity, ['warning', 'medium'])) {
                return SamsaraEvent::SEVERITY_WARNING;
            }

            return SamsaraEvent::SEVERITY_INFO;
        }

        // 2. Si no viene severity, determinar por el tipo de evento y descripción
        $eventDescription = $this->extractEventDescription($payload);
        $eventType = $this->determineEventType($payload);

        // Eventos críticos por descripción
        $criticalDescriptions = [
            'panic button',
            'botón de pánico',
            'collision',
            'colisión',
            'crash',
            'accidente',
        ];

        if ($eventDescription) {
            $descriptionLower = strtolower($eventDescription);
            foreach ($criticalDescriptions as $critical) {
                if (str_contains($descriptionLower, $critical)) {
                    return SamsaraEvent::SEVERITY_CRITICAL;
                }
            }
        }

        // Eventos críticos por tipo
        $criticalTypes = [
            'panic_button',
            'panicbutton',
            'collision',
            'crash',
        ];

        $typeLower = strtolower($eventType);
        if (in_array($typeLower, $criticalTypes)) {
            return SamsaraEvent::SEVERITY_CRITICAL;
        }

        // Eventos de advertencia por descripción
        $warningDescriptions = [
            'hard braking',
            'frenado brusco',
            'harsh acceleration',
            'aceleración brusca',
            'sharp turn',
            'giro brusco',
            'distracted driving',
            'conducción distraída',
            'speeding',
            'exceso de velocidad',
        ];

        if ($eventDescription) {
            $descriptionLower = strtolower($eventDescription);
            foreach ($warningDescriptions as $warning) {
                if (str_contains($descriptionLower, $warning)) {
                    return SamsaraEvent::SEVERITY_WARNING;
                }
            }
        }

        // Por defecto: info
        return SamsaraEvent::SEVERITY_INFO;
    }
}
