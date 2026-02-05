<?php

namespace App\Pulse\Recorders;

use App\Models\SamsaraEvent;
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
        $samsaraEvent = $event->samsaraEvent;
        $duration = $event->durationMs;

        // Registrar duración del procesamiento
        Pulse::record(
            type: 'alert_processing',
            key: $samsaraEvent->event_type ?? 'unknown',
            value: $duration
        )->avg()->max()->count();

        // Registrar por severidad
        Pulse::record(
            type: 'alert_severity',
            key: $samsaraEvent->severity ?? 'unknown',
            value: $duration
        )->count();

        // Registrar por verdict
        $verdict = $samsaraEvent->ai_assessment['verdict'] ?? 'unknown';
        Pulse::record(
            type: 'alert_verdict',
            key: $verdict,
            value: 1
        )->count();

        // Registrar por status final
        Pulse::record(
            type: 'alert_status',
            key: $samsaraEvent->ai_status ?? 'unknown',
            value: 1
        )->count();

        // Registrar por company (multi-tenant)
        if ($samsaraEvent->company_id) {
            Pulse::record(
                type: 'alert_company',
                key: (string) $samsaraEvent->company_id,
                value: $duration
            )->avg()->count();
        }
    }

    /**
     * Record directly without event (for use in Jobs).
     */
    public static function recordAlertProcessing(
        SamsaraEvent $event,
        int $durationMs,
        string $status = 'completed'
    ): void {
        // Registrar duración del procesamiento
        Pulse::record(
            type: 'alert_processing',
            key: $event->event_type ?? 'unknown',
            value: $durationMs
        )->avg()->max()->count();

        // Registrar por severidad
        Pulse::record(
            type: 'alert_severity',
            key: $event->severity ?? 'unknown',
            value: 1
        )->count();

        // Registrar por verdict
        $verdict = $event->ai_assessment['verdict'] ?? 'unknown';
        Pulse::record(
            type: 'alert_verdict',
            key: $verdict,
            value: 1
        )->count();

        // Registrar status final
        Pulse::record(
            type: 'alert_status',
            key: $status,
            value: 1
        )->count();

        // Registrar por company
        if ($event->company_id) {
            Pulse::record(
                type: 'alert_company',
                key: (string) $event->company_id,
                value: $durationMs
            )->avg()->count();
        }

        Log::debug('Pulse: Alert processing recorded', [
            'event_id' => $event->id,
            'duration_ms' => $durationMs,
            'status' => $status,
            'verdict' => $verdict,
        ]);
    }
}
