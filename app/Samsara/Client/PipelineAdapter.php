<?php

declare(strict_types=1);

namespace App\Samsara\Client;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Adapter for the alert-processing pipeline.
 *
 * Consumed by ProcessAlertJob and RevalidateAlertJob.
 * Provides parallel preloading with caching for vehicle/driver data.
 */
class PipelineAdapter extends TelematicsClientCore
{
    const VEHICLE_INFO_CACHE_TTL = 3600;
    const DRIVER_ASSIGNMENT_CACHE_TTL = 300;

    // =========================================================================
    // Pre-load / Reload
    // =========================================================================

    public function preloadAllData(
        string $vehicleId,
        string $eventTime,
        bool $isSafetyEvent = false,
        array $timeWindows = [],
        array $dbSafetyEvents = []
    ): array {
        if (!$this->hasToken()) {
            Log::warning('PipelineAdapter: API token not configured, skipping preload');
            return [];
        }

        $eventDt = Carbon::parse($eventTime);
        $hasSafetyEventsFromDb = !empty($dbSafetyEvents);

        $vehicleStatsBefore = $timeWindows['vehicle_stats_before_minutes'] ?? 5;
        $vehicleStatsAfter = $timeWindows['vehicle_stats_after_minutes'] ?? 2;
        $safetyEventsBefore = $timeWindows['safety_events_before_minutes'] ?? 30;
        $safetyEventsAfter = $timeWindows['safety_events_after_minutes'] ?? 10;
        $cameraMediaWindow = $timeWindows['camera_media_window_minutes'] ?? 2;

        Log::info('PipelineAdapter: Starting parallel preload', [
            'vehicle_id' => $vehicleId,
            'event_time' => $eventTime,
            'is_safety_event' => $isSafetyEvent,
            'safety_events_from_db' => $hasSafetyEventsFromDb,
            'db_events_count' => count($dbSafetyEvents),
        ]);

        $startTime = microtime(true);

        $poolRequests = [
            'vehicle_info' => fn ($r) => $r->timeout(15)
                ->get("/fleet/vehicles/{$vehicleId}"),

            'driver_assignment' => fn ($r) => $r->timeout(15)
                ->get('/fleet/driver-vehicle-assignments', [
                    'filterBy' => 'vehicles',
                    'vehicleIds' => $vehicleId,
                ]),

            'vehicle_stats' => fn ($r) => $r->timeout(15)
                ->get('/fleet/vehicles/stats/history', [
                    'vehicleIds' => $vehicleId,
                    'startTime' => $eventDt->copy()->subMinutes($vehicleStatsBefore)->toIso8601String(),
                    'endTime' => $eventDt->copy()->addMinutes($vehicleStatsAfter)->toIso8601String(),
                    'types' => 'gps,engineStates',
                ]),

            'camera_media' => function ($r) use ($vehicleId, $eventDt, $cameraMediaWindow) {
                $startTime = $eventDt->copy()->subMinutes($cameraMediaWindow)->toIso8601String();
                $endTime = $eventDt->copy()->addMinutes($cameraMediaWindow)->min(Carbon::now()->subSeconds(60))->toIso8601String();
                Log::info('PipelineAdapter: Requesting camera media (window around event time)', [
                    'vehicle_ids' => $vehicleId,
                    'event_time_utc' => $eventDt->toIso8601String(),
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                ]);
                return $r->timeout(15)->get('/cameras/media', [
                    'vehicleIds' => $vehicleId,
                    'startTime' => $startTime,
                    'endTime' => $endTime,
                ]);
            },
        ];

        if (!$hasSafetyEventsFromDb) {
            $poolRequests['safety_events'] = fn ($r) => $r->timeout(15)
                ->get('/fleet/safety-events', $isSafetyEvent
                    ? [
                        'vehicleIds' => $vehicleId,
                        'startTime' => $eventDt->copy()->subMinutes(2)->toIso8601String(),
                        'endTime' => $eventDt->copy()->addMinutes(2)->toIso8601String(),
                    ]
                    : [
                        'vehicleIds' => $vehicleId,
                        'startTime' => $eventDt->copy()->subMinutes($safetyEventsBefore)->toIso8601String(),
                        'endTime' => $eventDt->copy()->addMinutes($safetyEventsAfter)->toIso8601String(),
                    ]
                );
        }

        $responses = $this->pool($poolRequests);

        $duration = round((microtime(true) - $startTime) * 1000);

        Log::info('PipelineAdapter: Parallel preload completed', [
            'vehicle_id' => $vehicleId,
            'duration_ms' => $duration,
        ]);

        $preloadedData = [];

        // Vehicle Info (with caching)
        $preloadedData = array_merge($preloadedData, $this->processVehicleInfoResponse($responses['vehicle_info'], $vehicleId));

        // Driver Assignment (with caching)
        $preloadedData = array_merge($preloadedData, $this->processDriverAssignmentResponse($responses['driver_assignment'], $vehicleId));

        // Vehicle Stats
        if ($responses['vehicle_stats']->successful()) {
            $preloadedData['vehicle_stats'] = $responses['vehicle_stats']->json('data')
                ?? $responses['vehicle_stats']->json();
        }

        // Safety Events
        $preloadedData = array_merge(
            $preloadedData,
            $this->processSafetyEventsResponse(
                $responses['safety_events'] ?? null,
                $hasSafetyEventsFromDb,
                $dbSafetyEvents,
                $isSafetyEvent,
                $eventDt,
                $vehicleId
            )
        );

        // Camera Media
        if ($responses['camera_media']->successful()) {
            $preloadedData['camera_media'] = $this->processCameraMedia(
                $responses['camera_media']->json('data.media')
                    ?? $responses['camera_media']->json('data')
                    ?? []
            );
        }

        $preloadedData['_metadata'] = [
            'preloaded_at' => now()->toIso8601String(),
            'duration_ms' => $duration,
            'vehicle_id' => $vehicleId,
            'event_time' => $eventTime,
            'safety_events_source' => $hasSafetyEventsFromDb ? 'database' : 'api',
            'db_events_count' => $hasSafetyEventsFromDb ? count($dbSafetyEvents) : 0,
        ];

        return $preloadedData;
    }

