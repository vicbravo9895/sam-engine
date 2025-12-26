<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla para comentarios/notas de monitoristas en alertas.
 * 
 * Sistema simple: un comentario es un comentario.
 * Sin tipos, sin complejidad adicional.
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('samsara_event_comments', function (Blueprint $table) {
            $table->id();
            
            // Relaciones
            $table->foreignId('samsara_event_id')
                ->constrained('samsara_events')
                ->cascadeOnDelete();
            
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            
            // Contenido del comentario
            $table->text('content');
            
            // Timestamps
            $table->timestamps();
            
            // Índices
            $table->index(['samsara_event_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('samsara_event_comments');
    }
};

