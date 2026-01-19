<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates alert_incidents table.
 * 
 * Groups related alerts into incidents for correlation detection.
 * Example: harsh_braking + panic_button within 2 minutes = collision incident
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('alert_incidents', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            
            $table->enum('incident_type', [
                'collision',      // harsh_braking + panic_button/collision_warning
                'emergency',      // camera_obstruction + panic_button
                'pattern',        // multiple similar events in short time
                'unknown'         // detected but type unclear
            ]);
            
            // Primary event that triggered the incident detection
            $table->foreignId('primary_event_id')
                ->constrained('samsara_events')
                ->cascadeOnDelete();
            
            $table->enum('severity', ['info', 'warning', 'critical']);
            
            $table->enum('status', [
                'open',           // Just detected
                'investigating',  // Under AI/human investigation
                'resolved',       // Confirmed and handled
                'false_positive'  // Determined to not be a real incident
            ])->default('open');
            
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            
            // AI-generated summary of the incident
            $table->text('ai_summary')->nullable();
            
            // Additional metadata (flexible for future needs)
            $table->jsonb('metadata')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['company_id', 'status', 'detected_at']);
            $table->index(['company_id', 'incident_type', 'status']);
            $table->index('primary_event_id');
            $table->index(['status', 'detected_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_incidents');
    }
};