    public function reloadDataForRevalidation(
        string $vehicleId,
        string $originalEventTime,
        string $lastInvestigationTime,
        bool $isSafetyEvent = false,
        array $timeWindows = []
    ): array {
        if (!$this->hasToken()) {
            Log::warning('PipelineAdapter: API token not configured, skipping reload');
            return [];
        }

        $lastCheckDt = Carbon::parse($lastInvestigationTime);
        $nowDt = Carbon::now()->subSeconds(60);

        Log::info('PipelineAdapter: Starting revalidation data reload', [
            'vehicle_id' => $vehicleId,
            'original_event_time' => $originalEventTime,
            'last_investigation_time' => $lastInvestigationTime,
            'now_adjusted' => $nowDt->toIso8601String(),
        ]);

        $startTime = microtime(true);

        $responses = $this->pool([
            'vehicle_info' => fn ($r) => $r->timeout(15)
                ->get("/fleet/vehicles/{$vehicleId}"),

            'driver_assignment' => fn ($r) => $r->timeout(15)
                ->get('/fleet/driver-vehicle-assignments', [
                    'filterBy' => 'vehicles',
                    'vehicleIds' => $vehicleId,
                ]),

            'vehicle_stats' => fn ($r) => $r->timeout(15)
                ->get('/fleet/vehicles/stats/history', [
                    'vehicleIds' => $vehicleId,
                    'startTime' => $lastCheckDt->toIso8601String(),
                    'endTime' => $nowDt->toIso8601String(),
                    'types' => 'gps,engineStates',
                ]),

            'safety_events_new' => fn ($r) => $r->timeout(15)
                ->get('/fleet/safety-events', [
                    'vehicleIds' => $vehicleId,
                    'startTime' => $lastCheckDt->toIso8601String(),
                    'endTime' => $nowDt->toIso8601String(),
                ]),

            'camera_media_new' => fn ($r) => $r->timeout(15)
                ->get('/cameras/media', [
                    'vehicleIds' => $vehicleId,
                    'startTime' => $lastCheckDt->toIso8601String(),
                    'endTime' => $nowDt->toIso8601String(),
                ]),
        ]);

        $duration = round((microtime(true) - $startTime) * 1000);

        Log::info('PipelineAdapter: Revalidation data reload completed', [
            'vehicle_id' => $vehicleId,
            'duration_ms' => $duration,
        ]);

        $reloadedData = [];

        if ($responses['vehicle_info']->successful()) {
            $reloadedData['vehicle_info'] = $responses['vehicle_info']->json('data')
                ?? $responses['vehicle_info']->json();
        }

        if ($responses['driver_assignment']->successful()) {
            $data = $responses['driver_assignment']->json('data') ?? [];
            $reloadedData['driver_assignment'] = $this->formatDriverAssignment($data);
        }

        if ($responses['vehicle_stats']->successful()) {
            $reloadedData['vehicle_stats_since_last_check'] = $responses['vehicle_stats']->json('data')
                ?? $responses['vehicle_stats']->json();
        }

        if ($responses['safety_events_new']->successful()) {
            $events = $responses['safety_events_new']->json('data') ?? [];
            $reloadedData['safety_events_since_last_check'] = [
                'total_events' => count($events),
                'events' => array_map(fn ($e) => $this->formatSafetyEventSummary($e), $events),
                'time_window' => [
                    'start' => $lastCheckDt->toIso8601String(),
                    'end' => $nowDt->toIso8601String(),
                ],
            ];
        }

        if ($responses['camera_media_new']->successful()) {
            $media = $responses['camera_media_new']->json('data.media')
                ?? $responses['camera_media_new']->json('data')
                ?? [];

            $limitedMedia = $this->limitCameraMedia($media, maxDriver: 3, maxRoad: 2);

            $reloadedData['camera_media_since_last_check'] = [
                'total_items_found' => count($media),
                'total_items_selected' => count($limitedMedia),
                'items' => array_map(fn ($m) => $this->formatMediaItem($m), $limitedMedia),
                'time_window' => [
                    'start' => $lastCheckDt->toIso8601String(),
                    'end' => $nowDt->toIso8601String(),
                ],
                'note' => count($media) > 5
                    ? 'Se seleccionaron las ' . count($limitedMedia) . ' imágenes más recientes (3 interior + 2 exterior) de ' . count($media) . ' disponibles'
                    : null,
            ];
        }

        $reloadedData['_metadata'] = [
            'reloaded_at' => $nowDt->toIso8601String(),
            'duration_ms' => $duration,
            'vehicle_id' => $vehicleId,
            'original_event_time' => $originalEventTime,
            'last_investigation_time' => $lastInvestigationTime,
            'query_window' => [
                'start' => $lastCheckDt->toIso8601String(),
                'end' => $nowDt->toIso8601String(),
                'minutes_covered' => $lastCheckDt->diffInMinutes($nowDt),
            ],
        ];

        return $reloadedData;
    }

