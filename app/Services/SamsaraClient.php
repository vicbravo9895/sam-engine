<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente para interactuar con la API de Samsara.
 * 
 * Se usa principalmente para enriquecer los payloads de webhooks
 * con información adicional de la API (safety events, vehicle info, etc.).
 * 
 * OBJETIVO: Pre-cargar toda la información posible desde Laravel
 * para reducir el tiempo de ejecución del AI Service.
 */
class SamsaraClient
{
    private string $baseUrl;
    private string $apiToken;

    /**
     * Constructor del cliente Samsara.
     * 
     * @param string|null $apiToken API token de Samsara. Si es null, usa el config global (legacy).
     * @param string|null $baseUrl Base URL de la API. Si es null, usa el config global.
     */
    public function __construct(?string $apiToken = null, ?string $baseUrl = null)
    {
        $this->baseUrl = $baseUrl ?? config('services.samsara.base_url', 'https://api.samsara.com');
        $this->apiToken = $apiToken ?? config('services.samsara.api_token', '');
    }

    /**
     * Pre-carga TODA la información disponible de Samsara en paralelo.
     * 
     * Esto ahorra ~4-5 segundos de ejecución en el AI Service al evitar
     * que los agentes tengan que llamar a las tools individualmente.
     * 
     * @param string $vehicleId ID del vehículo
     * @param string $eventTime Timestamp ISO 8601 del evento
     * @param bool $isSafetyEvent Si es un safety event (buscar detalle específico)
     * @return array Datos pre-cargados
     */
    public function preloadAllData(
        string $vehicleId,
        string $eventTime,
        bool $isSafetyEvent = false
    ): array {
        if (empty($this->apiToken)) {
            Log::warning('SamsaraClient: API token not configured, skipping preload');
            return [];
        }

        $eventDt = Carbon::parse($eventTime);
        
        Log::info('SamsaraClient: Starting parallel preload', [
            'vehicle_id' => $vehicleId,
            'event_time' => $eventTime,
            'is_safety_event' => $isSafetyEvent,
        ]);

        $startTime = microtime(true);

        // Ejecutar todas las llamadas en PARALELO
        $responses = Http::pool(fn (Pool $pool) => [
            // 1. Vehicle Info (información estática)
            $pool->as('vehicle_info')
                ->withHeaders(['Authorization' => "Bearer {$this->apiToken}"])
                ->timeout(15)
                ->get("{$this->baseUrl}/fleet/vehicles/{$vehicleId}"),
            
            // 2. Driver Assignment (conductor asignado)
            $pool->as('driver_assignment')
                ->withHeaders(['Authorization' => "Bearer {$this->apiToken}"])
                ->timeout(15)
                ->get("{$this->baseUrl}/fleet/driver-vehicle-assignments", [
                    'filterBy' => 'vehicles',
                    'vehicleIds' => $vehicleId,
                ]),
            
            // 3. Vehicle Stats (GPS, velocidad, etc.) - 5 min antes, 2 min después
            $pool->as('vehicle_stats')
                ->withHeaders(['Authorization' => "Bearer {$this->apiToken}"])
                ->timeout(15)
                ->get("{$this->baseUrl}/fleet/vehicles/stats/history", [
                    'vehicleIds' => $vehicleId,
                    'startTime' => $eventDt->copy()->subMinutes(5)->toIso8601String(),
                    'endTime' => $eventDt->copy()->addMinutes(2)->toIso8601String(),
                    'types' => 'gps,engineStates',
                ]),
            
            // 4. Safety Events - ventana depende del tipo de evento
            // Safety event: ±2 minutos (buscar el evento específico - ampliado para capturar diferencias de tiempo)
            // Panic/otro: -30min/+10min (buscar eventos correlacionados)
            $pool->as('safety_events')
                ->withHeaders(['Authorization' => "Bearer {$this->apiToken}"])
                ->timeout(15)
                ->get("{$this->baseUrl}/fleet/safety-events", $isSafetyEvent 
                    ? [
                        'vehicleIds' => $vehicleId,
                        'startTime' => $eventDt->copy()->subMinutes(2)->toIso8601String(),
                        'endTime' => $eventDt->copy()->addMinutes(2)->toIso8601String(),
                    ]
                    : [
                        'vehicleIds' => $vehicleId,
                        'startTime' => $eventDt->copy()->subMinutes(30)->toIso8601String(),
                        'endTime' => $eventDt->copy()->addMinutes(10)->toIso8601String(),
                    ]
                ),
            
            // 6. Camera Media (URLs) - 2 min antes, 2 min después
            $pool->as('camera_media')
                ->withHeaders(['Authorization' => "Bearer {$this->apiToken}"])
                ->timeout(15)
                ->get("{$this->baseUrl}/cameras/media", [
                    'vehicleIds' => $vehicleId,
                    'startTime' => $eventDt->copy()->subMinutes(2)->toIso8601String(),
                    'endTime' => $eventDt->copy()->addMinutes(2)->toIso8601String(),
                ]),
        ]);

        $duration = round((microtime(true) - $startTime) * 1000);
        
        Log::info('SamsaraClient: Parallel preload completed', [
            'vehicle_id' => $vehicleId,
            'duration_ms' => $duration,
        ]);

        // Procesar respuestas
        $preloadedData = [];

        // Vehicle Info
        if ($responses['vehicle_info']->successful()) {
            $preloadedData['vehicle_info'] = $responses['vehicle_info']->json('data') 
                ?? $responses['vehicle_info']->json();
        }

        // Driver Assignment
        if ($responses['driver_assignment']->successful()) {
            $data = $responses['driver_assignment']->json('data') ?? [];
            $preloadedData['driver_assignment'] = $this->formatDriverAssignment($data);
        }

        // Vehicle Stats
        if ($responses['vehicle_stats']->successful()) {
            $preloadedData['vehicle_stats'] = $responses['vehicle_stats']->json('data') 
                ?? $responses['vehicle_stats']->json();
        }

        // Safety Events - procesamiento según tipo de evento
        if ($responses['safety_events']->successful()) {
            $events = $responses['safety_events']->json('data') ?? [];
            
            if ($isSafetyEvent) {
                // Safety Event: buscar el evento específico en la ventana de ±2 minutos
                if (!empty($events)) {
                    $closest = $this->findClosestEvent($events, $eventDt);
                    if ($closest) {
                        $preloadedData['safety_event_detail'] = $this->formatSafetyEvent($closest);
                    }
                }
                // No incluimos correlación porque la ventana es corta
            } else {
                // Panic/Otro: todos los eventos son correlación
                $preloadedData['safety_events_correlation'] = [
                    'total_events' => count($events),
                    'events' => array_map(fn($e) => $this->formatSafetyEventSummary($e), $events),
                ];
            }
        } else {
            Log::warning('SamsaraClient::preloadAllData - Safety events API call failed', [
                'vehicle_id' => $vehicleId,
                'status' => $responses['safety_events']->status(),
                'body' => substr($responses['safety_events']->body(), 0, 500),
            ]);
        }

        // Camera Media (URLs sin análisis)
        if ($responses['camera_media']->successful()) {
            $media = $responses['camera_media']->json('data.media') 
                ?? $responses['camera_media']->json('data') 
                ?? [];
            $preloadedData['camera_media'] = [
                'total_items' => count($media),
                'items' => array_map(fn($m) => $this->formatMediaItem($m), $media),
                'note' => 'URLs pre-cargadas. El análisis con Vision se hace en el AI Service.',
            ];
        }

        $preloadedData['_metadata'] = [
            'preloaded_at' => now()->toIso8601String(),
            'duration_ms' => $duration,
            'vehicle_id' => $vehicleId,
            'event_time' => $eventTime,
        ];

        return $preloadedData;
    }

