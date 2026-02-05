<?php

namespace App\Pulse\Recorders;

use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Log;
use Laravel\Pulse\Facades\Pulse;

/**
 * Recorder para métricas del AI Service (FastAPI).
 * 
 * Registra:
 * - Tiempo de respuesta del AI Service
 * - Tasa de éxito/fallo
 * - Requests por endpoint
 */
class AiServiceRecorder
{
    /**
     * The events to listen for.
     *
     * @var array<int, class-string>
     */
    public array $listen = [
        \App\Events\AiServiceCalled::class,
    ];

    public function __construct(
        protected Repository $config,
    ) {}

    /**
     * Record an AI service call event.
     */
    public function record(\App\Events\AiServiceCalled $event): void
    {
        self::recordAiServiceCall(
            endpoint: $event->endpoint,
            success: $event->success,
            durationMs: $event->durationMs,
            statusCode: $event->statusCode ?? 200
        );
    }

    /**
     * Record directly without event (for use in Jobs).
     */
    public static function recordAiServiceCall(
        string $endpoint,
        bool $success,
        int $durationMs,
        int $statusCode = 200
    ): void {
        $status = $success ? 'success' : 'failed';

        // Registrar tiempo de respuesta por endpoint
        Pulse::record(
            type: 'ai_service_response',
            key: $endpoint,
            value: $durationMs
        )->avg()->max()->count();

        // Registrar status por endpoint
        Pulse::record(
            type: 'ai_service_status',
            key: "{$endpoint}:{$status}",
            value: 1
        )->count();

        // Registrar por código HTTP
        Pulse::record(
            type: 'ai_service_http',
            key: (string) $statusCode,
            value: 1
        )->count();

        // Registrar totales
        Pulse::record(
            type: 'ai_service_total',
            key: $status,
            value: 1
        )->count();

        // Registrar percentiles de latencia
        if ($durationMs > 5000) {
            Pulse::record(
                type: 'ai_service_slow',
                key: $endpoint,
                value: $durationMs
            )->count()->max();
        }

        Log::debug('Pulse: AI Service call recorded', [
            'endpoint' => $endpoint,
            'success' => $success,
            'duration_ms' => $durationMs,
            'status_code' => $statusCode,
        ]);
    }
}
