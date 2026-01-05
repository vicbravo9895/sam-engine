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
        // Add company_id to samsara_events table
        Schema::table('samsara_events', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->index('company_id');
        });

        // Add company_id to contacts table
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->index('company_id');
        });

        // Add company_id to samsara_event_comments table
        Schema::table('samsara_event_comments', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('samsara_event_id')->constrained()->nullOnDelete();
            $table->index('company_id');
        });

        // Add company_id to samsara_event_activities table
        Schema::table('samsara_event_activities', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('samsara_event_id')->constrained()->nullOnDelete();
            $table->index('company_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('samsara_event_activities', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });

        Schema::table('samsara_event_comments', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });

        Schema::table('samsara_events', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};