    /**
     * Formatea la asignación de conductor.
     */
    private function formatDriverAssignment(array $data): array
    {
        if (empty($data)) {
            return [
                'driver' => null,
                'note' => 'No driver assignment found',
            ];
        }

        $assignment = $data[0] ?? null;
        if (!$assignment) {
            return ['driver' => null];
        }

        return [
            'driver' => [
                'id' => $assignment['driver']['id'] ?? null,
                'name' => $assignment['driver']['name'] ?? null,
            ],
            'vehicle' => [
                'id' => $assignment['vehicle']['id'] ?? null,
                'name' => $assignment['vehicle']['name'] ?? null,
            ],
            'start_time' => $assignment['startTime'] ?? null,
        ];
    }

    /**
     * Formatea un resumen de safety event para correlación.
     */
    private function formatSafetyEventSummary(array $event): array
    {
        return [
            'id' => $event['id'] ?? null,
            'behavior_label' => $event['behaviorLabel'] ?? $event['label'] ?? null,
            'severity' => $event['severity'] ?? null,
            'time' => $event['time'] ?? null,
        ];
    }

    /**
     * Formatea un item de media.
     */
    private function formatMediaItem(array $media): array
    {
        return [
            'id' => $media['id'] ?? null,
            'media_type' => $media['mediaType'] ?? null,
            'captured_at' => $media['capturedAtTime'] ?? null,
            'url' => $media['urlInfo']['url'] ?? $media['url'] ?? null,
            'download_url' => $media['urlInfo']['downloadUrl'] ?? $media['downloadUrl'] ?? null,
            'camera_type' => $media['cameraType'] ?? null,
        ];
    }

