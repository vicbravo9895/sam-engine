<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates notification_dedupe_log table.
 * 
 * Tracks dedupe keys to prevent duplicate notifications.
 * Replaces in-memory deduplication that was lost on service restart.
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notification_dedupe_log', function (Blueprint $table) {
            // Dedupe key is the primary key (format: vehicle_id:event_time:alert_type)
            $table->string('dedupe_key', 255)->primary();
            
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->integer('count')->default(1);
            
            // Index for cleanup queries
            $table->index('last_seen_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_dedupe_log');
    }
};
