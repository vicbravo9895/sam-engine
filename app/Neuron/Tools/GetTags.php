<?php

declare(strict_types=1);

namespace App\Neuron\Tools;

use App\Models\Tag;
use App\Neuron\Tools\Concerns\UsesCompanyContext;
use Illuminate\Support\Facades\Cache;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class GetTags extends Tool
{
    use UsesCompanyContext;

    /**
     * Cache key for last sync timestamp (will be prefixed with company).
     */
    private const CACHE_KEY_LAST_SYNC = 'tags_last_sync';

    /**
     * Minimum time between API syncs (in seconds).
     * Default: 5 minutes.
     */
    private const SYNC_INTERVAL = 300;

    public function __construct()
    {
        parent::__construct(
            'GetTags',
            'Obtener las etiquetas (tags) de la organización. Las etiquetas se usan para agrupar y organizar vehículos, conductores y otros recursos. Devuelve información sobre cada tag incluyendo nombre, tag padre (si es jerárquico), y los recursos asociados (vehículos, conductores, etc.). Los datos se sincronizan automáticamente con la API de Samsara.'
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
                description: 'Término de búsqueda opcional para filtrar tags por nombre.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Número máximo de tags a devolver en el listado. Por defecto es 50.',
                required: false,
            ),
            new ToolProperty(
                name: 'include_hierarchy',
                type: PropertyType::BOOLEAN,
                description: 'Si es true, incluye información de la jerarquía (tags padres e hijos). Por defecto es false.',
                required: false,
            ),
            new ToolProperty(
                name: 'with_vehicles',
                type: PropertyType::BOOLEAN,
                description: 'Si es true, incluye solo tags que tienen vehículos asociados. Útil para ver cómo están agrupados los vehículos. Por defecto es false.',
                required: false,
            ),
        ];
    }

    /**
     * Maximum response size in characters to prevent context overflow.
     */
    private const MAX_RESPONSE_SIZE = 8000;

    public function __invoke(
        bool $force_sync = false,
        ?string $search = null,
        int $limit = 20,
        bool $include_hierarchy = false,
        bool $with_vehicles = false
    ): string {
        try {
            // Get company context for multi-tenant isolation
            $companyId = $this->getCompanyId();

            // Check if company has Samsara access
            if (!$this->hasSamsaraAccess()) {
                return $this->noSamsaraAccessResponse();
            }

            // Enforce reasonable limits to prevent context overflow
            $limit = min($limit, 30);

            // Check if we need to sync from API
            $shouldSync = $force_sync || $this->shouldSyncFromApi();

            if ($shouldSync) {
                $syncResult = $this->syncTagsFromApi();
            }

            // Fetch tags from database - FILTERED BY COMPANY
            $query = Tag::forCompany($companyId);

            if ($search) {
                $searchTerm = '%' . $search . '%';
                $query->where('name', 'like', $searchTerm);
            }

            // Filter by tags with vehicles if requested
            if ($with_vehicles) {
                $query->whereNotNull('vehicles')
                    ->whereRaw("json_array_length(vehicles::json) > 0");
            }

            // Get total count before limiting
            $totalCount = $query->count();

            // Build compact response
            $response = [
                'total_tags' => $totalCount,
                'showing' => 0,
            ];

            // Get limited tags for the listing
            $tags = $query->orderBy('name')->limit($limit)->get();

            $response['showing'] = $tags->count();
            
            // Build compact tag list (exclude vehicles by default to save space)
            $response['tags'] = $tags->map(function ($tag) use ($include_hierarchy, $with_vehicles) {
                $tagData = [
                    'id' => $tag->samsara_id,
                    'name' => $tag->name,
                    'vehicles' => $tag->vehicle_count ?? 0,
                    'drivers' => $tag->driver_count ?? 0,
                ];

                // Only include vehicle list if explicitly requested AND searching
                if ($with_vehicles && !empty($tag->vehicles)) {
                    // Only show first 5 vehicles to save space
                    $vehicleNames = array_slice(
                        array_map(fn($v) => $v['name'] ?? 'Sin nombre', $tag->vehicles),
                        0,
                        5
                    );
                    $tagData['vehicle_names'] = $vehicleNames;
                    if (count($tag->vehicles) > 5) {
                        $tagData['more_vehicles'] = count($tag->vehicles) - 5;
                    }
                }

                // Include hierarchy only if requested
                if ($include_hierarchy && $tag->parent_tag_id) {
                    $tagData['parent_id'] = $tag->parent_tag_id;
                }

                return $tagData;
            })->toArray();

            if ($totalCount > $limit) {
                $response['note'] = "Mostrando {$limit} de {$totalCount}. Usa 'search' para filtrar.";
            }

            // Generate response and check size
            $jsonResponse = json_encode($response, JSON_UNESCAPED_UNICODE);
            
            // If response is too large, truncate tags list
            if (strlen($jsonResponse) > self::MAX_RESPONSE_SIZE) {
                $truncatedLimit = max(5, intval($limit / 2));
                $response['tags'] = array_slice($response['tags'], 0, $truncatedLimit);
                $response['showing'] = count($response['tags']);
                $response['note'] = "Respuesta truncada a {$truncatedLimit} tags por límite de tamaño. Usa 'search' para filtrar.";
                $jsonResponse = json_encode($response, JSON_UNESCAPED_UNICODE);
            }

            return $jsonResponse;

        } catch (\Exception $e) {
            return json_encode([
                'error' => true,
                'message' => 'Error al obtener tags: ' . $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Get a summary of the tags.
     */
    protected function getTagSummary(): array
    {
        $companyId = $this->getCompanyId();
        $query = Tag::forCompany($companyId);
        
        $totalTags = (clone $query)->count();
        $rootTags = (clone $query)->where(function($q) {
            $q->whereNull('parent_tag_id')->orWhere('parent_tag_id', '');
        })->count();
        $tagsWithVehicles = (clone $query)->whereNotNull('vehicles')
            ->whereRaw("json_array_length(vehicles::json) > 0")
            ->count();
        $tagsWithDrivers = (clone $query)->whereNotNull('drivers')
            ->whereRaw("json_array_length(drivers::json) > 0")
            ->count();

        return [
            'total_tags' => $totalTags,
            'root_tags' => $rootTags,
            'child_tags' => $totalTags - $rootTags,
            'tags_with_vehicles' => $tagsWithVehicles,
            'tags_with_drivers' => $tagsWithDrivers,
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
     * Sync tags from Samsara API to database.
     */
    protected function syncTagsFromApi(): string
    {
        $companyId = $this->getCompanyId();
        $client = $this->createSamsaraClient();
        $tags = $client->getTags();

        $created = 0;
        $updated = 0;
        $unchanged = 0;

        foreach ($tags as $tagData) {
            $existingTag = Tag::forCompany($companyId)
                ->where('samsara_id', $tagData['id'])
                ->first();
            $dataHash = Tag::generateDataHash($tagData);

            if (!$existingTag) {
                // New tag - associate with company
                Tag::syncFromSamsara($tagData, $companyId);
                $created++;
            } elseif ($existingTag->data_hash !== $dataHash) {
                // Tag changed
                Tag::syncFromSamsara($tagData, $companyId);
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

