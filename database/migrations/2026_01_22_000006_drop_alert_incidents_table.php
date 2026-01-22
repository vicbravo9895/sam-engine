<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the legacy alert_incidents table.
 * 
 * This table has been replaced by the new `incidents` table
 * which has a more flexible structure for incident tracking.
 * 
 * IMPORTANT: alert_correlations must be dropped first,
 * and samsara_events.incident_id FK must be removed first.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('alert_incidents');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('alert_incidents', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            
            $table->enum('incident_type', [
                'collision',
                'emergency',
                'pattern',
                'unknown'
            ]);
            
            $table->foreignId('primary_event_id')
                ->constrained('samsara_events')
                ->cascadeOnDelete();
            
            $table->enum('severity', ['info', 'warning', 'critical']);
            
            $table->enum('status', [
                'open',
                'investigating',
                'resolved',
                'false_positive'
            ])->default('open');
            
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            
            $table->text('ai_summary')->nullable();
            $table->jsonb('metadata')->nullable();
            
            $table->timestamps();
            
            $table->index(['company_id', 'status', 'detected_at']);
            $table->index(['company_id', 'incident_type', 'status']);
            $table->index('primary_event_id');
            $table->index(['status', 'detected_at']);
        });
    }
};
