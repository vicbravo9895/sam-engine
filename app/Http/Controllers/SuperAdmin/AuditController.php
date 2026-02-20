<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\DomainEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditController extends Controller
{
    public function index(Request $request)
    {
        $query = DomainEvent::query()
            ->orderBy('occurred_at', 'desc');

        if ($companyId = $request->input('company_id')) {
            $query->where('company_id', $companyId);
        }

        if ($entityType = $request->input('entity_type')) {
            $query->where('entity_type', $entityType);
        }

        if ($eventType = $request->input('event_type')) {
            $query->where('event_type', $eventType);
        }

        if ($from = $request->input('from')) {
            $query->where('occurred_at', '>=', Carbon::parse($from)->startOfDay());
        }

        if ($to = $request->input('to')) {
            $query->where('occurred_at', '<=', Carbon::parse($to)->endOfDay());
        }

        $events = $query->paginate(50)->withQueryString();

        $events->getCollection()->transform(function ($event) {
            return [
                'id' => $event->id,
                'company_id' => $event->company_id,
                'occurred_at' => $event->occurred_at->toIso8601String(),
                'entity_type' => $event->entity_type,
                'entity_id' => $event->entity_id,
                'event_type' => $event->event_type,
                'actor_type' => $event->actor_type,
                'actor_id' => $event->actor_id,
                'payload' => $event->payload,
                'traceparent' => $event->traceparent,
            ];
        });

        $companies = Company::orderBy('name')->get(['id', 'name']);

        $entityTypes = DomainEvent::select('entity_type')
            ->distinct()
            ->pluck('entity_type');

        $eventTypes = DomainEvent::select('event_type')
            ->distinct()
            ->orderBy('event_type')
            ->pluck('event_type');

        return Inertia::render('super-admin/audit/index', [
            'events' => $events,
            'companies' => $companies,
            'entityTypes' => $entityTypes,
            'eventTypes' => $eventTypes,
            'filters' => $request->only(['company_id', 'entity_type', 'event_type', 'from', 'to']),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = DomainEvent::query()
            ->orderBy('occurred_at', 'desc');

        if ($companyId = $request->input('company_id')) {
            $query->where('company_id', $companyId);
        }

        if ($entityType = $request->input('entity_type')) {
            $query->where('entity_type', $entityType);
        }

        if ($eventType = $request->input('event_type')) {
            $query->where('event_type', $eventType);
        }

        if ($from = $request->input('from')) {
            $query->where('occurred_at', '>=', Carbon::parse($from)->startOfDay());
        }

        if ($to = $request->input('to')) {
            $query->where('occurred_at', '<=', Carbon::parse($to)->endOfDay());
        }

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Company ID', 'Occurred At', 'Entity Type', 'Entity ID', 'Event Type', 'Actor Type', 'Actor ID', 'Payload']);

            $query->chunk(500, function ($events) use ($handle) {
                foreach ($events as $event) {
                    fputcsv($handle, [
                        $event->id,
                        $event->company_id,
                        $event->occurred_at->toIso8601String(),
                        $event->entity_type,
                        $event->entity_id,
                        $event->event_type,
                        $event->actor_type,
                        $event->actor_id,
                        json_encode($event->payload),
                    ]);
                }
            });

            fclose($handle);
        }, 'audit-log-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }
}
