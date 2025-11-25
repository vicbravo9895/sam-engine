<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('samsara_events', function (Blueprint $table) {
            $table->id();

            // Información del evento de Samsara
            $table->string('event_type')->index(); // 'safety_event', 'alert', 'panic_button', etc.
            $table->string('samsara_event_id')->nullable()->index();

            // Información del vehículo
            $table->string('vehicle_id')->nullable()->index();
            $table->string('vehicle_name')->nullable();

            // Información del conductor
            $table->string('driver_id')->nullable()->index();
            $table->string('driver_name')->nullable();

            // Severidad y timestamp
            $table->enum('severity', ['info', 'warning', 'critical'])->default('info')->index();
            $table->timestamp('occurred_at')->nullable()->index();

            // Payload completo de Samsara
            $table->json('raw_payload');

            // Estado del procesamiento de IA
            $table->enum('ai_status', ['pending', 'processing', 'investigating', 'completed', 'failed'])
                ->default('pending')
                ->index();

            // Resultados del análisis de IA
            $table->json('ai_assessment')->nullable();
            $table->json('ai_actions')->nullable();
            $table->text('ai_message')->nullable();
            $table->timestamp('ai_processed_at')->nullable();
            $table->text('ai_error')->nullable();

            // Campos para investigación continua
            $table->timestamp('last_investigation_at')->nullable();
            $table->integer('investigation_count')->default(0);
            $table->integer('next_check_minutes')->nullable();
            $table->json('investigation_history')->nullable();

            // Timestamps estándar de Laravel
            $table->timestamps();

            // Índices compuestos para queries comunes
            $table->index(['ai_status', 'created_at']);
            $table->index(['severity', 'ai_status']);
            $table->index(['vehicle_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('samsara_events');
    }
};
