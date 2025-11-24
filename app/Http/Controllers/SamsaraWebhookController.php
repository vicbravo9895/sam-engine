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

        return [
            'event_type' => $eventType,
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
     * Determina la severidad del evento
     */
    private function determineSeverity(array $payload): string
    {
        $severity = $payload['severity'] ?? $payload['level'] ?? 'info';

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
}
