<?php

declare(strict_types=1);

namespace App\Neuron\Tools;

use App\Models\Vehicle;
use App\Neuron\Tools\Concerns\FlexibleVehicleSearch;
use App\Neuron\Tools\Concerns\UsesCompanyContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class GetTrips extends Tool
{
    use FlexibleVehicleSearch;
    use UsesCompanyContext;
    /**
     * Human-readable descriptions for trip completion statuses.
     */
    private const COMPLETION_STATUS_DESCRIPTIONS = [
        'completed' => 'Completado',
        'inProgress' => 'En progreso',
        'unknown' => 'Desconocido',
    ];

    /**
     * Human-readable descriptions for asset types.
     */
    private const ASSET_TYPE_DESCRIPTIONS = [
        'vehicle' => 'Vehículo',
        'trailer' => 'Remolque',
        'equipment' => 'Equipo',
    ];

    public function __construct()
    {
        parent::__construct(
            'GetTrips',
            'Obtener los viajes (trips) recientes de los vehículos de la flota. Incluye información del vehículo, ubicación de inicio y fin, tiempos del viaje, y estado de completado. Útil para reportes de actividad vehicular, rutas realizadas, y análisis de trayectos.'
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'vehicle_ids',
                type: PropertyType::STRING,
                description: 'IDs de los vehículos (assets) a consultar, separados por coma. Máximo 5 IDs. Si no se proporciona junto con vehicle_names, se usarán los primeros 5 vehículos del sistema. Ejemplo: "123456789,987654321"',
                required: false,
            ),
            new ToolProperty(
                name: 'vehicle_names',
                type: PropertyType::STRING,
                description: 'Nombres de los vehículos a consultar, separados por coma (máximo 5). Se buscarán en la base de datos para obtener sus IDs. Ejemplo: "Camión 1, Unidad 42"',
                required: false,
            ),
            new ToolProperty(
                name: 'hours_back',
                type: PropertyType::INTEGER,
                description: 'Horas hacia atrás desde ahora para buscar viajes. Por defecto es 24 horas. Máximo: 72 horas (3 días).',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Número máximo de viajes a retornar. Por defecto es 5. Máximo: 10.',
                required: false,
            ),
        ];
    }

    public function __invoke(
        ?string $vehicle_ids = null,
        ?string $vehicle_names = null,
        int $hours_back = 24,
        int $limit = 5
    ): string {
        try {
            // Check if company has Samsara access
            if (!$this->hasSamsaraAccess()) {
                return $this->noSamsaraAccessResponse();
            }

            $companyId = $this->getCompanyId();
            $vehicleIds = [];
            $vehicleNamesMap = [];

            // Resolve vehicle IDs from names if provided (using flexible search)
            if ($vehicle_names) {
                $result = $this->resolveVehicleNamesFlexible($vehicle_names);
                $vehicleIds = $result['vehicleIds'];
                $vehicleNamesMap = $result['vehicleNamesMap'];
                
                // If we have suggestions but no exact matches, ask for clarification
                if (empty($vehicleIds) && !empty($result['suggestions'])) {
                    return $this->generateClarificationResponse($result['suggestions']);
                }

                if (empty($vehicleIds)) {
                    return json_encode([
                        'error' => true,
                        'message' => 'No se encontraron vehículos con los nombres especificados: ' . $vehicle_names,
                    ], JSON_UNESCAPED_UNICODE);
                }
            }

            // Add directly provided IDs (validate they belong to this company)
            if ($vehicle_ids) {
                $ids = array_map('trim', explode(',', $vehicle_ids));
                // Validate vehicle IDs belong to this company
                $validIds = Vehicle::forCompany($companyId)
                    ->whereIn('samsara_id', $ids)
                    ->pluck('samsara_id')
                    ->toArray();
                $vehicleIds = array_merge($vehicleIds, $validIds);
            }

            // If no IDs provided, get vehicles from database (limited to 5 to avoid context overflow)
            // FILTERED BY COMPANY
            if (empty($vehicleIds)) {
                $vehicles = Vehicle::forCompany($companyId)->limit(5)->get();
                foreach ($vehicles as $vehicle) {
                    $vehicleIds[] = $vehicle->samsara_id;
                    $vehicleNamesMap[$vehicle->samsara_id] = $vehicle->name;
                }

                if (empty($vehicleIds)) {
                    return json_encode([
                        'error' => true,
                        'message' => 'No hay vehículos registrados para esta empresa.',
                    ], JSON_UNESCAPED_UNICODE);
                }
            }

            // Limit to 5 IDs to avoid context window overflow
            $vehicleIds = array_slice(array_unique($vehicleIds), 0, 5);

            // Limit parameters to reasonable range to avoid context overflow
            $hours_back = max(1, min(72, $hours_back));
            $limit = max(1, min(10, $limit));

            // Fetch trips from API using company-specific client
            $client = $this->createSamsaraClient();
            $response = $client->getRecentTrips(
                $vehicleIds,
                $hours_back,
                $limit
            );

            // Process and format the response
            // BUG FIX: Pass requested vehicleIds for defensive filtering
            // The Samsara API may return trips from other vehicles even when filtering by vehicleIds.
            // We enforce strict filtering to ensure only trips from requested vehicles are included.
            $formattedTrips = $this->formatTrips($response, $vehicleNamesMap, $vehicleIds);

            return json_encode($formattedTrips, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            return json_encode([
                'error' => true,
                'message' => 'Error al obtener viajes: ' . $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Format the trips response for better readability.
     * 
     * BUG FIX: Added defensive filtering by requested vehicleIds.
     * The Samsara API may return trips from other vehicles even when filtering by vehicleIds.
     * This method enforces strict filtering to ensure only trips from requested vehicles are included.
     * 
     * @param array $response API response with trips data
     * @param array $vehicleNamesMap Map of vehicleId => vehicleName
     * @param array $requestedVehicleIds List of vehicle IDs that were requested (for defensive filtering)
     */
    protected function formatTrips(array $response, array $vehicleNamesMap, array $requestedVehicleIds = []): array
    {
        $trips = $response['data'] ?? [];
        $meta = $response['meta'] ?? [];

        $result = [
            'total_trips' => count($trips),
            'search_range_hours' => $meta['searchRangeHours'] ?? 24,
            'period' => [
                'start' => $meta['startTime'] ?? null,
                'end' => $meta['endTime'] ?? null,
            ],
            'trips' => [],
        ];

        if (empty($trips)) {
            $result['message'] = 'No se encontraron viajes en el período especificado.';
            return $result;
        }

        // BUG FIX: Defensive filtering - only include trips from requested vehicles
        // Convert requestedVehicleIds to array of strings for comparison
        $requestedIds = array_map('strval', $requestedVehicleIds);
        $filteredTrips = [];
        
        foreach ($trips as $trip) {
            $asset = $trip['asset'] ?? [];
            $vehicleId = $asset['id'] ?? 'unknown';
            
            // CRITICAL: Only include trips from requested vehicles
            // If vehicleIds were specified, enforce strict filtering
            if (!empty($requestedIds) && !in_array(strval($vehicleId), $requestedIds, true)) {
                continue; // Skip trips from other vehicles
            }
            
            $filteredTrips[] = $trip;
        }

        // If we filtered everything out, return early with a clear message
        if (empty($filteredTrips) && !empty($requestedIds)) {
            $result['total_trips'] = 0;
            $result['message'] = 'No se encontraron viajes para este vehículo en el rango de tiempo especificado.';
            $result['_cardData'] = $this->generateCardData($result);
            return $result;
        }

        // Use filtered trips for processing
        $trips = $filteredTrips;
        $result['total_trips'] = count($trips);

        // Group trips by vehicle (limit to 5 trips per vehicle to avoid context overflow)
        $tripsByVehicle = [];
        $maxTripsPerVehicle = 5;
        
        foreach ($trips as $trip) {
            $asset = $trip['asset'] ?? [];
            $vehicleId = $asset['id'] ?? 'unknown';
            $vehicleName = $asset['name'] ?? $vehicleNamesMap[$vehicleId] ?? 'Sin nombre';
            $vehicleType = $asset['type'] ?? 'vehicle';
            
            if (!isset($tripsByVehicle[$vehicleId])) {
                $tripsByVehicle[$vehicleId] = [
                    'vehicle_id' => $vehicleId,
                    'vehicle_name' => $vehicleName,
                    'vehicle_type_description' => self::ASSET_TYPE_DESCRIPTIONS[$vehicleType] ?? $vehicleType,
                    'trips' => [],
                ];
            }

            // Limit trips per vehicle
            if (count($tripsByVehicle[$vehicleId]['trips']) >= $maxTripsPerVehicle) {
                continue;
            }

            // Format individual trip
            $formattedTrip = $this->formatSingleTrip($trip);
            $tripsByVehicle[$vehicleId]['trips'][] = $formattedTrip;
        }

        // Convert to array and add trip counts
        foreach ($tripsByVehicle as &$vehicleData) {
            $vehicleData['trip_count'] = count($vehicleData['trips']);
        }

        $result['trips'] = array_values($tripsByVehicle);

        // Add summary by status (using filtered trips only)
        $statusCounts = [];
        foreach ($trips as $trip) {
            $status = $trip['completionStatus'] ?? 'unknown';
            $translatedStatus = self::COMPLETION_STATUS_DESCRIPTIONS[$status] ?? $status;
            $statusCounts[$translatedStatus] = ($statusCounts[$translatedStatus] ?? 0) + 1;
        }
        $result['summary_by_status'] = $statusCounts;

        // Add summary by vehicle
        $vehicleCounts = [];
        foreach ($tripsByVehicle as $vehicleData) {
            $vehicleCounts[$vehicleData['vehicle_name']] = $vehicleData['trip_count'];
        }
        $result['summary_by_vehicle'] = $vehicleCounts;

        // Generate card data for frontend
        $result['_cardData'] = $this->generateCardData($result);

        // Add usage hint (anti-redundancy)
        $result['_hint'] = 'USA: :::trips\\n{_cardData.trips}\\n::: — NO describas viajes en texto, los datos ya están en la card.';

        return $result;
    }

    /**
     * Format a single trip with essential data only (reduced for context size).
     */
    protected function formatSingleTrip(array $trip): array
    {
        $status = $trip['completionStatus'] ?? 'unknown';
        
        $formattedTrip = [
            'status_description' => self::COMPLETION_STATUS_DESCRIPTIONS[$status] ?? $status,
            'trip_start_time' => $trip['tripStartTime'] ?? null,
            'trip_end_time' => $trip['tripEndTime'] ?? null,
            'start_location' => null,
            'end_location' => null,
        ];

        // Calculate duration if both times are available
        if (isset($trip['tripStartTime']) && isset($trip['tripEndTime'])) {
            try {
                $start = new \DateTime($trip['tripStartTime']);
                $end = new \DateTime($trip['tripEndTime']);
                $diff = $start->diff($end);
                $minutes = ($diff->h * 60) + $diff->i + ($diff->days * 24 * 60);
                $formattedTrip['duration_formatted'] = $this->formatDuration($minutes);
            } catch (\Exception $e) {
                // Ignore date parsing errors
            }
        }

        // Format start location (simplified)
        if (isset($trip['startLocation'])) {
            $formattedTrip['start_location'] = $this->formatLocation($trip['startLocation']);
        }

        // Format end location (simplified)
        if (isset($trip['endLocation'])) {
            $formattedTrip['end_location'] = $this->formatLocation($trip['endLocation']);
        }

        return $formattedTrip;
    }

    /**
     * Format a location with simplified address details (reduced for context size).
     */
    protected function formatLocation(array $location): array
    {
        $address = $location['address'] ?? [];
        $lat = $location['latitude'] ?? null;
        $lng = $location['longitude'] ?? null;

        // Build simplified address (only key parts)
        $addressParts = [];
        if (!empty($address['street'])) {
            $addressParts[] = $address['street'];
        }
        if (!empty($address['city'])) {
            $addressParts[] = $address['city'];
        }
        if (!empty($address['state'])) {
            $addressParts[] = $address['state'];
        }

        $locationData = [
            'address' => !empty($addressParts) ? implode(', ', $addressParts) : null,
        ];

        // Add point of interest if available (often more useful than address)
        if (!empty($address['pointOfInterest'])) {
            $locationData['point_of_interest'] = $address['pointOfInterest'];
        }

        // Add maps link only (no coordinates to save space)
        if ($lat && $lng) {
            $locationData['maps_link'] = sprintf(
                'https://www.google.com/maps?q=%s,%s',
                $lat,
                $lng
            );
        }

        return $locationData;
    }

    /**
     * Format duration in minutes to human-readable string.
     */
    protected function formatDuration(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes} min";
        }

        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        if ($remainingMinutes === 0) {
            return "{$hours} h";
        }

        return "{$hours} h {$remainingMinutes} min";
    }

    /**
     * Generate card data formatted for frontend rich cards.
     */
    protected function generateCardData(array $result): array
    {
        return [
            'trips' => [
                'totalTrips' => $result['total_trips'],
                'searchRangeHours' => $result['search_range_hours'],
                'periodStart' => $result['period']['start'] ?? null,
                'periodEnd' => $result['period']['end'] ?? null,
                'summaryByStatus' => $result['summary_by_status'] ?? [],
                'summaryByVehicle' => $result['summary_by_vehicle'] ?? [],
                'trips' => $result['trips'],
            ],
        ];
    }

    /**
     * Get list of available completion statuses with descriptions.
     */
    public static function getAvailableStatuses(): array
    {
        return self::COMPLETION_STATUS_DESCRIPTIONS;
    }

    /**
     * Get list of available asset types with descriptions.
     */
    public static function getAvailableAssetTypes(): array
    {
        return self::ASSET_TYPE_DESCRIPTIONS;
    }
}

