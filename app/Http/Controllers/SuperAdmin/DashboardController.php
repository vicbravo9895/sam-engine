<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Alert;
use App\Models\AlertMetrics;
use App\Models\Company;
use App\Models\PendingWebhook;
use App\Models\Signal;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Conversation;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * Display the super admin dashboard.
     */
    public function index()
    {
        $stats = [
            'companies' => [
                'total' => Company::count(),
                'active' => Company::where('is_active', true)->count(),
                'with_samsara' => Company::whereNotNull('samsara_api_key')->count(),
            ],
            'users' => [
                'total' => User::where('role', '!=', User::ROLE_SUPER_ADMIN)->count(),
                'active' => User::where('role', '!=', User::ROLE_SUPER_ADMIN)->where('is_active', true)->count(),
                'admins' => User::where('role', User::ROLE_ADMIN)->count(),
            ],
            'vehicles' => [
                'total' => Vehicle::count(),
            ],
            'conversations' => [
                'total' => Conversation::count(),
                'today' => Conversation::whereDate('created_at', today())->count(),
            ],
        ];

        $recentCompanies = Company::withCount(['users', 'vehicles'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get(['id', 'name', 'is_active', 'created_at']);

        $recentUsers = User::with('company:id,name')
            ->where('role', '!=', User::ROLE_SUPER_ADMIN)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get(['id', 'name', 'email', 'role', 'company_id', 'created_at']);

        // ========================================
        // ADOPTION METRICS (Phase 3.3)
        // ========================================
        $adoptionMetrics = $this->getAdoptionMetrics();

        return Inertia::render('super-admin/dashboard', [
            'stats' => $stats,
            'recentCompanies' => $recentCompanies,
            'recentUsers' => $recentUsers,
            'adoptionMetrics' => $adoptionMetrics,
        ]);
    }
    /**
     * Gather adoption and product metrics for super admin.
     */
    private function getAdoptionMetrics(): array
    {
        $last30Days = Carbon::now()->subDays(30);
        $last7Days = Carbon::now()->subDays(7);
        $thisMonth = Carbon::now()->startOfMonth();

        // Pipeline performance (p50, p95 latency today)
        $latencyToday = AlertMetrics::whereDate('ai_finished_at', today())
            ->whereNotNull('pipeline_latency_ms')
            ->pluck('pipeline_latency_ms')
            ->sort()
            ->values();

        $p50Latency = null;
        $p95Latency = null;
        if ($latencyToday->count() > 0) {
            $p50Index = (int) floor($latencyToday->count() * 0.50);
            $p95Index = (int) floor($latencyToday->count() * 0.95);
            $p50Latency = $latencyToday->get(min($p50Index, $latencyToday->count() - 1));
            $p95Latency = $latencyToday->get(min($p95Index, $latencyToday->count() - 1));
        }

        // Human review rate (last 30 days)
        $totalCompleted = Alert::where('created_at', '>=', $last30Days)
            ->whereIn('ai_status', ['completed', 'investigating'])
            ->count();

        $humanReviewed = Alert::where('created_at', '>=', $last30Days)
            ->whereIn('ai_status', ['completed', 'investigating'])
            ->where('human_status', '!=', 'pending')
            ->count();

        $humanOverride = Alert::where('created_at', '>=', $last30Days)
            ->where('human_status', 'false_positive')
            ->count();

        // Copilot usage (this month)
        $copilotSessions = Conversation::where('created_at', '>=', $thisMonth)->count();
        $copilotMessages = ChatMessage::where('role', 'user')
            ->where('created_at', '>=', $thisMonth)
            ->count();

        $copilotActiveUsers = Conversation::where('created_at', '>=', $thisMonth)
            ->distinct('user_id')
            ->count('user_id');

        // Failed alerts (last 7 days)
        $failedLast7Days = Alert::where('created_at', '>=', $last7Days)
            ->where('ai_status', 'failed')
            ->count();

        // Pending webhooks
        $pendingWebhooks = 0;
        try {
            $pendingWebhooks = PendingWebhook::unresolved()->count();
        } catch (\Exception $e) {
            // Table may not exist yet
        }

        // Days to first alert by company
        $companiesFirstAlert = DB::table('alerts')
            ->select('company_id', DB::raw('MIN(created_at) as first_alert_at'))
            ->groupBy('company_id')
            ->get();

        $companiesCreation = DB::table('companies')
            ->select('id', 'created_at')
            ->whereIn('id', $companiesFirstAlert->pluck('company_id'))
            ->pluck('created_at', 'id');

        $daysToFirstAlert = [];
        foreach ($companiesFirstAlert as $row) {
            $companyCreatedAt = $companiesCreation[$row->company_id] ?? null;
            if ($companyCreatedAt && $row->first_alert_at) {
                $days = Carbon::parse($companyCreatedAt)->diffInDays(Carbon::parse($row->first_alert_at));
                $daysToFirstAlert[] = $days;
            }
        }
        $avgDaysToFirstAlert = count($daysToFirstAlert) > 0
            ? round(array_sum($daysToFirstAlert) / count($daysToFirstAlert), 1)
            : null;

        return [
            'pipeline' => [
                'p50_latency_ms' => $p50Latency,
                'p95_latency_ms' => $p95Latency,
                'events_today' => $latencyToday->count(),
                'failed_last_7_days' => $failedLast7Days,
                'pending_webhooks' => $pendingWebhooks,
            ],
            'human_review' => [
                'total_completed_30d' => $totalCompleted,
                'human_reviewed_30d' => $humanReviewed,
                'review_rate_pct' => $totalCompleted > 0
                    ? round(($humanReviewed / $totalCompleted) * 100, 1)
                    : null,
                'human_override_30d' => $humanOverride,
                'override_rate_pct' => $totalCompleted > 0
                    ? round(($humanOverride / $totalCompleted) * 100, 1)
                    : null,
            ],
            'copilot' => [
                'sessions_this_month' => $copilotSessions,
                'messages_this_month' => $copilotMessages,
                'active_users_this_month' => $copilotActiveUsers,
                'avg_messages_per_session' => $copilotSessions > 0
                    ? round($copilotMessages / $copilotSessions, 1)
                    : null,
            ],
            'onboarding' => [
                'avg_days_to_first_alert' => $avgDaysToFirstAlert,
                'companies_with_alerts' => count($daysToFirstAlert),
            ],
        ];
    }
}
