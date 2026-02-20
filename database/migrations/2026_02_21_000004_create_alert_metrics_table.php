<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_metrics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('alert_id')->unique();

            $table->timestampTz('ai_started_at')->nullable();
            $table->timestampTz('ai_finished_at')->nullable();
            $table->unsignedInteger('pipeline_latency_ms')->nullable();
            $table->unsignedInteger('ai_tokens')->nullable();
            $table->decimal('ai_cost_estimate', 10, 6)->nullable();
            $table->timestampTz('notification_sent_at')->nullable();

            $table->timestamps();

            $table->foreign('alert_id')->references('id')->on('alerts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_metrics');
    }
};
