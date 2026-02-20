<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'notification_results',
            'notification_acks',
            'notification_delivery_events',
            'event_recommended_actions',
            'event_investigation_steps',
            'domain_events',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'alert_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->unsignedBigInteger('alert_id')->nullable()->after('id');
                    $blueprint->index('alert_id');
                });
            }
        }
    }

    public function down(): void
    {
        $tables = [
            'notification_results',
            'notification_acks',
            'notification_delivery_events',
            'event_recommended_actions',
            'event_investigation_steps',
            'domain_events',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'alert_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('alert_id');
                });
            }
        }
    }
};
