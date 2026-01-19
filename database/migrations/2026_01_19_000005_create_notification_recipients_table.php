<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates notification_recipients table.
 * 
 * Stores recipients for notification decisions (previously in notification_decision.recipients JSON array).
 * Many-to-one relationship with notification_decisions.
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notification_recipients', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('notification_decision_id')
                ->constrained('notification_decisions')
                ->cascadeOnDelete();
            
            $table->enum('recipient_type', [
                'operator',
                'monitoring_team',
                'supervisor',
                'emergency',
                'dispatch',
                'other'  // Para casos genéricos o de prueba
            ]);
            
            $table->string('phone', 20)->nullable();
            $table->string('whatsapp', 20)->nullable();
            $table->integer('priority')->default(999);
            
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes
            $table->index(['notification_decision_id', 'priority']);
            $table->index('recipient_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_recipients');
    }
};
