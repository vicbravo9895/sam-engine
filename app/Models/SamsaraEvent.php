<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SamsaraEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_type',
        'event_description',
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
        'ai_actions',
        'last_investigation_at',
        'investigation_count',
        'next_check_minutes',
        'investigation_history',
        // Notification tracking
        'notification_status',
        'notification_channels',
        'notification_sent_at',
        'twilio_call_sid',
        'call_response',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'ai_assessment' => 'array',
        'ai_actions' => 'array',
        'investigation_history' => 'array',
        'occurred_at' => 'datetime',
        'ai_processed_at' => 'datetime',
        'last_investigation_at' => 'datetime',
        // Notification fields
        'notification_channels' => 'array',
        'notification_sent_at' => 'datetime',
        'call_response' => 'array',
    ];

    // Constantes de estado
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_INVESTIGATING = 'investigating';
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

    public function scopeInvestigating($query)
    {
        return $query->where('ai_status', self::STATUS_INVESTIGATING);
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

    public function markAsCompleted(array $assessment, string $message, ?array $actions = null): void
    {
        $this->update([
            'ai_status' => self::STATUS_COMPLETED,
            'ai_assessment' => $assessment,
            'ai_message' => $message,
            'ai_actions' => $actions,
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

    public function markAsInvestigating(array $assessment, string $message, int $nextCheckMinutes, ?array $actions = null): void
    {
        $this->update([
            'ai_status' => self::STATUS_INVESTIGATING,
            'ai_assessment' => $assessment,
            'ai_message' => $message,
            'ai_actions' => $actions,
            'last_investigation_at' => now(),
            'investigation_count' => $this->investigation_count + 1,
            'next_check_minutes' => $nextCheckMinutes,
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

    /**
     * Verificar si debe revalidarse
     */
    public function shouldRevalidate(): bool
    {
        if ($this->ai_status !== self::STATUS_INVESTIGATING) {
            return false;
        }

        if (!$this->last_investigation_at || !$this->next_check_minutes) {
            return true;
        }

        return now()->diffInMinutes($this->last_investigation_at) >= $this->next_check_minutes;
    }

    /**
     * Agregar registro de investigación al historial
     */
    public function addInvestigationRecord(string $reason): void
    {
        $history = $this->investigation_history ?? [];
        $history[] = [
            'timestamp' => now()->toIso8601String(),
            'reason' => $reason,
            'count' => $this->investigation_count,
        ];

        $this->update(['investigation_history' => $history]);
    }

    /**
     * Máximo de investigaciones permitidas
     */
    public static function getMaxInvestigations(): int
    {
        return 3;
    }
}
