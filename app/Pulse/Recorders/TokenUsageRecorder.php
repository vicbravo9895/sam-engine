<?php

namespace App\Pulse\Recorders;

use App\Models\TokenUsage;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Log;
use Laravel\Pulse\Facades\Pulse;

/**
 * Recorder para métricas de consumo de tokens LLM.
 * 
 * Registra:
 * - Tokens consumidos por modelo (gpt-4o, gpt-4o-mini)
 * - Tokens por usuario
 * - Tokens por tipo de request (chat, copilot, etc.)
 */
class TokenUsageRecorder
{
    /**
     * The events to listen for.
     *
     * @var array<int, class-string>
     */
    public array $listen = [
        \App\Events\TokensUsed::class,
    ];

    public function __construct(
        protected Repository $config,
    ) {}

    /**
     * Record a token usage event.
     */
    public function record(\App\Events\TokensUsed $event): void
    {
        self::recordTokenUsage(
            model: $event->model,
            inputTokens: $event->inputTokens,
            outputTokens: $event->outputTokens,
            userId: $event->userId,
            requestType: $event->requestType ?? 'chat'
        );
    }

    /**
     * Record directly without event (for use in observers/models).
     */
    public static function recordTokenUsage(
        string $model,
        int $inputTokens,
        int $outputTokens,
        ?int $userId = null,
        string $requestType = 'chat'
    ): void {
        $totalTokens = $inputTokens + $outputTokens;

        // Registrar tokens por modelo
        Pulse::record(
            type: 'token_usage_model',
            key: $model,
            value: $totalTokens
        )->sum()->count();

        // Registrar input vs output tokens por modelo
        Pulse::record(
            type: 'token_input',
            key: $model,
            value: $inputTokens
        )->sum();

        Pulse::record(
            type: 'token_output',
            key: $model,
            value: $outputTokens
        )->sum();

        // Registrar por tipo de request
        Pulse::record(
            type: 'token_request_type',
            key: $requestType,
            value: $totalTokens
        )->sum()->count();

        // Registrar por usuario (si disponible)
        if ($userId) {
            Pulse::record(
                type: 'token_user',
                key: (string) $userId,
                value: $totalTokens
            )->sum()->count();
        }

        Log::debug('Pulse: Token usage recorded', [
            'model' => $model,
            'total_tokens' => $totalTokens,
            'user_id' => $userId,
            'request_type' => $requestType,
        ]);
    }
}
