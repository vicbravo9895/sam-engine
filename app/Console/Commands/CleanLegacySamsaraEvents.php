<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove all legacy data from samsara_events and related tables.
 *
 * Run this AFTER migrations (including 2026_02_23_000001 and 2026_02_23_000002).
 * Use when you do not want to preserve any historical events — clean cutover only.
 */
class CleanLegacySamsaraEvents extends Command
{
    protected $signature = 'sam:clean-legacy-samsara-events
                            {--force : Skip confirmation prompt}';

    protected $description = 'Remove all legacy samsara_events data (comments, activities, notifications, then drop samsara_events). Run after migrate.';

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('This will permanently delete all data in alert_comments, alert_activities, notification_* and related tables, then DROP samsara_events. Continue?')) {
            $this->warn('Aborted.');
            return self::FAILURE;
        }

        // Order matters: truncate dependents before parents (notification_delivery_events, notification_acks → notification_results; notification_recipients → notification_decisions)
        $tablesToTruncate = [
            'notification_delivery_events',
            'notification_acks',
            'notification_results',
            'notification_recipients',
            'notification_decisions',
            'event_recommended_actions',
            'event_investigation_steps',
            'alert_comments',
            'alert_activities',
            'notification_throttle_logs',
        ];

        foreach ($tablesToTruncate as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
                $this->line("Truncated: {$table}");
            }
        }

        if (Schema::hasTable('conversations') && Schema::hasColumn('conversations', 'context_event_id')) {
            DB::table('conversations')->update(['context_event_id' => null]);
            $this->line('Nullified conversations.context_event_id');
            try {
                Schema::table('conversations', function ($table) {
                    $table->dropForeign(['context_event_id']);
                });
                $this->line('Dropped FK conversations.context_event_id -> samsara_events');
            } catch (\Throwable $e) {
                $this->warn('Could not drop FK (may already be gone): '.$e->getMessage());
            }
        }

        if (Schema::hasTable('samsara_events')) {
            // CASCADE drops all foreign key constraints that reference this table (PostgreSQL)
            DB::statement('DROP TABLE IF EXISTS samsara_events CASCADE');
            $this->info('Dropped table samsara_events.');
        } else {
            $this->line('Table samsara_events does not exist (already dropped or never created).');
        }

        $this->info('Legacy samsara_events cleanup done. New events will use signals + alerts only.');
        return self::SUCCESS;
    }
}
