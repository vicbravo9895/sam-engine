<?php

namespace App\Console\Commands;

use App\Models\Signal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Exporta raw_payload reales desde la tabla signals a archivos JSON
 * para usar como fixtures del simulador alerts:replay.
 *
 * Sin anonimización: los fixtures replican exactamente el payload para que
 * las pruebas reproduzcan el mismo contexto (driver, vehicle, tags, etc.).
 * Solo ejecutar en entorno local.
 *
 * Uso: sail artisan webhooks:export-fixtures [--out=storage/app/fixtures/samsara_webhooks]
 */
class ExportSamsaraWebhookFixtures extends Command
{
    protected $signature = 'webhooks:export-fixtures
                            {--out= : Directorio de salida (default: storage/app/fixtures/samsara_webhooks)}
                            {--force : Sobrescribir archivos existentes}';

    protected $description = 'Exporta raw_payload reales de signals a fixtures JSON (sin anonimizar, solo dev)';

    /** Mapeo event_description / event_type → nombre de fixture (sin .json) */
    private const PROFILE_MAP = [
        'panic' => 'panic_button',
        'panic button' => 'panic_button',
        'botón de pánico' => 'panic_button',
        'panic_button' => 'panic_button',
        'hard braking' => 'harsh_braking',
        'harsh braking' => 'harsh_braking',
        'frenado brusco' => 'harsh_braking',
        'speeding' => 'speeding',
        'exceso de velocidad' => 'speeding',
        'a safety event occurred' => 'alert_incident',
        'evento de seguridad' => 'alert_incident',
        'harsh acceleration' => 'harsh_acceleration',
        'aceleración brusca' => 'harsh_acceleration',
        'sharp turn' => 'sharp_turn',
        'harsh turn' => 'sharp_turn',
        'giro brusco' => 'sharp_turn',
        'distracted driving' => 'distracted_driving',
        'conducción distraída' => 'distracted_driving',
        'collision' => 'collision',
        'colisión' => 'collision',
    ];

    public function handle(): int
    {
        if (!app()->environment('local') && !filter_var(env('WEBHOOK_SIMULATOR_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
            $this->error('Solo permitido en local o con WEBHOOK_SIMULATOR_ENABLED.');
            return self::FAILURE;
        }

        $outDir = $this->option('out') ?: storage_path('app/fixtures/samsara_webhooks');
        $force = $this->option('force');

        if (!File::isDirectory($outDir)) {
            File::makeDirectory($outDir, 0755, true);
        }

        $signals = Signal::query()
            ->whereNotNull('raw_payload')
            ->whereRaw("raw_payload != '{}'")
            ->orderByDesc('occurred_at')
            ->get();

        if ($signals->isEmpty()) {
            $this->warn('No hay signals con raw_payload en la base de datos.');
            $this->line('Ejecuta el replay con los fixtures de ejemplo o ingresa webhooks reales primero.');
            return self::SUCCESS;
        }

        $byProfile = [];
        /** @var Signal $signal */
        foreach ($signals as $signal) {
            $profile = $this->profileFromSignal($signal);
            if (!isset($byProfile[$profile])) {
                $byProfile[$profile] = $signal;
            }
        }

        $written = 0;
        foreach ($byProfile as $profile => $signal) {
            $path = $outDir . '/' . $profile . '.json';
            if (File::exists($path) && !$force) {
                $this->line("  Omitido (existe): {$profile}.json");
                continue;
            }
            $payload = $signal->raw_payload;
            if (!is_array($payload)) {
                continue;
            }
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                $this->warn("  Error JSON para {$profile}");
                continue;
            }
            File::put($path, $json);
            $this->info("  Exportado: {$profile}.json (desde signal {$signal->id}, {$signal->event_description})");
            $written++;
        }

        $this->info('');
        $this->info("Fixtures escritos en: {$outDir}");
        $this->info("Perfiles: " . implode(', ', array_keys($byProfile)));
        $this->info("Total: {$written} archivos.");
        $this->info('');

        return self::SUCCESS;
    }

    private function profileFromSignal(Signal $signal): string
    {
        $desc = $signal->event_description ? strtolower(trim($signal->event_description)) : '';
        $type = $signal->event_type ? strtolower(trim($signal->event_type)) : '';

        if ($desc !== '' && isset(self::PROFILE_MAP[$desc])) {
            return self::PROFILE_MAP[$desc];
        }
        if ($type !== '' && isset(self::PROFILE_MAP[$type])) {
            return self::PROFILE_MAP[$type];
        }
        foreach (self::PROFILE_MAP as $key => $profile) {
            if (str_contains($desc, $key) || str_contains($type, $key)) {
                return $profile;
            }
        }
        return 'alert_incident';
    }
}
