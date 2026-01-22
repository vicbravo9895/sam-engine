<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the legacy alert_correlations table.
 * 
 * This table was a junction between alert_incidents and samsara_events.
 * The new system uses incident_safety_signals pivot instead.
 * 
 * IMPORTANT: Must be dropped BEFORE alert_incidents due to FK constraint.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('alert_correlations');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('alert_correlations', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('incident_id')
                ->constrained('alert_incidents')
                ->cascadeOnDelete();
            
            $table->foreignId('samsara_event_id')
                ->constrained('samsara_events')
                ->cascadeOnDelete();
            
            $table->enum('correlation_type', [
                'temporal',
                'causal',
                'pattern'
            ]);
            
            $table->decimal('correlation_strength', 3, 2)->default(0.5);
            $table->integer('time_delta_seconds')->nullable();
            
            $table->enum('detected_by', [
                'ai',
                'rule',
                'human'
            ]);
            
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            
            $table->unique(['incident_id', 'samsara_event_id']);
            $table->index('samsara_event_id');
            $table->index('incident_id');
            $table->index(['correlation_type', 'correlation_strength']);
        });
    }
};
