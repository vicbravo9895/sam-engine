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
        Schema::table('tags', function (Blueprint $table) {
            // Drop the old unique constraint on samsara_id only
            $table->dropUnique(['samsara_id']);
            
            // Add composite unique constraint for multi-tenancy
            // Same tag can exist for different companies
            $table->unique(['company_id', 'samsara_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'samsara_id']);
            $table->unique('samsara_id');
        });
    }
};


