<?php

use App\Jobs\CalculateEventMetricsJob;
use App\Jobs\CheckAttentionSlaJob;
use App\Jobs\GenerateShiftSummaryJob;
use App\Jobs\ProcessPendingWebhooksJob;
use App\Models\Company;
use Illuminate\Support\Facades\Schedule;


/*
|--------------------------------------------------------------------------
| Scheduled Commands
|--------------------------------------------------------------------------
|
| Here you may define all of your scheduled commands. Commands are run
| in a single, sequential process to avoid race conditions.
|
*/

// Sync vehicles from Samsara API every 5 minutes
Schedule::command('samsara:sync-vehicles')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/vehicle-sync.log'));

// Sync drivers from Samsara API every 5 minutes
Schedule::command('samsara:sync-drivers')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/driver-sync.log'));

// Sync tags from Samsara API once daily at 3:00 AM
Schedule::command('samsara:sync-tags')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/tag-sync.log'));

// Sync vehicle stats (GPS, engine state, odometer) every 30 seconds
Schedule::command('samsara:sync-vehicle-stats')
    ->everyThirtySeconds()
    ->withoutOverlapping(2)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/vehicle-stats-sync.log'));

// Calculate daily event metrics at 1:00 AM
Schedule::job(new CalculateEventMetricsJob())
    ->name('calculate-event-metrics')
    ->dailyAt('01:00')
    ->withoutOverlapping();

// Process pending webhooks (orphan webhooks without matching vehicle) every 5 minutes
Schedule::job(new ProcessPendingWebhooksJob())
    ->name('process-pending-webhooks')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Generate shift summaries for companies that have it enabled (run hourly, check if it's a shift boundary)
Schedule::call(function () {
    $currentHour = (int) now()->format('G');
    
    Company::active()
        ->whereNotNull('samsara_api_key')
        ->where('samsara_api_key', '!=', '')
        ->each(function (Company $company) use ($currentHour) {
            $shiftConfig = $company->getAiConfig('shift_summary');
            
            if (!($shiftConfig['enabled'] ?? false)) {
                return;
            }
            
            $hours = $shiftConfig['hours'] ?? [7, 15, 23];
            
            if (in_array($currentHour, $hours)) {
                $duration = $shiftConfig['shift_duration_hours'] ?? 8;
                GenerateShiftSummaryJob::dispatch(
                    companyId: $company->id,
                    periodStart: now()->subHours($duration),
                    periodEnd: now(),
                );
            }
        });
})->name('generate-shift-summaries')->hourly()->withoutOverlapping();

// Detect safety patterns and create aggregated incidents every 15 minutes
// Analyzes the last 4 hours with a threshold of 3 occurrences
Schedule::command('samsara:detect-patterns --hours=4 --threshold=3')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/pattern-detection.log'));

// Check for stale vehicles (not reporting stats) every 5 minutes
Schedule::command('samsara:check-stale-vehicles')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/stale-vehicle-check.log'));

// Migrate local media files to S3 and free disk space (daily at 2:00 AM)
Schedule::command('media:migrate-to-s3 --delete --update-urls')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/media-s3-migration.log'));

// Attention Engine: check for overdue SLA events and auto-escalate every minute
Schedule::job(new CheckAttentionSlaJob())
    ->name('check-attention-sla')
    ->everyMinute()
    ->withoutOverlapping();

// Usage metering: aggregate usage_events into daily summaries (for in-house pricing later)
Schedule::command('sam:aggregate-usage')
    ->dailyAt('02:30')
    ->name('aggregate-usage')
    ->withoutOverlapping();
