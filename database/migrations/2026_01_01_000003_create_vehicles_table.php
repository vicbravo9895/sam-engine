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
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('samsara_id')->unique(); // Samsara vehicle ID
            $table->string('name')->nullable();
            $table->string('vin')->nullable();
            $table->string('serial')->nullable();
            $table->string('esn')->nullable();
            $table->string('license_plate')->nullable();
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->string('year')->nullable();
            $table->text('notes')->nullable();
            
            // Aux input types
            $table->string('aux_input_type_1')->nullable();
            $table->string('aux_input_type_2')->nullable();
            $table->string('aux_input_type_3')->nullable();
            $table->string('aux_input_type_4')->nullable();
            $table->string('aux_input_type_5')->nullable();
            $table->string('aux_input_type_6')->nullable();
            $table->string('aux_input_type_7')->nullable();
            $table->string('aux_input_type_8')->nullable();
            $table->string('aux_input_type_9')->nullable();
            $table->string('aux_input_type_10')->nullable();
            $table->string('aux_input_type_11')->nullable();
            $table->string('aux_input_type_12')->nullable();
            $table->string('aux_input_type_13')->nullable();
            
            // Camera and gateway
            $table->string('camera_serial')->nullable();
            $table->json('gateway')->nullable(); // model, serial
            
            // Settings
            $table->string('harsh_acceleration_setting_type')->nullable();
            $table->boolean('is_remote_privacy_button_enabled')->default(false);
            $table->string('vehicle_regulation_mode')->nullable();
            $table->string('vehicle_type')->nullable();
            
            // Weight
            $table->integer('vehicle_weight')->nullable();
            $table->integer('vehicle_weight_in_kilograms')->nullable();
            $table->integer('vehicle_weight_in_pounds')->nullable();
            
            // Complex JSON fields
            $table->json('attributes')->nullable();
            $table->json('external_ids')->nullable();
            $table->json('sensor_configuration')->nullable();
            $table->json('static_assigned_driver')->nullable();
            $table->json('tags')->nullable();
            
            // Samsara timestamps
            $table->timestamp('samsara_created_at')->nullable();
            $table->timestamp('samsara_updated_at')->nullable();
            
            // Local hash for change detection
            $table->string('data_hash')->nullable();
            
            $table->timestamps();
            
            $table->index('company_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};

