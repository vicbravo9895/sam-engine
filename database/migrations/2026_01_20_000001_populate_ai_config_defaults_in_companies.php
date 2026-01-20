<?php

use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Populates default AI configuration and notification settings for existing companies.
 * 
 * This migration ensures all existing companies have the default configuration
 * values in their settings JSON column, allowing them to customize later via the UI.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $companies = Company::all();
        $updated = 0;

        foreach ($companies as $company) {
            $settings = $company->settings ?? [];
            $needsUpdate = false;

            // Add default ai_config if not present
            if (!isset($settings['ai_config'])) {
                $settings['ai_config'] = Company::DEFAULT_AI_CONFIG;
                $needsUpdate = true;
            }

            // Add default notifications config if not present
            if (!isset($settings['notifications'])) {
                $settings['notifications'] = Company::DEFAULT_NOTIFICATION_CONFIG;
                $needsUpdate = true;
            }

            if ($needsUpdate) {
                $company->settings = $settings;
                $company->save();
                $updated++;
            }
        }

        Log::info("Migration: Populated AI config defaults for {$updated} companies");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // We don't remove the settings on rollback as they may have been customized
        // This is intentional to prevent data loss
    }
};
