<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modelo para decisiones de notificación.
 * 
 * Almacena la decisión del agente de notificación para cada evento.
 * Anteriormente almacenado en notification_decision JSON.
 */
class NotificationDecision extends Model
{
    use HasFactory;

    /**
     * Disable timestamps - only using created_at.
     */
    public $timestamps = false;

    protected $fillable = [
        'alert_id',
        'should_notify',
        'escalation_level',
        'message_text',
        'call_script',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'should_notify' => 'boolean',
        'created_at' => 'datetime',
    ];

    // Escalation levels (must match DB check constraint)
    public const ESCALATION_EMERGENCY = 'emergency';
    public const ESCALATION_CRITICAL = 'critical';
    public const ESCALATION_HIGH = 'high';
    public const ESCALATION_LOW = 'low';
    public const ESCALATION_NONE = 'none';

    /** @var list<string> Allowed values for escalation_level in DB */
    public const ESCALATION_LEVELS = [
        self::ESCALATION_EMERGENCY,
        self::ESCALATION_CRITICAL,
        self::ESCALATION_HIGH,
        self::ESCALATION_LOW,
        self::ESCALATION_NONE,
    ];

    /**
     * Normalize escalation level to an allowed DB value.
     * Use before persisting to avoid check constraint violations.
     */
    public static function normalizeEscalationLevel(?string $level): string
    {
        if ($level === null || $level === '') {
            return self::ESCALATION_NONE;
        }
        $level = strtolower(trim($level));
        return in_array($level, self::ESCALATION_LEVELS, true)
            ? $level
            : self::ESCALATION_CRITICAL;
    }

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
     * Event this decision belongs to.
     */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    /**
     * Recipients for this notification decision.
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(NotificationRecipient::class, 'notification_decision_id')
            ->orderBy('priority', 'asc');
    }

    /**
     * ========================================
     * SCOPES
     * ========================================
     */

    /**
     * Scope for decisions that should notify.
     */
    public function scopeShouldNotify($query)
    {
        return $query->where('should_notify', true);
    }

    /**
     * Scope by escalation level.
     */
    public function scopeEscalation($query, string $level)
    {
        return $query->where('escalation_level', $level);
    }

    /**
     * Scope for critical escalation.
     */
    public function scopeCritical($query)
    {
        return $query->where('escalation_level', self::ESCALATION_CRITICAL);
    }

    /**
     * ========================================
     * HELPERS
     * ========================================
     */

    /**
     * Get human-readable escalation level label.
     */
    public function getEscalationLabel(): string
    {
        return match($this->escalation_level) {
            self::ESCALATION_EMERGENCY => 'Emergencia',
            self::ESCALATION_CRITICAL => 'Crítico',
            self::ESCALATION_HIGH => 'Alto',
            self::ESCALATION_LOW => 'Bajo',
            self::ESCALATION_NONE => 'Sin escalación',
            default => $this->escalation_level,
        };
    }

    /**
     * Create decision with recipients from array.
     */
    public static function createWithRecipients(array $decisionData, array $recipients): self
    {
        $decision = self::create($decisionData);
        
        foreach ($recipients as $recipientData) {
            $recipientData['notification_decision_id'] = $decision->id;
            NotificationRecipient::create($recipientData);
        }
        
        return $decision;
    }
}
