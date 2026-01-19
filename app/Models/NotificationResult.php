<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo para resultados de notificaciones.
 * 
 * Almacena los resultados de cada intento de notificación.
 * Anteriormente almacenado en notification_execution.results JSON.
 */
class NotificationResult extends Model
{
    use HasFactory;

    /**
     * Disable timestamps - only using created_at.
     */
    public $timestamps = false;

    protected $fillable = [
        'samsara_event_id',
        'channel',
        'recipient_type',
        'to_number',
        'success',
        'error',
        'call_sid',
        'message_sid',
        'timestamp_utc',
        'created_at',
    ];

    protected $casts = [
        'success' => 'boolean',
        'timestamp_utc' => 'datetime',
        'created_at' => 'datetime',
    ];

    // Notification channels
    const CHANNEL_SMS = 'sms';
    const CHANNEL_WHATSAPP = 'whatsapp';
    const CHANNEL_CALL = 'call';

    /**
     * Boot method to set created_at.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->created_at) {
                $model->created_at = now();
            }
        });
    }

    /**
     * ========================================
     * RELATIONSHIPS
     * ========================================
     */

    /**
     * Event this result belongs to.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(SamsaraEvent::class, 'samsara_event_id');
    }

    /**
     * ========================================
     * SCOPES
     * ========================================
     */

    /**
     * Scope by channel.
     */
    public function scopeChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    /**
     * Scope for successful notifications.
     */
    public function scopeSuccessful($query)
    {
        return $query->where('success', true);
    }

    /**
     * Scope for failed notifications.
     */
    public function scopeFailed($query)
    {
        return $query->where('success', false);
    }

    /**
     * Scope for SMS notifications.
     */
    public function scopeSms($query)
    {
        return $query->where('channel', self::CHANNEL_SMS);
    }

    /**
     * Scope for WhatsApp notifications.
     */
    public function scopeWhatsapp($query)
    {
        return $query->where('channel', self::CHANNEL_WHATSAPP);
    }

    /**
     * Scope for call notifications.
     */
    public function scopeCalls($query)
    {
        return $query->where('channel', self::CHANNEL_CALL);
    }

    /**
     * ========================================
     * HELPERS
     * ========================================
     */

    /**
     * Get human-readable channel label.
     */
    public function getChannelLabel(): string
    {
        return match($this->channel) {
            self::CHANNEL_SMS => 'SMS',
            self::CHANNEL_WHATSAPP => 'WhatsApp',
            self::CHANNEL_CALL => 'Llamada',
            default => $this->channel,
        };
    }

    /**
     * Get the Twilio SID (call_sid or message_sid).
     */
    public function getTwilioSid(): ?string
    {
        return $this->call_sid ?? $this->message_sid;
    }

    /**
     * Check if this is a call notification.
     */
    public function isCall(): bool
    {
        return $this->channel === self::CHANNEL_CALL;
    }

    /**
     * Create result for successful notification.
     */
    public static function createSuccess(array $data): self
    {
        return self::create(array_merge($data, [
            'success' => true,
            'timestamp_utc' => now(),
        ]));
    }

    /**
     * Create result for failed notification.
     */
    public static function createFailure(array $data, string $error): self
    {
        return self::create(array_merge($data, [
            'success' => false,
            'error' => $error,
            'timestamp_utc' => now(),
        ]));
    }
}