    /**
     * Encuentra el evento más cercano a un timestamp.
     */
    private function findClosestEvent(array $events, Carbon $targetTime): ?array
    {
        if (empty($events)) {
            return null;
        }

        if (count($events) === 1) {
            return $events[0];
        }

        $closestEvent = null;
        $closestDiff = PHP_INT_MAX;

        foreach ($events as $event) {
            $eventTime = Carbon::parse($event['time'] ?? $event['happenedAtTime'] ?? '');
            $diff = abs($targetTime->timestamp - $eventTime->timestamp);
            if ($diff < $closestDiff) {
                $closestDiff = $diff;
                $closestEvent = $event;
            }
        }

        return $closestEvent;
    }

    /**
     * Obtiene los safety events de un vehículo en una ventana de tiempo.
     * 
     * @param string $vehicleId ID del vehículo en Samsara
     * @param string $eventTime Timestamp ISO 8601 del evento
     * @param int $secondsBefore Segundos antes del evento
     * @param int $secondsAfter Segundos después del evento
     * @return array|null El safety event encontrado o null
     */
    public function getSafetyEventDetail(
        string $vehicleId,
        string $eventTime,
        int $secondsBefore = 5,
        int $secondsAfter = 5
    ): ?array {
        if (empty($this->apiToken)) {
            Log::warning('SamsaraClient: API token not configured');
            return null;
        }

        try {
            $eventDt = Carbon::parse($eventTime);
            $startTime = $eventDt->copy()->subSeconds($secondsBefore)->toIso8601String();
            $endTime = $eventDt->copy()->addSeconds($secondsAfter)->toIso8601String();

            Log::debug('SamsaraClient: Fetching safety events', [
                'vehicle_id' => $vehicleId,
                'event_time' => $eventTime,
                'start_time' => $startTime,
                'end_time' => $endTime,
            ]);

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiToken}",
            ])->timeout(30)->get("{$this->baseUrl}/fleet/safety-events", [
                'vehicleIds' => $vehicleId,
                'startTime' => $startTime,
                'endTime' => $endTime,
            ]);

            if ($response->failed()) {
                Log::warning('SamsaraClient: Failed to fetch safety events', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            $events = $data['data'] ?? [];

            Log::debug('SamsaraClient: Safety events fetched', [
                'count' => count($events),
            ]);

            // Buscar el evento más cercano al timestamp del evento
            if (empty($events)) {
                return null;
            }

            // Si solo hay uno, retornarlo
            if (count($events) === 1) {
                return $this->formatSafetyEvent($events[0]);
            }

            // Si hay múltiples, buscar el más cercano al eventTime
            $targetTime = $eventDt->timestamp;
            $closestEvent = null;
            $closestDiff = PHP_INT_MAX;

            foreach ($events as $event) {
                $eventTimestamp = Carbon::parse($event['time'] ?? $event['happenedAtTime'] ?? '')->timestamp;
                $diff = abs($targetTime - $eventTimestamp);
                if ($diff < $closestDiff) {
                    $closestDiff = $diff;
                    $closestEvent = $event;
                }
            }

            return $closestEvent ? $this->formatSafetyEvent($closestEvent) : null;

        } catch (\Exception $e) {
            Log::error('SamsaraClient: Error fetching safety events', [
                'error' => $e->getMessage(),
                'vehicle_id' => $vehicleId,
            ]);
            return null;
        }
    }

    /**
     * Formatea un safety event para enriquecer el payload.
     */
    private function formatSafetyEvent(array $event): array
    {
        // Extraer el nombre legible del behavior label
        // behaviorLabels es un array de objetos con {label, source, name}
        // 'name' es el nombre legible (ej: "Passenger Detection", "Hard Braking")
        $behaviorLabels = $event['behaviorLabels'] ?? [];
        $primaryBehaviorName = null;
        $primaryBehaviorLabel = $event['behaviorLabel'] ?? $event['label'] ?? null;
        
        if (!empty($behaviorLabels) && isset($behaviorLabels[0]['name'])) {
            $primaryBehaviorName = $behaviorLabels[0]['name'];
        }
        
        return [
            'safety_event_id' => $event['id'] ?? null,
            'behavior_label' => $primaryBehaviorLabel,
            'behavior_name' => $primaryBehaviorName, // Nombre legible del evento
            'behavior_labels' => $behaviorLabels,
            'severity' => $event['severity'] ?? null,
            'max_acceleration_g' => $event['maxAccelerationGForce'] ?? $event['maxAccelerationG'] ?? null,
            'duration_ms' => $event['durationMs'] ?? null,
            'coaching_state' => $event['coachingState'] ?? null,
            'location' => [
                'latitude' => $event['location']['latitude'] ?? null,
                'longitude' => $event['location']['longitude'] ?? null,
                'address' => $event['location']['address'] ?? null,
            ],
            'driver' => [
                'id' => $event['driver']['id'] ?? null,
                'name' => $event['driver']['name'] ?? null,
            ],
            'vehicle' => [
                'id' => $event['vehicle']['id'] ?? null,
                'name' => $event['vehicle']['name'] ?? null,
            ],
            'download_forward_video_url' => $event['downloadForwardVideoUrl'] ?? null,
            'download_inward_video_url' => $event['downloadInwardVideoUrl'] ?? null,
            'download_tracked_inward_video_url' => $event['downloadTrackedInwardVideoUrl'] ?? null,
        ];
    }

    /**
     * Verifica si un payload es un safety event que necesita enriquecimiento.
     */
    public static function isSafetyEvent(array $payload): bool
    {
        $eventType = strtolower($payload['eventType'] ?? '');
        
        // AlertIncident puede contener diferentes tipos de alertas
        if ($eventType === 'alertincident') {
            // Verificar si es un safety event buscando en conditions
            $conditions = $payload['data']['conditions'] ?? [];
            foreach ($conditions as $condition) {
                $description = strtolower($condition['description'] ?? '');
                $triggerId = $condition['triggerId'] ?? 0;
                
                // Panic Button no es un safety event típico, pero otros sí
                if ($description !== 'panic button' && $triggerId > 0) {
                    // Verificar si hay detalles de safety event
                    $details = $condition['details'] ?? [];
                    foreach ($details as $key => $detail) {
                        // Si tiene behaviorLabel o es un evento de comportamiento
                        if (isset($detail['behaviorLabel']) || 
                            in_array($key, ['harshEvent', 'safetyEvent', 'driverBehavior'])) {
                            return true;
                        }
                    }
                }
            }
        }
        
        // Verificar tipos directos de safety events
        $safetyEventTypes = [
            'safetyevent',
            'harshevent', 
            'harshbraking',
            'harshacceleration',
            'sharpturn',
            'speeding',
            'collision',
            'nearcollision',
            'followingdistance',
            'distracteddriving',
            'drowsiness',
            'cellphoneuse',
        ];
        
        return in_array(str_replace(['_', '-', ' '], '', $eventType), $safetyEventTypes);
    }

    /**
     * Extrae el vehicleId y happenedAtTime del payload para buscar el safety event.
     */
    public static function extractEventContext(array $payload): ?array
    {
        $vehicleId = null;
        $happenedAtTime = null;

        // Buscar vehicleId en diferentes estructuras
        if (isset($payload['vehicle']['id'])) {
            $vehicleId = $payload['vehicle']['id'];
        } elseif (isset($payload['vehicleId'])) {
            $vehicleId = $payload['vehicleId'];
        } elseif (isset($payload['data']['conditions'])) {
            // Buscar en conditions -> details -> [tipo] -> vehicle
            foreach ($payload['data']['conditions'] as $condition) {
                $details = $condition['details'] ?? [];
                
                // Panic Button: details.panicButton.vehicle.id
                if (isset($details['panicButton']['vehicle']['id'])) {
                    $vehicleId = $details['panicButton']['vehicle']['id'];
                    break;
                }
                
                // Safety Events: details.[tipo].vehicle.id o details.vehicle.id
                foreach ($details as $key => $detail) {
                    if (is_array($detail)) {
                        if (isset($detail['vehicle']['id'])) {
                            $vehicleId = $detail['vehicle']['id'];
                            break 2;
                        }
                    }
                }
            }
        }

        // Buscar happenedAtTime
        $happenedAtTime = $payload['data']['happenedAtTime'] 
            ?? $payload['happenedAtTime'] 
            ?? $payload['eventTime'] 
            ?? $payload['time'] 
            ?? null;

        if ($vehicleId && $happenedAtTime) {
            return [
                'vehicle_id' => $vehicleId,
                'happened_at_time' => $happenedAtTime,
            ];
        }

        return null;
    }
}
