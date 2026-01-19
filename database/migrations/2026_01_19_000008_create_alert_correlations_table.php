<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates alert_correlations table.
 * 
 * Links individual alerts to incidents with correlation metadata.
 * Junction table between alert_incidents and samsara_events.
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
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
                'temporal',  // Events close in time
                'causal',    // One event likely caused another
                'pattern'    // Part of a behavioral pattern
            ]);
            
            // Strength of correlation (0.0 to 1.0)
            $table->decimal('correlation_strength', 3, 2)->default(0.5);
            
            // Time difference between this event and primary event (in seconds)
            $table->integer('time_delta_seconds')->nullable();
            
            // How the correlation was detected
            $table->enum('detected_by', [
                'ai',     // Detected by correlation agent
                'rule',   // Detected by deterministic rules
                'human'   // Manually linked by human
            ]);
            
            // Additional metadata
            $table->jsonb('metadata')->nullable();
            
            $table->timestamp('created_at')->useCurrent();
            
            // Unique constraint: one event can only be in one incident once
            $table->unique(['incident_id', 'samsara_event_id']);
            
            // Indexes
            $table->index('samsara_event_id');
            $table->index('incident_id');
            $table->index(['correlation_type', 'correlation_strength']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_correlations');
    }
};
