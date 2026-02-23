<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\AlertMetrics;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\NotificationResult;
use App\Models\Signal;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $isSuperAdmin = $user->isSuperAdmin();
        $today = Carbon::today();
        $lastWeek = Carbon::now()->subDays(7);
        $lastMonth = Carbon::now()->subDays(30);
        $last14Days = Carbon::now()->subDays(14);

        $companyIdFilter = $isSuperAdmin ? null : $user->company_id;

        $alertsBaseQuery = Alert::query();
        if ($companyIdFilter !== null) {
            $alertsBaseQuery->forCompany($companyIdFilter);
        }

        // Single aggregated query for all samsaraStats counts (avoids 10 separate COUNT queries)
        $samsaraCounts = (clone $alertsBaseQuery)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN DATE(created_at) = ? THEN 1 ELSE 0 END) as today,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as this_week,
                SUM(CASE WHEN severity = ? THEN 1 ELSE 0 END) as critical,
                SUM(CASE WHEN ai_status = ? THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN ai_status = ? THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN ai_status = ? THEN 1 ELSE 0 END) as investigating,
                SUM(CASE WHEN ai_status = ? THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN ai_status = ? THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN human_status = ? AND (ai_status IN (?, ?) OR severity = ? OR risk_escalation IN (?, ?)) THEN 1 ELSE 0 END) as needs_human_attention
            ", [
                $today->format('Y-m-d'),
                $lastWeek,
                Alert::SEVERITY_CRITICAL,
                Alert::STATUS_PENDING,
                Alert::STATUS_PROCESSING,
                Alert::STATUS_INVESTIGATING,
                Alert::STATUS_COMPLETED,
                Alert::STATUS_FAILED,
                Alert::HUMAN_STATUS_PENDING,
                Alert::STATUS_FAILED,
                Alert::STATUS_INVESTIGATING,
                Alert::SEVERITY_CRITICAL,
                Alert::RISK_CALL,
                Alert::RISK_EMERGENCY,
            ])
            ->first();

        $samsaraStats = [
            'total' => (int) $samsaraCounts->total,
            'today' => (int) $samsaraCounts->today,
            'thisWeek' => (int) $samsaraCounts->this_week,
            'critical' => (int) $samsaraCounts->critical,
            'pending' => (int) $samsaraCounts->pending,
            'processing' => (int) $samsaraCounts->processing,
            'investigating' => (int) $samsaraCounts->investigating,
            'completed' => (int) $samsaraCounts->completed,
            'failed' => (int) $samsaraCounts->failed,
            'needsHumanAttention' => (int) $samsaraCounts->needs_human_attention,
        ];

        $eventsBySeverity = (clone $alertsBaseQuery)
            ->select('severity', DB::raw('COUNT(*) as count'))
            ->groupBy('severity')
            ->get()
            ->pluck('count', 'severity')
            ->toArray();

        $eventsByAiStatus = (clone $alertsBaseQuery)
            ->select('ai_status', DB::raw('COUNT(*) as count'))
            ->groupBy('ai_status')
            ->get()
            ->pluck('count', 'ai_status')
            ->toArray();

        $eventsActivity = (clone $alertsBaseQuery)
            ->where('created_at', '>=', $lastWeek)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $eventsByDay = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            $dayName = Carbon::now()->subDays($i)->locale('es')->isoFormat('ddd');
            $eventsByDay[] = [
                'day' => ucfirst($dayName),
                'count' => (int) ($eventsActivity->get($date)?->count ?? 0),
            ];
        }

        $vehiclesQuery = Vehicle::query();
        if ($companyIdFilter !== null) {
            $vehiclesQuery->forCompany($companyIdFilter);
        }

        $vehiclesStats = [
            'total' => (clone $vehiclesQuery)->count(),
        ];

        $contactsQuery = Contact::query();
        if ($companyIdFilter !== null) {
            $contactsQuery->forCompany($companyIdFilter);
        }

        $contactsStats = [
            'total' => (clone $contactsQuery)->count(),
            'active' => (clone $contactsQuery)->active()->count(),
            'default' => (clone $contactsQuery)->default()->count(),
        ];

        $usersStats = null;
        if ($isSuperAdmin) {
            $usersStats = [
                'total' => User::where('role', '!=', User::ROLE_SUPER_ADMIN)->count(),
                'active' => User::where('role', '!=', User::ROLE_SUPER_ADMIN)->where('is_active', true)->count(),
                'admins' => User::where('role', User::ROLE_ADMIN)->count(),
            ];
        } else {
            $usersQuery = User::query()->forCompany($user->company_id);
            $usersStats = [
                'total' => (clone $usersQuery)->count(),
                'active' => (clone $usersQuery)->active()->count(),
            ];
        }

        $recentEvents = (clone $alertsBaseQuery)
            ->with('signal:id,event_type,event_description,vehicle_name,driver_name')
            ->orderBy('occurred_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn (Alert $a) => [
                'id' => $a->id,
                'event_type' => $a->signal?->event_type,
                'event_description' => $a->signal?->event_description ?? $a->event_description,
                'vehicle_name' => $a->signal?->vehicle_name,
                'driver_name' => $a->signal?->driver_name,
                'severity' => $a->severity,
                'ai_status' => $a->ai_status,
                'occurred_at' => $a->occurred_at,
                'risk_escalation' => $a->risk_escalation,
            ]);

        $criticalEvents = (clone $alertsBaseQuery)
            ->with('signal:id,event_type,event_description,vehicle_name,driver_name')
            ->critical()
            ->orderBy('occurred_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn (Alert $a) => [
                'id' => $a->id,
                'event_type' => $a->signal?->event_type,
                'event_description' => $a->signal?->event_description ?? $a->event_description,
                'vehicle_name' => $a->signal?->vehicle_name,
                'driver_name' => $a->signal?->driver_name,
                'ai_status' => $a->ai_status,
                'occurred_at' => $a->occurred_at,
                'risk_escalation' => $a->risk_escalation,
            ]);

        $eventsNeedingAttention = (clone $alertsBaseQuery)
            ->with('signal:id,event_type,event_description,vehicle_name,driver_name')
            ->needsHumanAttention()
            ->orderBy('occurred_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn (Alert $a) => [
                'id' => $a->id,
                'event_type' => $a->signal?->event_type,
                'event_description' => $a->signal?->event_description ?? $a->event_description,
                'vehicle_name' => $a->signal?->vehicle_name,
                'driver_name' => $a->signal?->driver_name,
                'severity' => $a->severity,
                'ai_status' => $a->ai_status,
                'occurred_at' => $a->occurred_at,
                'risk_escalation' => $a->risk_escalation,
            ]);

        $signalScope = fn ($q) => $companyIdFilter !== null ? $q->forCompany($companyIdFilter) : $q;

        $eventsByType = Signal::query()->tap($signalScope)
            ->where('created_at', '>=', $lastMonth)
            ->select('event_type', DB::raw('COUNT(*) as count'))
            ->groupBy('event_type')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn ($item) => [
                'type' => $item->event_type,
                'count' => $item->count,
            ]);

        $conversationsQuery = Conversation::query();
        if ($companyIdFilter !== null) {
            $conversationsQuery->forCompany($companyIdFilter);
        }

        $conversationsStats = [
            'total' => (clone $conversationsQuery)->count(),
            'today' => (clone $conversationsQuery)->whereDate('created_at', $today)->count(),
            'thisWeek' => (clone $conversationsQuery)->where('created_at', '>=', $lastWeek)->count(),
        ];

        $recentConversationsCollection = (clone $conversationsQuery)
            ->withCount('messages')
            ->with('user:id,name')
            ->orderBy('updated_at', 'desc')
            ->limit(5)
            ->get(['id', 'thread_id', 'title', 'user_id', 'updated_at', 'created_at']);

        $threadIds = $recentConversationsCollection->pluck('thread_id')->filter()->values()->all();
        $lastMessagesByThread = collect();
        if ($threadIds !== []) {
            $lastMessagesByThread = ChatMessage::query()
                ->whereIn('thread_id', $threadIds)
                ->orderByDesc('created_at')
                ->get()
                ->unique('thread_id')
                ->keyBy('thread_id');
        }

        $recentConversations = $recentConversationsCollection->map(function ($conv) use ($lastMessagesByThread) {
            $lastMessage = $lastMessagesByThread->get($conv->thread_id);
            return [
                'id' => $conv->id,
                'thread_id' => $conv->thread_id,
                'title' => $conv->title,
                'user_name' => $conv->user?->name ?? 'Usuario',
                'message_count' => (int) $conv->messages_count,
                'last_message_preview' => $lastMessage
                    ? (is_array($lastMessage->content)
                        ? \Illuminate\Support\Str::limit($lastMessage->content['text'] ?? '', 80)
                        : \Illuminate\Support\Str::limit($lastMessage->content ?? '', 80))
                    : null,
                'updated_at' => $conv->updated_at->diffForHumans(),
            ];
        });

        $onboardingStatus = null;
        if (!$isSuperAdmin && $user->company) {
            $onboardingStatus = $user->company->getOnboardingStatus();
        }

        $pipelineHealth = null;
        if ($companyIdFilter !== null) {
            $lastProcessedMetric = AlertMetrics::query()
                ->whereHas('alert', fn ($q) => $q->forCompany($companyIdFilter))
                ->whereNotNull('ai_finished_at')
                ->orderByDesc('ai_finished_at')
                ->first();

            $avgLatencyMs = AlertMetrics::query()
                ->whereHas('alert', fn ($q) => $q->forCompany($companyIdFilter))
                ->whereDate('ai_finished_at', $today)
                ->whereNotNull('pipeline_latency_ms')
                ->avg('pipeline_latency_ms');

            $pipelineHealth = [
                'last_processed_at' => $lastProcessedMetric?->ai_finished_at,
                'avg_latency_ms_today' => $avgLatencyMs ? (int) round($avgLatencyMs) : null,
            ];
        }

        $alertsQuery = Alert::query()->when($companyIdFilter !== null, fn ($q) => $q->forCompany($companyIdFilter));

        // Single aggregated query for operationalStatus alert counts (avoids 5+ separate COUNT queries)
        $operationalCounts = (clone $alertsQuery)
            ->selectRaw("
                SUM(CASE WHEN ai_status != ? THEN 1 ELSE 0 END) as alerts_open,
                SUM(CASE WHEN ack_status = ? AND ack_due_at IS NOT NULL AND ack_due_at < ? THEN 1 ELSE 0 END) as sla_breaches,
                SUM(CASE WHEN attention_state IS NOT NULL AND attention_state != ? THEN 1 ELSE 0 END) as needs_attention,
                SUM(CASE WHEN DATE(created_at) = ? THEN 1 ELSE 0 END) as alerts_today,
                SUM(CASE WHEN acked_at IS NOT NULL AND acked_at >= ? THEN 1 ELSE 0 END) as acked_last_week
            ", [
                Alert::STATUS_COMPLETED,
                Alert::ACK_PENDING,
                now(),
                Alert::ATTENTION_CLOSED,
                $today->format('Y-m-d'),
                $lastWeek,
            ])
            ->first();

        $operationalStatus = [
            'alerts_open' => (int) $operationalCounts->alerts_open,
            'sla_breaches' => (int) $operationalCounts->sla_breaches,
            'needs_attention' => (int) $operationalCounts->needs_attention,
            'avg_ack_seconds' => null,
            'deliverability_rate' => null,
            'alerts_today' => (int) $operationalCounts->alerts_today,
        ];

        $ackedCount = (int) $operationalCounts->acked_last_week;
        if ($ackedCount > 0) {
            $avgAck = (clone $alertsQuery)
                ->whereNotNull('acked_at')
                ->where('acked_at', '>=', $lastWeek)
                ->selectRaw('AVG(EXTRACT(EPOCH FROM (acked_at - created_at))) as avg_sec')
                ->value('avg_sec');
            $operationalStatus['avg_ack_seconds'] = $avgAck ? (int) round($avgAck) : null;
        }

        $nrBaseQuery = NotificationResult::query()
            ->whereHas('alert', function ($q) use ($companyIdFilter) {
                if ($companyIdFilter !== null) {
                    $q->where('company_id', $companyIdFilter);
                }
            })
            ->where('notification_results.created_at', '>=', $lastWeek);
        $totalNr = (clone $nrBaseQuery)->count();
        if ($totalNr > 0) {
            $deliveredNr = (clone $nrBaseQuery)->whereIn('status_current', ['delivered', 'read'])->count();
            $operationalStatus['deliverability_rate'] = round($deliveredNr / $totalNr * 100, 1);
        }

        $attentionQuery = Alert::query()
            ->with(['signal', 'ownerUser'])
            ->needsAttention()
            ->orderByAttentionPriority()
            ->limit(20);
        if ($companyIdFilter !== null) {
            $attentionQuery->forCompany($companyIdFilter);
        }
        $attentionQueue = $attentionQuery->get()
            ->map(fn (Alert $a) => [
                'id' => $a->id,
                'vehicle_name' => $a->signal?->vehicle_name,
                'event_type' => $a->signal?->event_type,
                'severity' => $a->severity,
                'created_at' => $a->created_at->toIso8601String(),
                'owner_name' => $a->ownerUser?->name,
                'ack_due_at' => $a->ack_due_at?->toIso8601String(),
                'ack_sla_remaining_seconds' => $a->ackSlaRemainingSeconds(),
                'ack_status' => $a->ack_status,
                'ai_status' => $a->ai_status,
                'attention_state' => $a->attention_state,
            ])
            ->toArray();

        // Single grouped query for alerts per day (avoids 14 separate COUNT queries)
        $fourteenDaysAgo = Carbon::now()->subDays(14)->startOfDay();
        $alertsByDate = (clone $alertsBaseQuery)
            ->where('created_at', '>=', $fourteenDaysAgo)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->get()
            ->keyBy('date');
        $alertsPerDay = [];
        for ($i = 13; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            $alertsPerDay[] = [
                'date' => $date,
                'count' => (int) ($alertsByDate->get($date)?->count ?? 0),
            ];
        }

        $notificationsPerDay = [];
        for ($i = 13; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            $dayStart = Carbon::parse($date)->startOfDay();
            $dayEnd = Carbon::parse($date)->endOfDay();
            $nrQ = NotificationResult::query()
                ->whereHas('alert', function ($q) use ($companyIdFilter) {
                    if ($companyIdFilter !== null) {
                        $q->where('company_id', $companyIdFilter);
                    }
                })
                ->whereBetween('notification_results.created_at', [$dayStart, $dayEnd]);
            $total = (clone $nrQ)->count();
            $delivered = (clone $nrQ)->whereIn('status_current', ['delivered', 'read'])->count();
            $notificationsPerDay[] = [
                'date' => $date,
                'total' => $total,
                'delivered' => $delivered,
            ];
        }

        $trends = [
            'alerts_per_day' => $alertsPerDay,
            'notifications_per_day' => $notificationsPerDay,
        ];

        return Inertia::render('dashboard', [
            'isSuperAdmin' => $isSuperAdmin,
            'companyName' => $user->company?->name ?? null,
            'onboardingStatus' => $onboardingStatus,
            'pipelineHealth' => $pipelineHealth,
            'operationalStatus' => $operationalStatus,
            'attentionQueue' => $attentionQueue,
            'trends' => $trends,
            'samsaraStats' => $samsaraStats,
            'vehiclesStats' => $vehiclesStats,
            'contactsStats' => $contactsStats,
            'usersStats' => $usersStats,
            'conversationsStats' => $conversationsStats,
            'eventsBySeverity' => $eventsBySeverity,
            'eventsByAiStatus' => $eventsByAiStatus,
            'eventsByDay' => $eventsByDay,
            'eventsByType' => $eventsByType,
            'recentEvents' => $recentEvents,
            'criticalEvents' => $criticalEvents,
            'eventsNeedingAttention' => $eventsNeedingAttention,
            'recentConversations' => $recentConversations,
        ]);
    }
}
