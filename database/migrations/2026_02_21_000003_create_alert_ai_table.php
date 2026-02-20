<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_ai', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('alert_id')->unique();

            $table->string('monitoring_reason')->nullable();
            $table->text('triage_notes')->nullable();
            $table->text('investigation_strategy')->nullable();
            $table->jsonb('supporting_evidence')->nullable();
            $table->jsonb('raw_ai_output')->nullable();
            $table->jsonb('alert_context')->nullable();
            $table->jsonb('ai_assessment')->nullable();
            $table->jsonb('ai_actions')->nullable();
            $table->text('ai_error')->nullable();

            $table->unsignedSmallInteger('investigation_count')->default(0);
            $table->timestampTz('last_investigation_at')->nullable();
            $table->unsignedSmallInteger('next_check_minutes')->nullable();
            $table->jsonb('investigation_history')->nullable();

            // Time window config
            $table->unsignedSmallInteger('correlation_window_minutes')->nullable();
            $table->unsignedSmallInteger('media_window_seconds')->nullable();
            $table->unsignedSmallInteger('safety_events_before_minutes')->nullable();
            $table->unsignedSmallInteger('safety_events_after_minutes')->nullable();

            $table->timestamps();

            $table->foreign('alert_id')->references('id')->on('alerts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_ai');
    }
};
