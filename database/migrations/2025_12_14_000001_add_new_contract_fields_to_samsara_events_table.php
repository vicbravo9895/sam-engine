<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración para agregar campos del nuevo contrato de respuesta.
 * 
 * Nuevos campos:
 * - alert_context: JSON estructurado del triage
 * - notification_decision: Decisión de notificación (sin side effects)
 * - notification_execution: Resultados de ejecución real
 * - dedupe_key: Clave de deduplicación (indexada)
 * - risk_escalation: Nivel de escalación de riesgo
 * - proactive_flag: Si es alerta proactiva
 * - data_consistency: Información de consistencia de datos
 * - recommended_actions: Lista de acciones recomendadas
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('samsara_events', function (Blueprint $table) {
            // Contexto estructurado del triage
            $table->json('alert_context')->nullable()->after('ai_actions');
            
            // Decisión de notificación (sin side effects)
            $table->json('notification_decision')->nullable()->after('alert_context');
            
            // Resultados de ejecución de notificaciones
            $table->json('notification_execution')->nullable()->after('notification_decision');
            
            // Campos operativos estandarizados
            $table->string('dedupe_key')->nullable()->after('notification_execution');
            $table->string('risk_escalation')->nullable()->after('dedupe_key');
            $table->boolean('proactive_flag')->default(false)->after('risk_escalation');
            $table->json('data_consistency')->nullable()->after('proactive_flag');
            $table->json('recommended_actions')->nullable()->after('data_consistency');
            
            // Índices
            $table->index('dedupe_key');
            $table->index('risk_escalation');
            $table->index('proactive_flag');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('samsara_events', function (Blueprint $table) {
            // Eliminar índices primero
            $table->dropIndex(['dedupe_key']);
            $table->dropIndex(['risk_escalation']);
            $table->dropIndex(['proactive_flag']);
            
            // Eliminar columnas
            $table->dropColumn([
                'alert_context',
                'notification_decision',
                'notification_execution',
                'dedupe_key',
                'risk_escalation',
                'proactive_flag',
                'data_consistency',
                'recommended_actions',
            ]);
        });
    }
};



