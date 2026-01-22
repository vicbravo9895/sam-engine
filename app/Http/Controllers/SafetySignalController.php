<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SafetySignal;
use Illuminate\Http\Request;
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
}
