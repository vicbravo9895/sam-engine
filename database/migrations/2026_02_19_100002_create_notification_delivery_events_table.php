<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notification_delivery_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('notification_result_id')
                ->constrained('notification_results')
                ->cascadeOnDelete();

            $table->string('provider_sid', 100);
            $table->string('status', 30);
            $table->string('error_code', 20)->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('raw_callback');
            $table->timestampTz('received_at');

            $table->index('provider_sid');
            $table->index(['notification_result_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_delivery_events');
    }
};
