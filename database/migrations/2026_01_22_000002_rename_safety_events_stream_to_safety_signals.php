<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renames safety_events_stream to safety_signals.
 * 
 * This normalizes the naming to match the new incident correlation system
 * where safety signals are linked to incidents via a pivot table.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::rename('safety_events_stream', 'safety_signals');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('safety_signals', 'safety_events_stream');
    }
};
