<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\SafetySignal;
use App\Models\VehicleStat;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class TestDetectionEngine extends Command
{
    protected $signature = 'detection:test
                            {scenario : Scenario to test (signal|stale)}
                            {--company= : Company ID (required)}
                            {--label=Collision : Behavior label for signal scenario}
                            {--vehicle= : Samsara vehicle ID (uses first available if omitted)}
                            {--dry-run : Show what would happen without sending notifications}';

    protected $description = 'Test the detection engine by simulating a safety signal or stale vehicle scenario';

    public function handle(): int
    {
        $scenario = $this->argument('scenario');
        $companyId = $this->option('company');

        if (!$companyId) {
            $this->error('--company is required. Example: detection:test signal --company=1');
            return self::FAILURE;
        }

        $company = Company::find($companyId);
        if (!$company) {
            $this->error("Company #{$companyId} not found.");
            return self::FAILURE;
        }

        $this->info("Company: {$company->name} (#{$company->id})");

        return match ($scenario) {
            'signal' => $this->testSignal($company),
            'stale' => $this->testStale($company),
            default => $this->invalidScenario($scenario),
        };
    }

    private function testSignal(Company $company): int
    {
        $label = $this->option('label');
        $this->info("Testing detection rules with label: {$label}");

        $config = $company->getSafetyStreamNotifyConfig();
        $this->table(
            ['Enabled', 'Rules count'],
            [[$config['enabled'] ? 'Yes' : 'No', count($config['rules'] ?? [])]],
        );

        if (!$config['enabled']) {
            $this->warn('Detection engine is disabled for this company. Enable it first.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Configured rules:');
        foreach ($config['rules'] as $rule) {
            $channels = !empty($rule['channels']) ? implode(', ', $rule['channels']) : '(escalation defaults)';
            $recipients = !empty($rule['recipients']) ? implode(', ', $rule['recipients']) : '(escalation defaults)';
            $this->line("  [{$rule['id']}] Conditions: " . implode(' AND ', $rule['conditions'])
                . " → Action: {$rule['action']}"
                . " | Channels: {$channels}"
                . " | Recipients: {$recipients}");
        }

        $vehicleStat = $this->resolveVehicle($company);
        if (!$vehicleStat) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Using vehicle: {$vehicleStat->vehicle_name} ({$vehicleStat->samsara_vehicle_id})");

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN — checking rule matching only, no signal will be created.');
            $testSignal = new SafetySignal([
                'company_id' => $company->id,
                'primary_behavior_label' => $label,
                'behavior_labels' => [$label],
            ]);
            $testSignal->setRelation('company', $company);

            $matched = $testSignal->getMatchedRule();
            if ($matched) {
                $this->info("Rule MATCHED: [{$matched['id']}] action={$matched['action']}");
                $channels = !empty($matched['channels']) ? implode(', ', $matched['channels']) : '(escalation defaults)';
                $recipients = !empty($matched['recipients']) ? implode(', ', $matched['recipients']) : '(escalation defaults)';
                $this->line("  Channels: {$channels}");
                $this->line("  Recipients: {$recipients}");
            } else {
                $this->warn("No rule matched for label '{$label}'. Check your rules.");
            }
            return self::SUCCESS;
        }

        if (!$this->confirm("This will create a real SafetySignal with label '{$label}' and trigger notifications. Continue?")) {
            $this->info('Cancelled.');
            return self::SUCCESS;
        }

        $signal = SafetySignal::create([
            'company_id' => $company->id,
            'samsara_event_id' => 'test-' . Str::uuid()->toString(),
            'vehicle_id' => $vehicleStat->samsara_vehicle_id,
            'vehicle_name' => $vehicleStat->vehicle_name,
            'driver_id' => null,
            'driver_name' => 'Conductor de prueba',
            'latitude' => $vehicleStat->latitude,
            'longitude' => $vehicleStat->longitude,
            'primary_behavior_label' => $label,
            'behavior_labels' => [$label],
            'severity' => in_array($label, SafetySignal::CRITICAL_LABELS) ? 'critical' : 'warning',
            'event_state' => 'needsReview',
            'occurred_at' => now(),
            'samsara_created_at' => now(),
            'samsara_updated_at' => now(),
            'raw_payload' => ['_source' => 'detection_test', '_label' => $label],
        ]);

        $this->info("SafetySignal created (ID: {$signal->id}). The observer should have fired.");
        $this->info('Check logs: sail artisan pail --filter="DetectionEngine"');

        return self::SUCCESS;
    }

    private function testStale(Company $company): int
    {
        $this->info('Testing stale vehicle monitor');

        $config = $company->getStaleVehicleMonitorConfig();
        $this->table(
            ['Enabled', 'Threshold (min)', 'Cooldown (min)', 'Channels', 'Recipients'],
            [[
                $config['enabled'] ? 'Yes' : 'No',
                $config['threshold_minutes'],
                $config['cooldown_minutes'],
                implode(', ', $config['channels'] ?? []),
                implode(', ', $config['recipients'] ?? []),
            ]],
        );

        if (!$config['enabled']) {
            $this->warn('Stale vehicle monitor is disabled. Enable it in the Detection Rules page first.');
            return self::FAILURE;
        }

        $vehicleStat = $this->resolveVehicle($company);
        if (!$vehicleStat) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Vehicle: {$vehicleStat->vehicle_name} ({$vehicleStat->samsara_vehicle_id})");
        $this->line("  Last synced: " . ($vehicleStat->synced_at?->toDateTimeString() ?? 'NEVER'));

        if ($this->option('dry-run')) {
            $threshold = $config['threshold_minutes'];
            $isStale = !$vehicleStat->synced_at || $vehicleStat->synced_at->lt(now()->subMinutes($threshold));
            $this->newLine();
            if ($isStale) {
                $ago = $vehicleStat->synced_at ? $vehicleStat->synced_at->diffForHumans() : 'never reported';
                $this->warn("Vehicle IS stale (last sync: {$ago}, threshold: {$threshold} min). Would trigger alert.");
            } else {
                $this->info("Vehicle is NOT stale (synced {$vehicleStat->synced_at->diffForHumans()}, threshold: {$threshold} min).");
            }
            return self::SUCCESS;
        }

        if (!$this->confirm("This will temporarily mark '{$vehicleStat->vehicle_name}' as stale and trigger notifications. The synced_at will be restored after. Continue?")) {
            $this->info('Cancelled.');
            return self::SUCCESS;
        }

        $originalSyncedAt = $vehicleStat->synced_at;

        $vehicleStat->update(['synced_at' => now()->subMinutes($config['threshold_minutes'] + 5)]);
        $this->info("Temporarily set synced_at to " . $vehicleStat->fresh()->synced_at->toDateTimeString());

        $this->call('samsara:check-stale-vehicles', ['--company' => $company->id]);

        $vehicleStat->update(['synced_at' => $originalSyncedAt]);
        $this->info("Restored synced_at to " . ($originalSyncedAt?->toDateTimeString() ?? 'null'));
        $this->info('Check logs: sail artisan pail --filter="StaleVehicle"');

        return self::SUCCESS;
    }

    private function resolveVehicle(Company $company): ?VehicleStat
    {
        $vehicleId = $this->option('vehicle');

        if ($vehicleId) {
            $stat = VehicleStat::where('company_id', $company->id)
                ->where('samsara_vehicle_id', $vehicleId)
                ->first();
            if (!$stat) {
                $this->error("Vehicle stat not found for samsara_vehicle_id: {$vehicleId}");
                return null;
            }
            return $stat;
        }

        $stat = VehicleStat::where('company_id', $company->id)
            ->whereNotNull('synced_at')
            ->orderByDesc('synced_at')
            ->first();

        if (!$stat) {
            $stat = VehicleStat::where('company_id', $company->id)->first();
        }

        if (!$stat) {
            $this->error('No vehicle stats found for this company. Run vehicle stats sync first.');
            return null;
        }

        return $stat;
    }

    private function invalidScenario(string $scenario): int
    {
        $this->error("Unknown scenario: {$scenario}. Use 'signal' or 'stale'.");
        $this->newLine();
        $this->info('Usage:');
        $this->line('  sail artisan detection:test signal --company=1 --label=Collision --dry-run');
        $this->line('  sail artisan detection:test signal --company=1 --label=Collision');
        $this->line('  sail artisan detection:test stale --company=1 --dry-run');
        $this->line('  sail artisan detection:test stale --company=1');
        return self::FAILURE;
    }
}
