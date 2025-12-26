<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega campos para tracking de revisión humana en samsara_events.
 * 
 * human_status: Estado de revisión humana (independiente del ai_status)
 * reviewed_by_id: Usuario que revisó/actuó sobre la alerta
 * reviewed_at: Timestamp de la última revisión humana
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('samsara_events', function (Blueprint $table) {
            // Estado de revisión humana (independiente del AI)
            $table->enum('human_status', [
                'pending',        // Sin revisar
                'reviewed',       // Revisado por humano
                'flagged',        // Marcado para seguimiento
                'resolved',       // Resuelto por humano
                'false_positive', // Confirmado como falso positivo
            ])->default('pending')->after('ai_error');
            
            // Quién revisó y cuándo
            $table->foreignId('reviewed_by_id')
                ->nullable()
                ->after('human_status')
                ->constrained('users')
                ->nullOnDelete();
            
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_id');
            
            // Índices para queries de dashboard
            $table->index('human_status');
            $table->index(['human_status', 'ai_status']);
            $table->index(['reviewed_by_id', 'reviewed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('samsara_events', function (Blueprint $table) {
            // Eliminar índices primero
            $table->dropIndex(['human_status']);
            $table->dropIndex(['human_status', 'ai_status']);
            $table->dropIndex(['reviewed_by_id', 'reviewed_at']);
            
            // Eliminar foreign key y columnas
            $table->dropForeign(['reviewed_by_id']);
            $table->dropColumn([
                'human_status',
                'reviewed_by_id',
                'reviewed_at',
            ]);
        });
    }
};

