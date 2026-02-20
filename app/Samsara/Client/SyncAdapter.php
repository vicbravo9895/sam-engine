<?php

declare(strict_types=1);

namespace App\Samsara\Client;

use Illuminate\Support\Collection;

/**
 * Adapter for sync commands and streaming daemons.
 *
 * Consumed by SyncVehicles, SyncDrivers, SyncTags, SyncVehicleStats,
 * and SafetyEventsStreamDaemon.
 */
class SyncAdapter extends TelematicsClientCore
{
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

    // =========================================================================
    // Full collection methods (paginate everything)
    // =========================================================================

    public function getAllVehicles(array $params = []): Collection
    {
        return $this->paginateAll('/fleet/vehicles', $params);
    }

    public function getAllDrivers(array $params = []): Collection
    {
        return $this->paginateAll('/fleet/drivers', $params);
    }

    public function getAllTags(int $limit = 512): Collection
    {
        return $this->paginateAll('/tags', ['limit' => $limit]);
    }

    // =========================================================================
    // Vehicle Stats (full fleet sync with type chunking)
    // =========================================================================

    public function getAllVehicleStats(array $types = ['gps', 'engineStates', 'obdOdometerMeters']): array
    {
        $allVehicles = [];
        $typeChunks = array_chunk($types, self::MAX_TYPES_PER_REQUEST);
        $firstChunk = $typeChunks[0] ?? $types;

        // First pass: paginate all vehicles with first chunk of types
        $cursor = null;
        $hasNextPage = true;

        while ($hasNextPage) {
            $params = ['types' => implode(',', $firstChunk)];
            if ($cursor) {
                $params['after'] = $cursor;
            }

            $response = $this->request('GET', '/fleet/vehicles/stats', $params);

            if (isset($response['data']) && is_array($response['data'])) {
                foreach ($response['data'] as $vehicleData) {
                    $vehicleId = $vehicleData['id'] ?? null;
                    if (!$vehicleId) {
                        continue;
                    }
                    if (!isset($allVehicles[$vehicleId])) {
                        $allVehicles[$vehicleId] = $vehicleData;
                    } else {
                        foreach ($firstChunk as $type) {
                            if (isset($vehicleData[$type])) {
                                $allVehicles[$vehicleId][$type] = $vehicleData[$type];
                            }
                        }
                    }
                }
            }

            $hasNextPage = $response['pagination']['hasNextPage'] ?? false;
            $cursor = $response['pagination']['endCursor'] ?? null;
        }

        // Subsequent passes: fetch remaining type chunks for discovered vehicles
        if (count($typeChunks) > 1) {
            $vehicleIds = array_keys($allVehicles);

            for ($i = 1; $i < count($typeChunks); $i++) {
                $typeChunk = $typeChunks[$i];
                $chunkCursor = null;
                $chunkHasNextPage = true;

                while ($chunkHasNextPage) {
                    $params = [
                        'vehicleIds' => implode(',', $vehicleIds),
                        'types' => implode(',', $typeChunk),
                    ];
                    if ($chunkCursor) {
                        $params['after'] = $chunkCursor;
                    }

                    $response = $this->request('GET', '/fleet/vehicles/stats', $params);

                    if (isset($response['data']) && is_array($response['data'])) {
                        foreach ($response['data'] as $vehicleData) {
                            $vehicleId = $vehicleData['id'] ?? null;
                            if (!$vehicleId || !isset($allVehicles[$vehicleId])) {
                                continue;
                            }
                            foreach ($typeChunk as $type) {
                                if (isset($vehicleData[$type])) {
                                    $allVehicles[$vehicleId][$type] = $vehicleData[$type];
                                }
                            }
                        }
                    }

                    $chunkHasNextPage = $response['pagination']['hasNextPage'] ?? false;
                    $chunkCursor = $response['pagination']['endCursor'] ?? null;
                }
            }
        }

        return array_values($allVehicles);
    }

    // =========================================================================
    // Safety Events Stream (for daemon)
    // =========================================================================

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
}
