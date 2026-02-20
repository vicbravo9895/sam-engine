<?php

namespace App\Pulse\Recorders;

use App\Models\Alert;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Log;
use Laravel\Pulse\Facades\Pulse;

/**
 * Recorder para métricas de procesamiento de alertas AI.
 * 
 * Registra:
 * - Tiempo de procesamiento de alertas
 * - Distribución por tipo/severidad/verdict
 * - Tasa de éxito/fallo
 */
class AlertProcessingRecorder
{
    /**
     * The events to listen for.
     *
     * @var array<int, class-string>
     */
    public array $listen = [
        \App\Events\AlertProcessed::class,
    ];

    public function __construct(
        protected Repository $config,
    ) {}

    /**
     * Record an alert processing event.
     */
    public function record(\App\Events\AlertProcessed $event): void
    {
        $alert = $event->alert;
        $alert->loadMissing('signal');
        $duration = $event->durationMs;

        $eventType = $alert->signal?->event_type ?? 'unknown';

        Pulse::record(
            type: 'alert_processing',
            key: $eventType,
            value: $duration
        )->avg()->max()->count();

        Pulse::record(
            type: 'alert_severity',
            key: $alert->severity ?? 'unknown',
            value: $duration
        )->count();

        $verdict = $alert->verdict ?? 'unknown';
        Pulse::record(
            type: 'alert_verdict',
            key: $verdict,
            value: 1
        )->count();

        Pulse::record(
            type: 'alert_status',
            key: $alert->ai_status ?? 'unknown',
            value: 1
        )->count();

        if ($alert->company_id) {
            Pulse::record(
                type: 'alert_company',
                key: (string) $alert->company_id,
                value: $duration
            )->avg()->count();
        }
    }

    /**
     * Record directly without event (for use in Jobs).
     */
    public static function recordAlertProcessing(
        Alert $alert,
        int $durationMs,
        string $status = 'completed'
    ): void {
        $alert->loadMissing('signal');
        $eventType = $alert->signal?->event_type ?? 'unknown';

        Pulse::record(
            type: 'alert_processing',
            key: $eventType,
            value: $durationMs
        )->avg()->max()->count();

        Pulse::record(
            type: 'alert_severity',
            key: $alert->severity ?? 'unknown',
            value: 1
        )->count();

        $verdict = $alert->verdict ?? 'unknown';
        Pulse::record(
            type: 'alert_verdict',
            key: $verdict,
            value: 1
        )->count();

        Pulse::record(
            type: 'alert_status',
            key: $status,
            value: 1
        )->count();

        if ($alert->company_id) {
            Pulse::record(
                type: 'alert_company',
                key: (string) $alert->company_id,
                value: $durationMs
            )->avg()->count();
        }

        Log::debug('Pulse: Alert processing recorded', [
            'alert_id' => $alert->id,
            'duration_ms' => $durationMs,
            'status' => $status,
            'verdict' => $verdict,
        ]);
    }
}
