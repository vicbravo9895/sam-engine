<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates event_recommended_actions table.
 * 
 * Stores recommended actions for each event (previously in ai_assessment.recommended_actions JSON array).
 * Normalized for efficient queries and data integrity.
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('event_recommended_actions', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('samsara_event_id')
                ->constrained('samsara_events')
                ->cascadeOnDelete();
            
            $table->text('action_text');
            $table->integer('display_order')->default(0);
            
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes
            $table->index(['samsara_event_id', 'display_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_recommended_actions');
    }
};