    // =========================================================================
    // Individual lookups
    // =========================================================================

    public function getSafetyEventDetail(
        string $vehicleId,
        string $eventTime,
        int $secondsBefore = 5,
        int $secondsAfter = 5
    ): ?array {
        if (!$this->hasToken()) {
            return null;
        }

        try {
            $eventDt = Carbon::parse($eventTime);

            $response = $this->request('GET', '/fleet/safety-events', [
                'vehicleIds' => $vehicleId,
                'startTime' => $eventDt->copy()->subSeconds($secondsBefore)->toIso8601String(),
                'endTime' => $eventDt->copy()->addSeconds($secondsAfter)->toIso8601String(),
            ]);

            $events = $response['data'] ?? [];

            if (empty($events)) {
                return null;
            }

            $closest = $this->findClosestEvent($events, $eventDt);

            return $closest ? $this->formatSafetyEvent($closest) : null;
        } catch (\Exception $e) {
            Log::error('PipelineAdapter: Error fetching safety event detail', [
                'error' => $e->getMessage(),
                'vehicle_id' => $vehicleId,
            ]);
            return null;
        }
    }

    // =========================================================================
    // Caching
    // =========================================================================

    public function getCachedVehicleInfo(string $vehicleId): ?array
    {
        return Cache::get($this->vehicleInfoCacheKey($vehicleId));
    }

