<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessAlertJob;
use App\Models\Alert;
use App\Models\AlertSource;
use App\Models\PendingWebhook;
use App\Models\Signal;
use App\Models\Vehicle;
use App\Services\DomainEventEmitter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SamsaraWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->all();
        $traceId = $request->header('X-Trace-ID', 'unknown');

        Log::info('Webhook received from Samsara', [
            'trace_id' => $traceId,
            'payload_size_bytes' => strlen(json_encode($payload)),
            'samsara_event_id' => $payload['eventId'] ?? $payload['id'] ?? null,
        ]);

        try {
            $eventData = $this->extractEventData($payload);

            $companyId = $this->determineCompanyId($eventData['vehicle_id'] ?? null);

            if (!$companyId) {
                $vehicleId = $eventData['vehicle_id'] ?? null;

                if ($vehicleId) {
                    PendingWebhook::create([
                        'vehicle_samsara_id' => $vehicleId,
                        'event_type' => $eventData['event_type'] ?? null,
                        'raw_payload' => $payload,
                    ]);
                }

                return response()->json([
                    'status' => 'accepted',
                    'message' => 'Vehicle not yet registered. Webhook queued.',
                ], 202);
            }

            $company = \App\Models\Company::find($companyId);
            if (!$company || !$company->hasSamsaraApiKey()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Company does not have Samsara API key configured.',
                ], 400);
            }

            // =========================================================
            // Deduplication against signals table
            // =========================================================
            $samsaraEventId = $eventData['samsara_event_id'] ?? null;

            if ($samsaraEventId) {
                $existingSignal = Signal::where('samsara_event_id', $samsaraEventId)
                    ->where('company_id', $companyId)
                    ->first();

                if ($existingSignal) {
                    Log::info('Webhook duplicate detected (samsara_event_id)', [
                        'trace_id' => $traceId,
                        'samsara_event_id' => $samsaraEventId,
                        'company_id' => $companyId,
                    ]);

                    return response()->json([
                        'status' => 'success',
                        'message' => 'Duplicate event - already processed',
                    ], 200);
                }
            }

            $occurredAt = $eventData['occurred_at'] ?? null;
            $vehicleId = $eventData['vehicle_id'] ?? null;
            $eventType = $eventData['event_type'] ?? null;

            if ($occurredAt && $vehicleId && $eventType) {
                $windowStart = \Carbon\Carbon::parse($occurredAt)->subSeconds(30);
                $windowEnd = \Carbon\Carbon::parse($occurredAt)->addSeconds(30);

                $duplicateSignal = Signal::where('company_id', $companyId)
                    ->where('vehicle_id', $vehicleId)
                    ->where('event_type', $eventType)
                    ->whereBetween('occurred_at', [$windowStart, $windowEnd])
                    ->first();

                if ($duplicateSignal) {
                    Log::info('Webhook duplicate detected (time window)', [
                        'trace_id' => $traceId,
                        'vehicle_id' => $vehicleId,
                        'event_type' => $eventType,
                    ]);

                    return response()->json([
                        'status' => 'success',
                        'message' => 'Duplicate event - already processed',
                    ], 200);
                }
            }

            // =========================================================
            // Create Signal + Alert + AlertSource in a transaction
            // =========================================================
            [$signal, $alert] = DB::transaction(function () use ($companyId, $eventData, $payload) {
                $signal = Signal::create([
                    'company_id' => $companyId,
                    'source' => 'webhook',
                    'samsara_event_id' => $eventData['samsara_event_id'],
                    'event_type' => $eventData['event_type'],
                    'event_description' => $eventData['event_description'],
                    'vehicle_id' => $eventData['vehicle_id'],
                    'vehicle_name' => $eventData['vehicle_name'],
                    'driver_id' => $eventData['driver_id'],
                    'driver_name' => $eventData['driver_name'],
                    'severity' => $eventData['severity'],
                    'occurred_at' => $eventData['occurred_at'],
                    'raw_payload' => $payload,
                ]);

                $alert = Alert::create([
                    'company_id' => $companyId,
                    'signal_id' => $signal->id,
                    'ai_status' => Alert::STATUS_PENDING,
                    'severity' => $eventData['severity'],
                    'event_description' => $eventData['event_description'],
                    'occurred_at' => $eventData['occurred_at'],
                ]);

                AlertSource::create([
                    'alert_id' => $alert->id,
                    'signal_id' => $signal->id,
                    'role' => 'primary',
                ]);

                return [$signal, $alert];
            });

            ProcessAlertJob::dispatch($alert);

            DomainEventEmitter::emit(
                companyId: $companyId,
                entityType: 'signal',
                entityId: (string) $signal->id,
                eventType: 'signal.ingested',
                payload: [
                    'event_type' => $signal->event_type,
                    'vehicle_id' => $signal->vehicle_id,
                    'vehicle_name' => $signal->vehicle_name,
                    'severity' => $signal->severity,
                    'samsara_event_id' => $signal->samsara_event_id,
                    'alert_id' => $alert->id,
                ],
            );

            Log::info('Webhook processed successfully', [
                'trace_id' => $traceId,
                'signal_id' => $signal->id,
                'alert_id' => $alert->id,
                'event_type' => $signal->event_type,
                'vehicle_id' => $signal->vehicle_id,
                'vehicle_name' => $signal->vehicle_name,
                'severity' => $signal->severity,
                'company_id' => $companyId,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Event received and queued for processing',
                'alert_id' => $alert->id,
            ], 202);

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

    private function extractEventData(array $payload): array
    {
        $eventType = $this->determineEventType($payload);
        $vehicleInfo = $this->extractVehicleInfo($payload);
        $eventDescription = $this->extractEventDescription($payload);

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
        ];
    }

    private function extractVehicleInfo(array $payload): array
    {
        if (isset($payload['vehicle'])) {
            return $payload['vehicle'];
        }

        if (isset($payload['vehicleId'])) {
            return [
                'id' => $payload['vehicleId'],
                'name' => $payload['vehicleName'] ?? null,
            ];
        }

        if (isset($payload['data']['conditions']) && is_array($payload['data']['conditions'])) {
            foreach ($payload['data']['conditions'] as $condition) {
                foreach ($condition['details'] ?? [] as $detail) {
                    if (isset($detail['vehicle'])) {
                        return $detail['vehicle'];
                    }
                }
            }
        }

        return [];
    }

    private function determineEventType(array $payload): string
    {
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

    private function extractEventDescription(array $payload): ?string
    {
        if (isset($payload['data']['conditions']) && is_array($payload['data']['conditions'])) {
            foreach ($payload['data']['conditions'] as $condition) {
                if (isset($condition['description'])) {
                    return $condition['description'];
                }
            }
        }
        return null;
    }

    private function translateEventDescription(string $description): string
    {
        $translations = [
            'Panic Button' => 'Botón de pánico',
            'panic button' => 'Botón de pánico',
            'PANIC BUTTON' => 'Botón de pánico',
            'A safety event occurred' => 'Evento de seguridad',
            'a safety event occurred' => 'Evento de seguridad',
            'Hard Braking' => 'Frenado brusco',
            'hard braking' => 'Frenado brusco',
            'Harsh Braking' => 'Frenado brusco',
            'Harsh Acceleration' => 'Aceleración brusca',
            'harsh acceleration' => 'Aceleración brusca',
            'Hard Acceleration' => 'Aceleración brusca',
            'Sharp Turn' => 'Giro brusco',
            'sharp turn' => 'Giro brusco',
            'Harsh Turn' => 'Giro brusco',
            'Distracted Driving' => 'Conducción distraída',
            'distracted driving' => 'Conducción distraída',
            'Drowsiness' => 'Somnolencia',
            'Cell Phone Use' => 'Uso de celular',
            'Cell Phone' => 'Uso de celular',
            'No Seatbelt' => 'Sin cinturón de seguridad',
            'Passenger Detection' => 'Detección de pasajero',
            'Driver Detection' => 'Detección de conductor',
            'No Driver Detected' => 'Conductor no detectado',
            'Obstructed Camera' => 'Cámara obstruida',
            'Following Distance' => 'Distancia de seguimiento',
            'following distance' => 'Distancia de seguimiento',
            'Speeding' => 'Exceso de velocidad',
            'speeding' => 'Exceso de velocidad',
            'Stop Sign Violation' => 'Violación de señal de alto',
            'stop sign violation' => 'Violación de señal de alto',
            'Lane Departure' => 'Salida de carril',
            'Forward Collision Warning' => 'Advertencia de colisión frontal',
            'Rolling Stop' => 'Alto sin detenerse',
            'Collision' => 'Colisión',
            'Near Collision' => 'Casi colisión',
        ];

        return $translations[$description] ?? $description;
    }

    private function determineSeverity(array $payload): string
    {
        $severity = $payload['severity'] ?? $payload['level'] ?? null;

        if ($severity) {
            $severity = strtolower($severity);
            if (in_array($severity, ['critical', 'high', 'panic'])) {
                return Alert::SEVERITY_CRITICAL;
            }
            if (in_array($severity, ['warning', 'medium'])) {
                return Alert::SEVERITY_WARNING;
            }
            return Alert::SEVERITY_INFO;
        }

        $eventDescription = $this->extractEventDescription($payload);
        $eventType = $this->determineEventType($payload);

        $criticalDescriptions = ['panic button', 'botón de pánico', 'collision', 'colisión', 'crash', 'accidente'];
        if ($eventDescription) {
            $descriptionLower = strtolower($eventDescription);
            foreach ($criticalDescriptions as $critical) {
                if (str_contains($descriptionLower, $critical)) {
                    return Alert::SEVERITY_CRITICAL;
                }
            }
        }

        $criticalTypes = ['panic_button', 'panicbutton', 'collision', 'crash'];
        $typeLower = strtolower($eventType);
        if (in_array($typeLower, $criticalTypes)) {
            return Alert::SEVERITY_CRITICAL;
        }

        $warningDescriptions = [
            'hard braking', 'frenado brusco', 'harsh acceleration', 'aceleración brusca',
            'sharp turn', 'giro brusco', 'distracted driving', 'conducción distraída',
            'speeding', 'exceso de velocidad',
        ];
        if ($eventDescription) {
            $descriptionLower = strtolower($eventDescription);
            foreach ($warningDescriptions as $warning) {
                if (str_contains($descriptionLower, $warning)) {
                    return Alert::SEVERITY_WARNING;
                }
            }
        }

        return Alert::SEVERITY_INFO;
    }

    private function determineCompanyId(?string $vehicleId): ?int
    {
        if (!$vehicleId) {
            return null;
        }

        $vehicle = Vehicle::where('samsara_id', $vehicleId)->first();
        if (!$vehicle || !$vehicle->company_id) {
            return null;
        }

        return $vehicle->company_id;
    }
}
