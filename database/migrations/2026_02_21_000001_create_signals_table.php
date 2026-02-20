<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('company_id');
            $table->string('source', 20); // webhook, stream, api
            $table->string('samsara_event_id')->nullable();
            $table->string('event_type');
            $table->string('event_description')->nullable();
            $table->string('vehicle_id')->nullable();
            $table->string('vehicle_name')->nullable();
            $table->string('driver_id')->nullable();
            $table->string('driver_name')->nullable();
            $table->string('severity', 20)->nullable();
            $table->timestampTz('occurred_at')->nullable();
            $table->jsonb('raw_payload')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();

            $table->index(['company_id', 'occurred_at']);
            $table->index(['company_id', 'vehicle_id', 'occurred_at']);
            $table->unique(['company_id', 'samsara_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signals');
    }
};
