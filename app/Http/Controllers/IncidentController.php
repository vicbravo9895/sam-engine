<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Incident;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class IncidentController extends Controller
{
    /**
     * Display a listing of incidents.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $query = Incident::forCompany($companyId)
            ->withCount('safetySignals')
            ->orderByPriority('asc')
            ->orderBy('detected_at', 'desc');

        // Apply filters
        if ($request->filled('status')) {
            $query->byStatus($request->input('status'));
        }

        if ($request->filled('priority')) {
            $query->byPriority($request->input('priority'));
        }

        if ($request->filled('type')) {
            $query->byType($request->input('type'));
        }

        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->input('subject_type'));
        }

        if ($request->filled('date_from')) {
            $query->where('detected_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('detected_at', '<=', $request->input('date_to'));
        }

        $incidents = $query->paginate(30)->through(fn (Incident $incident) => [
            'id' => $incident->id,
            'incident_type' => $incident->incident_type,
            'type_label' => $incident->getTypeLabel(),
            'priority' => $incident->priority,
            'priority_label' => $incident->getPriorityLabel(),
            'severity' => $incident->severity,
            'severity_label' => $incident->getSeverityLabel(),
            'status' => $incident->status,
            'status_label' => $incident->getStatusLabel(),
            'subject_type' => $incident->subject_type,
            'subject_id' => $incident->subject_id,
            'subject_name' => $incident->subject_name,
            'source' => $incident->source,
            'ai_summary' => $incident->ai_summary,
            'detected_at' => $incident->detected_at?->toIso8601String(),
            'detected_at_human' => $incident->detected_at?->diffForHumans(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
            'safety_signals_count' => $incident->safety_signals_count,
            'is_high_priority' => $incident->isHighPriority(),
            'is_resolved' => $incident->isResolved(),
        ]);

        // Get stats for the header
        $stats = [
            'total' => Incident::forCompany($companyId)->count(),
            'open' => Incident::forCompany($companyId)->open()->count(),
            'high_priority' => Incident::forCompany($companyId)->highPriority()->unresolved()->count(),
            'resolved_today' => Incident::forCompany($companyId)
                ->where('resolved_at', '>=', now()->startOfDay())
                ->count(),
        ];

        // Get counts by priority for quick view
        $priorityCounts = [
            'P1' => Incident::forCompany($companyId)->byPriority('P1')->unresolved()->count(),
            'P2' => Incident::forCompany($companyId)->byPriority('P2')->unresolved()->count(),
            'P3' => Incident::forCompany($companyId)->byPriority('P3')->unresolved()->count(),
            'P4' => Incident::forCompany($companyId)->byPriority('P4')->unresolved()->count(),
        ];

        return Inertia::render('incidents/index', [
            'incidents' => $incidents,
            'stats' => $stats,
            'priorityCounts' => $priorityCounts,
            'filters' => $request->only([
                'status', 'priority', 'type', 'subject_type', 'date_from', 'date_to'
            ]),
        ]);
    }

    /**
     * Display the specified incident.
     */
    public function show(Request $request, Incident $incident): Response
    {
        $user = $request->user();
        
        // Authorization check
        if ($incident->company_id !== $user->company_id) {
            abort(403);
        }

        $incident->load(['safetySignals']);

        $incidentData = [
            'id' => $incident->id,
            'incident_type' => $incident->incident_type,
            'type_label' => $incident->getTypeLabel(),
            'priority' => $incident->priority,
            'priority_label' => $incident->getPriorityLabel(),
            'severity' => $incident->severity,
            'severity_label' => $incident->getSeverityLabel(),
            'status' => $incident->status,
            'status_label' => $incident->getStatusLabel(),
            'subject_type' => $incident->subject_type,
            'subject_id' => $incident->subject_id,
            'subject_name' => $incident->subject_name,
            'source' => $incident->source,
            'samsara_event_id' => $incident->samsara_event_id,
            'dedupe_key' => $incident->dedupe_key,
            'ai_summary' => $incident->ai_summary,
            'ai_assessment' => $incident->ai_assessment,
            'metadata' => $incident->metadata,
            'detected_at' => $incident->detected_at?->toIso8601String(),
            'detected_at_human' => $incident->detected_at?->diffForHumans(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
            'resolved_at_human' => $incident->resolved_at?->diffForHumans(),
            'created_at' => $incident->created_at?->toIso8601String(),
            'is_high_priority' => $incident->isHighPriority(),
            'is_resolved' => $incident->isResolved(),
            'safety_signals' => $incident->safetySignals->map(fn ($signal) => [
                'id' => $signal->id,
                'samsara_event_id' => $signal->samsara_event_id,
                'vehicle_name' => $signal->vehicle_name,
                'driver_name' => $signal->driver_name,
                'primary_behavior_label' => $signal->primary_behavior_label,
                'primary_label_translated' => $signal->primary_label_translated,
                'severity' => $signal->severity,
                'severity_label' => $signal->severity_label,
                'address' => $signal->address,
                'occurred_at' => $signal->occurred_at?->toIso8601String(),
                'occurred_at_human' => $signal->occurred_at?->diffForHumans(),
                'pivot_role' => $signal->pivot->role,
                'pivot_relevance_score' => $signal->pivot->relevance_score,
            ]),
        ];

        return Inertia::render('incidents/show', [
            'incident' => $incidentData,
        ]);
    }

    /**
     * Update incident status.
     */
    public function updateStatus(Request $request, Incident $incident): RedirectResponse
    {
        $user = $request->user();
        
        if ($incident->company_id !== $user->company_id) {
            abort(403);
        }

        $validated = $request->validate([
            'status' => 'required|in:open,investigating,pending_action,resolved,false_positive',
        ]);

        if ($validated['status'] === 'resolved') {
            $incident->markAsResolved();
        } elseif ($validated['status'] === 'false_positive') {
            $incident->markAsFalsePositive();
        } else {
            $incident->update(['status' => $validated['status']]);
        }

        return back()->with('success', 'Estado actualizado correctamente.');
    }
}
