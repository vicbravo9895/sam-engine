<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo para correlaciones entre alertas.
 * 
 * Tabla de unión entre alert_incidents y samsara_events.
 * Almacena metadata de la correlación (tipo, fuerza, diferencia temporal).
 */
class AlertCorrelation extends Model
{
    use HasFactory;

    /**
     * Disable default timestamps since we only have created_at.
     */
    public $timestamps = false;

    protected $fillable = [
        'incident_id',
        'samsara_event_id',
        'correlation_type',
        'correlation_strength',
        'time_delta_seconds',
        'detected_by',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'correlation_strength' => 'decimal:2',
        'time_delta_seconds' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    // Correlation types
    const TYPE_TEMPORAL = 'temporal';
    const TYPE_CAUSAL = 'causal';
    const TYPE_PATTERN = 'pattern';

    // Detection methods
    const DETECTED_BY_AI = 'ai';
    const DETECTED_BY_RULE = 'rule';
    const DETECTED_BY_HUMAN = 'human';

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
     * Incident this correlation belongs to.
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(AlertIncident::class, 'incident_id');
    }

    /**
     * Event this correlation links.
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
     * Scope by correlation type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('correlation_type', $type);
    }

    /**
     * Scope by detection method.
     */
    public function scopeDetectedBy($query, string $method)
    {
        return $query->where('detected_by', $method);
    }

    /**
     * Scope by minimum correlation strength.
     */
    public function scopeMinStrength($query, float $minStrength)
    {
        return $query->where('correlation_strength', '>=', $minStrength);
    }

    /**
     * Scope for strong correlations (>= 0.7).
     */
    public function scopeStrong($query)
    {
        return $query->where('correlation_strength', '>=', 0.7);
    }

    /**
     * ========================================
     * HELPERS
     * ========================================
     */

    /**
     * Get human-readable correlation type label.
     */
    public function getTypeLabel(): string
    {
        return match($this->correlation_type) {
            self::TYPE_TEMPORAL => 'Temporal',
            self::TYPE_CAUSAL => 'Causal',
            self::TYPE_PATTERN => 'Patrón',
            default => $this->correlation_type,
        };
    }

    /**
     * Get human-readable time delta.
     */
    public function getTimeDeltaHuman(): string
    {
        if ($this->time_delta_seconds === null) {
            return 'N/A';
        }

        $absSeconds = abs($this->time_delta_seconds);
        
        if ($absSeconds < 60) {
            return "{$absSeconds} segundos";
        }
        
        $minutes = round($absSeconds / 60);
        return "{$minutes} minutos";
    }

    /**
     * Check if this is a strong correlation.
     */
    public function isStrong(): bool
    {
        return $this->correlation_strength >= 0.7;
    }
}
