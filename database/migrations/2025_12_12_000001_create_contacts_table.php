<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            
            // Información básica
            $table->string('name');
            $table->string('role')->nullable(); // e.g., "Supervisor de turno", "Operador de monitoreo"
            
            // Tipo de contacto (determina cómo lo usa el agente de notificaciones)
            $table->enum('type', [
                'operator',           // Conductor/Operador del vehículo
                'monitoring_team',    // Equipo de monitoreo (central)
                'supervisor',         // Supervisor de zona/turno
                'emergency',          // Contacto de emergencia
                'dispatch',           // Centro de despacho
            ]);
            
            // Canales de comunicación
            $table->string('phone')->nullable();           // Teléfono principal (E.164: +521...)
            $table->string('phone_whatsapp')->nullable();  // WhatsApp (si es diferente)
            $table->string('email')->nullable();
            
            // Asociación opcional a entidades
            $table->string('entity_type')->nullable();     // 'vehicle', 'driver', o null para global
            $table->string('entity_id')->nullable();       // ID de Samsara del vehículo o conductor
            
            // Configuración
            $table->boolean('is_default')->default(false); // Es el contacto por defecto para su tipo
            $table->integer('priority')->default(0);       // Orden de prioridad (mayor = más prioritario)
            $table->boolean('is_active')->default(true);   // Si está activo para recibir notificaciones
            
            // Preferencias de notificación
            $table->json('notification_preferences')->nullable(); // Canales preferidos, horarios, etc.
            
            // Metadata
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Índices
            $table->index(['type', 'is_active', 'is_default']);
            $table->index(['entity_type', 'entity_id']);
            $table->index('phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};

