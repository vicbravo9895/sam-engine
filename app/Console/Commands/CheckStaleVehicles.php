<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendStaleVehicleAlertJob;
use App\Models\Company;
use App\Models\StaleVehicleAlert;
use App\Models\VehicleStat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckStaleVehicles extends Command
{
    protected $signature = 'samsara:check-stale-vehicles
                            {--company= : Check only a specific company by ID}';

    protected $description = 'Detect vehicles that stopped reporting stats and send notifications';

    public function handle(): int
    {
        $query = Company::query()
            ->active()
            ->whereNotNull('samsara_api_key')
            ->where('samsara_api_key', '!=', '');

        if ($companyId = $this->option('company')) {
            $query->where('id', $companyId);
        }

        $companies = $query->get();

        if ($companies->isEmpty()) {
            $this->warn('No active companies found.');
            return self::SUCCESS;
        }

        $totalAlerts = 0;
        $totalResolved = 0;

        /** @var Company $company */
        foreach ($companies as $company) {
            $config = $company->getStaleVehicleMonitorConfig();

            if (!($config['enabled'] ?? false)) {
                continue;
            }

            [$alerts, $resolved] = $this->processCompany($company, $config);
            $totalAlerts += $alerts;
            $totalResolved += $resolved;
        }

        $this->info("Done. Alerts sent: {$totalAlerts}, Resolved: {$totalResolved}");

        return self::SUCCESS;
    }

    /**
     * @return array{int, int} [alerts dispatched, alerts resolved]
     */
    private function processCompany(Company $company, array $config): array
    {
        $thresholdMinutes = $config['threshold_minutes'] ?? 30;
        $cooldownMinutes = $config['cooldown_minutes'] ?? 60;

        $staleVehicles = VehicleStat::where('company_id', $company->id)
            ->where(function ($q) use ($thresholdMinutes) {
                $q->where('synced_at', '<', now()->subMinutes($thresholdMinutes))
                  ->orWhereNull('synced_at');
            })
            ->get();

        $alertsDispatched = 0;

        foreach ($staleVehicles as $stat) {
            $recentAlert = StaleVehicleAlert::forCompany($company->id)
                ->forVehicle($stat->samsara_vehicle_id)
                ->unresolved()
                ->first();

            if ($recentAlert && !$recentAlert->alerted_at->lt(now()->subMinutes($cooldownMinutes))) {
                continue;
            }

            SendStaleVehicleAlertJob::dispatch($company->id, $stat, $config);
            $alertsDispatched++;

            Log::info('StaleVehicleCheck: Dispatched alert', [
                'company_id' => $company->id,
                'vehicle_id' => $stat->samsara_vehicle_id,
                'vehicle_name' => $stat->vehicle_name,
                'last_synced_at' => $stat->synced_at?->toIso8601String(),
                'threshold_minutes' => $thresholdMinutes,
            ]);
        }

        $resolved = $this->resolveRecoveredVehicles($company, $thresholdMinutes);

        return [$alertsDispatched, $resolved];
    }

    private function resolveRecoveredVehicles(Company $company, int $thresholdMinutes): int
    {
        $unresolvedAlerts = StaleVehicleAlert::forCompany($company->id)
            ->unresolved()
            ->get();

        $resolved = 0;

        /** @var StaleVehicleAlert $alert */
        foreach ($unresolvedAlerts as $alert) {
            $currentStat = VehicleStat::where('company_id', $company->id)
                ->where('samsara_vehicle_id', $alert->samsara_vehicle_id)
                ->first();

            if (!$currentStat) {
                continue;
            }

            $isReporting = $currentStat->synced_at
                && $currentStat->synced_at->gt(now()->subMinutes($thresholdMinutes));

            if ($isReporting) {
                $alert->markResolved();
                $resolved++;

                Log::info('StaleVehicleCheck: Vehicle recovered, alert resolved', [
                    'company_id' => $company->id,
                    'vehicle_id' => $alert->samsara_vehicle_id,
                    'vehicle_name' => $alert->vehicle_name,
                    'alert_id' => $alert->id,
                ]);
            }
        }

        return $resolved;
    }
}
