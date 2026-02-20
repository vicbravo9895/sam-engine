<?php

namespace App\Http\Controllers;

use App\Models\NotificationResult;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $filters = [
            'search' => trim((string) $request->input('search', '')),
            'channel' => (string) $request->input('channel', ''),
            'status' => (string) $request->input('status', ''),
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
        ];

        // Query notification results for this company's alerts
        $query = NotificationResult::query()
            ->whereHas('alert', fn ($q) => $q->where('company_id', $companyId))
            ->with(['deliveryEvents', 'alert.signal:id,vehicle_name,event_type'])
            ->latest('created_at');

        // Apply filters
        if ($filters['search'] !== '') {
            $term = '%' . mb_strtolower($filters['search']) . '%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(to_number) LIKE ?', [$term])
                    ->orWhereHas('alert', fn ($aq) =>
                        $aq->whereHas('signal', fn ($sq) => $sq->whereRaw('LOWER(vehicle_name) LIKE ?', [$term]))
                    );
            });
        }

        if ($filters['channel'] !== '') {
            $query->where('channel', $filters['channel']);
        }

        if ($filters['status'] !== '') {
            $query->where('status_current', $filters['status']);
        }

        if ($filters['date_from'] !== '') {
            try {
                $query->where('created_at', '>=', \Carbon\Carbon::parse($filters['date_from'])->startOfDay());
            } catch (\Throwable $e) {
            }
        }

        if ($filters['date_to'] !== '') {
            try {
                $query->where('created_at', '<=', \Carbon\Carbon::parse($filters['date_to'])->endOfDay());
            } catch (\Throwable $e) {
            }
        }

        $results = $query->paginate(25)->withQueryString();

        // Format results
        $results->through(function (NotificationResult $nr) {
            $signal = $nr->alert?->signal;

            return [
                'id' => $nr->id,
                'alert_id' => $nr->alert_id,
                'vehicle_name' => $signal?->vehicle_name,
                'event_type' => $signal?->event_type,
                'channel' => $nr->channel,
                'to_number' => $nr->to_number,
                'success' => $nr->success,
                'status_current' => $nr->status_current,
                'error' => $nr->error,
                'created_at' => $nr->created_at->toIso8601String(),
                'created_at_human' => $nr->created_at->diffForHumans(),
                'delivery_events' => $nr->deliveryEvents->map(fn ($de) => [
                    'id' => $de->id,
                    'status' => $de->status,
                    'received_at' => $de->received_at->toIso8601String(),
                    'error_message' => $de->error_message,
                ])->values(),
            ];
        });

        // Stats
        $statsBase = NotificationResult::query()
            ->whereHas('alert', fn ($q) => $q->where('company_id', $companyId));

        $total = (clone $statsBase)->count();
        $delivered = (clone $statsBase)->whereIn('status_current', ['delivered', 'read'])->count();
        $failed = (clone $statsBase)->where('success', false)->count();

        $byChannel = (clone $statsBase)
            ->selectRaw('channel, COUNT(*) as total, SUM(CASE WHEN status_current IN (\'delivered\', \'read\') THEN 1 ELSE 0 END) as delivered')
            ->groupBy('channel')
            ->get()
            ->map(fn ($row) => [
                'channel' => $row->channel,
                'total' => $row->total,
                'delivered' => (int) $row->delivered,
                'rate' => $row->total > 0 ? round(($row->delivered / $row->total) * 100, 1) : 0,
            ]);

        return Inertia::render('notifications/index', [
            'results' => $results,
            'filters' => $filters,
            'stats' => [
                'total' => $total,
                'delivered' => $delivered,
                'failed' => $failed,
                'deliverability_rate' => $total > 0 ? round(($delivered / $total) * 100, 1) : 0,
                'by_channel' => $byChannel,
            ],
        ]);
    }

    public function stats(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;
        $days = (int) $request->input('days', 14);
        $startDate = now()->subDays($days)->startOfDay();

        $daily = NotificationResult::query()
            ->whereHas('alert', fn ($q) => $q->where('company_id', $companyId))
            ->where('created_at', '>=', $startDate)
            ->selectRaw("DATE(created_at) as date, channel, COUNT(*) as total, SUM(CASE WHEN status_current IN ('delivered', 'read') THEN 1 ELSE 0 END) as delivered")
            ->groupBy('date', 'channel')
            ->orderBy('date')
            ->get();

        return response()->json(['daily' => $daily]);
    }
}
