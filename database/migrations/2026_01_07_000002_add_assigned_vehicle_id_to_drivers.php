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
        Schema::table('drivers', function (Blueprint $table) {
            // Store the Samsara ID of the assigned vehicle for easy relationship queries
            $table->string('assigned_vehicle_samsara_id')->nullable()->after('static_assigned_vehicle')->index();
        });

        Schema::table('vehicles', function (Blueprint $table) {
            // Store the Samsara ID of the assigned driver for easy relationship queries
            $table->string('assigned_driver_samsara_id')->nullable()->after('static_assigned_driver')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn('assigned_vehicle_samsara_id');
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('assigned_driver_samsara_id');
        });
    }
};


