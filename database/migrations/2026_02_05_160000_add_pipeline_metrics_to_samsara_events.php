<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase T1 — Instrumentación mínima confiable.
 *
 * Añade columnas para métricas del pipeline por evento (por company_id vía evento):
 * - time_webhook_received: usar created_at
 * - time_ai_started: pipeline_time_ai_started_at
 * - time_ai_finished: pipeline_time_ai_finished_at
 * - time_notifications_sent: notification_sent_at (ya existe)
 * - pipeline_latency_ms: latencia webhook → fin AI (p50/p95 por company)
 * - ai_tokens / ai_cost_estimate: opcionales si el AI Service los devuelve
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('samsara_events', function (Blueprint $table) {
            $table->timestamp('pipeline_time_ai_started_at')->nullable()->after('ai_processed_at');
            $table->timestamp('pipeline_time_ai_finished_at')->nullable()->after('pipeline_time_ai_started_at');
            $table->unsignedInteger('pipeline_latency_ms')->nullable()->after('pipeline_time_ai_finished_at');
            $table->unsignedInteger('ai_tokens')->nullable()->after('pipeline_latency_ms');
            $table->decimal('ai_cost_estimate', 12, 6)->nullable()->after('ai_tokens');

            $table->index(['company_id', 'pipeline_time_ai_finished_at'], 'idx_company_pipeline_finished');
        });
    }

    public function down(): void
    {
        Schema::table('samsara_events', function (Blueprint $table) {
            $table->dropIndex('idx_company_pipeline_finished');
            $table->dropColumn([
                'pipeline_time_ai_started_at',
                'pipeline_time_ai_finished_at',
                'pipeline_latency_ms',
                'ai_tokens',
                'ai_cost_estimate',
            ]);
        });
    }
};
