<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\VehicleStat;
use App\Samsara\Client\SyncAdapter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncVehicleStats extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'samsara:sync-vehicle-stats 
                            {--company= : Sync only a specific company by ID}';

    /**
     * The console command description.
     */
    protected $description = 'Sync vehicle stats (GPS, engine state, odometer) from Samsara API for all active companies';

    /**
     * Stat types to sync.
     */
    private const STAT_TYPES = ['gps', 'engineStates', 'obdOdometerMeters'];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $companyId = $this->option('company');

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

        $this->info("Syncing vehicle stats for {$companies->count()} companies...");

        $totalSynced = 0;
        $totalFailed = 0;

        foreach ($companies as $company) {
            try {
                $result = $this->syncCompanyVehicleStats($company);
                $totalSynced += $result['synced'];

                $this->line("  [{$company->name}] Synced: {$result['synced']} vehicles");
            } catch (\Exception $e) {
                $totalFailed++;
                $this->error("  [{$company->name}] Error: {$e->getMessage()}");
                Log::error("Vehicle stats sync failed for company {$company->id}", [
                    'company_id' => $company->id,
                    'company_name' => $company->name,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        $this->info("Sync complete. Total synced: {$totalSynced}, Failed companies: {$totalFailed}");

        return $totalFailed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Sync vehicle stats for a specific company.
     */
    protected function syncCompanyVehicleStats(Company $company): array
    {
        $client = new SyncAdapter($company->getSamsaraApiKey());
        
        // Fetch all vehicle stats with pagination
        $vehicleStats = $client->getAllVehicleStats(self::STAT_TYPES);

        $synced = 0;

        foreach ($vehicleStats as $vehicleData) {
            try {
                VehicleStat::syncFromSamsara($vehicleData, $company->id);
                $synced++;
            } catch (\Exception $e) {
                Log::warning("Failed to sync vehicle stat", [
                    'company_id' => $company->id,
                    'vehicle_id' => $vehicleData['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'synced' => $synced,
            'total' => count($vehicleStats),
        ];
    }
}
