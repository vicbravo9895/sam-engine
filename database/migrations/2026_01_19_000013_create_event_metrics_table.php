<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates event_metrics table.
 * 
 * Stores aggregated daily metrics for business intelligence.
 * Populated by CalculateEventMetricsJob running daily.
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('event_metrics', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            
            $table->date('metric_date');
            
            // Event counts
            $table->integer('total_events')->default(0);
            $table->integer('critical_events')->default(0);
            $table->integer('warning_events')->default(0);
            $table->integer('info_events')->default(0);
            
            // AI verdict breakdown
            $table->integer('real_panic_count')->default(0);
            $table->integer('confirmed_violation_count')->default(0);
            $table->integer('needs_review_count')->default(0);
            $table->integer('false_positive_count')->default(0);
            $table->integer('no_action_needed_count')->default(0);
            
            // Performance metrics
            $table->integer('avg_processing_time_ms')->nullable();
            $table->integer('avg_response_time_minutes')->nullable();
            
            // Correlation/incident metrics
            $table->integer('incidents_detected')->default(0);
            $table->integer('incidents_resolved')->default(0);
            
            // Notification metrics
            $table->integer('notifications_sent')->default(0);
            $table->integer('notifications_throttled')->default(0);
            $table->integer('notifications_failed')->default(0);
            
            // Human review metrics
            $table->integer('events_reviewed')->default(0);
            $table->integer('events_flagged')->default(0);
            
            $table->timestamp('created_at')->useCurrent();
            
            // Unique constraint: one record per company per day
            $table->unique(['company_id', 'metric_date']);
            
            // Indexes
            $table->index(['company_id', 'metric_date']);
            $table->index('metric_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_metrics');
    }
};
