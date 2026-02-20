<?php

declare(strict_types=1);

namespace App\Neuron\Tools;

use App\Models\Vehicle;
use App\Neuron\Tools\Concerns\FlexibleVehicleSearch;
use App\Neuron\Tools\Concerns\UsesCompanyContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class GetVehicleStats extends Tool
{
    use FlexibleVehicleSearch;
    use UsesCompanyContext;
    /**
     * Human-readable descriptions for stat types.
     */
    private const STAT_DESCRIPTIONS = [
        'gps' => 'Ubicación GPS (latitud, longitud, velocidad)',
        'fuelPercents' => 'Nivel de combustible (%)',
        'obdOdometerMeters' => 'Odómetro (kilómetros)',
        'engineStates' => 'Estado del motor (encendido/apagado/ralentí)',
        'engineRpm' => 'RPM del motor',
        'vehicleBatteryVoltage' => 'Voltaje de batería (V)',
        'engineCoolantTemperatureMilliC' => 'Temperatura del refrigerante (°C)',
        'engineLoadPercent' => 'Carga del motor (%)',
        'ambientAirTemperatureMilliC' => 'Temperatura ambiente (°C)',
        'faultCodes' => 'Códigos de falla del motor',
    ];

    public function __construct()
    {
        parent::__construct(
            'GetVehicleStats',
            'Obtener estadísticas en tiempo real de uno o varios vehículos. Incluye datos como: ubicación GPS, nivel de combustible, odómetro, estado del motor, RPM, voltaje de batería, temperaturas y códigos de falla. Ideal para monitoreo de flota en vivo.'
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'vehicle_ids',
                type: PropertyType::STRING,
                description: 'IDs de los vehículos a consultar, separados por coma. Si no se proporciona, consulta todos los vehículos. Ejemplo: "123456789,987654321"',
                required: false,
            ),
            new ToolProperty(
                name: 'vehicle_names',
                type: PropertyType::STRING,
                description: 'Nombres de los vehículos a consultar, separados por coma. Se buscarán en la base de datos para obtener sus IDs. Ejemplo: "Camión 1, Unidad 42"',
                required: false,
            ),
            new ToolProperty(
                name: 'stat_types',
                type: PropertyType::STRING,
                description: 'Tipos de estadísticas a consultar, separados por coma (máximo 3 tipos por consulta). Opciones: gps (ubicación), fuelPercents (combustible), obdOdometerMeters (km), engineStates (estado motor). Si no se especifica, usa: gps,engineStates,fuelPercents.',
                required: false,
            ),
            new ToolProperty(
                name: 'include_vehicle_info',
                type: PropertyType::BOOLEAN,
                description: 'Si es true, incluye información adicional del vehículo (nombre, marca, modelo). Por defecto es true.',
                required: false,
            ),
        ];
    }

    public function __invoke(
        ?string $vehicle_ids = null,
        ?string $vehicle_names = null,
        ?string $stat_types = null,
        bool $include_vehicle_info = true
    ): string {
        try {
            // Check if company has Samsara access
            if (!$this->hasSamsaraAccess()) {
                return $this->noSamsaraAccessResponse();
            }

            $vehicleIds = [];

            // Resolve vehicle IDs from names if provided (using flexible search)
            if ($vehicle_names) {
                $result = $this->resolveVehicleNamesFlexible($vehicle_names);
                $vehicleIds = $result['vehicleIds'];
                
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
                $validIds = $this->validateVehicleIds($ids);
                $vehicleIds = array_merge($vehicleIds, $validIds);
            }

            // Parse stat types (limit to 3 as per Samsara API)
            $types = [];
            if ($stat_types) {
                $types = array_map('trim', explode(',', $stat_types));
                // Validate types
                $validTypes = \App\Samsara\Client\CopilotAdapter::STAT_TYPES;
                $types = array_filter($types, fn($type) => in_array($type, $validTypes));
                // Limit to max 3 types per API requirement
                $types = array_slice(array_values($types), 0, \App\Samsara\Client\CopilotAdapter::MAX_TYPES_PER_REQUEST);
            }

            // Default types if none provided (most commonly used)
            if (empty($types)) {
                $types = ['gps', 'engineStates', 'fuelPercents'];
            }

            // Fetch stats from API using company-specific client
            $client = $this->createSamsaraClient();
            $response = $client->getVehicleStats($vehicleIds, $types);

            // Process and format the response
            // BUG FIX: Pass requested vehicleIds for defensive filtering
            // The Samsara API may return stats from other vehicles even when filtering by vehicleIds.
            // We enforce strict filtering to ensure only stats from requested vehicles are included.
            $formattedStats = $this->formatStats($response, $include_vehicle_info, $vehicleIds);

            return json_encode($formattedStats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            return json_encode([
                'error' => true,
                'message' => 'Error al obtener estadísticas de vehículos: ' . $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Helper to get stat data - handles both array and object formats from API.
     * API sometimes returns: "gps": [{ ... }] (array)
     * And sometimes returns: "gps": { ... } (object directly)
     * Also handles singular/plural field names (engineState vs engineStates)
     */
    protected function getStatData(array $vehicleStats, string $key, ?string $altKey = null): ?array
    {
        // Try primary key first
        $data = $vehicleStats[$key] ?? null;
        
        // Try alternate key if primary not found
        if ($data === null && $altKey !== null) {
            $data = $vehicleStats[$altKey] ?? null;
        }
        
        if ($data === null) {
            return null;
        }
        
        // If it's an array of items, get the first one
        if (is_array($data) && isset($data[0]) && is_array($data[0])) {
            return $data[0];
        }
        
        // If it's a direct object (associative array), return as-is
        if (is_array($data)) {
            return $data;
        }
        
        return null;
    }

    /**
     * Format the stats response for better readability.
     * 
     * BUG FIX: Added defensive filtering by requested vehicleIds.
     * The Samsara API may return stats from other vehicles even when filtering by vehicleIds.
     * This method enforces strict filtering to ensure only stats from requested vehicles are included.
     * 
     * @param array $response API response with vehicle stats data
     * @param bool $includeVehicleInfo Whether to include additional vehicle info from database
     * @param array $requestedVehicleIds List of vehicle IDs that were requested (for defensive filtering)
     */
    protected function formatStats(array $response, bool $includeVehicleInfo, array $requestedVehicleIds = []): array
    {
        $result = [
            'total_vehicles' => 0,
            'vehicles' => [],
        ];

        $data = $response['data'] ?? [];

        // BUG FIX: Defensive filtering - only include stats from requested vehicles
        // Convert requestedVehicleIds to array of strings for comparison
        $requestedIds = array_map('strval', $requestedVehicleIds);
        $filteredData = [];
        
        foreach ($data as $vehicleStats) {
            $vehicleId = $vehicleStats['id'] ?? 'unknown';
            
            // CRITICAL: Only include stats from requested vehicles
            // If vehicleIds were specified, enforce strict filtering
            if (!empty($requestedIds) && !in_array(strval($vehicleId), $requestedIds, true)) {
                continue; // Skip stats from other vehicles
            }
            
            $filteredData[] = $vehicleStats;
        }

        // Use filtered data for processing
        $data = $filteredData;
        $result['total_vehicles'] = count($data);

        // If we filtered everything out, return early with a clear message
        if (empty($data) && !empty($requestedIds)) {
            $result['message'] = 'No se encontraron estadísticas para este vehículo.';
            return $result;
        }

        foreach ($data as $vehicleStats) {
            $vehicleId = $vehicleStats['id'] ?? 'unknown';
            $vehicleName = $vehicleStats['name'] ?? 'Sin nombre';

            $formattedVehicle = [
                'id' => $vehicleId,
                'name' => $vehicleName,
            ];

            // Add vehicle info from database if requested - FILTERED BY COMPANY
            if ($includeVehicleInfo) {
                $companyId = $this->getCompanyId();
                $dbVehicle = Vehicle::forCompany($companyId)->where('samsara_id', $vehicleId)->first();
                if ($dbVehicle) {
                    $formattedVehicle['make'] = $dbVehicle->make;
                    $formattedVehicle['model'] = $dbVehicle->model;
                    $formattedVehicle['year'] = $dbVehicle->year;
                    $formattedVehicle['license_plate'] = $dbVehicle->license_plate;
                }
            }

            $formattedVehicle['stats'] = [];

            // Process GPS data (handles both array and object format)
            $gps = $this->getStatData($vehicleStats, 'gps');
            if ($gps) {
                $lat = $gps['latitude'] ?? null;
                $lng = $gps['longitude'] ?? null;
                
                // Use geofence name if available (address), otherwise use reverseGeo
                $ubicacionNombre = null;
                $esGeofence = false;
                if (isset($gps['address']['name']) && !empty($gps['address']['name'])) {
                    $ubicacionNombre = $gps['address']['name'];
                    $esGeofence = true;
                } elseif (isset($gps['reverseGeo']['formattedLocation'])) {
                    $ubicacionNombre = $gps['reverseGeo']['formattedLocation'];
                }
                
                // Build Google Maps link for easy access
                $mapsLink = null;
                if ($lat && $lng) {
                    $mapsLink = "https://www.google.com/maps?q={$lat},{$lng}";
                }
                
                $formattedVehicle['stats']['ubicacion'] = [
                    'latitud' => $lat,
                    'longitud' => $lng,
                    'nombre' => $ubicacionNombre,
                    'es_geofence' => $esGeofence,
                    'velocidad_kmh' => isset($gps['speedMilesPerHour']) 
                        ? round($gps['speedMilesPerHour'] * 1.60934, 1) 
                        : null,
                    'direccion_grados' => $gps['headingDegrees'] ?? null,
                    'mapa' => $mapsLink,
                    'tiempo' => $gps['time'] ?? null,
                ];
            }

            // Process fuel data
            $fuel = $this->getStatData($vehicleStats, 'fuelPercents', 'fuelPercent');
            if ($fuel) {
                $formattedVehicle['stats']['combustible_porcentaje'] = $fuel['value'] ?? null;
                $formattedVehicle['stats']['combustible_tiempo'] = $fuel['time'] ?? null;
            }

            // Process odometer data
            $odometer = $this->getStatData($vehicleStats, 'obdOdometerMeters', 'obdOdometer');
            if ($odometer) {
                $meters = $odometer['value'] ?? 0;
                $formattedVehicle['stats']['odometro_km'] = round($meters / 1000, 1);
                $formattedVehicle['stats']['odometro_tiempo'] = $odometer['time'] ?? null;
            }

            // Process engine state (handles both engineStates and engineState)
            $engineState = $this->getStatData($vehicleStats, 'engineStates', 'engineState');
            if ($engineState) {
                $stateMap = [
                    'Off' => 'Apagado',
                    'On' => 'Encendido',
                    'Idle' => 'Ralentí',
                ];
                $state = $engineState['value'] ?? 'unknown';
                $formattedVehicle['stats']['motor_estado'] = $stateMap[$state] ?? $state;
                $formattedVehicle['stats']['motor_tiempo'] = $engineState['time'] ?? null;
            }

            // Process engine RPM
            $rpm = $this->getStatData($vehicleStats, 'engineRpm');
            if ($rpm) {
                $formattedVehicle['stats']['motor_rpm'] = $rpm['value'] ?? null;
            }

            // Process battery voltage
            $battery = $this->getStatData($vehicleStats, 'vehicleBatteryVoltage', 'batteryVoltage');
            if ($battery) {
                $millivolts = $battery['value'] ?? 0;
                $formattedVehicle['stats']['bateria_voltaje'] = round($millivolts / 1000, 2);
            }

            // Process coolant temperature
            $coolant = $this->getStatData($vehicleStats, 'engineCoolantTemperatureMilliC', 'engineCoolantTemperature');
            if ($coolant) {
                $milliC = $coolant['value'] ?? 0;
                $formattedVehicle['stats']['refrigerante_celsius'] = round($milliC / 1000, 1);
            }

            // Process engine load
            $load = $this->getStatData($vehicleStats, 'engineLoadPercent', 'engineLoad');
            if ($load) {
                $formattedVehicle['stats']['motor_carga_porcentaje'] = $load['value'] ?? null;
            }

            // Process ambient temperature
            $ambient = $this->getStatData($vehicleStats, 'ambientAirTemperatureMilliC', 'ambientAirTemperature');
            if ($ambient) {
                $milliC = $ambient['value'] ?? 0;
                $formattedVehicle['stats']['temperatura_ambiente_celsius'] = round($milliC / 1000, 1);
            }

            // Process fault codes
            $faultCodes = $this->getStatData($vehicleStats, 'faultCodes');
            if ($faultCodes) {
                if (!empty($faultCodes['obdii']) || !empty($faultCodes['j1939'])) {
                    $formattedVehicle['stats']['codigos_falla'] = [
                        'obdii' => $faultCodes['obdii'] ?? [],
                        'j1939' => $faultCodes['j1939'] ?? [],
                    ];
                    $formattedVehicle['stats']['tiene_fallas'] = true;
                } else {
                    $formattedVehicle['stats']['tiene_fallas'] = false;
                }
            }

            // Generate card data for rich rendering
            $formattedVehicle['_cardData'] = $this->generateCardData($formattedVehicle);
            
            $result['vehicles'][] = $formattedVehicle;
        }

        // Add usage hint (anti-redundancy)
        $result['_hint'] = 'USA: :::location\\n{_cardData.location}\\n::: o :::vehicleStats\\n{_cardData.vehicleStats}\\n::: — NO repitas ubicación/velocidad/motor en texto.';

        return $result;
    }

    /**
     * Generate card data formatted for frontend rich cards.
     */
    protected function generateCardData(array $vehicle): array
    {
        $cardData = [];
        
        // Location card data
        if (isset($vehicle['stats']['ubicacion'])) {
            $loc = $vehicle['stats']['ubicacion'];
            $cardData['location'] = [
                'vehicleName' => $vehicle['name'],
                'vehicleId' => $vehicle['id'],
                'lat' => $loc['latitud'],
                'lng' => $loc['longitud'],
                'locationName' => $loc['nombre'] ?? 'Ubicación desconocida',
                'isGeofence' => $loc['es_geofence'] ?? false,
                'speedKmh' => $loc['velocidad_kmh'] ?? 0,
                'headingDegrees' => $loc['direccion_grados'] ?? 0,
                'mapsLink' => $loc['mapa'] ?? '',
                'timestamp' => $loc['tiempo'] ?? now()->toIso8601String(),
                'make' => $vehicle['make'] ?? null,
                'model' => $vehicle['model'] ?? null,
                'licensePlate' => $vehicle['license_plate'] ?? null,
            ];
        }
        
        // Vehicle stats card data
        $cardData['vehicleStats'] = [
            'vehicleName' => $vehicle['name'],
            'vehicleId' => $vehicle['id'],
            'make' => $vehicle['make'] ?? null,
            'model' => $vehicle['model'] ?? null,
            'year' => $vehicle['year'] ?? null,
            'licensePlate' => $vehicle['license_plate'] ?? null,
            'stats' => $vehicle['stats'],
        ];
        
        return $cardData;
    }

    /**
     * Get list of available stat types with descriptions.
     */
    public static function getAvailableStatTypes(): array
    {
        return self::STAT_DESCRIPTIONS;
    }

    /**
     * Validate that vehicle IDs belong to this company.
     * 
     * @param array $ids Array of samsara_id values to validate
     * @return array Array of valid samsara_ids that belong to this company
     */
    protected function validateVehicleIds(array $ids): array
    {
        $companyId = $this->getCompanyId();
        
        return Vehicle::forCompany($companyId)
            ->whereIn('samsara_id', $ids)
            ->pluck('samsara_id')
            ->toArray();
    }
}

