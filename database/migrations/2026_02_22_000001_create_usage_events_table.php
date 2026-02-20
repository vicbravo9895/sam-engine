<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('company_id')->constrained('companies');
            $table->timestampTz('occurred_at');
            $table->string('meter', 100);
            $table->decimal('qty', 16, 4);
            $table->jsonb('dimensions')->nullable();
            $table->string('idempotency_key', 255)->unique();
            $table->timestamps();

            $table->index(['company_id', 'meter', 'occurred_at'], 'ue_company_meter_time');
            $table->index(['company_id', 'occurred_at'], 'ue_company_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
    }
};
