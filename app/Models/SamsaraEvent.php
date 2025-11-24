<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SamsaraEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_type',
        'samsara_event_id',
        'vehicle_id',
        'vehicle_name',
        'driver_id',
        'driver_name',
        'severity',
        'occurred_at',
        'raw_payload',
        'ai_status',
        'ai_assessment',
        'ai_message',
        'ai_processed_at',
        'ai_error',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'ai_assessment' => 'array',
        'occurred_at' => 'datetime',
        'ai_processed_at' => 'datetime',
    ];

    // Constantes de estado
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    // Constantes de severidad
    const SEVERITY_INFO = 'info';
    const SEVERITY_WARNING = 'warning';
    const SEVERITY_CRITICAL = 'critical';

    /**
     * Scopes para filtrar eventos
     */
    public function scopePending($query)
    {
        return $query->where('ai_status', self::STATUS_PENDING);
    }

    public function scopeProcessing($query)
    {
        return $query->where('ai_status', self::STATUS_PROCESSING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('ai_status', self::STATUS_COMPLETED);
    }

    public function scopeFailed($query)
    {
        return $query->where('ai_status', self::STATUS_FAILED);
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', self::SEVERITY_CRITICAL);
    }

    /**
     * Métodos helper para cambiar estado
     */
    public function markAsProcessing(): void
    {
        $this->update(['ai_status' => self::STATUS_PROCESSING]);
    }

    public function markAsCompleted(array $assessment, string $message): void
    {
        $this->update([
            'ai_status' => self::STATUS_COMPLETED,
            'ai_assessment' => $assessment,
            'ai_message' => $message,
            'ai_processed_at' => now(),
        ]);
    }

    public function markAsFailed(string $error): void
    {
        $this->update([
            'ai_status' => self::STATUS_FAILED,
            'ai_error' => $error,
            'ai_processed_at' => now(),
        ]);
    }

    /**
     * Verificar si el evento está procesado
     */
    public function isProcessed(): bool
    {
        return in_array($this->ai_status, [self::STATUS_COMPLETED, self::STATUS_FAILED]);
    }

    /**
     * Verificar si es crítico
     */
    public function isCritical(): bool
    {
        return $this->severity === self::SEVERITY_CRITICAL;
    }
}
