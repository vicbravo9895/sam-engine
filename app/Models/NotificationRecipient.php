<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo para destinatarios de notificaciones.
 * 
 * Almacena los destinatarios de cada decisión de notificación.
 * Anteriormente almacenado en notification_decision.recipients JSON.
 */
class NotificationRecipient extends Model
{
    use HasFactory;

    /**
     * Disable timestamps - only using created_at.
     */
    public $timestamps = false;

    protected $fillable = [
        'notification_decision_id',
        'recipient_type',
        'phone',
        'whatsapp',
        'priority',
        'created_at',
    ];

    protected $casts = [
        'priority' => 'integer',
        'created_at' => 'datetime',
    ];

    // Recipient types
    const TYPE_OPERATOR = 'operator';
    const TYPE_MONITORING_TEAM = 'monitoring_team';
    const TYPE_SUPERVISOR = 'supervisor';
    const TYPE_EMERGENCY = 'emergency';
    const TYPE_DISPATCH = 'dispatch';

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
     * Notification decision this recipient belongs to.
     */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(NotificationDecision::class, 'notification_decision_id');
    }

    /**
     * ========================================
     * SCOPES
     * ========================================
     */

    /**
     * Scope by recipient type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('recipient_type', $type);
    }

    /**
     * Scope for recipients with phone numbers.
     */
    public function scopeWithPhone($query)
    {
        return $query->whereNotNull('phone');
    }

    /**
     * Scope for recipients with WhatsApp numbers.
     */
    public function scopeWithWhatsapp($query)
    {
        return $query->whereNotNull('whatsapp');
    }

    /**
     * ========================================
     * HELPERS
     * ========================================
     */

    /**
     * Get human-readable recipient type label.
     */
    public function getTypeLabel(): string
    {
        return match($this->recipient_type) {
            self::TYPE_OPERATOR => 'Operador',
            self::TYPE_MONITORING_TEAM => 'Equipo de monitoreo',
            self::TYPE_SUPERVISOR => 'Supervisor',
            self::TYPE_EMERGENCY => 'Emergencia',
            self::TYPE_DISPATCH => 'Despacho',
            default => $this->recipient_type,
        };
    }

    /**
     * Get the best contact number (phone or WhatsApp).
     */
    public function getBestContactNumber(): ?string
    {
        return $this->phone ?? $this->whatsapp;
    }

    /**
     * Check if recipient has any contact method.
     */
    public function hasContactMethod(): bool
    {
        return !empty($this->phone) || !empty($this->whatsapp);
    }
}
