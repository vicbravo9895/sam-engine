<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds safety_stream_start_time to companies table.
     * 
     * This is needed because Samsara's cursor pagination requires the same
     * startTime parameter that was used in the original request. Without storing it,
     * the daemon was using different startTime values which invalidated the cursor,
     * causing an infinite loop of resets and always fetching 24h of historical data.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('safety_stream_start_time')
                ->nullable()
                ->after('safety_stream_cursor')
                ->comment('The startTime used when cursor was created, for pagination consistency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('safety_stream_start_time');
        });
    }
};
