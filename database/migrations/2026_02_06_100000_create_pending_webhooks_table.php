<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Cola de webhooks que no pudieron asociarse a un vehículo/empresa.
     * Se reprocesan automáticamente cuando el vehículo se sincroniza.
     */
    public function up(): void
    {
        Schema::create('pending_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('vehicle_samsara_id')->index();
            $table->string('event_type')->nullable();
            $table->jsonb('raw_payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->string('resolution_note')->nullable();
            $table->timestamps();

            // Index for the retry query
            $table->index(['resolved_at', 'attempts']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pending_webhooks');
    }
};
