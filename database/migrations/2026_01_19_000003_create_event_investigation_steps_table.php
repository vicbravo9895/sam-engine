<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates event_investigation_steps table.
 * 
 * Stores investigation steps for each event (previously in alert_context.investigation_plan JSON array).
 * Normalized for efficient queries and data integrity.
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('event_investigation_steps', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('samsara_event_id')
                ->constrained('samsara_events')
                ->cascadeOnDelete();
            
            $table->text('step_text');
            $table->integer('step_order')->default(0);
            
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes
            $table->index(['samsara_event_id', 'step_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_investigation_steps');
    }
};
