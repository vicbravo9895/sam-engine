<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resúmenes de turno generados automáticamente por IA.
     */
    public function up(): void
    {
        Schema::create('shift_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('shift_label')->nullable(); // e.g. "Turno matutino 7:00 - 15:00"
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            
            // Summary content
            $table->text('summary_text'); // Narrative summary in Spanish
            
            // Raw metrics used to generate the summary
            $table->jsonb('metrics')->nullable();
            
            // AI metadata
            $table->string('model_used')->nullable();
            $table->unsignedInteger('tokens_used')->nullable();
            
            // Delivery
            $table->jsonb('delivered_to')->nullable(); // channels/recipients
            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();
            
            $table->index(['company_id', 'period_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_summaries');
    }
};