    public function getCachedDriverAssignment(string $vehicleId): ?array
    {
        return Cache::get($this->driverAssignmentCacheKey($vehicleId));
    }

    public function clearVehicleCache(string $vehicleId): void
    {
        Cache::forget($this->vehicleInfoCacheKey($vehicleId));
        Cache::forget($this->driverAssignmentCacheKey($vehicleId));
    }

    public function refreshVehicleInfo(string $vehicleId): ?array
    {
        if (!$this->hasToken()) {
            return null;
        }

        try {
            $data = $this->request('GET', "/fleet/vehicles/{$vehicleId}");
            $vehicleInfo = $data['data'] ?? $data;

            if (!empty($vehicleInfo)) {
                Cache::put($this->vehicleInfoCacheKey($vehicleId), $vehicleInfo, self::VEHICLE_INFO_CACHE_TTL);
            }

            return $vehicleInfo;
        } catch (\Exception) {
            return null;
        }
    }

    public function refreshDriverAssignment(string $vehicleId): ?array
    {
        if (!$this->hasToken()) {
            return null;
        }

        try {
            $data = $this->request('GET', '/fleet/driver-vehicle-assignments', [
                'filterBy' => 'vehicles',
                'vehicleIds' => $vehicleId,
            ]);

            $assignment = $this->formatDriverAssignment($data['data'] ?? []);

            if (!empty($assignment)) {
                Cache::put($this->driverAssignmentCacheKey($vehicleId), $assignment, self::DRIVER_ASSIGNMENT_CACHE_TTL);
            }

            return $assignment;
        } catch (\Exception) {
            return null;
        }
    }

    // =========================================================================
    // Static helpers (kept for backward compatibility during migration)
    // =========================================================================

    public static function isSafetyEvent(array $payload): bool
    {
        $eventType = strtolower($payload['eventType'] ?? '');

        if ($eventType === 'alertincident') {
            $conditions = $payload['data']['conditions'] ?? [];
            foreach ($conditions as $condition) {
                $description = strtolower($condition['description'] ?? '');
                $triggerId = $condition['triggerId'] ?? 0;

                if ($description !== 'panic button' && $triggerId > 0) {
                    $details = $condition['details'] ?? [];
                    foreach ($details as $key => $detail) {
                        if (isset($detail['behaviorLabel']) ||
                            in_array($key, ['harshEvent', 'safetyEvent', 'driverBehavior'])) {
                            return true;
                        }
                    }
                }
            }
        }

        $safetyEventTypes = [
            'safetyevent', 'harshevent', 'harshbraking', 'hardbraking',
            'harshacceleration', 'hardacceleration', 'harshturn', 'sharpturn',
            'harshcornering', 'speeding', 'collision', 'nearcollision',
            'followingdistance', 'distracteddriving', 'drowsiness', 'cellphoneuse',
        ];

        return in_array(str_replace(['_', '-', ' '], '', $eventType), $safetyEventTypes);
    }

