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

        $samsaraStats = [
            'total' => (clone $alertsBaseQuery)->count(),
            'today' => (clone $alertsBaseQuery)->whereDate('created_at', $today)->count(),
            'thisWeek' => (clone $alertsBaseQuery)->where('created_at', '>=', $lastWeek)->count(),
            'critical' => (clone $alertsBaseQuery)->critical()->count(),
            'pending' => (clone $alertsBaseQuery)->pending()->count(),
            'processing' => (clone $alertsBaseQuery)->processing()->count(),
            'investigating' => (clone $alertsBaseQuery)->investigating()->count(),
            'completed' => (clone $alertsBaseQuery)->completed()->count(),
            'failed' => (clone $alertsBaseQuery)->failed()->count(),
            'needsHumanAttention' => (clone $alertsBaseQuery)->needsHumanAttention()->count(),
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

        $recentConversations = (clone $conversationsQuery)
            ->with('user:id,name')
            ->orderBy('updated_at', 'desc')
            ->limit(5)
            ->get(['id', 'thread_id', 'title', 'user_id', 'updated_at', 'created_at'])
            ->map(function ($conv) {
                $messageCount = ChatMessage::where('thread_id', $conv->thread_id)->count();
                $lastMessage = ChatMessage::where('thread_id', $conv->thread_id)
                    ->orderBy('created_at', 'desc')
                    ->first();

                return [
                    'id' => $conv->id,
                    'thread_id' => $conv->thread_id,
                    'title' => $conv->title,
                    'user_name' => $conv->user?->name ?? 'Usuario',
                    'message_count' => $messageCount,
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

        $operationalStatus = [
            'alerts_open' => (clone $alertsQuery)->whereNotIn('ai_status', ['completed'])->count(),
            'sla_breaches' => (clone $alertsQuery)->overdueAck()->count(),
            'needs_attention' => (clone $alertsQuery)->needsAttention()->count(),
            'avg_ack_seconds' => null,
            'deliverability_rate' => null,
            'alerts_today' => (clone $alertsQuery)->whereDate('created_at', $today)->count(),
        ];

        $ackedCount = (clone $alertsQuery)->whereNotNull('acked_at')->where('acked_at', '>=', $lastWeek)->count();
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

        $alertsPerDay = [];
        for ($i = 13; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            $dayStart = Carbon::parse($date)->startOfDay();
            $dayEnd = Carbon::parse($date)->endOfDay();
            $q = Alert::query()->whereBetween('created_at', [$dayStart, $dayEnd]);
            if ($companyIdFilter !== null) {
                $q->forCompany($companyIdFilter);
            }
            $alertsPerDay[] = [
                'date' => $date,
                'count' => $q->count(),
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
