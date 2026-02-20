<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Models\AlertSource;
use App\Models\Company;
use App\Models\Signal;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Simulador de webhooks Samsara para QA en desarrollo.
 *
 * Envía payloads tipo Samsara al endpoint de webhook a intervalos configurables,
 * permitiendo validar el pipeline de alertas (AI, notificaciones, enriquecimiento)
 * sin depender de eventos reales. Solo disponible en entorno local/simulador.
 *
 * Uso típico:
 *   sail artisan alerts:replay --interval=120 --profile=panic
 *   sail artisan alerts:replay --once --company=1 --profile=harsh_braking
 */
class ReplaySamsaraWebhooks extends Command
{
    protected $signature = 'alerts:replay
                            {--interval=60 : Segundos entre envíos (ignorado con --once)}
                            {--profile= : Perfil de alerta: panic, harsh_braking, speeding, mixed, o all}
                            {--company= : ID de empresa (por defecto: primera con vehículos)}
                            {--once : Enviar un solo webhook y salir}
                            {--max=0 : Máximo de webhooks a enviar (0 = ilimitado)}
                            {--url= : URL base del backend (por defecto: WEBHOOK_SIMULATOR_TARGET_URL o APP_URL)}
                            {--fixture-dir= : Directorio de fixtures JSON (por defecto: storage/app/fixtures/samsara_webhooks)}
                            {--keep-fixture-time : No sobrescribir happenedAtTime; usar la fecha/hora del fixture}
                            {--strict : Enviar el payload del fixture tal cual (solo se cambia eventId); no hidratar con vehículo de la empresa}';

    protected $description = 'Replay/simula webhooks Samsara para QA del pipeline (solo dev)';

    private string $fixtureDir;

    private ?Company $company = null;

    /** @var array<string, array<int, array>> */
    private array $fixturesByProfile = [];

    public function handle(): int
    {
        if (!$this->isSimulatorAllowed()) {
            $this->error('El simulador de webhooks solo está permitido en entorno local o con WEBHOOK_SIMULATOR_ENABLED=true.');
            return self::FAILURE;
        }

        $this->fixtureDir = $this->option('fixture-dir') ?: storage_path('app/fixtures/samsara_webhooks');
        $profile = $this->option('profile') ?: 'mixed';
        $interval = (int) $this->option('interval');
        $max = (int) $this->option('max');
        $once = $this->option('once');

        if ($interval < 1 && !$once) {
            $this->error('--interval debe ser >= 1 cuando no se usa --once.');
            return self::FAILURE;
        }

        $strict = $this->option('strict');
        if (!$strict && !$this->resolveCompany()) {
            return self::FAILURE;
        }

        if (!$this->loadFixtures($profile)) {
            return self::FAILURE;
        }

        $baseUrl = rtrim($this->option('url') ?: env('WEBHOOK_SIMULATOR_TARGET_URL', config('app.url')), '/');
        $webhookUrl = $baseUrl . '/api/webhooks/samsara';

        $this->info('');
        $this->info('========================================');
        $this->info('  SAM - Replay de webhooks Samsara');
        $this->info('========================================');
        if (!$strict) {
            $this->info('  Empresa: ' . $this->company->name . ' (ID: ' . $this->company->id . ')');
        }
        $this->info('  Perfil: ' . $profile);
        $this->info('  URL: ' . $webhookUrl);
        $this->info('  Modo: ' . ($once ? 'una vez' : "cada {$interval}s"));
        $this->info('  Payload: ' . ($strict ? 'strict (fixture tal cual, solo eventId nuevo)' : ($this->option('keep-fixture-time') ? 'fecha del fixture' : 'hidratado con vehículo de la empresa')));
        $this->info('========================================');
        $this->info('');

        $sent = 0;
        do {
            $payload = $this->nextPayload($profile);
            if (!$payload) {
                $this->warn('No hay fixtures disponibles para el perfil.');
                break;
            }

            $this->deleteSignalsMatchingPayload($payload);

            $response = Http::timeout(15)
                ->withHeaders(['X-Trace-ID' => 'replay-' . Str::uuid()])
                ->post($webhookUrl, $payload);

            if ($response->successful()) {
                $sent++;
                $alertId = $response->json('alert_id');
                $vehicleName = $payload['vehicleName'] ?? $payload['vehicleId'] ?? '?';
                $vehicleId = $payload['vehicleId'] ?? '?';
                $this->line(sprintf(
                    '[%s] Enviado #%d — event_type=%s vehículo=%s (id=%s) → %d %s',
                    now()->toDateTimeString(),
                    $sent,
                    $payload['eventType'] ?? $payload['event_type'] ?? '?',
                    $vehicleName,
                    $vehicleId,
                    $response->status(),
                    $alertId ? "alert_id={$alertId}" : $response->body()
                ));
            } else {
                $this->error(sprintf(
                    '[%s] Error HTTP %d — %s',
                    now()->toDateTimeString(),
                    $response->status(),
                    $response->body()
                ));
            }

            if ($once || ($max > 0 && $sent >= $max)) {
                break;
            }

            if (!$once) {
                $this->comment("  Siguiente envío en {$interval}s (Ctrl+C para detener).");
                sleep($interval);
            }
        } while (true);

        $this->info('');
        $this->info("Total enviados: {$sent}");
        $this->info('');

        return self::SUCCESS;
    }

