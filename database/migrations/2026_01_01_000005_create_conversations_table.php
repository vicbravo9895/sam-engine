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
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            
            $table->string('thread_id')->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->json('meta')->nullable();
            
            // Token tracking fields
            $table->unsignedBigInteger('total_input_tokens')->default(0)->after('meta');
            $table->unsignedBigInteger('total_output_tokens')->default(0)->after('total_input_tokens');
            $table->unsignedBigInteger('total_tokens')->default(0)->after('total_output_tokens');

            $table->index(['user_id', 'id']); 
            $table->index(['thread_id', 'id']);
            $table->index('company_id');
            $table->unique(['user_id', 'thread_id']);
        
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};