    public static function extractEventContext(array $payload): ?array
    {
        $vehicleId = null;
        $happenedAtTime = null;

        if (isset($payload['vehicle']['id'])) {
            $vehicleId = $payload['vehicle']['id'];
        } elseif (isset($payload['vehicleId'])) {
            $vehicleId = $payload['vehicleId'];
        } elseif (isset($payload['data']['conditions'])) {
            foreach ($payload['data']['conditions'] as $condition) {
                $details = $condition['details'] ?? [];

                if (isset($details['panicButton']['vehicle']['id'])) {
                    $vehicleId = $details['panicButton']['vehicle']['id'];
                    break;
                }

                foreach ($details as $detail) {
                    if (is_array($detail) && isset($detail['vehicle']['id'])) {
                        $vehicleId = $detail['vehicle']['id'];
                        break 2;
                    }
                }
            }
        }

        $happenedAtTime = $payload['data']['happenedAtTime']
            ?? $payload['happenedAtTime']
            ?? $payload['eventTime']
            ?? $payload['time']
            ?? null;

        if ($vehicleId && $happenedAtTime) {
            // Normalizar a UTC ISO8601 para que la ventana de cámaras/media sea siempre respecto al evento
            $eventDt = Carbon::parse($happenedAtTime)->utc();
            $happenedAtTimeUtc = $eventDt->toIso8601String();

            Log::info('PipelineAdapter: extractEventContext result', [
                'vehicle_id' => (string) $vehicleId,
                'happened_at_time_raw' => $happenedAtTime,
                'happened_at_time_utc' => $happenedAtTimeUtc,
            ]);

            return [
                'vehicle_id' => (string) $vehicleId,
                'happened_at_time' => $happenedAtTimeUtc,
            ];
        }

        Log::info('PipelineAdapter: extractEventContext no context', [
            'has_vehicle_id' => ! empty($vehicleId),
            'has_happened_at_time' => ! empty($happenedAtTime),
        ]);

        return null;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function vehicleInfoCacheKey(string $vehicleId): string
    {
        return "samsara:vehicle_info:{$vehicleId}";
    }

    private function driverAssignmentCacheKey(string $vehicleId): string
    {
        return "samsara:driver_assignment:{$vehicleId}";
    }

    private function processVehicleInfoResponse($response, string $vehicleId): array
    {
        $cacheKey = $this->vehicleInfoCacheKey($vehicleId);

        if ($response->successful()) {
            $vehicleInfo = $response->json('data') ?? $response->json();

            if (!empty($vehicleInfo)) {
                Cache::put($cacheKey, $vehicleInfo, self::VEHICLE_INFO_CACHE_TTL);
                return ['vehicle_info' => $vehicleInfo];
            }
        }

        $cached = Cache::get($cacheKey);
        if ($cached) {
            $cached['_from_cache'] = true;
            Log::info('PipelineAdapter: Using cached vehicle info (API failed)', ['vehicle_id' => $vehicleId]);
            return ['vehicle_info' => $cached];
        }

        return [];
    }

    private function processDriverAssignmentResponse($response, string $vehicleId): array
    {
        $cacheKey = $this->driverAssignmentCacheKey($vehicleId);

        if ($response->successful()) {
            $data = $response->json('data') ?? [];
            $assignment = $this->formatDriverAssignment($data);

            if (!empty($assignment)) {
                Cache::put($cacheKey, $assignment, self::DRIVER_ASSIGNMENT_CACHE_TTL);
                return ['driver_assignment' => $assignment];
            }
        }

        $cached = Cache::get($cacheKey);
        if ($cached) {
            $cached['_from_cache'] = true;
            Log::info('PipelineAdapter: Using cached driver assignment (API failed)', ['vehicle_id' => $vehicleId]);
            return ['driver_assignment' => $cached];
        }

        return [];
    }

    private function processSafetyEventsResponse(
        $response,
        bool $hasSafetyEventsFromDb,
        array $dbSafetyEvents,
        bool $isSafetyEvent,
        Carbon $eventDt,
        string $vehicleId
    ): array {
        $result = [];

        if ($hasSafetyEventsFromDb) {
            Log::info('PipelineAdapter: Using safety events from database', [
                'vehicle_id' => $vehicleId,
                'count' => count($dbSafetyEvents),
            ]);

            if ($isSafetyEvent) {
                $closest = $this->findClosestEvent($dbSafetyEvents, $eventDt);
                if ($closest) {
                    $result['safety_event_detail'] = $this->formatSafetyEvent($closest);
                    $result['safety_event_detail']['_from_database'] = true;
                }
            } else {
                $result['safety_events_correlation'] = [
                    'total_events' => count($dbSafetyEvents),
                    'events' => array_map(fn ($e) => $this->formatSafetyEventSummary($e), $dbSafetyEvents),
                    '_from_database' => true,
                ];
            }
        } elseif ($response && $response->successful()) {
            $events = $response->json('data') ?? [];

            if (!empty($events)) {
                $result['_api_safety_events'] = $events;
            }

            if ($isSafetyEvent) {
                $closest = $this->findClosestEvent($events, $eventDt);
                if ($closest) {
                    $result['safety_event_detail'] = $this->formatSafetyEvent($closest);
                }
            } else {
                $result['safety_events_correlation'] = [
                    'total_events' => count($events),
                    'events' => array_map(fn ($e) => $this->formatSafetyEventSummary($e), $events),
                ];
            }
        } elseif ($response) {
            Log::warning('PipelineAdapter: Safety events API call failed', [
                'vehicle_id' => $vehicleId,
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);
        }

        return $result;
    }

    private function processCameraMedia(array $media): array
    {
        $limitedMedia = $this->limitCameraMedia($media, maxDriver: 3, maxRoad: 2);

        return [
            'total_items_found' => count($media),
            'total_items_selected' => count($limitedMedia),
            'items' => array_map(fn ($m) => $this->formatMediaItem($m), $limitedMedia),
            'note' => count($media) > 5
                ? 'Se seleccionaron las ' . count($limitedMedia) . ' imágenes más recientes (3 interior + 2 exterior) de ' . count($media) . ' disponibles. El análisis con Vision se hace en el AI Service.'
                : 'URLs pre-cargadas. El análisis con Vision se hace en el AI Service.',
        ];
    }

    private function limitCameraMedia(array $media, int $maxDriver = 3, int $maxRoad = 2): array
    {
        $media = array_filter($media, fn ($item) => is_array($item));
        if (empty($media)) {
            return [];
        }

        $driverImages = [];
        $roadImages = [];
        $otherImages = [];

        foreach ($media as $item) {
            $input = $item['input'] ?? '';
            if (stripos($input, 'driver') !== false) {
                $driverImages[] = $item;
            } elseif (stripos($input, 'road') !== false) {
                $roadImages[] = $item;
            } else {
                $otherImages[] = $item;
            }
        }

        $sortByTime = fn ($a, $b) => strcmp(
            $b['startTime'] ?? $b['availableAtTime'] ?? '',
            $a['startTime'] ?? $a['availableAtTime'] ?? ''
        );

        usort($driverImages, $sortByTime);
        usort($roadImages, $sortByTime);
        usort($otherImages, $sortByTime);

        $selected = array_merge(
            array_slice($driverImages, 0, $maxDriver),
            array_slice($roadImages, 0, $maxRoad)
        );

        $remaining = ($maxDriver + $maxRoad) - count($selected);
        if ($remaining > 0 && !empty($otherImages)) {
            $selected = array_merge($selected, array_slice($otherImages, 0, $remaining));
        }

        usort($selected, fn ($a, $b) => strcmp(
            $a['startTime'] ?? $a['availableAtTime'] ?? '',
            $b['startTime'] ?? $b['availableAtTime'] ?? ''
        ));

        return $selected;
    }

    private function formatDriverAssignment(array $data): array
    {
        if (empty($data)) {
            return ['driver' => null, 'note' => 'No driver assignment found'];
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

    private function formatSafetyEventSummary(array $event): array
    {
        return [
            'id' => $event['id'] ?? null,
            'behavior_label' => $event['behaviorLabel'] ?? $event['label'] ?? null,
            'severity' => $event['severity'] ?? null,
            'time' => $event['time'] ?? null,
        ];
    }

    private function formatMediaItem(array $media): array
    {
        return [
            'id' => $media['id'] ?? null,
            'media_type' => $media['mediaType'] ?? null,
            'captured_at' => $media['capturedAtTime'] ?? null,
            'url' => $media['urlInfo']['url'] ?? $media['url'] ?? null,
            'download_url' => $media['urlInfo']['downloadUrl'] ?? $media['downloadUrl'] ?? null,
            'camera_type' => $media['cameraType'] ?? null,
            'input' => $media['input'] ?? null,
            'start_time' => $media['startTime'] ?? $media['availableAtTime'] ?? null,
        ];
    }

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

    private function formatSafetyEvent(array $event): array
    {
        $behaviorLabels = $event['behaviorLabels'] ?? [];
        $primaryBehaviorName = null;
        $primaryBehaviorLabel = $event['behaviorLabel'] ?? $event['label'] ?? null;

        if (!empty($behaviorLabels) && isset($behaviorLabels[0]['name'])) {
            $primaryBehaviorName = $behaviorLabels[0]['name'];
        }

        return [
            'safety_event_id' => $event['id'] ?? null,
            'behavior_label' => $primaryBehaviorLabel,
            'behavior_name' => $primaryBehaviorName,
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
}
