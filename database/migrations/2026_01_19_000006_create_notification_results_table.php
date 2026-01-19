<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates notification_results table.
 * 
 * Stores results of notification executions (previously in notification_execution.results JSON array).
 * Tracks success/failure of each notification attempt.
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notification_results', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('samsara_event_id')
                ->constrained('samsara_events')
                ->cascadeOnDelete();
            
            $table->enum('channel', ['sms', 'whatsapp', 'call']);
            $table->string('recipient_type', 50)->nullable();
            $table->string('to_number', 20);
            $table->boolean('success');
            $table->text('error')->nullable();
            $table->string('call_sid', 100)->nullable();
            $table->string('message_sid', 100)->nullable();
            $table->timestamp('timestamp_utc');
            
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes
            $table->index(['samsara_event_id', 'timestamp_utc']);
            $table->index(['channel', 'success', 'timestamp_utc']);
            $table->index('call_sid');
            $table->index('message_sid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_results');
    }
};
