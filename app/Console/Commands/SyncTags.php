<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Tag;
use App\Samsara\Client\SyncAdapter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncTags extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'samsara:sync-tags 
                            {--company= : Sync only a specific company by ID}
                            {--force : Force sync even if recently synced}';

    /**
     * The console command description.
     */
    protected $description = 'Sync tags from Samsara API for all active companies';

    /**
     * Cache key prefix for last sync timestamp.
     */
    private const CACHE_KEY_LAST_SYNC = 'tags_last_sync';

    /**
     * Minimum time between syncs (in seconds) - 1 hour.
     * Tags change less frequently than vehicles/drivers.
     */
    private const SYNC_INTERVAL = 3600;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $companyId = $this->option('company');
        $force = $this->option('force');

        $query = Company::query()
            ->active()
            ->whereNotNull('samsara_api_key')
            ->where('samsara_api_key', '!=', '');

        if ($companyId) {
            $query->where('id', $companyId);
        }

        $companies = $query->get();

        if ($companies->isEmpty()) {
            $this->warn('No companies with Samsara API keys found.');
            return self::SUCCESS;
        }

        $this->info("Found {$companies->count()} companies to sync.");

        $totalCreated = 0;
        $totalUpdated = 0;
        $totalUnchanged = 0;
        $totalFailed = 0;

        foreach ($companies as $company) {
            $this->line('');
            $this->info("Syncing tags for: {$company->name} (ID: {$company->id})");

            // Check if we should skip due to recent sync
            if (!$force && !$this->shouldSync($company->id)) {
                $lastSync = $this->getLastSyncTime($company->id);
                $this->comment("  ⏭ Skipped - Last sync: {$lastSync}");
                continue;
            }

            try {
                $result = $this->syncCompanyTags($company);
                
                $totalCreated += $result['created'];
                $totalUpdated += $result['updated'];
                $totalUnchanged += $result['unchanged'];

                $this->info("  ✓ Created: {$result['created']}, Updated: {$result['updated']}, Unchanged: {$result['unchanged']}");

                // Update last sync timestamp
                $this->updateLastSyncTime($company->id);
            } catch (\Exception $e) {
                $totalFailed++;
                $this->error("  ✗ Error: {$e->getMessage()}");
                Log::error("Tag sync failed for company {$company->id}", [
                    'company_id' => $company->id,
                    'company_name' => $company->name,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        $this->line('');
        $this->info('═══════════════════════════════════════');
        $this->info('Sync Summary:');
        $this->info("  Created:   {$totalCreated}");
        $this->info("  Updated:   {$totalUpdated}");
        $this->info("  Unchanged: {$totalUnchanged}");
        if ($totalFailed > 0) {
            $this->error("  Failed:    {$totalFailed} companies");
        }
        $this->info('═══════════════════════════════════════');

        return $totalFailed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Sync tags for a specific company.
     */
    protected function syncCompanyTags(Company $company): array
    {
        $client = new SyncAdapter($company->getSamsaraApiKey());
        $tags = $client->getAllTags();

        $created = 0;
        $updated = 0;
        $unchanged = 0;

        foreach ($tags as $tagData) {
            $existingTag = Tag::forCompany($company->id)
                ->where('samsara_id', $tagData['id'])
                ->first();
            
            $dataHash = Tag::generateDataHash($tagData);

            if (!$existingTag) {
                Tag::syncFromSamsara($tagData, $company->id);
                $created++;
            } elseif ($existingTag->data_hash !== $dataHash) {
                Tag::syncFromSamsara($tagData, $company->id);
                $updated++;
            } else {
                $unchanged++;
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
        ];
    }

    /**
     * Check if we should sync based on the last sync time.
     */
    protected function shouldSync(int $companyId): bool
    {
        $cacheKey = $this->getCacheKey($companyId);
        $lastSync = Cache::get($cacheKey);

        if (!$lastSync) {
            return true;
        }

        return (time() - $lastSync) > self::SYNC_INTERVAL;
    }

    /**
     * Get the last sync time as a human-readable string.
     */
    protected function getLastSyncTime(int $companyId): string
    {
        $cacheKey = $this->getCacheKey($companyId);
        $lastSync = Cache::get($cacheKey);

        if (!$lastSync) {
            return 'never';
        }

        return \Carbon\Carbon::createFromTimestamp($lastSync)->diffForHumans();
    }

    /**
     * Update the last sync timestamp for a company.
     */
    protected function updateLastSyncTime(int $companyId): void
    {
        $cacheKey = $this->getCacheKey($companyId);
        Cache::put($cacheKey, time(), now()->addDay());
    }

    /**
     * Get the cache key for a company's last sync timestamp.
     */
    protected function getCacheKey(int $companyId): string
    {
        return "company_{$companyId}_" . self::CACHE_KEY_LAST_SYNC;
    }
}


