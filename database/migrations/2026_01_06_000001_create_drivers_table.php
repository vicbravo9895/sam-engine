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
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            
            // Samsara identifiers
            $table->string('samsara_id')->index();
            $table->string('username')->nullable();
            $table->string('current_id_card_code')->nullable();
            
            // Basic info
            $table->string('name');
            $table->string('phone')->nullable();
            $table->text('profile_image_url')->nullable(); // TEXT for long signed S3 URLs
            $table->text('notes')->nullable();
            
            // License info
            $table->string('license_number')->nullable();
            $table->string('license_state')->nullable();
            
            // Status
            $table->string('driver_activation_status')->default('active'); // active, deactivated
            $table->boolean('is_deactivated')->default(false);
            
            // Locale & timezone
            $table->string('timezone')->nullable();
            $table->string('locale')->nullable();
            
            // ELD settings
            $table->boolean('eld_exempt')->default(false);
            $table->string('eld_exempt_reason')->nullable();
            $table->boolean('eld_adverse_weather_exemption_enabled')->default(false);
            $table->boolean('eld_big_day_exemption_enabled')->default(false);
            $table->integer('eld_day_start_hour')->default(0);
            $table->boolean('eld_pc_enabled')->default(false);
            $table->boolean('eld_ym_enabled')->default(false);
            
            // Tachograph (EU)
            $table->string('tachograph_card_number')->nullable();
            
            // Features
            $table->boolean('has_driving_features_hidden')->default(false);
            $table->boolean('has_vehicle_unpinning_enabled')->default(false);
            $table->boolean('waiting_time_duty_status_enabled')->default(false);
            
            // JSON fields for complex nested data
            $table->json('attributes')->nullable();
            $table->json('carrier_settings')->nullable();
            $table->json('eld_settings')->nullable();
            $table->json('external_ids')->nullable();
            $table->json('static_assigned_vehicle')->nullable();
            $table->json('tags')->nullable();
            $table->json('peer_group_tag')->nullable();
            $table->json('vehicle_group_tag')->nullable();
            $table->json('us_driver_ruleset_override')->nullable();
            
            // Samsara timestamps
            $table->timestamp('samsara_created_at')->nullable();
            $table->timestamp('samsara_updated_at')->nullable();
            
            // Change detection
            $table->string('data_hash', 32)->nullable();
            
            $table->timestamps();
            
            // Unique constraint: same driver can exist for different companies
            $table->unique(['company_id', 'samsara_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};

