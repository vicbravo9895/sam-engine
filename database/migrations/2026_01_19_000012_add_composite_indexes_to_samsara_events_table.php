<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds composite indexes to samsara_events table.
 * 
 * These indexes optimize common dashboard queries that filter by:
 * - company_id + ai_status + occurred_at (event listing)
 * - company_id + severity + ai_status (severity filtering)
 * - company_id + human_status + reviewed_at (review queue)
 * - vehicle_id + occurred_at (vehicle history)
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('samsara_events', function (Blueprint $table) {
            // Primary dashboard query: list events for company by status
            $table->index(
                ['company_id', 'ai_status', 'occurred_at'],
                'idx_company_status_occurred'
            );
            
            // Severity filtering for company
            $table->index(
                ['company_id', 'severity', 'ai_status'],
                'idx_company_severity_status'
            );
            
            // Human review queue
            $table->index(
                ['company_id', 'human_status', 'reviewed_at'],
                'idx_company_human_status'
            );
            
            // Vehicle history lookup (partial index concept - all vehicle_ids)
            $table->index(
                ['vehicle_id', 'occurred_at'],
                'idx_vehicle_occurred'
            );
            
            // Driver history lookup
            $table->index(
                ['driver_id', 'occurred_at'],
                'idx_driver_occurred'
            );
            
            // Correlation queries: find recent events for same vehicle
            $table->index(
                ['company_id', 'vehicle_id', 'occurred_at'],
                'idx_company_vehicle_occurred'
            );
            
            // Risk escalation filtering
            $table->index(
                ['company_id', 'risk_escalation', 'occurred_at'],
                'idx_company_risk_occurred'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('samsara_events', function (Blueprint $table) {
            $table->dropIndex('idx_company_status_occurred');
            $table->dropIndex('idx_company_severity_status');
            $table->dropIndex('idx_company_human_status');
            $table->dropIndex('idx_vehicle_occurred');
            $table->dropIndex('idx_driver_occurred');
            $table->dropIndex('idx_company_vehicle_occurred');
            $table->dropIndex('idx_company_risk_occurred');
        });
    }
};
