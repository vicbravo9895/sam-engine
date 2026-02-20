<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla notification_throttle_logs si no existe.
 *
 * Resuelve Sentry: SQLSTATE[42P01] relation "notification_throttle_logs" does not exist
 * cuando en producción la migración original no se ejecutó o la tabla fue eliminada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notification_throttle_logs')) {
            return;
        }

        Schema::create('notification_throttle_logs', function (Blueprint $table) {
            $table->id();
            $table->string('throttle_key', 255);
            $table->timestamp('notification_timestamp');

            if (Schema::hasTable('alerts')) {
                $table->unsignedBigInteger('alert_id')->nullable();
                $table->foreign('alert_id')->references('id')->on('alerts')->nullOnDelete();
            } else {
                $table->unsignedBigInteger('alert_id')->nullable();
            }

            $table->index(['throttle_key', 'notification_timestamp']);
            $table->index('notification_timestamp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_throttle_logs');
    }
};
