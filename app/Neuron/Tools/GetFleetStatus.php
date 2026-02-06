<?php

declare(strict_types=1);

namespace App\Neuron\Tools;

use App\Models\Tag;
use App\Models\Vehicle;
use App\Models\VehicleStat;
use App\Neuron\Tools\Concerns\UsesCompanyContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class GetFleetStatus extends Tool
{
    use UsesCompanyContext;

    public function __construct()
    {
        parent::__construct(
            'GetFleetStatus',
            'Obtener el estado actual de la flota en tiempo real. Muestra una tabla con todos los vehículos: nombre, placas, ubicación, estado del motor (activo/inactivo), velocidad y última actualización. Ideal para responder preguntas como "¿cómo está mi flota?", "¿cuántos vehículos están activos?", "estado de los vehículos del tag X".'
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'tag_name',
                type: PropertyType::STRING,
                description: 'Nombre del tag/grupo para filtrar vehículos. Ejemplo: "FORANEO JC", "LOCAL", etc.',
                required: false,
            ),
            new ToolProperty(
                name: 'tag_ids',
                type: PropertyType::STRING,
                description: 'IDs de tags separados por coma para filtrar vehículos. Ejemplo: "1234,5678".',
                required: false,
            ),
            new ToolProperty(
                name: 'status_filter',
                type: PropertyType::STRING,
                description: 'Filtrar por estado: "active" (motor encendido o en movimiento), "inactive" (motor apagado), "all" (todos). Por defecto es "all".',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Número máximo de vehículos a mostrar. Por defecto es 30.',
                required: false,
            ),
        ];
    }

    public function __invoke(
        ?string $tag_name = null,
        ?string $tag_ids = null,
        ?string $status_filter = 'all',
        int $limit = 30
    ): string {
        try {
            // Get company context for multi-tenant isolation
            $companyId = $this->getCompanyId();

            // Check if company has Samsara access
            if (!$this->hasSamsaraAccess()) {
                return $this->noSamsaraAccessResponse();
            }

            // Enforce reasonable limits to prevent context overflow
            $limit = min($limit, 50);

            // Collect vehicle IDs from tags if filtering by tag
            $tagVehicleIds = null;
            $tagInfo = null;
            $suggestedTags = null;

            if ($tag_ids || $tag_name) {
                $result = $this->getVehicleIdsFromTags($tag_ids, $tag_name);
                $tagVehicleIds = $result['vehicleIds'];
                $tagInfo = $result['tagInfo'];
                $suggestedTags = $result['suggestions'];

                if (empty($tagVehicleIds)) {
                    $response = [
                        'total' => 0,
                        'message' => $tag_name
                            ? "No se encontró el tag '{$tag_name}' o no tiene vehículos asociados."
                            : "No se encontraron vehículos para los tags especificados.",
                    ];
                    
                    if (!empty($suggestedTags)) {
                        $response['sugerencias'] = "Tags similares encontrados: " . implode(', ', array_slice($suggestedTags, 0, 5));
                    }
                    
                    return json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                }
            }

            // Check if vehicle_stats table has data
            $hasStats = VehicleStat::forCompany($companyId)->exists();

            if (!$hasStats) {
                // Fallback: fetch real-time stats from API
                return $this->fetchRealTimeStats($tagVehicleIds, $tagInfo, $status_filter, $limit);
            }

            // Build query for vehicle stats
            $query = VehicleStat::forCompany($companyId)
                ->with('vehicle');

            // Filter by tag vehicle IDs
            if ($tagVehicleIds !== null) {
                $query->whereIn('samsara_vehicle_id', $tagVehicleIds);
            }

            // Filter by status
            if ($status_filter === 'active') {
                $query->active();
            } elseif ($status_filter === 'inactive') {
                $query->inactive();
            }

            // Get counts before limiting
            $totalQuery = clone $query;
            $totalCount = $totalQuery->count();

            // If no stats for the filtered vehicles, try real-time
            if ($totalCount === 0 && $tagVehicleIds !== null) {
                return $this->fetchRealTimeStats($tagVehicleIds, $tagInfo, $status_filter, $limit);
            }

            $activeQuery = VehicleStat::forCompany($companyId)->active();
            $inactiveQuery = VehicleStat::forCompany($companyId)->inactive();

            if ($tagVehicleIds !== null) {
                $activeQuery->whereIn('samsara_vehicle_id', $tagVehicleIds);
                $inactiveQuery->whereIn('samsara_vehicle_id', $tagVehicleIds);
            }

            $activeCount = $activeQuery->count();
            $inactiveCount = $inactiveQuery->count();

            // Get last sync time
            $lastSync = VehicleStat::forCompany($companyId)
                ->max('synced_at');

            // Get vehicles with limit
            $stats = $query
                ->orderByRaw("CASE WHEN engine_state = 'on' THEN 0 WHEN engine_state = 'idle' THEN 1 ELSE 2 END")
                ->orderByDesc('speed_kmh')
                ->orderBy('vehicle_name')
                ->limit($limit)
                ->get();

            // Format vehicles for response
            $vehicles = $this->formatVehicleStats($stats);

            // Build response
            $response = $this->buildResponse($vehicles, $totalCount, $activeCount, $inactiveCount, $lastSync, $tagInfo, $status_filter, $limit);

            return json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            return json_encode([
                'error' => true,
                'message' => 'Error al obtener estado de la flota: ' . $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Fetch real-time stats from Samsara API when local data is unavailable.
     */
    protected function fetchRealTimeStats(?array $vehicleIds, ?array $tagInfo, string $statusFilter, int $limit): string
    {
        try {
            $client = $this->createSamsaraClient();
            $statsData = $client->getVehicleStats($vehicleIds ?? [], ['gps', 'engineStates', 'obdOdometerMeters']);

            $vehicles = [];
            $activeCount = 0;
            $inactiveCount = 0;

            $companyId = $this->getCompanyId();
            
            foreach ($statsData['data'] ?? [] as $vehicleStat) {
                $samsaraId = $vehicleStat['id'] ?? null;
                if (!$samsaraId) continue;

                // Filter by vehicleIds if provided
                if ($vehicleIds !== null && !in_array($samsaraId, $vehicleIds)) {
                    continue;
                }

                // Get local vehicle for additional info
                $localVehicle = Vehicle::forCompany($companyId)
                    ->where('samsara_id', $samsaraId)
                    ->first();

                // Parse GPS data
                $gps = $this->extractStatData($vehicleStat, 'gps');
                $engineState = $this->extractStatData($vehicleStat, 'engineStates') ?? $this->extractStatData($vehicleStat, 'engineState');
                $odometer = $this->extractStatData($vehicleStat, 'obdOdometerMeters');

                $engineValue = strtolower($engineState['value'] ?? 'off');
                $speedMph = isset($gps['speedMilesPerHour']) ? (float) $gps['speedMilesPerHour'] : 0;
                $speedKmh = round($speedMph * 1.60934, 1);
                $isActive = $engineValue !== 'off' || $speedKmh > 0;

                if ($isActive) {
                    $activeCount++;
                } else {
                    $inactiveCount++;
                }

                // Apply status filter
                if ($statusFilter === 'active' && !$isActive) continue;
                if ($statusFilter === 'inactive' && $isActive) continue;

                // Format location
                $locationName = 'Ubicación desconocida';
                $isGeofence = false;
                if (isset($gps['address']['name']) && !empty($gps['address']['name'])) {
                    $locationName = $gps['address']['name'];
                    $isGeofence = true;
                } elseif (isset($gps['reverseGeo']['formattedLocation'])) {
                    $locationName = $gps['reverseGeo']['formattedLocation'];
                }

                $lat = $gps['latitude'] ?? null;
                $lng = $gps['longitude'] ?? null;

                $vehicles[] = [
                    'id' => $samsaraId,
                    'name' => $vehicleStat['name'] ?? $localVehicle?->name ?? 'Sin nombre',
                    'licensePlate' => $localVehicle?->license_plate ?? null,
                    'make' => $localVehicle?->make ?? null,
                    'model' => $localVehicle?->model ?? null,
                    'location' => $locationName,
                    'isGeofence' => $isGeofence,
                    'lat' => $lat,
                    'lng' => $lng,
                    'mapsLink' => $lat && $lng ? "https://www.google.com/maps?q={$lat},{$lng}" : null,
                    'isActive' => $isActive,
                    'isMoving' => $speedKmh > 0,
                    'engineState' => match ($engineValue) {
                        'on' => 'Encendido',
                        'idle' => 'Ralentí',
                        default => 'Apagado',
                    },
                    'speedKmh' => $speedKmh,
                    'odometerKm' => isset($odometer['value']) ? round((float) $odometer['value'] / 1000, 1) : null,
                    'lastUpdate' => $gps['time'] ?? now()->toIso8601String(),
                ];

                if (count($vehicles) >= $limit) break;
            }

            $totalCount = count($vehicles);

            $response = $this->buildResponse(
                $vehicles,
                $totalCount,
                $activeCount,
                $inactiveCount,
                now()->toIso8601String(),
                $tagInfo,
                $statusFilter,
                $limit
            );

            $response['dataSource'] = 'API en tiempo real (datos locales no disponibles)';

            return json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            return json_encode([
                'error' => true,
                'message' => 'Error al obtener datos en tiempo real: ' . $e->getMessage(),
                'suggestion' => 'Ejecuta el comando: sail artisan samsara:sync-vehicle-stats',
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Extract stat data from Samsara response, handling both array and object formats.
     */
    protected function extractStatData(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;
        
        if ($value === null) {
            return null;
        }

        // If it's an array of items, get the first one
        if (is_array($value) && isset($value[0]) && is_array($value[0])) {
            return $value[0];
        }

        // If it's a direct object (associative array), return as-is
        if (is_array($value)) {
            return $value;
        }

        return null;
    }

    /**
     * Format vehicle stats for response.
     */
    protected function formatVehicleStats($stats): array
    {
        return $stats->map(function ($stat) {
            $vehicle = $stat->vehicle;

            return [
                'id' => $stat->samsara_vehicle_id,
                'name' => $stat->vehicle_name ?? $vehicle?->name ?? 'Sin nombre',
                'licensePlate' => $vehicle?->license_plate ?? null,
                'make' => $vehicle?->make ?? null,
                'model' => $vehicle?->model ?? null,
                'location' => $stat->getFormattedLocation(),
                'isGeofence' => $stat->is_geofence,
                'lat' => $stat->latitude ? (float) $stat->latitude : null,
                'lng' => $stat->longitude ? (float) $stat->longitude : null,
                'mapsLink' => $stat->getMapsLink(),
                'isActive' => $stat->isActive(),
                'isMoving' => $stat->isMoving(),
                'engineState' => $stat->getEngineStateLabel(),
                'speedKmh' => round((float) ($stat->speed_kmh ?? 0), 1),
                'odometerKm' => $stat->getOdometerKm(),
                'lastUpdate' => $stat->gps_time?->toIso8601String() ?? $stat->synced_at?->toIso8601String(),
            ];
        })->toArray();
    }

    /**
     * Build the response structure.
     */
    protected function buildResponse(
        array $vehicles,
        int $totalCount,
        int $activeCount,
        int $inactiveCount,
        ?string $lastSync,
        ?array $tagInfo,
        string $statusFilter,
        int $limit
    ): array {
        $response = [
            'total' => $totalCount,
            'active' => $activeCount,
            'inactive' => $inactiveCount,
            'showing' => count($vehicles),
            'lastSync' => $lastSync,
            'statusFilter' => $statusFilter,
            'vehicles' => $vehicles,
        ];

        // Add tag filter info if applicable
        if ($tagInfo) {
            $response['filteredByTag'] = $tagInfo;
        }

        // Add note if results are limited
        if ($totalCount > $limit) {
            $response['note'] = "Mostrando {$limit} de {$totalCount} vehículos.";
        }

        // Generate card data for rich rendering
        $response['_cardData'] = [
            'fleetStatus' => [
                'total' => $totalCount,
                'active' => $activeCount,
                'inactive' => $inactiveCount,
                'lastSync' => $lastSync,
                'filteredByTag' => $tagInfo,
                'vehicles' => $vehicles,
            ],
        ];

        // Add usage hint (anti-redundancy)
        $response['_hint'] = 'USA: :::fleetStatus\n{_cardData.fleetStatus}\n::: — NO repitas la tabla en texto.';

        return $response;
    }

    /**
     * Get vehicle IDs from tags with fuzzy matching and suggestions.
     *
     * @param string|null $tagIds Comma-separated tag IDs
     * @param string|null $tagName Tag name to search
     * @return array Array with vehicleIds, tagInfo, and suggestions
     */
    protected function getVehicleIdsFromTags(?string $tagIds, ?string $tagName): array
    {
        $vehicleIds = [];
        $tagInfo = [];
        $suggestions = [];
        $companyId = $this->getCompanyId();

        // Find tags by ID - FILTERED BY COMPANY
        if ($tagIds) {
            $ids = array_map('trim', explode(',', $tagIds));
            $tags = Tag::forCompany($companyId)->whereIn('samsara_id', $ids)->get();

            foreach ($tags as $tag) {
                $tagInfo[] = [
                    'id' => $tag->samsara_id,
                    'name' => $tag->name,
                    'vehicle_count' => $tag->vehicle_count,
                ];

                if (!empty($tag->vehicles)) {
                    foreach ($tag->vehicles as $vehicle) {
                        if (isset($vehicle['id'])) {
                            $vehicleIds[] = $vehicle['id'];
                        }
                    }
                }
            }
        }

        // Find tags by name - FILTERED BY COMPANY (case-insensitive)
        if ($tagName) {
            $searchTerm = mb_strtolower(trim($tagName));
            
            // First try exact match (case-insensitive)
            $tags = Tag::forCompany($companyId)
                ->whereRaw('LOWER(name) = ?', [$searchTerm])
                ->get();

            // If no exact match, try partial match
            if ($tags->isEmpty()) {
                $tags = Tag::forCompany($companyId)
                    ->whereRaw('LOWER(name) LIKE ?', ['%' . $searchTerm . '%'])
                    ->get();
            }

            foreach ($tags as $tag) {
                $tagInfo[] = [
                    'id' => $tag->samsara_id,
                    'name' => $tag->name,
                    'vehicle_count' => $tag->vehicle_count,
                ];

                if (!empty($tag->vehicles)) {
                    foreach ($tag->vehicles as $vehicle) {
                        if (isset($vehicle['id'])) {
                            $vehicleIds[] = $vehicle['id'];
                        }
                    }
                }
            }

            // If still no results, provide suggestions
            if (empty($vehicleIds)) {
                // Get similar tags using word matching
                $words = explode(' ', $searchTerm);
                $suggestionQuery = Tag::forCompany($companyId)
                    ->whereNotNull('vehicles')
                    ->whereRaw("json_array_length(vehicles::json) > 0");
                
                $suggestionQuery->where(function ($q) use ($words) {
                    foreach ($words as $word) {
                        if (strlen($word) >= 3) {
                            $q->orWhereRaw('LOWER(name) LIKE ?', ['%' . $word . '%']);
                        }
                    }
                });

                $suggestions = $suggestionQuery
                    ->orderBy('vehicle_count', 'desc')
                    ->limit(10)
                    ->pluck('name')
                    ->toArray();
            }
        }

        return [
            'vehicleIds' => array_unique($vehicleIds),
            'tagInfo' => $tagInfo,
            'suggestions' => $suggestions,
        ];
    }
}
