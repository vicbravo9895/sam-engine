<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE notification_results ALTER COLUMN to_number TYPE varchar(50)');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE notification_results MODIFY to_number VARCHAR(50) NOT NULL');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE notification_results ALTER COLUMN to_number TYPE varchar(20)');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE notification_results MODIFY to_number VARCHAR(20) NOT NULL');
        }
    }
};
