<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('notification_results', function (Blueprint $table) {
            $table->string('status_current', 30)->default('queued')->after('message_sid');
        });
    }

    public function down(): void
    {
        Schema::table('notification_results', function (Blueprint $table) {
            $table->dropColumn('status_current');
        });
    }
};
