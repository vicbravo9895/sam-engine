<?php

declare(strict_types=1);

namespace App\Neuron\Tools;

use App\Models\Vehicle;
use App\Neuron\Tools\Concerns\FlexibleVehicleSearch;
use App\Neuron\Tools\Concerns\UsesCompanyContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class RunFleetAnalysis extends Tool
{
    use FlexibleVehicleSearch;
    use UsesCompanyContext;

    /**
     * Tipos de análisis disponibles con descripción para el LLM.
     */
    private const ANALYSIS_TYPES = [
        'driver_risk_profile' => 'Perfil de riesgo de un conductor específico (score, patrones, tendencias)',
        'fleet_safety_overview' => 'Resumen ejecutivo de seguridad de toda la flota',
        'vehicle_health' => 'Evaluación de salud de un vehículo (batería, motor, combustible)',
        'operational_efficiency' => 'Eficiencia operativa (viajes, ralentí, utilización)',
        'anomaly_detection' => 'Detección de anomalías (tampering, obstrucciones, patrones sospechosos)',
    ];

    public function __construct()
    {
        parent::__construct(
            'RunFleetAnalysis',
            'Ejecuta un análisis avanzado de datos de flota impulsado por AI. '
            . 'Combina análisis estadístico profundo (Python) con interpretación inteligente (LLM). '
            . 'Tipos disponibles: '
            . 'driver_risk_profile (riesgo de conductor), '
            . 'fleet_safety_overview (seguridad general de la flota), '
            . 'vehicle_health (salud del vehículo), '
            . 'operational_efficiency (eficiencia operativa), '
            . 'anomaly_detection (detección de anomalías). '
            . 'Úsalo cuando el usuario pida análisis, reportes de riesgo, evaluaciones, tendencias o predicciones.'
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'analysis_type',
                type: PropertyType::STRING,
                description: 'Tipo de análisis: driver_risk_profile, fleet_safety_overview, vehicle_health, operational_efficiency, anomaly_detection',
                required: true,
            ),
            new ToolProperty(
                name: 'vehicle_ids',
                type: PropertyType::STRING,
                description: 'IDs de vehículos separados por coma (para vehicle_health, driver_risk_profile)',
                required: false,
            ),
            new ToolProperty(
                name: 'vehicle_names',
                type: PropertyType::STRING,
                description: 'Nombres de vehículos separados por coma (para vehicle_health, driver_risk_profile)',
                required: false,
            ),
            new ToolProperty(
                name: 'driver_name',
                type: PropertyType::STRING,
                description: 'Nombre del conductor para driver_risk_profile',
                required: false,
            ),
            new ToolProperty(
                name: 'days_back',
                type: PropertyType::INTEGER,
                description: 'Días hacia atrás para el análisis. Default: 7. Máximo: 30.',
                required: false,
            ),
        ];
    }

    public function __invoke(
        string $analysis_type,
        ?string $vehicle_ids = null,
        ?string $vehicle_names = null,
        ?string $driver_name = null,
        int $days_back = 7,
    ): string {
        try {
            // Validar acceso a Samsara
            if (!$this->hasSamsaraAccess()) {
                return $this->noSamsaraAccessResponse();
            }

            // Validar tipo de análisis
            if (!isset(self::ANALYSIS_TYPES[$analysis_type])) {
                return json_encode([
                    'error' => true,
                    'message' => 'Tipo de análisis no válido. Tipos disponibles: '
                        . implode(', ', array_keys(self::ANALYSIS_TYPES)),
                ], JSON_UNESCAPED_UNICODE);
            }

            $companyId = $this->getCompanyId();
            $days_back = max(1, min(30, $days_back));

            // Resolver vehicle IDs si se proporcionan
            $resolvedVehicleIds = $this->resolveVehicleIds($vehicle_ids, $vehicle_names, $companyId);

            // Recopilar datos crudos según el tipo de análisis
            $rawData = $this->gatherRawData($analysis_type, $resolvedVehicleIds, $days_back);

            // Construir parámetros
            $parameters = [
                'days_back' => $days_back,
                'vehicle_ids' => $resolvedVehicleIds,
            ];

            if ($driver_name) {
                $parameters['driver_name'] = $driver_name;
            }

            if ($vehicle_names) {
                $parameters['vehicle_name'] = explode(',', $vehicle_names)[0];
            }

            // Llamar al AI Service
            $aiServiceUrl = config('services.ai_engine.url');
            $response = Http::timeout(120)
                ->post("{$aiServiceUrl}/analysis/on-demand", [
                    'analysis_type' => $analysis_type,
                    'company_id' => $companyId,
                    'parameters' => $parameters,
                    'raw_data' => $rawData,
                ]);

            if (!$response->successful()) {
                Log::error('Fleet analysis AI Service error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'analysis_type' => $analysis_type,
                ]);

                return json_encode([
                    'error' => true,
                    'message' => 'Error al ejecutar el análisis. Intenta de nuevo.',
                ], JSON_UNESCAPED_UNICODE);
            }

            $result = $response->json();

            if (($result['status'] ?? '') === 'error') {
                return json_encode([
                    'error' => true,
                    'message' => $result['error'] ?? 'Error desconocido en el análisis.',
                ], JSON_UNESCAPED_UNICODE);
            }

            // Construir respuesta con _cardData
            $cardData = $this->buildCardData($result);

            return json_encode([
                'analysis_type' => $result['analysis_type'] ?? $analysis_type,
                'title' => $result['title'] ?? 'Análisis',
                'summary' => $result['summary'] ?? '',
                'risk_level' => $result['risk_level'] ?? 'low',
                '_cardData' => $cardData,
                '_hint' => 'USA: :::fleetAnalysis\n{_cardData.fleetAnalysis}\n::: — NO describas los datos en texto, la card ya muestra todo. Solo agrega 1 línea de contexto antes de la card.',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            Log::error('RunFleetAnalysis error', [
                'error' => $e->getMessage(),
                'analysis_type' => $analysis_type,
            ]);

            return json_encode([
                'error' => true,
                'message' => 'Error al ejecutar el análisis: ' . $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Resuelve IDs de vehículo desde nombres o IDs proporcionados.
     */
    private function resolveVehicleIds(?string $vehicleIds, ?string $vehicleNames, int $companyId): array
    {
        $ids = [];

        if ($vehicleNames) {
            $result = $this->resolveVehicleNamesFlexible($vehicleNames);
            $ids = array_merge($ids, $result['vehicleIds'] ?? []);
        }

        if ($vehicleIds) {
            $directIds = array_map('trim', explode(',', $vehicleIds));
            $validIds = Vehicle::forCompany($companyId)
                ->whereIn('samsara_id', $directIds)
                ->pluck('samsara_id')
                ->toArray();
            $ids = array_merge($ids, $validIds);
        }

        return array_unique($ids);
    }

    /**
     * Recopila datos crudos de Samsara según el tipo de análisis.
     */
    private function gatherRawData(string $analysisType, array $vehicleIds, int $daysBack): array
    {
        $client = $this->createSamsaraClient();
        $rawData = [];
        $hoursBack = $daysBack * 24;
        $minutesBefore = $hoursBack * 60;

        switch ($analysisType) {
            case 'driver_risk_profile':
            case 'fleet_safety_overview':
            case 'anomaly_detection':
                // Obtener safety events
                try {
                    $events = $client->getRecentSafetyEventsStream(
                        $vehicleIds,
                        min($minutesBefore, 43200), // Max 30 days in minutes
                        [],
                        50, // Más eventos para análisis profundo
                    );
                    $rawData['safety_events'] = $events;
                } catch (\Exception $e) {
                    Log::warning('Failed to fetch safety events for analysis', ['error' => $e->getMessage()]);
                    $rawData['safety_events'] = ['data' => []];
                }
                break;

            case 'vehicle_health':
                // Obtener stats del vehículo
                if (!empty($vehicleIds)) {
                    try {
                        $stats = $client->getVehicleStats($vehicleIds, [
                            'gps', 'engineStates', 'fuelPercents', 'obdOdometerMeters',
                            'batteryMilliVolts', 'engineRpm', 'engineCoolantTemperatureMilliC',
                            'engineLoadPercent', 'ambientAirTemperatureMilliC', 'obdDtcCodes',
                        ]);
                        $rawData['vehicle_stats'] = $stats;
                    } catch (\Exception $e) {
                        Log::warning('Failed to fetch vehicle stats for analysis', ['error' => $e->getMessage()]);
                        $rawData['vehicle_stats'] = [];
                    }
                }
                break;

            case 'operational_efficiency':
                // Obtener viajes
                if (!empty($vehicleIds)) {
                    try {
                        $trips = $client->getRecentTrips($vehicleIds, min($hoursBack, 720), 50);
                        $rawData['trips'] = $trips;
                    } catch (\Exception $e) {
                        Log::warning('Failed to fetch trips for analysis', ['error' => $e->getMessage()]);
                        $rawData['trips'] = ['data' => []];
                    }
                }

                // También obtener stats para idle time
                if (!empty($vehicleIds)) {
                    try {
                        $stats = $client->getVehicleStats($vehicleIds, [
                            'gps', 'engineStates', 'fuelPercents',
                        ]);
                        $rawData['vehicle_stats'] = $stats;
                    } catch (\Exception $e) {
                        $rawData['vehicle_stats'] = [];
                    }
                }
                break;
        }

        return $rawData;
    }

    /**
     * Construye los datos para la rich card del frontend.
     */
    private function buildCardData(array $result): array
    {
        return [
            'fleetAnalysis' => [
                'analysisType' => $result['analysis_type'] ?? 'unknown',
                'title' => $result['title'] ?? 'Análisis',
                'summary' => $result['summary'] ?? '',
                'riskLevel' => $result['risk_level'] ?? 'low',
                'metrics' => $result['metrics'] ?? [],
                'findings' => $result['findings'] ?? [],
                'insights' => $result['insights'] ?? '',
                'recommendations' => $result['recommendations'] ?? [],
                'dataWindow' => $result['data_window'] ?? [],
                'methodology' => $result['methodology'] ?? '',
            ],
        ];
    }
}
