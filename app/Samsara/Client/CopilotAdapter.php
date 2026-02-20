<?php

declare(strict_types=1);

namespace App\Samsara\Client;

use Illuminate\Support\Collection;

/**
 * Adapter for the Copilot / Neuron Tools.
 *
 * Consumed by all Neuron Tools (GetVehicleStats, GetSafetyEvents, etc.)
 * via CompanyContext::createSamsaraClient().
 */
class CopilotAdapter extends TelematicsClientCore
{
    /**
     * Maximum stat types per Samsara API request.
     */
    public const MAX_TYPES_PER_REQUEST = 3;

    public const STAT_TYPES = [
        'gps',
        'fuelPercents',
        'obdOdometerMeters',
        'engineStates',
        'engineRpm',
        'vehicleBatteryVoltage',
        'engineCoolantTemperatureMilliC',
        'engineLoadPercent',
        'ambientAirTemperatureMilliC',
        'faultCodes',
    ];

    public const MEDIA_INPUTS = [
        'dashcamRoadFacing',
        'dashcamDriverFacing',
    ];

    // =========================================================================
    // Vehicles
    // =========================================================================

    public function getVehicles(array $params = []): array
    {
        return $this->paginateAll('/fleet/vehicles', $params)->all();
    }

    public function getVehicle(string $vehicleId): array
    {
        $data = $this->request('GET', "/fleet/vehicles/{$vehicleId}");
        return $data['data'] ?? [];
    }

    // =========================================================================
    // Vehicle Stats (handles 3-type-per-request chunking)
    // =========================================================================

    public function getVehicleStatsFeed(array $vehicleIds = [], array $types = [], ?string $after = null): array
    {
        $params = [];

        if (!empty($vehicleIds)) {
            $params['vehicleIds'] = implode(',', $vehicleIds);
        }

        if (empty($types)) {
            $types = self::STAT_TYPES;
        }
        $params['types'] = implode(',', $types);

        if ($after) {
            $params['after'] = $after;
        }

        return $this->request('GET', '/fleet/vehicles/stats', $params);
    }

    public function getVehicleStats(array $vehicleIds = [], array $types = []): array
    {
        if (empty($types)) {
            $types = array_slice(self::STAT_TYPES, 0, self::MAX_TYPES_PER_REQUEST);
        }

        $typeChunks = array_chunk($types, self::MAX_TYPES_PER_REQUEST);
        $mergedData = [];

        foreach ($typeChunks as $typeChunk) {
            $response = $this->getVehicleStatsFeed($vehicleIds, $typeChunk);

            if (isset($response['data']) && is_array($response['data'])) {
                foreach ($response['data'] as $vehicleData) {
                    $vehicleId = $vehicleData['id'] ?? null;
                    if (!$vehicleId) {
                        continue;
                    }

                    if (!isset($mergedData[$vehicleId])) {
                        $mergedData[$vehicleId] = $vehicleData;
                    } else {
                        foreach ($typeChunk as $type) {
                            if (isset($vehicleData[$type])) {
                                $mergedData[$vehicleId][$type] = $vehicleData[$type];
                            }
                        }
                    }
                }
            }
        }

        return ['data' => array_values($mergedData)];
    }

    // =========================================================================
    // Safety Events
    // =========================================================================

    public function getSafetyEvents(string $startTime, string $endTime, array $vehicleIds = []): array
    {
        $params = [
            'startTime' => $startTime,
            'endTime' => $endTime,
        ];

        if (!empty($vehicleIds)) {
            $params['vehicleIds'] = implode(',', $vehicleIds);
        }

        return $this->request('GET', '/fleet/safety-events', $params);
    }

    public function getSafetyEventsRecent(
        array $vehicleIds = [],
        int $minutesBefore = 5,
        ?string $endTime = null
    ): array {
        $endDateTime = $endTime ? new \DateTime($endTime) : new \DateTime();
        $startDateTime = (clone $endDateTime)->modify("-{$minutesBefore} minutes");

        $response = $this->getSafetyEvents(
            $startDateTime->format(\DateTime::RFC3339),
            $endDateTime->format(\DateTime::RFC3339),
            $vehicleIds
        );

        $events = $response['data'] ?? [];

        return [
            'data' => $events,
            'meta' => [
                'searchRangeMinutes' => $minutesBefore,
                'startTime' => $startDateTime->format(\DateTime::RFC3339),
                'endTime' => $endDateTime->format(\DateTime::RFC3339),
                'totalEvents' => count($events),
            ],
        ];
    }

