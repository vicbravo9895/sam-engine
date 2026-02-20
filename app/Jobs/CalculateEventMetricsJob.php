<?php

namespace App\Jobs;

use App\Models\Alert;
use App\Models\Company;
use App\Models\Incident;
use App\Models\EventMetric;
use App\Models\NotificationResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job to calculate daily event metrics for all companies.
 * 
 * This job should be scheduled to run daily (e.g., at 1 AM)
 * to calculate metrics for the previous day.
 * 
 * Metrics calculated:
 * - Event counts by severity and verdict
 * - Processing time averages
 * - Incident detection and resolution rates
 * - Notification success rates
 * - Human review metrics
 */
class CalculateEventMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The date to calculate metrics for.
     */
    protected string $metricDate;

    /**
     * Create a new job instance.
     * 
     * @param string|null $date Date in Y-m-d format. Defaults to yesterday.
     */
    public function __construct(?string $date = null)
    {
        $this->metricDate = $date ?? now()->subDay()->toDateString();
        $this->onQueue('metrics');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('CalculateEventMetricsJob: Starting', [
            'metric_date' => $this->metricDate,
        ]);

        $startTime = microtime(true);

        // Get all companies
        $companies = Company::all();

        foreach ($companies as $company) {
            try {
                $this->calculateMetricsForCompany($company);
            } catch (\Throwable $e) {
                Log::error('CalculateEventMetricsJob: Error calculating metrics', [
                    'company_id' => $company->id,
                    'metric_date' => $this->metricDate,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        Log::info('CalculateEventMetricsJob: Completed', [
            'metric_date' => $this->metricDate,
            'companies_processed' => $companies->count(),
            'duration_ms' => $duration,
        ]);
    }

    /**
     * Calculate metrics for a single company.
     */
    protected function calculateMetricsForCompany(Company $company): void
    {
        $dateStart = "{$this->metricDate} 00:00:00";
        $dateEnd = "{$this->metricDate} 23:59:59";

        // Get all events for this company on this date
        $eventsQuery = Alert::forCompany($company->id)
            ->whereBetween('occurred_at', [$dateStart, $dateEnd]);

        // Basic counts
        $totalEvents = $eventsQuery->count();

        if ($totalEvents === 0) {
            // No events, don't create a metric record
            return;
        }

        // Severity breakdown
        $criticalEvents = (clone $eventsQuery)->where('severity', 'critical')->count();
        $warningEvents = (clone $eventsQuery)->where('severity', 'warning')->count();
        $infoEvents = (clone $eventsQuery)->where('severity', 'info')->count();

        // Verdict breakdown (from normalized column)
        $realPanicCount = (clone $eventsQuery)->where('verdict', 'real_panic')->count();
        $confirmedViolationCount = (clone $eventsQuery)->where('verdict', 'confirmed_violation')->count();
        $needsReviewCount = (clone $eventsQuery)->where('verdict', 'needs_review')->count();
        $falsePositiveCount = (clone $eventsQuery)
            ->whereIn('verdict', ['likely_false_positive', 'no_action_needed'])->count();
        $noActionNeededCount = (clone $eventsQuery)->where('verdict', 'no_action_needed')->count();

        // Processing time average (from ai_actions or estimate from timestamps)
        $avgProcessingTime = $this->calculateAverageProcessingTime($company->id, $dateStart, $dateEnd);

        // Response time (time from event to first notification)
        $avgResponseTime = $this->calculateAverageResponseTime($company->id, $dateStart, $dateEnd);

        // Incident metrics
        $incidentsDetected = Incident::forCompany($company->id)
            ->whereBetween('detected_at', [$dateStart, $dateEnd])
            ->count();

        $incidentsResolved = Incident::forCompany($company->id)
            ->whereBetween('resolved_at', [$dateStart, $dateEnd])
            ->count();

        // Notification metrics
        $notificationMetrics = $this->calculateNotificationMetrics($company->id, $dateStart, $dateEnd);

        // Human review metrics
        $eventsReviewed = (clone $eventsQuery)
            ->where('human_status', '!=', 'pending')
            ->count();

        $eventsFlagged = (clone $eventsQuery)
            ->where('human_status', 'flagged')
            ->count();

        // Create or update metric record
        EventMetric::updateOrCreate(
            [
                'company_id' => $company->id,
                'metric_date' => $this->metricDate,
            ],
            [
                'total_events' => $totalEvents,
                'critical_events' => $criticalEvents,
                'warning_events' => $warningEvents,
                'info_events' => $infoEvents,
                'real_panic_count' => $realPanicCount,
                'confirmed_violation_count' => $confirmedViolationCount,
                'needs_review_count' => $needsReviewCount,
                'false_positive_count' => $falsePositiveCount,
                'no_action_needed_count' => $noActionNeededCount,
                'avg_processing_time_ms' => $avgProcessingTime,
                'avg_response_time_minutes' => $avgResponseTime,
                'incidents_detected' => $incidentsDetected,
                'incidents_resolved' => $incidentsResolved,
                'notifications_sent' => $notificationMetrics['sent'],
                'notifications_throttled' => $notificationMetrics['throttled'],
                'notifications_failed' => $notificationMetrics['failed'],
                'events_reviewed' => $eventsReviewed,
                'events_flagged' => $eventsFlagged,
            ]
        );

        Log::debug('CalculateEventMetricsJob: Metrics calculated', [
            'company_id' => $company->id,
            'metric_date' => $this->metricDate,
            'total_events' => $totalEvents,
        ]);
    }

    /**
     * Calculate average processing time in milliseconds.
     */
    protected function calculateAverageProcessingTime(int $companyId, string $dateStart, string $dateEnd): ?int
    {
        // Try to get from ai_actions if available
        $alertsWithAi = Alert::forCompany($companyId)
            ->whereBetween('occurred_at', [$dateStart, $dateEnd])
            ->whereHas('ai', function ($q) {
                $q->whereNotNull('ai_actions');
            })
            ->with('ai')
            ->get();

        $totalDuration = 0;
        $count = 0;

        foreach ($alertsWithAi as $alert) {
            $aiActions = $alert->ai?->ai_actions;
            if (is_array($aiActions) && isset($aiActions['total_duration_ms'])) {
                $totalDuration += (int) $aiActions['total_duration_ms'];
                $count++;
            }
        }

        if ($count === 0) {
            return null;
        }

        return (int) round($totalDuration / $count);
    }

    /**
     * Calculate average response time in minutes.
     */
    protected function calculateAverageResponseTime(int $companyId, string $dateStart, string $dateEnd): ?int
    {
        // Calculate time from event occurred_at to first notification
        $avgMinutes = DB::table('alerts as a')
            ->join('notification_results as nr', 'a.id', '=', 'nr.alert_id')
            ->where('a.company_id', $companyId)
            ->whereBetween('a.occurred_at', [$dateStart, $dateEnd])
            ->where('nr.success', true)
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (nr.timestamp_utc - a.occurred_at)) / 60) as avg_minutes')
            ->first();

        if ($avgMinutes && $avgMinutes->avg_minutes !== null) {
            return (int) round($avgMinutes->avg_minutes);
        }

        return null;
    }

    /**
     * Calculate notification metrics.
     */
    protected function calculateNotificationMetrics(int $companyId, string $dateStart, string $dateEnd): array
    {
        // Get notification results
        $results = NotificationResult::whereHas('alert', function ($query) use ($companyId, $dateStart, $dateEnd) {
            $query->where('company_id', $companyId)
                ->whereBetween('occurred_at', [$dateStart, $dateEnd]);
        })->get();

        $sent = $results->where('success', true)->count();
        $failed = $results->where('success', false)->count();

        $throttled = Alert::forCompany($companyId)
            ->whereBetween('occurred_at', [$dateStart, $dateEnd])
            ->whereNotNull('notification_execution')
            ->get()
            ->filter(function ($alert) {
                $execution = $alert->notification_execution;
                return is_array($execution) && ($execution['throttled'] ?? false);
            })
            ->count();

        return [
            'sent' => $sent,
            'failed' => $failed,
            'throttled' => $throttled,
        ];
    }
}
