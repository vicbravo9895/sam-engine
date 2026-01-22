<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Esta tabla almacena safety events del stream de Samsara de forma normalizada.
     * Los eventos se guardan para referencia histórica y consultas, NO para procesamiento IA.
     */
    public function up(): void
    {
        Schema::create('safety_events_stream', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            
            // Identificador único del evento en Samsara
            $table->string('samsara_event_id')->index();
            
            // Vehículo
            $table->string('vehicle_id')->nullable()->index();
            $table->string('vehicle_name')->nullable();
            
            // Conductor
            $table->string('driver_id')->nullable()->index();
            $table->string('driver_name')->nullable();
            
            // Ubicación
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('address')->nullable();
            
            // Comportamiento y severidad
            $table->string('primary_behavior_label')->nullable()->index();
            $table->json('behavior_labels')->nullable();
            $table->json('context_labels')->nullable();
            $table->string('severity')->default('info')->index(); // info, warning, critical
            
            // Estado del evento en Samsara
            $table->string('event_state')->nullable()->index(); // needsReview, needsCoaching, dismissed, coached
            
            // Métricas
            $table->decimal('max_acceleration_g', 5, 3)->nullable();
            $table->json('speeding_metadata')->nullable();
            
            // Media (URLs de dashcam)
            $table->json('media_urls')->nullable();
            
            // URLs de Samsara
            $table->string('inbox_event_url')->nullable();
            $table->string('incident_report_url')->nullable();
            
            // Timestamps de Samsara
            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamp('samsara_created_at')->nullable();
            $table->timestamp('samsara_updated_at')->nullable();
            
            // Raw payload para debug/referencia
            $table->json('raw_payload')->nullable();
            
            $table->timestamps();
            
            // Índice único para evitar duplicados
            $table->unique(['company_id', 'samsara_event_id']);
        });

        // Agregar campos de configuración de stream a companies
        Schema::table('companies', function (Blueprint $table) {
            $table->string('safety_stream_cursor')->nullable()->after('settings');
            $table->timestamp('safety_stream_last_sync')->nullable()->after('safety_stream_cursor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('safety_events_stream');
        
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['safety_stream_cursor', 'safety_stream_last_sync']);
        });
    }
};
