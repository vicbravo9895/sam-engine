<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel Pennant feature flags table.
 *
 * This migration is the equivalent of running:
 *   php artisan vendor:publish --provider="Laravel\Pennant\PennantServiceProvider"
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('scope');
            $table->text('value');
            $table->timestamps();

            $table->unique(['name', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
