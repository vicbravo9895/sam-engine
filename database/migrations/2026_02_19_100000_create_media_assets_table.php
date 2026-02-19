<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('assetable');
            $table->string('category', 30);
            $table->string('disk', 20);
            $table->text('source_url');
            $table->string('storage_path');
            $table->text('local_url')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index('category');
            $table->index('status');
            $table->index('storage_path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
