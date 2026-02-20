<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_daily_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->date('date');
            $table->string('meter', 100);
            $table->decimal('total_qty', 20, 4)->default(0);
            $table->timestampTz('computed_at');
            $table->timestamps();

            $table->unique(['company_id', 'date', 'meter'], 'uds_company_date_meter');
            $table->index(['company_id', 'meter'], 'uds_company_meter');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_daily_summaries');
    }
};
