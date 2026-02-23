<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE notification_results DROP CONSTRAINT IF EXISTS notification_results_channel_check');
            DB::statement("ALTER TABLE notification_results ADD CONSTRAINT notification_results_channel_check CHECK (channel::text = ANY (ARRAY['sms'::text, 'whatsapp'::text, 'call'::text, 'whatsapp_template'::text]))");
        } elseif ($driver === 'mysql') {
            DB::statement("ALTER TABLE notification_results MODIFY COLUMN channel ENUM('sms', 'whatsapp', 'call', 'whatsapp_template') NOT NULL");
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE notification_results DROP CONSTRAINT IF EXISTS notification_results_channel_check');
            DB::statement("ALTER TABLE notification_results ADD CONSTRAINT notification_results_channel_check CHECK (channel::text = ANY (ARRAY['sms'::text, 'whatsapp'::text, 'call'::text]))");
        } elseif ($driver === 'mysql') {
            DB::statement("ALTER TABLE notification_results MODIFY COLUMN channel ENUM('sms', 'whatsapp', 'call') NOT NULL");
        }
    }
};