    public function getSafetyEventsStream(
        ?string $startTime = null,
        ?string $endTime = null,
        array $vehicleIds = [],
        array $eventStates = [],
        ?string $after = null,
        int $limit = 100
    ): array {
        $params = ['limit' => $limit];

        if ($startTime) {
            $params['startTime'] = $startTime;
        }
        if ($endTime) {
            $params['endTime'] = $endTime;
        }
        if (!empty($vehicleIds)) {
            $params['vehicleIds'] = implode(',', $vehicleIds);
        }
        if (!empty($eventStates)) {
            $params['eventStates'] = implode(',', $eventStates);
        }
        if ($after) {
            $params['after'] = $after;
        }

        return $this->request('GET', '/safety-events/stream', $params);
    }

    public function getRecentSafetyEventsStream(
        array $vehicleIds = [],
        int $minutesBefore = 60,
        array $eventStates = [],
        int $limit = 50
    ): array {
        $endDateTime = new \DateTime();
        $startDateTime = (clone $endDateTime)->modify("-{$minutesBefore} minutes");

        $allEvents = [];
        $cursor = null;
        $hasNextPage = true;

        while ($hasNextPage && count($allEvents) < $limit) {
            $response = $this->getSafetyEventsStream(
                $startDateTime->format(\DateTime::RFC3339),
                $endDateTime->format(\DateTime::RFC3339),
                $vehicleIds,
                $eventStates,
                $cursor,
                min($limit - count($allEvents), 100)
            );

            $allEvents = array_merge($allEvents, $response['data'] ?? []);
            $hasNextPage = $response['pagination']['hasNextPage'] ?? false;
            $cursor = $response['pagination']['endCursor'] ?? null;
        }

        usort($allEvents, fn ($a, $b) => strcmp(
            $b['createdAtTime'] ?? $b['startMs'] ?? '',
            $a['createdAtTime'] ?? $a['startMs'] ?? ''
        ));

        $allEvents = array_slice($allEvents, 0, $limit);

        return [
            'data' => $allEvents,
            'meta' => [
                'searchRangeMinutes' => $minutesBefore,
                'startTime' => $startDateTime->format(\DateTime::RFC3339),
                'endTime' => $endDateTime->format(\DateTime::RFC3339),
                'totalEvents' => count($allEvents),
            ],
        ];
    }

    public function getLatestSafetyEvents(
        array $vehicleIds = [],
        int $minEvents = 10,
        int $maxRangeHours = 24,
        array $eventStates = []
    ): array {
        $endDateTime = new \DateTime();
        $incrementMinutes = 60;
        $totalMinutes = 0;
        $maxMinutes = $maxRangeHours * 60;
        $allEvents = [];
        $startDateTime = clone $endDateTime;

        while (count($allEvents) < $minEvents && $totalMinutes < $maxMinutes) {
            $totalMinutes += $incrementMinutes;
            $startDateTime = (clone $endDateTime)->modify("-{$totalMinutes} minutes");

            $response = $this->getSafetyEventsStream(
                $startDateTime->format(\DateTime::RFC3339),
                $endDateTime->format(\DateTime::RFC3339),
                $vehicleIds,
                $eventStates,
                null,
                100
            );

            $events = $response['data'] ?? [];
            if (!empty($events)) {
                $allEvents = $events;

                $hasNextPage = $response['pagination']['hasNextPage'] ?? false;
                $cursor = $response['pagination']['endCursor'] ?? null;

                while ($hasNextPage && count($allEvents) < $minEvents) {
                    $pageResponse = $this->getSafetyEventsStream(
                        $startDateTime->format(\DateTime::RFC3339),
                        $endDateTime->format(\DateTime::RFC3339),
                        $vehicleIds,
                        $eventStates,
                        $cursor,
                        100
                    );
                    $allEvents = array_merge($allEvents, $pageResponse['data'] ?? []);
                    $hasNextPage = $pageResponse['pagination']['hasNextPage'] ?? false;
                    $cursor = $pageResponse['pagination']['endCursor'] ?? null;
                }
            }

            $incrementMinutes = min($incrementMinutes * 2, 240);
        }

        usort($allEvents, fn ($a, $b) => strcmp(
            $b['createdAtTime'] ?? $b['startMs'] ?? '',
            $a['createdAtTime'] ?? $a['startMs'] ?? ''
        ));

        return [
            'data' => $allEvents,
            'meta' => [
                'searchRangeMinutes' => $totalMinutes,
                'startTime' => $startDateTime->format(\DateTime::RFC3339),
                'endTime' => $endDateTime->format(\DateTime::RFC3339),
                'totalEvents' => count($allEvents),
            ],
        ];
    }

    // =========================================================================
    // Dashcam Media
    // =========================================================================

    public function getUploadedMedia(
        string $startTime,
        string $endTime,
        array $vehicleIds = [],
        array $inputs = []
    ): array {
        $queryParts = [
            'startTime=' . urlencode($startTime),
            'endTime=' . urlencode($endTime),
        ];

        if (!empty($vehicleIds)) {
            $queryParts[] = 'vehicleIds=' . urlencode(implode(',', $vehicleIds));
        }

        foreach ($inputs as $input) {
            $queryParts[] = 'inputs=' . urlencode($input);
        }

        return $this->request('GET', '/cameras/media?' . implode('&', $queryParts));
    }

