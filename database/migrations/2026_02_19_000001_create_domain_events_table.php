<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_events', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('company_id')->constrained('companies');
            $table->timestampTz('occurred_at')->useCurrent();
            $table->string('entity_type');
            $table->string('entity_id');
            $table->string('event_type');
            $table->string('actor_type')->default('system');
            $table->string('actor_id')->nullable();
            $table->string('traceparent')->nullable();
            $table->string('correlation_id')->nullable();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->jsonb('payload')->default('{}');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['company_id', 'occurred_at'], 'de_company_time');
            $table->index(['company_id', 'entity_type', 'entity_id', 'occurred_at'], 'de_entity');
            $table->index(['company_id', 'event_type', 'occurred_at'], 'de_type_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_events');
    }
};
