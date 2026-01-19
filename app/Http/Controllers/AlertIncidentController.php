<?php

namespace App\Http\Controllers;

use App\Models\AlertIncident;
use App\Models\SamsaraEvent;
use App\Services\AlertCorrelationService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AlertIncidentController extends Controller
{
    public function __construct(
        protected AlertCorrelationService $correlationService
    ) {}

    /**
     * Display a listing of incidents.
     */
    public function index(Request $request)
    {
        $companyId = auth()->user()->company_id;
        $status = $request->input('status', 'all');
        $type = $request->input('type', 'all');

        $query = AlertIncident::forCompany($companyId)
            ->with(['primaryEvent', 'correlations.event'])
            ->orderByDesc('detected_at');

        // Filter by status
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        // Filter by type
        if ($type !== 'all') {
            $query->where('incident_type', $type);
        }

        $incidents = $query->paginate(20);

        // Transform for frontend
        $incidents->getCollection()->transform(function ($incident) {
            return [
                'id' => $incident->id,
                'incident_type' => $incident->incident_type,
                'type_label' => $incident->getTypeLabel(),
                'severity' => $incident->severity,
                'status' => $incident->status,
                'status_label' => $incident->getStatusLabel(),
                'detected_at' => $incident->detected_at->toIso8601String(),
                'resolved_at' => $incident->resolved_at?->toIso8601String(),
                'ai_summary' => $incident->ai_summary,
                'related_events_count' => $incident->correlations->count(),
                'primary_event' => $incident->primaryEvent ? [
                    'id' => $incident->primaryEvent->id,
                    'event_type' => $incident->primaryEvent->event_type,
                    'event_description' => $incident->primaryEvent->event_description,
                    'vehicle_name' => $incident->primaryEvent->vehicle_name,
                    'driver_name' => $incident->primaryEvent->driver_name,
                    'occurred_at' => $incident->primaryEvent->occurred_at->toIso8601String(),
                ] : null,
            ];
        });

        return Inertia::render('samsara/incidents/index', [
            'incidents' => $incidents,
            'filters' => [
                'status' => $status,
                'type' => $type,
            ],
            'statuses' => [
                'all' => 'Todos',
                AlertIncident::STATUS_OPEN => 'Abiertos',
                AlertIncident::STATUS_INVESTIGATING => 'En investigación',
                AlertIncident::STATUS_RESOLVED => 'Resueltos',
                AlertIncident::STATUS_FALSE_POSITIVE => 'Falsos positivos',
            ],
            'types' => [
                'all' => 'Todos',
                AlertIncident::TYPE_COLLISION => 'Colisión',
                AlertIncident::TYPE_EMERGENCY => 'Emergencia',
                AlertIncident::TYPE_PATTERN => 'Patrón',
                AlertIncident::TYPE_UNKNOWN => 'Desconocido',
            ],
        ]);
    }

    /**
     * Display a specific incident.
     */
    public function show(AlertIncident $incident)
    {
        // Ensure incident belongs to user's company
        $this->authorize('view', $incident);

        $incident->load(['primaryEvent', 'correlations.event', 'events']);

        // Transform related events
        $relatedEvents = $incident->correlations->map(function ($correlation) {
            $event = $correlation->event;
            return [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'event_description' => $event->event_description,
                'vehicle_name' => $event->vehicle_name,
                'driver_name' => $event->driver_name,
                'occurred_at' => $event->occurred_at->toIso8601String(),
                'severity' => $event->severity,
                'verdict' => $event->verdict,
                'ai_message' => $event->ai_message,
                'correlation' => [
                    'type' => $correlation->correlation_type,
                    'type_label' => $correlation->getTypeLabel(),
                    'strength' => $correlation->correlation_strength,
                    'time_delta' => $correlation->getTimeDeltaHuman(),
                    'time_delta_seconds' => $correlation->time_delta_seconds,
                ],
            ];
        });

        return Inertia::render('samsara/incidents/show', [
            'incident' => [
                'id' => $incident->id,
                'incident_type' => $incident->incident_type,
                'type_label' => $incident->getTypeLabel(),
                'severity' => $incident->severity,
                'status' => $incident->status,
                'status_label' => $incident->getStatusLabel(),
                'detected_at' => $incident->detected_at->toIso8601String(),
                'resolved_at' => $incident->resolved_at?->toIso8601String(),
                'ai_summary' => $incident->ai_summary,
                'metadata' => $incident->metadata,
                'primary_event' => $incident->primaryEvent ? [
                    'id' => $incident->primaryEvent->id,
                    'event_type' => $incident->primaryEvent->event_type,
                    'event_description' => $incident->primaryEvent->event_description,
                    'vehicle_id' => $incident->primaryEvent->vehicle_id,
                    'vehicle_name' => $incident->primaryEvent->vehicle_name,
                    'driver_id' => $incident->primaryEvent->driver_id,
                    'driver_name' => $incident->primaryEvent->driver_name,
                    'occurred_at' => $incident->primaryEvent->occurred_at->toIso8601String(),
                    'severity' => $incident->primaryEvent->severity,
                    'verdict' => $incident->primaryEvent->verdict,
                    'likelihood' => $incident->primaryEvent->likelihood,
                    'ai_message' => $incident->primaryEvent->ai_message,
                    'reasoning' => $incident->primaryEvent->reasoning,
                ] : null,
                'related_events' => $relatedEvents,
            ],
        ]);
    }

    /**
     * Update incident status.
     */
    public function updateStatus(Request $request, AlertIncident $incident)
    {
        $this->authorize('update', $incident);

        $validated = $request->validate([
            'status' => 'required|in:' . implode(',', [
                AlertIncident::STATUS_OPEN,
                AlertIncident::STATUS_INVESTIGATING,
                AlertIncident::STATUS_RESOLVED,
                AlertIncident::STATUS_FALSE_POSITIVE,
            ]),
            'summary' => 'nullable|string|max:1000',
        ]);

        $oldStatus = $incident->status;
        $newStatus = $validated['status'];

        if ($newStatus === AlertIncident::STATUS_RESOLVED) {
            $incident->markAsResolved($validated['summary'] ?? null);
        } elseif ($newStatus === AlertIncident::STATUS_FALSE_POSITIVE) {
            $incident->markAsFalsePositive($validated['summary'] ?? null);
        } else {
            $incident->update([
                'status' => $newStatus,
                'ai_summary' => $validated['summary'] ?? $incident->ai_summary,
            ]);
        }

        return back()->with('success', 'Estado del incidente actualizado');
    }

    /**
     * Get incidents for API.
     */
    public function apiIndex(Request $request)
    {
        $companyId = auth()->user()->company_id;

        $incidents = $this->correlationService->getOpenIncidents($companyId);

        return response()->json([
            'data' => $incidents->map(function ($incident) {
                return [
                    'id' => $incident->id,
                    'incident_type' => $incident->incident_type,
                    'type_label' => $incident->getTypeLabel(),
                    'severity' => $incident->severity,
                    'status' => $incident->status,
                    'status_label' => $incident->getStatusLabel(),
                    'detected_at' => $incident->detected_at->toIso8601String(),
                    'ai_summary' => $incident->ai_summary,
                    'related_events_count' => $incident->correlations->count(),
                    'primary_event' => $incident->primaryEvent ? [
                        'id' => $incident->primaryEvent->id,
                        'vehicle_name' => $incident->primaryEvent->vehicle_name,
                        'event_description' => $incident->primaryEvent->event_description,
                    ] : null,
                ];
            }),
        ]);
    }

    /**
     * Get incident summary for an event (API).
     */
    public function apiEventIncident(SamsaraEvent $event)
    {
        $this->authorize('view', $event);

        $summary = $this->correlationService->getIncidentSummary($event);

        return response()->json([
            'data' => $summary,
        ]);
    }
}
