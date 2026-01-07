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
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->enum('status', ['pending', 'streaming', 'completed', 'failed'])->default('completed')->after('role');
            $table->text('streaming_content')->nullable()->after('content'); // Para acumular chunks mientras se genera
            $table->timestamp('streaming_started_at')->nullable()->after('streaming_content');
            $table->timestamp('streaming_completed_at')->nullable()->after('streaming_started_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn(['status', 'streaming_content', 'streaming_started_at', 'streaming_completed_at']);
        });
    }
};

