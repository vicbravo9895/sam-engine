<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * T5: Copilot contextual en alerta.
     * 
     * Permite vincular una conversación del copilot a un evento de Samsara
     * para que el agente tenga contexto operativo automático.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('context_event_id')
                ->nullable()
                ->after('company_id')
                ->constrained('samsara_events')
                ->nullOnDelete();

            $table->json('context_payload')
                ->nullable()
                ->after('context_event_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('context_event_id');
            $table->dropColumn('context_payload');
        });
    }
};
