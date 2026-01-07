<?php

declare(strict_types=1);

namespace App\Neuron\Tools;

use App\Models\Driver;
use App\Models\Tag;
use App\Neuron\Tools\Concerns\UsesCompanyContext;
use Illuminate\Support\Facades\Cache;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class GetDrivers extends Tool
{
    use UsesCompanyContext;

    /**
     * Cache key for last sync timestamp (will be prefixed with company).
     */
    private const CACHE_KEY_LAST_SYNC = 'drivers_last_sync';

    public function __construct()
    {
        parent::__construct(
            'GetDrivers',
            'Obtener información de los conductores de la flota. Devuelve la lista de conductores con sus detalles como nombre, teléfono, licencia, estado de activación, vehículo asignado, etc. Puede filtrar por tags/grupos o por estado. Los datos se sincronizan automáticamente en segundo plano cada 5 minutos.'
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'search',
                type: PropertyType::STRING,
                description: 'Término de búsqueda opcional para filtrar conductores por nombre, teléfono, username o número de licencia.',
                required: false,
            ),
            new ToolProperty(
                name: 'tag_name',
                type: PropertyType::STRING,
                description: 'Nombre del tag/grupo para filtrar conductores. Se buscará el tag por nombre y se filtrarán los conductores asociados. Ejemplo: "LOCAL JC".',
                required: false,
            ),
            new ToolProperty(
                name: 'status',
                type: PropertyType::STRING,
                description: 'Filtrar por estado del conductor: "active" (activos), "deactivated" (desactivados), o "all" (todos). Por defecto es "active".',
                required: false,
            ),
            new ToolProperty(
                name: 'with_vehicle',
                type: PropertyType::BOOLEAN,
                description: 'Si es true, solo devuelve conductores que tienen un vehículo asignado. Por defecto es false.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Número máximo de conductores a devolver en el listado. Por defecto es 20.',
                required: false,
            ),
            new ToolProperty(
                name: 'summary_only',
                type: PropertyType::BOOLEAN,
                description: 'Si es true, solo devuelve el conteo total y estadísticas sin el listado detallado. Útil para responder preguntas como "¿cuántos conductores tengo?". Por defecto es false.',
                required: false,
            ),
        ];
    }

    public function __invoke(
        ?string $search = null,
        ?string $tag_name = null,
        string $status = 'active',
        bool $with_vehicle = false,
        int $limit = 20,
        bool $summary_only = false
    ): string {
        try {
            // Get company context for multi-tenant isolation
            $companyId = $this->getCompanyId();

            // Check if company has Samsara access
            if (!$this->hasSamsaraAccess()) {
                return $this->noSamsaraAccessResponse();
            }

            // Collect driver IDs from tags if filtering by tag
            $tagDriverIds = null;
            $tagInfo = null;

            if ($tag_name) {
                $tagDriverIds = $this->getDriverIdsFromTag($tag_name, $tagInfo);
                
                if (empty($tagDriverIds)) {
                    return json_encode([
                        'total_drivers' => 0,
                        'message' => "No se encontró el tag '{$tag_name}' o no tiene conductores asociados.",
                        'tag_info' => $tagInfo,
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                }
            }

            // Fetch drivers from database - FILTERED BY COMPANY
            $query = Driver::forCompany($companyId);

            // Filter by status
            if ($status === 'active') {
                $query->active();
            } elseif ($status === 'deactivated') {
                $query->where('is_deactivated', true);
            }
            // 'all' doesn't add any filter

            // Filter by tag driver IDs
            if ($tagDriverIds !== null) {
                $query->whereIn('samsara_id', $tagDriverIds);
            }

            // Filter by drivers with assigned vehicle
            if ($with_vehicle) {
                $query->whereNotNull('assigned_vehicle_samsara_id');
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $searchTerm = '%' . $search . '%';
                    $q->where('name', 'like', $searchTerm)
                        ->orWhere('phone', 'like', $searchTerm)
                        ->orWhere('username', 'like', $searchTerm)
                        ->orWhere('license_number', 'like', $searchTerm);
                });
            }

            // Get total count before limiting
            $totalCount = $query->count();

            // Build response
            $response = [
                'total_drivers' => $totalCount,
                'status_filter' => $status,
                'data_source' => 'Datos desde base de datos (sincronización automática: ' . $this->getLastSyncTime() . ')',
            ];

            // Add tag filter info if applicable
            if ($tagInfo) {
                $response['filtered_by_tag'] = $tagInfo;
            }

            // If summary_only, return just the count and statistics
            if ($summary_only) {
                $response['summary'] = $this->getDriverSummary($tagDriverIds);
                return json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }

            // Get limited drivers for the listing
            $drivers = $query->orderBy('name')->limit($limit)->get();

            $response['showing'] = $drivers->count();
            $response['limit'] = $limit;
            $response['drivers'] = $drivers->map(function ($driver) {
                $driverData = [
                    'id' => $driver->samsara_id,
                    'name' => $driver->name,
                    'phone' => $driver->phone,
                    'username' => $driver->username,
                    'license_number' => $driver->license_number,
                    'license_state' => $driver->license_state,
                    'status' => $driver->driver_activation_status,
                    'timezone' => $driver->timezone,
                ];

                // Include assigned vehicle info if available
                if ($driver->assigned_vehicle_samsara_id) {
                    $driverData['assigned_vehicle'] = [
                        'id' => $driver->assigned_vehicle_samsara_id,
                        'name' => $driver->static_assigned_vehicle['name'] ?? null,
                    ];
                }

                // Include carrier info if available
                if ($driver->carrier_settings) {
                    $driverData['carrier'] = $driver->carrier_settings['carrierName'] ?? null;
                }

                // Include tags summary
                if (!empty($driver->tags)) {
                    $driverData['tags'] = array_map(fn($t) => $t['name'] ?? null, array_slice($driver->tags, 0, 5));
                }

                return $driverData;
            })->toArray();

            if ($totalCount > $limit) {
                $response['note'] = "Mostrando {$limit} de {$totalCount} conductores. Usa el parámetro 'limit' para ver más o 'search' para filtrar.";
            }

            return json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            return json_encode([
                'error' => true,
                'message' => 'Error al obtener conductores: ' . $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Get driver IDs from a tag by name.
     * 
     * @param string $tagName Tag name to search
     * @param array|null &$tagInfo Output parameter with tag information
     * @return array|null Array of driver samsara IDs, or null if no filter
     */
    protected function getDriverIdsFromTag(string $tagName, ?array &$tagInfo): ?array
    {
        $driverIds = [];
        $tagInfo = [];
        $companyId = $this->getCompanyId();

        // Find tags by name - FILTERED BY COMPANY
        $tags = Tag::forCompany($companyId)->where('name', 'like', '%' . $tagName . '%')->get();
        
        foreach ($tags as $tag) {
            $tagInfo[] = [
                'id' => $tag->samsara_id,
                'name' => $tag->name,
                'driver_count' => $tag->driver_count,
            ];
            
            if (!empty($tag->drivers)) {
                foreach ($tag->drivers as $driver) {
                    if (isset($driver['id'])) {
                        $driverIds[] = $driver['id'];
                    }
                }
            }
        }

        return array_unique($driverIds);
    }

    /**
     * Get a summary of the drivers.
     * 
     * @param array|null $filterByIds Optional array of driver IDs to filter by
     */
    protected function getDriverSummary(?array $filterByIds = null): array
    {
        $companyId = $this->getCompanyId();
        $query = Driver::forCompany($companyId);
        
        if ($filterByIds !== null) {
            $query->whereIn('samsara_id', $filterByIds);
        }

        $totalDrivers = (clone $query)->count();
        $activeDrivers = (clone $query)->active()->count();
        $deactivatedDrivers = (clone $query)->where('is_deactivated', true)->count();
        $driversWithVehicle = (clone $query)->whereNotNull('assigned_vehicle_samsara_id')->count();
        $eldExemptDrivers = (clone $query)->where('eld_exempt', true)->count();

        // Get carrier distribution
        $byCarrier = (clone $query)
            ->whereNotNull('carrier_settings')
            ->get()
            ->groupBy(fn($d) => $d->carrier_settings['carrierName'] ?? 'Sin carrier')
            ->map(fn($group) => $group->count())
            ->sortDesc()
            ->take(5)
            ->toArray();

        // Get license state distribution
        $byLicenseState = (clone $query)
            ->selectRaw('license_state, COUNT(*) as count')
            ->whereNotNull('license_state')
            ->groupBy('license_state')
            ->orderByDesc('count')
            ->limit(5)
            ->pluck('count', 'license_state')
            ->toArray();

        return [
            'total_drivers' => $totalDrivers,
            'active_drivers' => $activeDrivers,
            'deactivated_drivers' => $deactivatedDrivers,
            'with_assigned_vehicle' => $driversWithVehicle,
            'without_assigned_vehicle' => $totalDrivers - $driversWithVehicle,
            'eld_exempt' => $eldExemptDrivers,
            'by_carrier' => $byCarrier,
            'by_license_state' => $byLicenseState,
        ];
    }

    /**
     * Get the last sync time as a human-readable string.
     */
    protected function getLastSyncTime(): string
    {
        $cacheKey = $this->companyCacheKey(self::CACHE_KEY_LAST_SYNC);
        $lastSync = Cache::get($cacheKey);

        if (!$lastSync) {
            return 'pendiente';
        }

        return \Carbon\Carbon::createFromTimestamp($lastSync)->diffForHumans();
    }
}