    public function getDashcamMediaWithRetry(
        array $vehicleIds = [],
        array $inputs = ['dashcamRoadFacing', 'dashcamDriverFacing'],
        int $maxRetries = 10,
        int $incrementMinutes = 5,
        ?string $endTime = null
    ): array {
        $endDateTime = $endTime ? new \DateTime($endTime) : new \DateTime();
        $startDateTime = (clone $endDateTime)->modify("-{$incrementMinutes} minutes");

        $attempt = 0;
        $totalRangeMinutes = $incrementMinutes;

        while ($attempt < $maxRetries) {
            $response = $this->getUploadedMedia(
                $startDateTime->format(\DateTime::RFC3339),
                $endDateTime->format(\DateTime::RFC3339),
                $vehicleIds,
                $inputs
            );

            $media = $response['data']['media'] ?? [];

            if (!empty($media)) {
                return [
                    'data' => $media,
                    'meta' => [
                        'attempts' => $attempt + 1,
                        'searchRangeMinutes' => $totalRangeMinutes,
                        'startTime' => $startDateTime->format(\DateTime::RFC3339),
                        'endTime' => $endDateTime->format(\DateTime::RFC3339),
                        'found' => true,
                    ],
                ];
            }

            $attempt++;
            $startDateTime->modify("-{$incrementMinutes} minutes");
            $totalRangeMinutes += $incrementMinutes;

            if ($totalRangeMinutes > 1440) {
                break;
            }
        }

        return [
            'data' => [],
            'meta' => [
                'attempts' => $attempt,
                'searchRangeMinutes' => $totalRangeMinutes,
                'startTime' => $startDateTime->format(\DateTime::RFC3339),
                'endTime' => $endDateTime->format(\DateTime::RFC3339),
                'found' => false,
            ],
        ];
    }

    // =========================================================================
    // Drivers
    // =========================================================================

    public function getDrivers(array $params = []): array
    {
        return $this->paginateAll('/fleet/drivers', $params)->all();
    }

    public function getDriver(string $driverId): array
    {
        $data = $this->request('GET', "/fleet/drivers/{$driverId}");
        return $data['data'] ?? [];
    }

    // =========================================================================
    // Tags
    // =========================================================================

    public function getTags(int $limit = 512): array
    {
        return $this->paginateAll('/tags', ['limit' => $limit])->all();
    }

    public function getTag(string $tagId): array
    {
        $data = $this->request('GET', "/tags/{$tagId}");
        return $data['data'] ?? [];
    }

    // =========================================================================
    // Trips
    // =========================================================================

    public function getTripsStream(
        string $startTime,
        array $assetIds,
        ?string $after = null,
        int $limit = 100
    ): array {
        if (empty($assetIds)) {
            throw new \InvalidArgumentException('At least one asset ID is required');
        }

        if (count($assetIds) > 50) {
            throw new \InvalidArgumentException('Maximum 50 asset IDs allowed per request');
        }

        $params = [
            'startTime' => $startTime,
            'ids' => implode(',', $assetIds),
            'limit' => $limit,
        ];

        if ($after) {
            $params['after'] = $after;
        }

        return $this->request('GET', '/trips/stream', $params);
    }

    public function getRecentTrips(array $assetIds, int $hoursBack = 24, int $limit = 10): array
    {
        $endDateTime = new \DateTime();
        $startDateTime = (clone $endDateTime)->modify("-{$hoursBack} hours");

        $allTrips = [];
        $cursor = null;
        $hasNextPage = true;

        while ($hasNextPage && count($allTrips) < $limit) {
            $response = $this->getTripsStream(
                $startDateTime->format(\DateTime::RFC3339),
                $assetIds,
                $cursor,
                min($limit - count($allTrips), 100)
            );

            $allTrips = array_merge($allTrips, $response['data'] ?? []);
            $hasNextPage = $response['pagination']['hasNextPage'] ?? false;
            $cursor = $response['pagination']['endCursor'] ?? null;
        }

        usort($allTrips, fn ($a, $b) => strcmp(
            $b['tripStartTime'] ?? $b['createdAtTime'] ?? '',
            $a['tripStartTime'] ?? $a['createdAtTime'] ?? ''
        ));

        $allTrips = array_slice($allTrips, 0, $limit);

        return [
            'data' => $allTrips,
            'meta' => [
                'searchRangeHours' => $hoursBack,
                'startTime' => $startDateTime->format(\DateTime::RFC3339),
                'endTime' => $endDateTime->format(\DateTime::RFC3339),
                'totalTrips' => count($allTrips),
            ],
        ];
    }
}
