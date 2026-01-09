<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use App\Models\Vehicle;
use App\Models\VehicleStat;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FleetReportController extends Controller
{
    /**
     * Display the fleet report with filters.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $companyId = $user->company_id;

        // Get available tags for the filter dropdown (only those with vehicles)
        $tags = Tag::forCompany($companyId)
            ->whereNotNull('vehicles')
            ->whereRaw("json_array_length(vehicles::json) > 0")
            ->orderBy('name')
            ->get()
            ->map(fn($tag) => [
                'id' => $tag->samsara_id,
                'name' => $tag->name,
                'vehicle_count' => $tag->vehicle_count,
            ]);

        // Build the query for vehicle stats
        $query = VehicleStat::forCompany($companyId)
            ->with('vehicle');

        // Filter by tag
        if ($request->filled('tag_id')) {
            $tag = Tag::forCompany($companyId)
                ->where('samsara_id', $request->tag_id)
                ->first();

            if ($tag && !empty($tag->vehicles)) {
                $vehicleIds = array_map(fn($v) => $v['id'] ?? null, $tag->vehicles);
                $vehicleIds = array_filter($vehicleIds);
                $query->whereIn('samsara_vehicle_id', $vehicleIds);
            }
        }

        // Search filter (name, license plate, or vehicle name)
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search, $companyId) {
                $q->where('vehicle_name', 'ilike', "%{$search}%")
                  ->orWhereHas('vehicle', function ($vq) use ($search) {
                      $vq->where('license_plate', 'ilike', "%{$search}%")
                         ->orWhere('name', 'ilike', "%{$search}%")
                         ->orWhere('serial', 'ilike', "%{$search}%");
                  });
            });
        }

        // Status filter
        if ($request->filled('status') && $request->status !== 'all') {
            if ($request->status === 'active') {
                $query->active();
            } elseif ($request->status === 'inactive') {
                $query->inactive();
            }
        }

        // Get counts for summary cards (before pagination)
        $baseQuery = VehicleStat::forCompany($companyId);
        
        // Apply tag filter to counts if selected
        if ($request->filled('tag_id')) {
            $tag = Tag::forCompany($companyId)
                ->where('samsara_id', $request->tag_id)
                ->first();

            if ($tag && !empty($tag->vehicles)) {
                $vehicleIds = array_map(fn($v) => $v['id'] ?? null, $tag->vehicles);
                $vehicleIds = array_filter($vehicleIds);
                $baseQuery->whereIn('samsara_vehicle_id', $vehicleIds);
            }
        }

        $totalCount = (clone $baseQuery)->count();
        $activeCount = (clone $baseQuery)->active()->count();
        $inactiveCount = (clone $baseQuery)->inactive()->count();

        // Get last sync time
        $lastSync = VehicleStat::forCompany($companyId)->max('synced_at');

        // Sorting
        $sortBy = $request->get('sort_by', 'engine_state');
        $sortDir = $request->get('sort_dir', 'asc');

        // Custom sorting for engine_state to show active first
        if ($sortBy === 'engine_state') {
            $query->orderByRaw("CASE WHEN engine_state = 'on' THEN 0 WHEN engine_state = 'idle' THEN 1 ELSE 2 END " . ($sortDir === 'desc' ? 'DESC' : 'ASC'));
            $query->orderByDesc('speed_kmh');
        } else {
            $query->orderBy($sortBy, $sortDir);
        }

        // Secondary sort by vehicle name for consistency
        $query->orderBy('vehicle_name');

        // Paginate results
        $vehicleStats = $query->paginate(25)->withQueryString();

        // Transform the data for the frontend
        $vehicleStats->getCollection()->transform(function ($stat) {
            $vehicle = $stat->vehicle;

            return [
                'id' => $stat->id,
                'samsara_id' => $stat->samsara_vehicle_id,
                'name' => $stat->vehicle_name ?? $vehicle?->name ?? 'Sin nombre',
                'license_plate' => $vehicle?->license_plate ?? null,
                'make' => $vehicle?->make ?? null,
                'model' => $vehicle?->model ?? null,
                'year' => $vehicle?->year ?? null,
                'serial' => $vehicle?->serial ?? null,
                'engine_state' => $stat->engine_state,
                'engine_state_label' => $stat->getEngineStateLabel(),
                'is_active' => $stat->isActive(),
                'is_moving' => $stat->isMoving(),
                'speed_kmh' => round((float) ($stat->speed_kmh ?? 0), 1),
                'latitude' => $stat->latitude,
                'longitude' => $stat->longitude,
                'location' => $stat->getFormattedLocation(),
                'is_geofence' => $stat->is_geofence,
                'odometer_km' => $stat->getOdometerKm(),
                'gps_time' => $stat->gps_time?->toIso8601String(),
                'synced_at' => $stat->synced_at?->toIso8601String(),
                'maps_link' => $stat->getMapsLink(),
            ];
        });

        return Inertia::render('fleet-report/index', [
            'vehicleStats' => $vehicleStats,
            'tags' => $tags,
            'summary' => [
                'total' => $totalCount,
                'active' => $activeCount,
                'inactive' => $inactiveCount,
                'lastSync' => $lastSync,
            ],
            'filters' => $request->only(['tag_id', 'search', 'status', 'sort_by', 'sort_dir']),
        ]);
    }
}
