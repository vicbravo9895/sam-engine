<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessSamsaraEventJob;
use App\Models\SamsaraEvent;
use App\Models\Vehicle;
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
        $traceId = $request->header('X-Trace-ID', 'unknown');
        $payloadSize = strlen(json_encode($payload));

        Log::info('Webhook received from Samsara', [
            'trace_id' => $traceId,
            'payload_size_bytes' => $payloadSize,
            'payload_keys' => array_keys($payload),
            'samsara_event_id' => $payload['eventId'] ?? $payload['id'] ?? null,
        ]);

        try {
            // Extraer información básica del payload
            $eventData = $this->extractEventData($payload);

            // Determinar company_id basado en vehicle_id
            $companyId = $this->determineCompanyId($eventData['vehicle_id'] ?? null);
            
            if (!$companyId) {
                Log::warning('Webhook rejected: Unknown vehicle', [
                    'trace_id' => $traceId,
                    'vehicle_id' => $eventData['vehicle_id'] ?? null,
                    'event_type' => $eventData['event_type'] ?? null,
                    'reason' => 'vehicle_not_registered',
                ]);
                
                return response()->json([
                    'status' => 'error',
                    'message' => 'Could not determine company for this vehicle. Vehicle may not be registered in the system.',
                ], 400);
            }

            $eventData['company_id'] = $companyId;

            // Verificar que la empresa tenga API key configurada
            $company = \App\Models\Company::find($companyId);
            if (!$company || !$company->hasSamsaraApiKey()) {
                Log::warning('Webhook rejected: No API key', [
                    'trace_id' => $traceId,
                    'company_id' => $companyId,
                    'company_name' => $company?->name,
                    'reason' => 'missing_api_key',
                ]);
                
                return response()->json([
                    'status' => 'error',
                    'message' => 'Company does not have Samsara API key configured.',
                ], 400);
            }

            // =====================================================
            // DUPLICATE VALIDATION (Idempotency)
            // =====================================================
            // Check for duplicate events using samsara_event_id
            $samsaraEventId = $eventData['samsara_event_id'] ?? null;
            if ($samsaraEventId) {
                $existingEvent = SamsaraEvent::where('samsara_event_id', $samsaraEventId)
                    ->where('company_id', $companyId)
                    ->first();
                
                if ($existingEvent) {
                    Log::info('Webhook duplicate detected (samsara_event_id)', [
                        'trace_id' => $traceId,
                        'samsara_event_id' => $samsaraEventId,
                        'existing_event_id' => $existingEvent->id,
                        'company_id' => $companyId,
                    ]);
                    
                    // Return 200 OK for idempotency (Samsara may retry webhooks)
                    return response()->json([
                        'status' => 'success',
                        'message' => 'Duplicate event - already processed',
                        'event_id' => $existingEvent->id,
                    ], 200);
                }
            }
            
            // Additional deduplication: check for same vehicle, time, and event type
            // within a small window (30 seconds) to catch duplicates without samsara_event_id
            $occurredAt = $eventData['occurred_at'] ?? null;
            $vehicleId = $eventData['vehicle_id'] ?? null;
            $eventType = $eventData['event_type'] ?? null;
            
            if ($occurredAt && $vehicleId && $eventType) {
                $windowStart = \Carbon\Carbon::parse($occurredAt)->subSeconds(30);
                $windowEnd = \Carbon\Carbon::parse($occurredAt)->addSeconds(30);
                
                $duplicateEvent = SamsaraEvent::where('company_id', $companyId)
                    ->where('vehicle_id', $vehicleId)
                    ->where('event_type', $eventType)
                    ->whereBetween('occurred_at', [$windowStart, $windowEnd])
                    ->first();
                
                if ($duplicateEvent) {
                    Log::info('Webhook duplicate detected (time window)', [
                        'trace_id' => $traceId,
                        'vehicle_id' => $vehicleId,
                        'event_type' => $eventType,
                        'occurred_at' => $occurredAt,
                        'existing_event_id' => $duplicateEvent->id,
                        'company_id' => $companyId,
                    ]);
                    
                    return response()->json([
                        'status' => 'success',
                        'message' => 'Duplicate event - already processed',
                        'event_id' => $duplicateEvent->id,
                    ], 200);
                }
            }

            // Crear el evento en la base de datos
            $event = SamsaraEvent::create($eventData);

            // Encolar el procesamiento con IA (Redis queue)
            ProcessSamsaraEventJob::dispatch($event);

            Log::info('Webhook processed successfully', [
                'trace_id' => $traceId,
                'event_id' => $event->id,
                'samsara_event_id' => $event->samsara_event_id,
                'event_type' => $event->event_type,
                'event_description' => $event->event_description,
                'vehicle_id' => $event->vehicle_id,
                'vehicle_name' => $event->vehicle_name,
                'severity' => $event->severity,
                'company_id' => $companyId,
                'company_name' => $company->name,
                'queue' => 'samsara-events',
            ]);

            // Responder inmediatamente a Samsara
            return response()->json([
                'status' => 'success',
                'message' => 'Event received and queued for processing',
                'event_id' => $event->id,
            ], 202); // 202 Accepted

        } catch (\Exception $e) {
            Log::error('Webhook processing failed', [
                'trace_id' => $traceId,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
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

    /**
     * Determina el company_id basado en el vehicle_id del webhook.
     * 
     * IMPORTANTE: En un sistema multi-tenant, cada vehículo debe estar
     * asociado a una empresa. Si el vehículo no existe, no podemos procesar
     * el webhook porque no sabemos qué API key usar.
     * 
     * @param string|null $vehicleId ID del vehículo en Samsara
     * @return int|null ID de la empresa o null si no se puede determinar
     */
    private function determineCompanyId(?string $vehicleId): ?int
    {
        if (!$vehicleId) {
            Log::warning('Samsara webhook: No vehicle_id in payload');
            return null;
        }

        // Buscar el vehículo por samsara_id
        // IMPORTANTE: En un sistema multi-tenant, el samsara_id debe ser único
        // por company_id. Si dos empresas tienen el mismo samsara_id, habrá conflicto.
        $vehicle = Vehicle::where('samsara_id', $vehicleId)->first();

        if (!$vehicle) {
            Log::warning('Samsara webhook: Vehicle not found in database', [
                'samsara_id' => $vehicleId,
                'note' => 'Vehicle must be synced from Samsara before webhooks can be processed',
            ]);
            return null;
        }

        if (!$vehicle->company_id) {
            Log::warning('Samsara webhook: Vehicle has no company_id', [
                'vehicle_id' => $vehicle->id,
                'samsara_id' => $vehicleId,
            ]);
            return null;
        }

        return $vehicle->company_id;
    }
}
