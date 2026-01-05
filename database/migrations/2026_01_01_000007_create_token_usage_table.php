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
        // Tabla para historial detallado de uso de tokens
        Schema::create('token_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('thread_id')->nullable()->index();
            $table->string('model')->nullable(); // ej: gpt-4o, claude-3, etc.
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->string('request_type')->default('chat'); // chat, tool_call, etc.
            $table->json('meta')->nullable(); // datos adicionales
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['thread_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('token_usage');
    }
};

