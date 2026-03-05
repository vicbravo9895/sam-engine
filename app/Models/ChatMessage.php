<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $fillable = [
        'thread_id', 'role', 'status', 'content', 'streaming_content', 
        'streaming_started_at', 'streaming_completed_at', 'meta'
    ];
    
    protected $casts = [
        'meta' => 'array',
        'streaming_started_at' => 'datetime',
        'streaming_completed_at' => 'datetime',
    ];
    
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'thread_id', 'thread_id');
    }

    /**
     * Extract plain text from the content field.
     *
     * Handles all storage formats:
     * - Plain string (legacy v2)
     * - Neuron v3 ContentBlocks array: [{"type":"text","content":"..."}]
     * - JSON-encoded ContentBlocks string (double-encoding from old array cast + json_encode)
     * - Single object with 'text' key (v2 tool messages)
     */
    public function getTextContent(): ?string
    {
        $raw = $this->attributes['content'] ?? null;

        if ($raw === null) {
            return null;
        }

        $parsed = self::parseContentField($raw);

        if (is_array($parsed)) {
            return self::extractTextFromBlocks($parsed);
        }

        return is_string($parsed) ? $parsed : null;
    }

    /**
     * Determine the Neuron message type (tool_call, tool_call_result, or null).
     */
    public function getNeuronType(): ?string
    {
        $raw = $this->attributes['content'] ?? null;

        if ($raw === null) {
            return null;
        }

        $parsed = self::parseContentField($raw);

        if (is_array($parsed) && isset($parsed['type'])) {
            return $parsed['type'];
        }

        return null;
    }

    /**
     * Decode the raw content value, unwrapping up to two layers of JSON encoding.
     *
     * @return array|string|null
     */
    private static function parseContentField(mixed $raw): array|string|null
    {
        if (!is_string($raw)) {
            return is_array($raw) ? $raw : null;
        }

        $first = json_decode($raw, true);

        if ($first === null && json_last_error() !== JSON_ERROR_NONE) {
            return $raw;
        }

        if (is_array($first)) {
            return $first;
        }

        if (is_string($first)) {
            $second = json_decode($first, true);
            return is_array($second) ? $second : $first;
        }

        return $raw;
    }

    private static function extractTextFromBlocks(array $data): ?string
    {
        if (isset($data['type'])) {
            if (in_array($data['type'], ['tool_call', 'tool_call_result'])) {
                return null;
            }
            return $data['text'] ?? $data['content'] ?? null;
        }

        $text = '';
        foreach ($data as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? '';
            if ($type === 'text') {
                $text .= $block['content'] ?? $block['text'] ?? '';
            }
        }

        return $text !== '' ? $text : null;
    }
}

