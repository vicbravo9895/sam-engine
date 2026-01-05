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
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('samsara_id')->unique(); // Samsara tag ID
            $table->string('name');
            $table->string('parent_tag_id')->nullable(); // Reference to parent tag (for hierarchical tags)
            
            // Related entities (stored as JSON arrays of IDs/names)
            $table->json('addresses')->nullable();    // Addresses tagged with this tag
            $table->json('assets')->nullable();       // Assets (vehicles, equipment) tagged
            $table->json('drivers')->nullable();      // Drivers tagged
            $table->json('machines')->nullable();     // Machines tagged
            $table->json('sensors')->nullable();      // Sensors tagged
            $table->json('vehicles')->nullable();     // Vehicles specifically tagged
            
            // External IDs for integration
            $table->json('external_ids')->nullable();
            
            // Local hash for change detection
            $table->string('data_hash')->nullable();
            
            $table->timestamps();
            
            // Indexes for faster lookups
            $table->index('company_id');
            $table->index('parent_tag_id');
            $table->index('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};

