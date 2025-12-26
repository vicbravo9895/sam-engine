<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla para audit trail de actividades en alertas.
 * 
 * Registra todas las acciones tanto de AI como de humanos:
 * - Cambios de estado (AI y humano)
 * - Comentarios agregados
 * - Revisiones
 * - Cualquier acción futura
 * 
 * user_id NULL = acción del sistema/AI
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('samsara_event_activities', function (Blueprint $table) {
            $table->id();
            
            // Relación con el evento
            $table->foreignId('samsara_event_id')
                ->constrained('samsara_events')
                ->cascadeOnDelete();
            
            // Usuario que realizó la acción (NULL = sistema/AI)
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            
            // Tipo de acción
            $table->string('action', 50);
            // Ejemplos:
            // - ai_processing_started
            // - ai_completed
            // - ai_failed
            // - ai_investigating
            // - human_reviewed
            // - human_status_changed
            // - comment_added
            // - marked_false_positive
            // - marked_resolved
            // - marked_flagged
            
            // Metadata adicional (cambios, contexto, etc.)
            $table->jsonb('metadata')->nullable();
            // Ejemplo: { "old_status": "pending", "new_status": "reviewed" }
            
            // Timestamp de la acción
            $table->timestamp('created_at')->useCurrent();
            
            // Índices para queries comunes
            $table->index(['samsara_event_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('samsara_event_activities');
    }
};

