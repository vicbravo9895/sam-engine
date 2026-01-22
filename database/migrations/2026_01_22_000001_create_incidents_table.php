<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the canonical `incidents` table.
 * 
 * This replaces the legacy `alert_incidents` table with a more flexible
 * structure that supports:
 * - Multiple sources (webhook, auto_pattern, auto_aggregator, manual)
 * - Priority-based classification (P1-P4)
 * - Dedupe logic for preventing duplicate incidents
 * - Link to safety_signals via pivot table
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            
            // Classification
            $table->string('incident_type'); // collision, emergency, pattern, safety_violation, etc.
            $table->enum('priority', ['P1', 'P2', 'P3', 'P4'])->default('P3');
            $table->enum('severity', ['info', 'warning', 'critical'])->default('warning');
            
            // Status workflow
            $table->enum('status', ['open', 'investigating', 'pending_action', 'resolved', 'false_positive'])->default('open');
            
            // Subject (driver or vehicle)
            $table->string('subject_type')->nullable(); // driver, vehicle
            $table->string('subject_id')->nullable();
            $table->string('subject_name')->nullable();
            
            // Source tracking
            $table->string('source'); // webhook, auto_pattern, auto_aggregator, manual
            $table->string('samsara_event_id')->nullable()->index(); // For webhook-triggered
            
            // Dedupe
            $table->string('dedupe_key')->nullable();
            
            // AI processing
            $table->text('ai_summary')->nullable();
            $table->json('ai_assessment')->nullable();
            $table->json('metadata')->nullable();
            
            // Timestamps
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            
            // Indexes for dedupe and lookups
            $table->unique(['company_id', 'samsara_event_id'], 'incidents_samsara_event_unique');
            $table->unique(['company_id', 'dedupe_key'], 'incidents_dedupe_unique');
            $table->index(['company_id', 'status', 'detected_at']);
            $table->index(['company_id', 'incident_type', 'priority']);
            $table->index(['company_id', 'subject_type', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
