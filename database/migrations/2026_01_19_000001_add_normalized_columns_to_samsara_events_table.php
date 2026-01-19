<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration to normalize samsara_events table.
 * 
 * Adds critical columns that were previously stored in JSON fields:
 * - verdict, likelihood, confidence (from ai_assessment)
 * - reasoning, monitoring_reason (from ai_assessment)
 * - alert_kind, triage_notes, investigation_strategy (from alert_context)
 * - time window configuration columns
 * - supporting_evidence as validated JSONB (the only JSON field needed)
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('samsara_events', function (Blueprint $table) {
            // =====================================================================
            // AI Assessment fields (previously in ai_assessment JSON)
            // =====================================================================
            
            // Verdict - the main AI conclusion
            $table->enum('verdict', [
                'real_panic',
                'confirmed_violation',
                'needs_review',
                'uncertain',
                'likely_false_positive',
                'no_action_needed',
                'risk_detected'
            ])->nullable()->after('ai_message');
            
            // Likelihood of the alert being real/important
            $table->enum('likelihood', ['high', 'medium', 'low'])->nullable()->after('verdict');
            
            // Confidence score (0.0 to 1.0)
            $table->decimal('confidence', 3, 2)->nullable()->after('likelihood');
            
            // Technical reasoning explanation
            $table->text('reasoning')->nullable()->after('confidence');
            
            // Monitoring reason (why we're monitoring)
            $table->text('monitoring_reason')->nullable()->after('reasoning');
            
            // =====================================================================
            // Alert Context fields (previously in alert_context JSON)
            // =====================================================================
            
            // Classification of the alert
            $table->enum('alert_kind', [
                'panic',
                'safety',
                'tampering',
                'connectivity',
                'unknown'
            ])->nullable()->after('monitoring_reason');
            
            // Triage notes
            $table->text('triage_notes')->nullable()->after('alert_kind');
            
            // Investigation strategy
            $table->text('investigation_strategy')->nullable()->after('triage_notes');
            
            // =====================================================================
            // Time window configuration (previously in alert_context.time_window)
            // =====================================================================
            
            $table->integer('correlation_window_minutes')->default(20)->after('investigation_strategy');
            $table->integer('media_window_seconds')->default(120)->after('correlation_window_minutes');
            $table->integer('safety_events_before_minutes')->default(30)->after('media_window_seconds');
            $table->integer('safety_events_after_minutes')->default(10)->after('safety_events_before_minutes');
            
            // =====================================================================
            // Supporting evidence (validated JSONB - only variable JSON needed)
            // =====================================================================
            
            $table->jsonb('supporting_evidence')->nullable()->after('safety_events_after_minutes');
            
            // =====================================================================
            // Raw AI output for audit/provenance (optional)
            // =====================================================================
            
            $table->jsonb('raw_ai_output')->nullable()->after('supporting_evidence');
            
            // =====================================================================
            // Indexes for new columns
            // =====================================================================
            
            $table->index('verdict');
            $table->index('likelihood');
            $table->index('alert_kind');
            $table->index(['verdict', 'likelihood']);
            $table->index(['alert_kind', 'verdict']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('samsara_events', function (Blueprint $table) {
            // Drop indexes first
            $table->dropIndex(['verdict']);
            $table->dropIndex(['likelihood']);
            $table->dropIndex(['alert_kind']);
            $table->dropIndex(['verdict', 'likelihood']);
            $table->dropIndex(['alert_kind', 'verdict']);
            
            // Drop columns
            $table->dropColumn([
                'verdict',
                'likelihood',
                'confidence',
                'reasoning',
                'monitoring_reason',
                'alert_kind',
                'triage_notes',
                'investigation_strategy',
                'correlation_window_minutes',
                'media_window_seconds',
                'safety_events_before_minutes',
                'safety_events_after_minutes',
                'supporting_evidence',
                'raw_ai_output',
            ]);
        });
    }
};
