<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notification_acks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('samsara_event_id')
                ->constrained('samsara_events')
                ->cascadeOnDelete();

            $table->foreignId('notification_result_id')
                ->nullable()
                ->constrained('notification_results')
                ->nullOnDelete();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->enum('ack_type', ['ui', 'reply', 'ivr']);

            $table->foreignId('ack_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->jsonb('ack_payload')->default('{}');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('samsara_event_id');
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_acks');
    }
};
