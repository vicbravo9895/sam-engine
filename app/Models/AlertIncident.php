<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modelo para incidentes de alertas correlacionadas.
 * 
 * Un incidente agrupa múltiples alertas relacionadas (ej: frenado brusco + botón de pánico = colisión).
 * El sistema detecta correlaciones temporales y las agrupa en incidentes para análisis.
 */
class AlertIncident extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'incident_type',
        'primary_event_id',
        'severity',
        'status',
        'detected_at',
        'resolved_at',
        'ai_summary',
        'metadata',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
        'metadata' => 'array',
    ];

    // Incident types
    const TYPE_COLLISION = 'collision';
    const TYPE_EMERGENCY = 'emergency';
    const TYPE_PATTERN = 'pattern';
    const TYPE_UNKNOWN = 'unknown';

    // Incident statuses
    const STATUS_OPEN = 'open';
    const STATUS_INVESTIGATING = 'investigating';
    const STATUS_RESOLVED = 'resolved';
    const STATUS_FALSE_POSITIVE = 'false_positive';

    // Severity levels
    const SEVERITY_INFO = 'info';
    const SEVERITY_WARNING = 'warning';
    const SEVERITY_CRITICAL = 'critical';

    /**
     * ========================================
     * RELATIONSHIPS
     * ========================================
     */

    /**
     * Company that owns this incident.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Primary event that triggered the incident.
     */
    public function primaryEvent(): BelongsTo
    {
        return $this->belongsTo(SamsaraEvent::class, 'primary_event_id');
    }

    /**
     * All correlations (links to related events).
     */
    public function correlations(): HasMany
    {
        return $this->hasMany(AlertCorrelation::class, 'incident_id');
    }

    /**
     * All events linked to this incident.
     */
    public function events(): HasMany
    {
        return $this->hasMany(SamsaraEvent::class, 'incident_id');
    }

    /**
     * ========================================
     * SCOPES
     * ========================================
     */

    /**
     * Scope to filter by company.
     */
    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Scope to filter by status.
     */
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope for open incidents.
     */
    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * Scope for investigating incidents.
     */
    public function scopeInvestigating($query)
    {
        return $query->where('status', self::STATUS_INVESTIGATING);
    }

    /**
     * Scope for unresolved incidents (open or investigating).
     */
    public function scopeUnresolved($query)
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_INVESTIGATING]);
    }

    /**
     * Scope by incident type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('incident_type', $type);
    }

    /**
     * Scope by severity.
     */
    public function scopeSeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    /**
     * ========================================
     * HELPERS
     * ========================================
     */

    /**
     * Check if incident is resolved.
     */
    public function isResolved(): bool
    {
        return in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_FALSE_POSITIVE]);
    }

    /**
     * Mark incident as resolved.
     */
    public function markAsResolved(?string $summary = null): void
    {
        $this->update([
            'status' => self::STATUS_RESOLVED,
            'resolved_at' => now(),
            'ai_summary' => $summary ?? $this->ai_summary,
        ]);
    }

    /**
     * Mark incident as false positive.
     */
    public function markAsFalsePositive(?string $reason = null): void
    {
        $this->update([
            'status' => self::STATUS_FALSE_POSITIVE,
            'resolved_at' => now(),
            'ai_summary' => $reason ?? $this->ai_summary,
        ]);
    }

    /**
     * Get count of related events.
     */
    public function getRelatedEventsCount(): int
    {
        return $this->correlations()->count();
    }

    /**
     * Get human-readable incident type label.
     */
    public function getTypeLabel(): string
    {
        return match($this->incident_type) {
            self::TYPE_COLLISION => 'Colisión',
            self::TYPE_EMERGENCY => 'Emergencia',
            self::TYPE_PATTERN => 'Patrón de comportamiento',
            self::TYPE_UNKNOWN => 'Desconocido',
            default => $this->incident_type,
        };
    }

    /**
     * Get human-readable status label.
     */
    public function getStatusLabel(): string
    {
        return match($this->status) {
            self::STATUS_OPEN => 'Abierto',
            self::STATUS_INVESTIGATING => 'En investigación',
            self::STATUS_RESOLVED => 'Resuelto',
            self::STATUS_FALSE_POSITIVE => 'Falso positivo',
            default => $this->status,
        };
    }
}
