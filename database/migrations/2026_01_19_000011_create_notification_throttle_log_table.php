<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates notification_throttle_logs table.
 * 
 * Tracks notification timestamps for throttling by vehicle/driver.
 * Replaces in-memory throttling that was lost on service restart.
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notification_throttle_logs', function (Blueprint $table) {
            $table->id();
            
            // Throttle key format: "v:123" or "v:123:d:456"
            $table->string('throttle_key', 255);
            
            $table->timestamp('notification_timestamp');
            
            // Optional reference to the event that triggered this notification
            $table->foreignId('samsara_event_id')
                ->nullable()
                ->constrained('samsara_events')
                ->nullOnDelete();
            
            // Indexes for efficient throttle checks
            $table->index(['throttle_key', 'notification_timestamp']);
            $table->index('notification_timestamp'); // For cleanup queries
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_throttle_logs');
    }
};
