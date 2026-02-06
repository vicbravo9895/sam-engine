<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event for streaming copilot responses via WebSocket.
 * 
 * Uses ShouldBroadcastNow (not ShouldBroadcast) because the Job already runs
 * in background - we don't want to double-queue the broadcast.
 * 
 * Event types:
 * - chunk: Text content chunk from the AI response
 * - tool_start: AI is calling a tool (e.g., GetVehicleStats)
 * - tool_end: Tool call completed
 * - stream_end: Stream completed successfully (includes token stats)
 * - stream_error: Stream failed with error message
 */
class CopilotStreamEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Event types
     */
    public const TYPE_CHUNK = 'chunk';
    public const TYPE_TOOL_START = 'tool_start';
    public const TYPE_TOOL_END = 'tool_end';
    public const TYPE_STREAM_END = 'stream_end';
    public const TYPE_STREAM_ERROR = 'stream_error';

    /**
     * Create a new event instance.
     *
     * @param string $threadId The conversation thread ID
     * @param string $type Event type (chunk, tool_start, tool_end, stream_end, stream_error)
     * @param array $payload Event-specific data
     */
    public function __construct(
        public string $threadId,
        public string $type,
        public array $payload = [],
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("copilot.{$this->threadId}"),
        ];
    }

    /**
     * The event's broadcast name.
     * 
     * Frontend listens for: .copilot.stream
     */
    public function broadcastAs(): string
    {
        return 'copilot.stream';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'type' => $this->type,
            'payload' => $this->payload,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Factory: Create a chunk event.
     */
    public static function chunk(string $threadId, string $content): self
    {
        return new self($threadId, self::TYPE_CHUNK, [
            'content' => $content,
        ]);
    }

    /**
     * Factory: Create a tool start event.
     */
    public static function toolStart(string $threadId, array $toolInfo): self
    {
        return new self($threadId, self::TYPE_TOOL_START, [
            'tool_info' => $toolInfo,
        ]);
    }

    /**
     * Factory: Create a tool end event.
     */
    public static function toolEnd(string $threadId): self
    {
        return new self($threadId, self::TYPE_TOOL_END);
    }

    /**
     * Factory: Create a stream end event.
     */
    public static function streamEnd(string $threadId, array $tokenStats = []): self
    {
        return new self($threadId, self::TYPE_STREAM_END, [
            'tokens' => $tokenStats,
        ]);
    }

    /**
     * Factory: Create a stream error event.
     */
    public static function streamError(string $threadId, string $error): self
    {
        return new self($threadId, self::TYPE_STREAM_ERROR, [
            'error' => $error,
        ]);
    }
}
