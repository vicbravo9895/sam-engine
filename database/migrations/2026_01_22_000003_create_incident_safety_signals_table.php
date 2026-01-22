<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates incident_safety_signals pivot table.
 * 
 * Links incidents to safety signals with metadata about
 * the role of each signal in the incident context.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('incident_safety_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('safety_signal_id')->constrained('safety_signals')->cascadeOnDelete();
            
            // Role of this signal in the incident
            $table->enum('role', ['supporting', 'contradicting', 'context'])->default('supporting');
            
            // Relevance score (0.00 to 1.00)
            $table->decimal('relevance_score', 3, 2)->default(0.50);
            
            $table->timestamp('created_at')->useCurrent();
            
            // Unique constraint: one signal can only be linked to an incident once
            $table->unique(['incident_id', 'safety_signal_id'], 'incident_signal_unique');
            
            // Index for reverse lookups
            $table->index('safety_signal_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incident_safety_signals');
    }
};
