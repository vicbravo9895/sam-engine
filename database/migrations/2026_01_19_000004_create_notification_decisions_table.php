<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates notification_decisions table.
 * 
 * Stores notification decisions for each event (previously in notification_decision JSON).
 * One-to-one relationship with samsara_events.
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notification_decisions', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('samsara_event_id')
                ->unique()
                ->constrained('samsara_events')
                ->cascadeOnDelete();
            
            $table->boolean('should_notify')->default(false);
            
            $table->enum('escalation_level', [
                'critical',
                'high',
                'low',
                'none'
            ])->default('none');
            
            $table->text('message_text')->nullable();
            $table->string('call_script', 500)->nullable();
            $table->text('reason')->nullable();
            
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes
            $table->index('samsara_event_id');
            $table->index('should_notify');
            $table->index(['should_notify', 'escalation_level']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_decisions');
    }
};
