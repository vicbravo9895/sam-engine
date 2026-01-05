<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenUsage extends Model
{
    protected $table = 'token_usage';

    protected $fillable = [
        'user_id',
        'thread_id',
        'model',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'request_type',
        'meta',
    ];

    protected $casts = [
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'total_tokens' => 'integer',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'thread_id', 'thread_id');
    }

    /**
     * Registrar uso de tokens
     */
    public static function record(
        int $userId,
        ?string $threadId,
        int $inputTokens,
        int $outputTokens,
        ?string $model = null,
        string $requestType = 'chat',
        ?array $meta = null
    ): self {
        $totalTokens = $inputTokens + $outputTokens;

        // Crear registro de uso
        $usage = self::create([
            'user_id' => $userId,
            'thread_id' => $threadId,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
            'request_type' => $requestType,
            'meta' => $meta,
        ]);

        // Actualizar totales en la conversación
        if ($threadId) {
            Conversation::where('thread_id', $threadId)->increment('total_input_tokens', $inputTokens);
            Conversation::where('thread_id', $threadId)->increment('total_output_tokens', $outputTokens);
            Conversation::where('thread_id', $threadId)->increment('total_tokens', $totalTokens);
        }

        // Actualizar total del usuario
        User::where('id', $userId)->increment('total_tokens_used', $totalTokens);

        return $usage;
    }

    /**
     * Obtener estadísticas de uso para un usuario
     */
    public static function getStatsForUser(int $userId, ?string $period = 'month'): array
    {
        $query = self::where('user_id', $userId);

        if ($period === 'day') {
            $query->whereDate('created_at', today());
        } elseif ($period === 'week') {
            $query->where('created_at', '>=', now()->subWeek());
        } elseif ($period === 'month') {
            $query->where('created_at', '>=', now()->subMonth());
        }

        return [
            'total_requests' => $query->count(),
            'total_input_tokens' => $query->sum('input_tokens'),
            'total_output_tokens' => $query->sum('output_tokens'),
            'total_tokens' => $query->sum('total_tokens'),
        ];
    }
}

