<?php

namespace App\Pulse\Recorders;

use App\Events\CopilotStreamEvent;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Log;
use Laravel\Pulse\Facades\Pulse;

/**
 * Recorder para métricas de WebSocket (Reverb).
 * 
 * Registra:
 * - Mensajes broadcast por tipo (chunk, tool_start, tool_end, stream_end, stream_error)
 * - Streams iniciados/completados/fallidos
 * - Actividad por canal
 */
class WebSocketRecorder
{
    /**
     * The events to listen for.
     *
     * @var array<int, class-string>
     */
    public array $listen = [
        CopilotStreamEvent::class,
    ];

    public function __construct(
        protected Repository $config,
    ) {}

    /**
     * Record a WebSocket broadcast event.
     */
    public function record(CopilotStreamEvent $event): void
    {
        // Don't record if disabled
        if (!$this->config->get('pulse.recorders.' . self::class . '.enabled', true)) {
            return;
        }

        // Record by event type
        Pulse::record(
            type: 'ws_message',
            key: $event->type,
            value: 1
        )->count();

        // Record by channel (for tracking active conversations)
        $channel = "copilot.{$event->threadId}";
        Pulse::record(
            type: 'ws_channel',
            key: $channel,
            value: 1
        )->count();

        // Track stream lifecycle events
        match ($event->type) {
            CopilotStreamEvent::TYPE_CHUNK => $this->recordChunk($event),
            CopilotStreamEvent::TYPE_TOOL_START => $this->recordToolStart($event),
            CopilotStreamEvent::TYPE_TOOL_END => $this->recordToolEnd($event),
            CopilotStreamEvent::TYPE_STREAM_END => $this->recordStreamEnd($event),
            CopilotStreamEvent::TYPE_STREAM_ERROR => $this->recordStreamError($event),
            default => null,
        };

        Log::debug('Pulse: WebSocket event recorded', [
            'type' => $event->type,
            'thread_id' => $event->threadId,
        ]);
    }

    /**
     * Record a text chunk broadcast.
     */
    private function recordChunk(CopilotStreamEvent $event): void
    {
        $contentLength = strlen($event->payload['content'] ?? '');
        
        Pulse::record(
            type: 'ws_chunk_size',
            key: 'bytes',
            value: $contentLength
        )->sum()->avg()->max();
    }

    /**
     * Record a tool start event.
     */
    private function recordToolStart(CopilotStreamEvent $event): void
    {
        $toolName = $event->payload['tool_info']['label'] ?? 'unknown';
        
        Pulse::record(
            type: 'ws_tool_call',
            key: $toolName,
            value: 1
        )->count();
    }

    /**
     * Record a tool end event.
     */
    private function recordToolEnd(CopilotStreamEvent $event): void
    {
        // Tool completions are tracked but don't need separate metrics
        // The tool_start already tracks which tools are called
    }

    /**
     * Record a stream completion.
     */
    private function recordStreamEnd(CopilotStreamEvent $event): void
    {
        Pulse::record(
            type: 'ws_stream',
            key: 'completed',
            value: 1
        )->count();

        // Track tokens if available
        $tokens = $event->payload['tokens'] ?? [];
        if (!empty($tokens['total_tokens'])) {
            Pulse::record(
                type: 'ws_tokens',
                key: 'total',
                value: $tokens['total_tokens']
            )->sum()->avg();
        }
    }

    /**
     * Record a stream error.
     */
    private function recordStreamError(CopilotStreamEvent $event): void
    {
        Pulse::record(
            type: 'ws_stream',
            key: 'failed',
            value: 1
        )->count();

        $error = $event->payload['error'] ?? 'unknown';
        Pulse::record(
            type: 'ws_error',
            key: substr($error, 0, 50), // Truncate long errors
            value: 1
        )->count();
    }

    /**
     * Record directly without event (for external calls).
     */
    public static function recordBroadcast(
        string $type,
        string $channel,
        int $payloadSize = 0
    ): void {
        Pulse::record(
            type: 'ws_broadcast',
            key: $type,
            value: $payloadSize
        )->count()->sum();

        Pulse::record(
            type: 'ws_channel_activity',
            key: $channel,
            value: 1
        )->count();
    }
}
