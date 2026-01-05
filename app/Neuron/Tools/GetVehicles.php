<?php

declare(strict_types=1);

namespace App\Neuron\Tools;

use App\Models\Tag;
use App\Models\Vehicle;
use App\Neuron\Tools\Concerns\UsesCompanyContext;
use Illuminate\Support\Facades\Cache;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class GetVehicles extends Tool
{
    use UsesCompanyContext;

    /**
     * Cache key for last sync timestamp (will be prefixed with company).
     */
    private const CACHE_KEY_LAST_SYNC = 'vehicles_last_sync';

    /**
     * Minimum time between API syncs (in seconds).
     * Default: 5 minutes.
     */
    private const SYNC_INTERVAL = 300;

    public function __construct()
    {
        parent::__construct(
            'GetVehicles',
            'Obtener información de los vehículos de la flota. Devuelve la lista completa de vehículos con sus detalles como nombre, VIN, marca, modelo, matrícula, etc. Puede filtrar por tags/grupos. Los datos se sincronizan automáticamente con la API de Samsara cuando es necesario.'
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'force_sync',
                type: PropertyType::BOOLEAN,
                description: 'Forzar sincronización con la API de Samsara aunque los datos estén actualizados. Por defecto es false.',
                required: false,
            ),
            new ToolProperty(
                name: 'search',
                type: PropertyType::STRING,
                description: 'Término de búsqueda opcional para filtrar vehículos por nombre, VIN, matrícula, marca o modelo.',
                required: false,
            ),
            new ToolProperty(
                name: 'tag_ids',
                type: PropertyType::STRING,
                description: 'IDs de tags separados por coma para filtrar vehículos que pertenecen a esos grupos/tags. Ejemplo: "1234,5678". Obtén los tag IDs usando GetTags primero.',
                required: false,
            ),
            new ToolProperty(
                name: 'tag_name',
                type: PropertyType::STRING,
                description: 'Nombre del tag/grupo para filtrar vehículos. Se buscará el tag por nombre y se filtrarán los vehículos asociados. Ejemplo: "FORANEO JC".',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Número máximo de vehículos a devolver en el listado. Por defecto es 20. Usa un número mayor solo si el usuario lo pide explícitamente.',
                required: false,
            ),
            new ToolProperty(
                name: 'summary_only',
                type: PropertyType::BOOLEAN,
                description: 'Si es true, solo devuelve el conteo total sin el listado detallado. Útil para responder preguntas como "¿cuántos vehículos tengo?". Por defecto es false.',
                required: false,
            ),
        ];
    }

    public function __invoke(
        bool $force_sync = false,
        ?string $search = null,
        ?string $tag_ids = null,
        ?string $tag_name = null,
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

            // Check if we need to sync from API
            $shouldSync = $force_sync || $this->shouldSyncFromApi();

            if ($shouldSync) {
                $syncResult = $this->syncVehiclesFromApi();
            }

            // Collect vehicle IDs from tags if filtering by tag
            $tagVehicleIds = null;
            $tagInfo = null;

            if ($tag_ids || $tag_name) {
                $tagVehicleIds = $this->getVehicleIdsFromTags($tag_ids, $tag_name, $tagInfo);
                
                if (empty($tagVehicleIds)) {
                    return json_encode([
                        'total_vehicles' => 0,
                        'message' => $tag_name 
                            ? "No se encontró el tag '{$tag_name}' o no tiene vehículos asociados."
                            : "No se encontraron vehículos para los tags especificados.",
                        'tag_info' => $tagInfo,
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                }
            }

            // Fetch vehicles from database - FILTERED BY COMPANY
            $query = Vehicle::forCompany($companyId);

            // Filter by tag vehicle IDs
            if ($tagVehicleIds !== null) {
                $query->whereIn('samsara_id', $tagVehicleIds);
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    // Direct search
                    $searchTerm = '%' . $search . '%';
                    $q->where('name', 'like', $searchTerm)
                        ->orWhere('vin', 'like', $searchTerm)
                        ->orWhere('license_plate', 'like', $searchTerm)
                        ->orWhere('make', 'like', $searchTerm)
                        ->orWhere('model', 'like', $searchTerm);
                    
                    // If search contains numbers, also search for those numbers anywhere in the name
                    // This allows "606" to match "T-606", "TR-606", "Camion606", etc.
                    preg_match_all('/\d+/', $search, $matches);
                    if (!empty($matches[0])) {
                        foreach ($matches[0] as $number) {
                            $q->orWhere('name', 'like', '%' . $number . '%');
                        }
                    }
                });
            }

            // Get total count before limiting
            $totalCount = $query->count();

            // Build response
            $response = [
                'total_vehicles' => $totalCount,
                'sync_status' => $shouldSync
                    ? ($syncResult ?? 'Sincronizado')
                    : 'Datos desde caché (última sincronización: ' . $this->getLastSyncTime() . ')',
            ];

            // Add tag filter info if applicable
            if ($tagInfo) {
                $response['filtered_by_tag'] = $tagInfo;
            }

            // If summary_only, return just the count and statistics
            if ($summary_only) {
                $response['summary'] = $this->getVehicleSummary($tagVehicleIds);
                return json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }

            // Get limited vehicles for the listing
            $vehicles = $query->orderBy('name')->limit($limit)->get();

            $response['showing'] = $vehicles->count();
            $response['limit'] = $limit;
            $response['vehicles'] = $vehicles->map(function ($vehicle) {
                return [
                    'id' => $vehicle->samsara_id,
                    'name' => $vehicle->name,
                    'vin' => $vehicle->vin,
                    'license_plate' => $vehicle->license_plate,
                    'make' => $vehicle->make,
                    'model' => $vehicle->model,
                    'year' => $vehicle->year,
                    'vehicle_type' => $vehicle->vehicle_type,
                ];
            })->toArray();

            if ($totalCount > $limit) {
                $response['note'] = "Mostrando {$limit} de {$totalCount} vehículos. Usa el parámetro 'limit' para ver más o 'search' para filtrar.";
            }

            return json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            return json_encode([
                'error' => true,
                'message' => 'Error al obtener vehículos: ' . $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Get vehicle IDs from tags.
     * 
     * @param string|null $tagIds Comma-separated tag IDs
     * @param string|null $tagName Tag name to search
     * @param array|null &$tagInfo Output parameter with tag information
     * @return array|null Array of vehicle samsara IDs, or null if no filter
     */
    protected function getVehicleIdsFromTags(?string $tagIds, ?string $tagName, ?array &$tagInfo): ?array
    {
        $vehicleIds = [];
        $tagInfo = [];
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

        // Find tags by name - FILTERED BY COMPANY
        if ($tagName) {
            $tags = Tag::forCompany($companyId)->where('name', 'like', '%' . $tagName . '%')->get();
            
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

        return array_unique($vehicleIds);
    }

    /**
     * Get a summary of the vehicle fleet.
     * 
     * @param array|null $filterByIds Optional array of vehicle IDs to filter by
     */
    protected function getVehicleSummary(?array $filterByIds = null): array
    {
        $companyId = $this->getCompanyId();
        $query = Vehicle::forCompany($companyId);
        
        if ($filterByIds !== null) {
            $query->whereIn('samsara_id', $filterByIds);
        }

        return [
            'by_make' => (clone $query)->selectRaw('make, COUNT(*) as count')
                ->whereNotNull('make')
                ->groupBy('make')
                ->orderByDesc('count')
                ->limit(10)
                ->pluck('count', 'make')
                ->toArray(),
            'by_year' => (clone $query)->selectRaw('year, COUNT(*) as count')
                ->whereNotNull('year')
                ->groupBy('year')
                ->orderByDesc('year')
                ->limit(10)
                ->pluck('count', 'year')
                ->toArray(),
        ];
    }

    /**
     * Check if we should sync from the API based on the last sync time.
     */
    protected function shouldSyncFromApi(): bool
    {
        $cacheKey = $this->companyCacheKey(self::CACHE_KEY_LAST_SYNC);
        $lastSync = Cache::get($cacheKey);

        if (!$lastSync) {
            return true;
        }

        return (time() - $lastSync) > self::SYNC_INTERVAL;
    }

    /**
     * Get the last sync time as a human-readable string.
     */
    protected function getLastSyncTime(): string
    {
        $cacheKey = $this->companyCacheKey(self::CACHE_KEY_LAST_SYNC);
        $lastSync = Cache::get($cacheKey);

        if (!$lastSync) {
            return 'nunca';
        }

        return \Carbon\Carbon::createFromTimestamp($lastSync)->diffForHumans();
    }

    /**
     * Sync vehicles from Samsara API to database.
     */
    protected function syncVehiclesFromApi(): string
    {
        $companyId = $this->getCompanyId();
        $client = $this->createSamsaraClient();
        $vehicles = $client->getVehicles();

        $created = 0;
        $updated = 0;
        $unchanged = 0;

        foreach ($vehicles as $vehicleData) {
            $existingVehicle = Vehicle::forCompany($companyId)
                ->where('samsara_id', $vehicleData['id'])
                ->first();
            $dataHash = Vehicle::generateDataHash($vehicleData);

            if (!$existingVehicle) {
                // New vehicle - associate with company
                Vehicle::syncFromSamsara($vehicleData, $companyId);
                $created++;
            } elseif ($existingVehicle->data_hash !== $dataHash) {
                // Vehicle changed
                Vehicle::syncFromSamsara($vehicleData, $companyId);
                $updated++;
            } else {
                // No changes
                $unchanged++;
            }
        }

        // Update last sync timestamp (company-specific)
        $cacheKey = $this->companyCacheKey(self::CACHE_KEY_LAST_SYNC);
        Cache::put($cacheKey, time());

        return "Sincronización completada: {$created} creados, {$updated} actualizados, {$unchanged} sin cambios.";
    }
}

