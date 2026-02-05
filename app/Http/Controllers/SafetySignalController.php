<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SafetySignal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class SafetySignalController extends Controller
{
    /**
     * Display a listing of safety signals.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $query = SafetySignal::forCompany($companyId)
            ->orderBy('occurred_at', 'desc');

        // Apply filters
        if ($request->filled('severity')) {
            $query->where('severity', $request->input('severity'));
        }

        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->input('vehicle_id'));
        }

        if ($request->filled('driver_id')) {
            $query->where('driver_id', $request->input('driver_id'));
        }

        if ($request->filled('behavior')) {
            $query->where('primary_behavior_label', 'ilike', '%' . $request->input('behavior') . '%');
        }

        if ($request->filled('event_state')) {
            $query->where('event_state', $request->input('event_state'));
        }

        if ($request->filled('date_from')) {
            $query->where('occurred_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('occurred_at', '<=', $request->input('date_to'));
        }

        $signals = $query->paginate(50)->through(fn (SafetySignal $signal) => [
            'id' => $signal->id,
            'samsara_event_id' => $signal->samsara_event_id,
            'vehicle_id' => $signal->vehicle_id,
            'vehicle_name' => $signal->vehicle_name,
            'driver_id' => $signal->driver_id,
            'driver_name' => $signal->driver_name,
            'primary_behavior_label' => $signal->primary_behavior_label,
            'primary_label_translated' => $signal->primary_label_translated,
            'behavior_labels' => $signal->behavior_labels,
            'severity' => $signal->severity,
            'severity_label' => $signal->severity_label,
            'event_state' => $signal->event_state,
            'event_state_translated' => $signal->event_state_translated,
            'address' => $signal->address,
            'latitude' => $signal->latitude,
            'longitude' => $signal->longitude,
            'max_acceleration_g' => $signal->max_acceleration_g,
            'media_urls' => $signal->media_urls,
            'inbox_event_url' => $signal->inbox_event_url,
            'occurred_at' => $signal->occurred_at?->toIso8601String(),
            'occurred_at_human' => $signal->occurred_at?->diffForHumans(),
            'created_at' => $signal->created_at?->toIso8601String(),
            'used_in_evidence' => $signal->incidents()->exists(),
        ]);

        // Get stats for the header
        $stats = [
            'total' => SafetySignal::forCompany($companyId)->count(),
            'critical' => SafetySignal::forCompany($companyId)->critical()->count(),
            'needs_review' => SafetySignal::forCompany($companyId)->needsReview()->count(),
            'today' => SafetySignal::forCompany($companyId)
                ->where('occurred_at', '>=', now()->startOfDay())
                ->count(),
        ];

        return Inertia::render('safety-signals/index', [
            'signals' => $signals,
            'stats' => $stats,
            'filters' => $request->only([
                'severity', 'vehicle_id', 'driver_id', 'behavior', 
                'event_state', 'date_from', 'date_to'
            ]),
        ]);
    }

    /**
     * Display the specified safety signal.
     */
    public function show(Request $request, SafetySignal $safetySignal): Response
    {
        $user = $request->user();
        
        // Authorization check
        if ($safetySignal->company_id !== $user->company_id) {
            abort(403);
        }

        $signal = [
            'id' => $safetySignal->id,
            'samsara_event_id' => $safetySignal->samsara_event_id,
            'vehicle_id' => $safetySignal->vehicle_id,
            'vehicle_name' => $safetySignal->vehicle_name,
            'driver_id' => $safetySignal->driver_id,
            'driver_name' => $safetySignal->driver_name,
            'primary_behavior_label' => $safetySignal->primary_behavior_label,
            'primary_label_translated' => $safetySignal->primary_label_translated,
            'primary_label_data' => $safetySignal->primary_label_data,
            'behavior_labels' => $safetySignal->behavior_labels,
            'behavior_labels_translated' => $safetySignal->behavior_labels_translated,
            'context_labels' => $safetySignal->context_labels,
            'severity' => $safetySignal->severity,
            'severity_label' => $safetySignal->severity_label,
            'event_state' => $safetySignal->event_state,
            'event_state_translated' => $safetySignal->event_state_translated,
            'address' => $safetySignal->address,
            'latitude' => $safetySignal->latitude,
            'longitude' => $safetySignal->longitude,
            'max_acceleration_g' => $safetySignal->max_acceleration_g,
            'speeding_metadata' => $safetySignal->speeding_metadata,
            'media_urls' => $safetySignal->media_urls,
            'inbox_event_url' => $safetySignal->inbox_event_url,
            'incident_report_url' => $safetySignal->incident_report_url,
            'occurred_at' => $safetySignal->occurred_at?->toIso8601String(),
            'occurred_at_human' => $safetySignal->occurred_at?->diffForHumans(),
            'samsara_created_at' => $safetySignal->samsara_created_at?->toIso8601String(),
            'created_at' => $safetySignal->created_at?->toIso8601String(),
            'incidents' => $safetySignal->incidents->map(fn ($incident) => [
                'id' => $incident->id,
                'incident_type' => $incident->incident_type,
                'type_label' => $incident->getTypeLabel(),
                'priority' => $incident->priority,
                'status' => $incident->status,
                'pivot_role' => $incident->pivot->role,
            ]),
        ];

        return Inertia::render('safety-signals/show', [
            'signal' => $signal,
        ]);
    }

    /**
     * Get analytics data for safety signals.
     */
    public function analytics(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $companyId = $user->company_id;
        $days = (int) $request->input('days', 30);
        $startDate = now()->subDays($days)->startOfDay();
        
        // Cache key based on company and period
        $cacheKey = "safety_signals_analytics:{$companyId}:{$days}";
        
        $data = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($companyId, $days, $startDate) {
            return $this->computeAnalytics($companyId, $days, $startDate);
        });
        
        return response()->json($data);
    }

    /**
     * Compute analytics data for safety signals.
     */
    private function computeAnalytics(int $companyId, int $days, $startDate): array
    {
        // 1. Signals by behavior (top 10)
        $signalsByBehavior = SafetySignal::forCompany($companyId)
            ->where('occurred_at', '>=', $startDate)
            ->whereNotNull('primary_behavior_label')
            ->selectRaw('primary_behavior_label, COUNT(*) as count')
            ->groupBy('primary_behavior_label')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'label' => $row->primary_behavior_label,
                'label_translated' => \App\Services\BehaviorLabelTranslator::getName($row->primary_behavior_label),
                'value' => $row->count,
            ]);
        
        // 2. Top vehicles with most signals
        $topVehicles = SafetySignal::forCompany($companyId)
            ->where('occurred_at', '>=', $startDate)
            ->whereNotNull('vehicle_name')
            ->selectRaw('vehicle_id, vehicle_name, COUNT(*) as count')
            ->groupBy('vehicle_id', 'vehicle_name')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'id' => $row->vehicle_id,
                'name' => $row->vehicle_name,
                'count' => $row->count,
            ]);
        
        // 3. Top drivers with most signals
        $topDrivers = SafetySignal::forCompany($companyId)
            ->where('occurred_at', '>=', $startDate)
            ->whereNotNull('driver_name')
            ->where('driver_name', '!=', '')
            ->selectRaw('driver_id, driver_name, COUNT(*) as count')
            ->groupBy('driver_id', 'driver_name')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'id' => $row->driver_id,
                'name' => $row->driver_name,
                'count' => $row->count,
            ]);
        
        // 4. Signals by severity
        $signalsBySeverity = SafetySignal::forCompany($companyId)
            ->where('occurred_at', '>=', $startDate)
            ->selectRaw('severity, COUNT(*) as count')
            ->groupBy('severity')
            ->get()
            ->mapWithKeys(fn($row) => [
                $row->severity => $row->count,
            ]);
        
        // 5. Signals by event state (coaching funnel)
        $signalsByState = SafetySignal::forCompany($companyId)
            ->where('occurred_at', '>=', $startDate)
            ->whereNotNull('event_state')
            ->selectRaw('event_state, COUNT(*) as count')
            ->groupBy('event_state')
            ->orderByDesc('count')
            ->get()
            ->map(fn($row) => [
                'state' => $row->event_state,
                'label' => \App\Services\BehaviorLabelTranslator::getStateName($row->event_state),
                'count' => $row->count,
            ]);
        
        // 6. Signals by day (trend)
        $signalsByDay = SafetySignal::forCompany($companyId)
            ->where('occurred_at', '>=', $startDate)
            ->selectRaw("DATE(occurred_at) as date, COUNT(*) as count")
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn($row) => [
                'date' => $row->date,
                'count' => $row->count,
            ]);
        
        // 7. Signals by hour of day (pattern)
        $signalsByHour = SafetySignal::forCompany($companyId)
            ->where('occurred_at', '>=', $startDate)
            ->selectRaw("EXTRACT(HOUR FROM occurred_at) as hour, COUNT(*) as count")
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->map(fn($row) => [
                'hour' => (int) $row->hour,
                'count' => $row->count,
            ]);

        // 8. Signals by day of week
        $signalsByDayOfWeek = SafetySignal::forCompany($companyId)
            ->where('occurred_at', '>=', $startDate)
            ->selectRaw("EXTRACT(DOW FROM occurred_at) as dow, COUNT(*) as count")
            ->groupBy('dow')
            ->orderBy('dow')
            ->get()
            ->map(fn($row) => [
                'day' => (int) $row->dow,
                'day_name' => $this->dayOfWeekName((int) $row->dow),
                'count' => $row->count,
            ]);

        // 9. Average acceleration by behavior
        $avgAccelerationByBehavior = SafetySignal::forCompany($companyId)
            ->where('occurred_at', '>=', $startDate)
            ->whereNotNull('primary_behavior_label')
            ->whereNotNull('max_acceleration_g')
            ->selectRaw('primary_behavior_label, AVG(max_acceleration_g) as avg_g, MAX(max_acceleration_g) as max_g, COUNT(*) as count')
            ->groupBy('primary_behavior_label')
            ->orderByDesc('avg_g')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'behavior' => $row->primary_behavior_label,
                'behavior_translated' => \App\Services\BehaviorLabelTranslator::getName($row->primary_behavior_label),
                'avg_g' => round((float) $row->avg_g, 3),
                'max_g' => round((float) $row->max_g, 3),
                'count' => $row->count,
            ]);
        
        // 10. Summary stats
        $totalSignals = SafetySignal::forCompany($companyId)
            ->where('occurred_at', '>=', $startDate)
            ->count();
        
        $criticalCount = SafetySignal::forCompany($companyId)
            ->where('occurred_at', '>=', $startDate)
            ->critical()
            ->count();

        $needsReviewCount = SafetySignal::forCompany($companyId)
            ->where('occurred_at', '>=', $startDate)
            ->needsReview()
            ->count();
        
        $coachedCount = SafetySignal::forCompany($companyId)
            ->where('occurred_at', '>=', $startDate)
            ->where('event_state', SafetySignal::STATE_COACHED)
            ->count();
        
        $dismissedCount = SafetySignal::forCompany($companyId)
            ->where('occurred_at', '>=', $startDate)
            ->where('event_state', SafetySignal::STATE_DISMISSED)
            ->count();

        $linkedToIncidents = SafetySignal::forCompany($companyId)
            ->where('occurred_at', '>=', $startDate)
            ->whereHas('incidents')
            ->count();
        
        // 11. Unique drivers and vehicles
        $uniqueDrivers = SafetySignal::forCompany($companyId)
            ->where('occurred_at', '>=', $startDate)
            ->whereNotNull('driver_id')
            ->distinct('driver_id')
            ->count('driver_id');
        
        $uniqueVehicles = SafetySignal::forCompany($companyId)
            ->where('occurred_at', '>=', $startDate)
            ->whereNotNull('vehicle_id')
            ->distinct('vehicle_id')
            ->count('vehicle_id');
        
        return [
            'period_days' => $days,
            'period_start' => $startDate->toIso8601String(),
            'summary' => [
                'total_signals' => $totalSignals,
                'critical' => $criticalCount,
                'critical_rate' => $totalSignals > 0 
                    ? round(($criticalCount / $totalSignals) * 100, 1) 
                    : 0,
                'needs_review' => $needsReviewCount,
                'coached' => $coachedCount,
                'coached_rate' => $totalSignals > 0 
                    ? round(($coachedCount / $totalSignals) * 100, 1) 
                    : 0,
                'dismissed' => $dismissedCount,
                'linked_to_incidents' => $linkedToIncidents,
                'unique_drivers' => $uniqueDrivers,
                'unique_vehicles' => $uniqueVehicles,
                'avg_daily' => $days > 0 ? round($totalSignals / $days, 1) : 0,
            ],
            'signals_by_behavior' => $signalsByBehavior,
            'top_vehicles' => $topVehicles,
            'top_drivers' => $topDrivers,
            'signals_by_severity' => $signalsBySeverity,
            'signals_by_state' => $signalsByState,
            'signals_by_day' => $signalsByDay,
            'signals_by_hour' => $signalsByHour,
            'signals_by_day_of_week' => $signalsByDayOfWeek,
            'avg_acceleration_by_behavior' => $avgAccelerationByBehavior,
        ];
    }

    /**
     * Get day of week name in Spanish.
     */
    private function dayOfWeekName(int $dow): string
    {
        return match ($dow) {
            0 => 'Domingo',
            1 => 'Lunes',
            2 => 'Martes',
            3 => 'Miércoles',
            4 => 'Jueves',
            5 => 'Viernes',
            6 => 'Sábado',
            default => 'Desconocido',
        };
    }

    /**
     * Get advanced analytics from Python AI Service.
     * 
     * This endpoint calls the Python analytics engine for:
     * - Pattern detection (correlations, hotspots)
     * - Driver risk scoring
     * - Incident predictions
     * - AI-generated insights
     */
    public function advancedAnalytics(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $companyId = $user->company_id;
        $days = (int) $request->input('days', 30);
        $startDate = now()->subDays($days)->startOfDay();
        
        // Cache key for advanced analytics (longer cache since it's expensive)
        $cacheKey = "safety_signals_advanced_analytics:{$companyId}:{$days}";
        
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return response()->json($cached);
        }
        
        // Get signals for the period
        $signals = SafetySignal::forCompany($companyId)
            ->where('occurred_at', '>=', $startDate)
            ->get()
            ->map(fn(SafetySignal $signal) => [
                'id' => $signal->id,
                'driver_id' => $signal->driver_id,
                'driver_name' => $signal->driver_name,
                'vehicle_id' => $signal->vehicle_id,
                'vehicle_name' => $signal->vehicle_name,
                'primary_behavior_label' => $signal->primary_behavior_label,
                'behavior_labels' => collect($signal->behavior_labels ?? [])
                    ->pluck('label')
                    ->filter()
                    ->values()
                    ->toArray(),
                'severity' => $signal->severity,
                'event_state' => $signal->event_state,
                'max_acceleration_g' => $signal->max_acceleration_g,
                'latitude' => $signal->latitude,
                'longitude' => $signal->longitude,
                'occurred_at' => $signal->occurred_at?->toIso8601String(),
            ])
            ->toArray();
        
        if (empty($signals)) {
            return response()->json([
                'patterns' => null,
                'driver_risk' => null,
                'predictions' => null,
                'insights' => null,
                'processing_time_ms' => 0,
                'errors' => ['No signals found for the specified period'],
            ]);
        }
        
        // Call Python AI Service
        $aiServiceUrl = config('services.ai_engine.url');
        
        try {
            $response = Http::timeout(60)
                ->post("{$aiServiceUrl}/analytics/signals", [
                    'company_id' => $companyId,
                    'signals' => $signals,
                    'period_days' => $days,
                    'include_patterns' => true,
                    'include_risk_scores' => true,
                    'include_predictions' => true,
                    'include_insights' => true,
                ]);
            
            if ($response->failed()) {
                Log::warning('AI Service analytics call failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                
                return response()->json([
                    'patterns' => null,
                    'driver_risk' => null,
                    'predictions' => null,
                    'insights' => null,
                    'processing_time_ms' => 0,
                    'errors' => ['AI Service returned error: ' . $response->status()],
                ], 200); // Return 200 with errors so frontend can handle gracefully
            }
            
            $data = $response->json();
            
            // Cache the result for 15 minutes
            Cache::put($cacheKey, $data, now()->addMinutes(15));
            
            return response()->json($data);
            
        } catch (\Exception $e) {
            Log::error('AI Service analytics call exception', [
                'error' => $e->getMessage(),
                'company_id' => $companyId,
            ]);
            
            return response()->json([
                'patterns' => null,
                'driver_risk' => null,
                'predictions' => null,
                'insights' => null,
                'processing_time_ms' => 0,
                'errors' => ['AI Service unavailable: ' . $e->getMessage()],
            ], 200);
        }
    }
}
