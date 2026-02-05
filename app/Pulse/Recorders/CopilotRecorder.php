<?php

namespace App\Pulse\Recorders;

use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Log;
use Laravel\Pulse\Facades\Pulse;

/**
 * Recorder para métricas del Copilot (FleetAgent).
 * 
 * Registra:
 * - Mensajes procesados
 * - Tools utilizadas
 * - Tiempo de respuesta
 * - Uso por usuario
 */
class CopilotRecorder
{
    /**
     * The events to listen for.
     *
     * @var array<int, class-string>
     */
    public array $listen = [
        \App\Events\CopilotMessageProcessed::class,
    ];

    public function __construct(
        protected Repository $config,
    ) {}

    /**
     * Record a copilot message event.
     */
    public function record(\App\Events\CopilotMessageProcessed $event): void
    {
        self::recordCopilotMessage(
            userId: $event->userId,
            durationMs: $event->durationMs,
            toolsUsed: $event->toolsUsed ?? [],
            model: $event->model ?? 'unknown'
        );
    }

    /**
     * Record directly without event (for use in Jobs).
     */
    public static function recordCopilotMessage(
        int $userId,
        int $durationMs,
        array $toolsUsed = [],
        string $model = 'gpt-4o'
    ): void {
        // Registrar mensaje procesado
        Pulse::record(
            type: 'copilot_message',
            key: 'processed',
            value: $durationMs
        )->avg()->max()->count();

        // Registrar por usuario
        Pulse::record(
            type: 'copilot_user',
            key: (string) $userId,
            value: $durationMs
        )->avg()->count();

        // Registrar tools utilizadas
        foreach ($toolsUsed as $tool) {
            Pulse::record(
                type: 'copilot_tool',
                key: $tool,
                value: 1
            )->count();
        }

        // Registrar por modelo
        Pulse::record(
            type: 'copilot_model',
            key: $model,
            value: 1
        )->count();

        // Registrar mensajes lentos (>3s)
        if ($durationMs > 3000) {
            Pulse::record(
                type: 'copilot_slow',
                key: 'slow_messages',
                value: $durationMs
            )->count()->max();
        }

        Log::debug('Pulse: Copilot message recorded', [
            'user_id' => $userId,
            'duration_ms' => $durationMs,
            'tools_used' => $toolsUsed,
            'model' => $model,
        ]);
    }
}