    private function isSimulatorAllowed(): bool
    {
        if (config('app.env') === 'local') {
            return true;
        }
        return filter_var(env('WEBHOOK_SIMULATOR_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function resolveCompany(): bool
    {
        $companyId = $this->option('company');
        if ($companyId) {
            $this->company = Company::find($companyId);
            if (!$this->company) {
                $this->error("Empresa con ID {$companyId} no encontrada.");
                return false;
            }
        } else {
            $this->company = Company::whereHas('vehicles')->first();
            if (!$this->company) {
                $this->company = Company::first();
            }
            if (!$this->company) {
                $this->error('No hay empresas en la BD. Crea una empresa o usa --company=ID.');
                return false;
            }
        }

        $vehicle = Vehicle::where('company_id', $this->company->id)->first();
        if (!$vehicle) {
            $this->error("La empresa {$this->company->name} no tiene vehículos. El webhook necesita vehicle_id registrado.");
            return false;
        }

        return true;
    }

    private function loadFixtures(string $profile): bool
    {
        if (!is_dir($this->fixtureDir)) {
            $this->error("Directorio de fixtures no encontrado: {$this->fixtureDir}");
            $this->line('Copia payloads de Samsara (o usa los ejemplos) en archivos .json por perfil: panic_button.json, harsh_braking.json, etc.');
            return false;
        }

        $profileToFile = [
            'panic' => 'panic_button',
            'panic_button' => 'panic_button',
            'harsh_braking' => 'harsh_braking',
            'braking' => 'harsh_braking',
            'speeding' => 'speeding',
            'alert_incident' => 'alert_incident',
            'harsh_acceleration' => 'harsh_acceleration',
            'sharp_turn' => 'sharp_turn',
            'distracted_driving' => 'distracted_driving',
            'collision' => 'collision',
        ];
        $allProfiles = [
            'panic_button', 'harsh_braking', 'speeding', 'alert_incident',
            'harsh_acceleration', 'sharp_turn', 'distracted_driving', 'collision',
        ];
        $profiles = $profile === 'mixed' || $profile === 'all'
            ? $allProfiles
            : [$profileToFile[$profile] ?? $profile];

        foreach ($profiles as $p) {
            $path = $this->fixtureDir . '/' . $p . '.json';
            if (!is_file($path)) {
                continue;
            }
            $content = file_get_contents($path);
            $decoded = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                $this->warn("Fixture inválido o no es array: {$path}");
                continue;
            }
            $this->fixturesByProfile[$p][] = $decoded;
        }

        // Aceptar también un único archivo "generic" o "alert_incident"
        $generic = $this->fixtureDir . '/generic.json';
        if (empty($this->fixturesByProfile) && is_file($generic)) {
            $content = file_get_contents($generic);
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $this->fixturesByProfile['generic'] = [$decoded];
            }
        }

        if (empty($this->fixturesByProfile)) {
            $this->error("No se encontraron fixtures en {$this->fixtureDir} para el perfil '{$profile}'.");
            $this->line('Ejemplos esperados: panic_button.json, harsh_braking.json, speeding.json, generic.json');
            return false;
        }

        return true;
    }

    private function nextPayload(string $profile): ?array
    {
        $all = [];
        foreach ($this->fixturesByProfile as $list) {
            foreach ($list as $p) {
                $all[] = $p;
            }
        }
        if (empty($all)) {
            return null;
        }

        $template = $all[array_rand($all)];

        if ($this->option('strict')) {
            $payload = $template;
            $payload['eventId'] = 'replay-' . Str::uuid();
            return $payload;
        }

        $vehicle = Vehicle::where('company_id', $this->company->id)->inRandomOrder()->first();
        if (!$vehicle) {
            return null;
        }

        return $this->hydratePayload($template, $vehicle);
    }

    private function hydratePayload(array $template, Vehicle $vehicle): array
    {
        $payload = $template;
        $keepFixtureTime = $this->option('keep-fixture-time');

        $payload['eventId'] = 'replay-' . Str::uuid();
        $payload['eventType'] = $payload['eventType'] ?? $payload['event_type'] ?? 'AlertIncident';
        $payload['vehicleId'] = $vehicle->samsara_id;
        $payload['vehicleName'] = $vehicle->name;

        $vehiclePayload = ['id' => $vehicle->samsara_id, 'name' => $vehicle->name];
        $this->injectVehicleIntoPayload($payload, $vehiclePayload);

        if (isset($payload['data']) && is_array($payload['data'])) {
            if (!$keepFixtureTime) {
                $payload['data']['happenedAtTime'] = now()->toIso8601String();
            }
            if (isset($payload['data']['updatedAtTime']) && !$keepFixtureTime) {
                $payload['data']['updatedAtTime'] = now()->toIso8601String();
            }
        } else {
            $payload['data'] = array_merge($payload['data'] ?? [], [
                'happenedAtTime' => $keepFixtureTime
                    ? ($template['data']['happenedAtTime'] ?? $template['eventTime'] ?? now()->toIso8601String())
                    : now()->toIso8601String(),
            ]);
        }

        if (!$keepFixtureTime) {
            $payload['eventTime'] = now()->toIso8601String();
        }

        if ($vehicle->static_assigned_driver && is_array($vehicle->static_assigned_driver)) {
            $payload['driverId'] = $vehicle->static_assigned_driver['id'] ?? null;
            $payload['driverName'] = $vehicle->static_assigned_driver['name'] ?? null;
        }

        return $payload;
    }

    /**
     * Elimina Signal (y Alert, AlertSource) que coincidan con el payload para dejar
     * la ejecución limpia y evitar "Duplicate event - already processed".
     * Criterio: mismo vehicle_id, event_type y occurred_at en ventana ±30s.
     */
    private function deleteSignalsMatchingPayload(array $payload): void
    {
        $vehicleId = $this->extractVehicleIdFromPayload($payload);
        $eventType = $payload['eventType'] ?? $payload['event_type'] ?? $payload['alertType'] ?? null;
        $occurredAt = $payload['data']['happenedAtTime'] ?? $payload['eventTime'] ?? $payload['time'] ?? null;

        if (!$vehicleId || !$eventType || !$occurredAt) {
            return;
        }

        $vehicle = Vehicle::where('samsara_id', $vehicleId)->first();
        if (!$vehicle || !$vehicle->company_id) {
            return;
        }

        $at = Carbon::parse($occurredAt);
        $windowStart = $at->copy()->subSeconds(30);
        $windowEnd = $at->copy()->addSeconds(30);

        $signalIds = Signal::where('company_id', $vehicle->company_id)
            ->where('vehicle_id', $vehicleId)
            ->where('event_type', $eventType)
            ->whereBetween('occurred_at', [$windowStart, $windowEnd])
            ->pluck('id')
            ->all();

        if (!empty($signalIds)) {
            Alert::whereIn('signal_id', $signalIds)->delete();
            AlertSource::whereIn('signal_id', $signalIds)->delete();
            Signal::whereIn('id', $signalIds)->delete();
        }
    }

    private function extractVehicleIdFromPayload(array $payload): ?string
    {
        if (isset($payload['vehicle']['id'])) {
            return (string) $payload['vehicle']['id'];
        }
        if (isset($payload['vehicleId'])) {
            return (string) $payload['vehicleId'];
        }
        if (isset($payload['data']['conditions']) && is_array($payload['data']['conditions'])) {
            foreach ($payload['data']['conditions'] as $condition) {
                foreach ($condition['details'] ?? [] as $detail) {
                    if (isset($detail['vehicle']['id'])) {
                        return (string) $detail['vehicle']['id'];
                    }
                }
            }
        }
        return null;
    }

    /**
     * Inyecta vehicle id/name en todas las estructuras anidadas del payload
     * (data.conditions[].details.panicButton.vehicle, etc.) para que el webhook
     * y el pipeline usen el mismo vehículo de la empresa.
     */
    private function injectVehicleIntoPayload(array &$payload, array $vehiclePayload): void
    {
        if (!isset($payload['data']['conditions']) || !is_array($payload['data']['conditions'])) {
            return;
        }

        foreach ($payload['data']['conditions'] as $i => $condition) {
            $details = $condition['details'] ?? [];
            if (!is_array($details)) {
                continue;
            }
            foreach ($details as $key => $detail) {
                if (is_array($detail) && isset($detail['vehicle'])) {
                    $payload['data']['conditions'][$i]['details'][$key]['vehicle'] = array_merge(
                        is_array($detail['vehicle']) ? $detail['vehicle'] : [],
                        $vehiclePayload
                    );
                }
            }
        }
    }
}
