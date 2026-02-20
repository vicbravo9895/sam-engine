<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // =====================================================================
        // 1. Add notification columns to alerts table
        // =====================================================================
        Schema::table('alerts', function (Blueprint $table) {
            $table->string('notification_status', 30)->default('none')->after('escalation_count');
            $table->json('notification_channels')->nullable()->after('notification_status');
            $table->timestamp('notification_sent_at')->nullable()->after('notification_channels');
            $table->string('twilio_call_sid')->nullable()->after('notification_sent_at');
            $table->json('call_response')->nullable()->after('twilio_call_sid');
            $table->json('notification_decision_payload')->nullable()->after('call_response');
            $table->json('notification_execution')->nullable()->after('notification_decision_payload');
            $table->string('event_description')->nullable()->after('notification_execution');
        });

        // =====================================================================
        // 2. Add alert_id to tables that don't have it yet
        // =====================================================================
        Schema::table('samsara_event_comments', function (Blueprint $table) {
            $table->unsignedBigInteger('alert_id')->nullable()->after('id');
            $table->foreign('alert_id')->references('id')->on('alerts')->onDelete('cascade');
            $table->index(['alert_id', 'created_at'], 'alert_comments_alert_id_created_at_index');
        });

        Schema::table('samsara_event_activities', function (Blueprint $table) {
            $table->unsignedBigInteger('alert_id')->nullable()->after('id');
            $table->foreign('alert_id')->references('id')->on('alerts')->onDelete('cascade');
            $table->index(['alert_id', 'created_at'], 'alert_activities_alert_id_created_at_index');
        });

        Schema::table('notification_decisions', function (Blueprint $table) {
            $table->unsignedBigInteger('alert_id')->nullable()->after('id');
            $table->foreign('alert_id')->references('id')->on('alerts')->onDelete('cascade');
            $table->index('alert_id', 'notification_decisions_alert_id_index');
        });

        // =====================================================================
        // 3. Backfill alert_id in all tables
        // =====================================================================
        $tables = [
            'samsara_event_comments',
            'samsara_event_activities',
            'notification_decisions',
            'notification_results',
            'notification_acks',
            'event_recommended_actions',
            'event_investigation_steps',
        ];

        foreach ($tables as $tableName) {
            DB::statement("
                UPDATE {$tableName} t
                SET alert_id = a.id
                FROM alerts a
                WHERE a.legacy_samsara_event_id = t.samsara_event_id
                  AND t.alert_id IS NULL
            ");
        }

        // Backfill event_description on alerts from signals
        DB::statement("
            UPDATE alerts a
            SET event_description = s.event_description
            FROM signals s
            WHERE s.id = a.signal_id
              AND a.event_description IS NULL
        ");

        // Backfill notification fields on alerts from samsara_events
        DB::statement("
            UPDATE alerts a
            SET notification_status = COALESCE(se.notification_status, 'none'),
                notification_channels = se.notification_channels,
                notification_sent_at = se.notification_sent_at,
                twilio_call_sid = se.twilio_call_sid,
                call_response = se.call_response,
                notification_decision_payload = se.notification_decision,
                notification_execution = se.notification_execution
            FROM samsara_events se
            WHERE se.id = a.legacy_samsara_event_id
        ");

        // =====================================================================
        // 4. Drop samsara_event_id FKs and columns BEFORE renaming tables
        // =====================================================================
        Schema::table('samsara_event_comments', function (Blueprint $table) {
            $table->dropForeign('samsara_event_comments_samsara_event_id_foreign');
            $table->dropIndex('samsara_event_comments_samsara_event_id_created_at_index');
            $table->dropColumn('samsara_event_id');
        });

        Schema::table('samsara_event_activities', function (Blueprint $table) {
            $table->dropForeign('samsara_event_activities_samsara_event_id_foreign');
            $table->dropIndex('samsara_event_activities_samsara_event_id_created_at_index');
            $table->dropColumn('samsara_event_id');
        });

        Schema::table('event_recommended_actions', function (Blueprint $table) {
            $table->dropForeign('event_recommended_actions_samsara_event_id_foreign');
            $table->dropColumn('samsara_event_id');
        });

        Schema::table('event_investigation_steps', function (Blueprint $table) {
            $table->dropForeign('event_investigation_steps_samsara_event_id_foreign');
            $table->dropColumn('samsara_event_id');
        });

        Schema::table('notification_results', function (Blueprint $table) {
            $table->dropForeign('notification_results_samsara_event_id_foreign');
            $table->dropIndex('notification_results_samsara_event_id_timestamp_utc_index');
            $table->dropColumn('samsara_event_id');
        });

        Schema::table('notification_acks', function (Blueprint $table) {
            $table->dropForeign('notification_acks_samsara_event_id_foreign');
            $table->dropColumn('samsara_event_id');
        });

        Schema::table('notification_decisions', function (Blueprint $table) {
            $table->dropForeign('notification_decisions_samsara_event_id_foreign');
            $table->dropUnique('notification_decisions_samsara_event_id_unique');
            $table->dropIndex('notification_decisions_samsara_event_id_index');
            $table->dropColumn('samsara_event_id');
        });

        // notification_throttle_logs: rename samsara_event_id → alert_id
        Schema::table('notification_throttle_logs', function (Blueprint $table) {
            $table->dropForeign(['samsara_event_id']);
            $table->renameColumn('samsara_event_id', 'alert_id');
        });

        // =====================================================================
        // 5. Rename tables to alert-centric names
        // =====================================================================
        Schema::rename('samsara_event_comments', 'alert_comments');
        Schema::rename('samsara_event_activities', 'alert_activities');
    }

    public function down(): void
    {
        // Destructive migration - manual rollback required
    }
};
