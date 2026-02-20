<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('alert_id');
            $table->uuid('signal_id');
            $table->string('role', 20)->default('primary'); // primary, correlated, context
            $table->decimal('relevance', 3, 2)->nullable();
            $table->timestamps();

            $table->foreign('alert_id')->references('id')->on('alerts')->cascadeOnDelete();
            $table->foreign('signal_id')->references('id')->on('signals')->cascadeOnDelete();

            $table->unique(['alert_id', 'signal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_sources');
    }
};
