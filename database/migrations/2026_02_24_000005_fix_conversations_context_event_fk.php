<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The context_event_id FK was originally pointing to samsara_events,
     * but after the alerts migration it should point to alerts.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign('conversations_context_event_id_foreign');

            $table->foreign('context_event_id')
                ->references('id')
                ->on('alerts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign('conversations_context_event_id_foreign');

            $table->foreign('context_event_id')
                ->references('id')
                ->on('samsara_events')
                ->nullOnDelete();
        });
    }
};
