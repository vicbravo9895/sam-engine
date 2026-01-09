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
        Schema::create('vehicle_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('samsara_vehicle_id')->index();
            $table->string('vehicle_name')->nullable();
            
            // GPS Data
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('speed_kmh', 8, 2)->nullable();
            $table->integer('heading_degrees')->nullable();
            $table->string('location_name')->nullable();
            $table->boolean('is_geofence')->default(false);
            $table->string('address_id')->nullable();
            $table->string('address_name')->nullable();
            $table->timestamp('gps_time')->nullable();
            
            // Engine State
            $table->enum('engine_state', ['on', 'off', 'idle'])->nullable();
            $table->timestamp('engine_time')->nullable();
            
            // Odometer
            $table->bigInteger('odometer_meters')->nullable();
            $table->timestamp('odometer_time')->nullable();
            
            // Sync metadata
            $table->timestamp('synced_at')->nullable();
            
            $table->timestamps();
            
            // Unique constraint: one stat record per vehicle per company
            $table->unique(['company_id', 'samsara_vehicle_id']);
            
            // Index for quick lookups
            $table->index(['company_id', 'engine_state']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_stats');
    }
};
