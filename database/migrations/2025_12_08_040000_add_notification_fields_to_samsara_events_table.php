<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('samsara_events', function (Blueprint $table) {
            // Notification status tracking
            $table->string('notification_status')->default('none')->after('ai_error');
            $table->json('notification_channels')->nullable()->after('notification_status');
            $table->timestamp('notification_sent_at')->nullable()->after('notification_channels');

            // Voice call tracking
            $table->string('twilio_call_sid')->nullable()->after('notification_sent_at');
            $table->json('call_response')->nullable()->after('twilio_call_sid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('samsara_events', function (Blueprint $table) {
            $table->dropColumn([
                'notification_status',
                'notification_channels',
                'notification_sent_at',
                'twilio_call_sid',
                'call_response',
            ]);
        });
    }
};
