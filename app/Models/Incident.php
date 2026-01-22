<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * Canonical Incident Model.
 * 
 * Represents operational tickets for fleet incidents with:
 * - Priority-based classification (P1-P4)
 * - SLA tracking
 * - Dedupe logic to prevent duplicates
 * - Link to safety signals via pivot table
 */
class Incident extends Model
{
    use HasFactory;

    // Incident types
    public const TYPE_COLLISION = 'collision';
    public const TYPE_EMERGENCY = 'emergency';
    public const TYPE_PATTERN = 'pattern';
    public const TYPE_SAFETY_VIOLATION = 'safety_violation';
    public const TYPE_TAMPERING = 'tampering';
    public const TYPE_UNKNOWN = 'unknown';

    // Priorities
    public const PRIORITY_P1 = 'P1'; // Critical - immediate response
    public const PRIORITY_P2 = 'P2'; // High - urgent response
    public const PRIORITY_P3 = 'P3'; // Medium - standard response
    public const PRIORITY_P4 = 'P4'; // Low - informational

    // Severity levels
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    // Status workflow
    public const STATUS_OPEN = 'open';
    public const STATUS_INVESTIGATING = 'investigating';
    public const STATUS_PENDING_ACTION = 'pending_action';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_FALSE_POSITIVE = 'false_positive';

    // Sources
    public const SOURCE_WEBHOOK = 'webhook';
    public const SOURCE_AUTO_PATTERN = 'auto_pattern';
    public const SOURCE_AUTO_AGGREGATOR = 'auto_aggregator';
    public const SOURCE_MANUAL = 'manual';

    // Subject types
    public const SUBJECT_DRIVER = 'driver';
    public const SUBJECT_VEHICLE = 'vehicle';

    protected $fillable = [
        'company_id',
        'incident_type',
        'priority',
        'severity',
        'status',
        'subject_type',
        'subject_id',
        'subject_name',
        'source',
        'samsara_event_id',
        'dedupe_key',
        'ai_summary',
        'ai_assessment',
        'metadata',
        'detected_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'ai_assessment' => 'array',
            'metadata' => 'array',
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

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
     * Safety signals linked to this incident.
     */
    public function safetySignals(): BelongsToMany
    {
        return $this->belongsToMany(SafetySignal::class, 'incident_safety_signals')
            ->withPivot(['role', 'relevance_score', 'created_at']);
    }

    /**
     * Supporting signals (positive evidence).
     */
    public function supportingSignals(): BelongsToMany
    {
        return $this->safetySignals()->wherePivot('role', 'supporting');
    }

    /**
     * Contradicting signals (evidence against).
     */
    public function contradictingSignals(): BelongsToMany
    {
        return $this->safetySignals()->wherePivot('role', 'contradicting');
    }

    /**
     * Context signals (additional context).
     */
    public function contextSignals(): BelongsToMany
    {
        return $this->safetySignals()->wherePivot('role', 'context');
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
     * Scope for open incidents.
     */
    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * Scope for unresolved incidents.
     */
    public function scopeUnresolved($query)
    {
        return $query->whereNotIn('status', [self::STATUS_RESOLVED, self::STATUS_FALSE_POSITIVE]);
    }

    /**
     * Scope by priority.
     */
    public function scopeByPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    /**
     * Scope by type.
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('incident_type', $type);
    }

    /**
     * Scope by status.
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope by subject (driver or vehicle).
     */
    public function scopeForSubject($query, string $type, string $id)
    {
        return $query->where('subject_type', $type)->where('subject_id', $id);
    }

    /**
     * Scope for high priority incidents (P1, P2).
     */
    public function scopeHighPriority($query)
    {
        return $query->whereIn('priority', [self::PRIORITY_P1, self::PRIORITY_P2]);
    }

    /**
     * Scope to order by priority (P1 first).
     */
    public function scopeOrderByPriority($query, string $direction = 'asc')
    {
        $order = $direction === 'asc' 
            ? "CASE priority WHEN 'P1' THEN 1 WHEN 'P2' THEN 2 WHEN 'P3' THEN 3 WHEN 'P4' THEN 4 END"
            : "CASE priority WHEN 'P4' THEN 1 WHEN 'P3' THEN 2 WHEN 'P2' THEN 3 WHEN 'P1' THEN 4 END";
        
        return $query->orderByRaw($order);
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
     * Check if incident is high priority.
     */
    public function isHighPriority(): bool
    {
        return in_array($this->priority, [self::PRIORITY_P1, self::PRIORITY_P2]);
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
     * Link a safety signal to this incident.
     */
    public function linkSignal(SafetySignal $signal, string $role = 'supporting', float $relevanceScore = 0.5): void
    {
        $this->safetySignals()->syncWithoutDetaching([
            $signal->id => [
                'role' => $role,
                'relevance_score' => $relevanceScore,
            ],
        ]);
    }

    /**
     * Link multiple safety signals.
     */
    public function linkSignals(Collection $signals, string $role = 'supporting'): void
    {
        $pivotData = $signals->mapWithKeys(fn ($signal) => [
            $signal->id => [
                'role' => $role,
                'relevance_score' => 0.5,
            ],
        ])->toArray();

        $this->safetySignals()->syncWithoutDetaching($pivotData);
    }

    /**
     * Get count of linked evidence (signals).
     */
    public function getEvidenceCount(): int
    {
        return $this->safetySignals()->count();
    }

    /**
     * Get human-readable incident type label.
     */
    public function getTypeLabel(): string
    {
        return match ($this->incident_type) {
            self::TYPE_COLLISION => 'Colisión',
            self::TYPE_EMERGENCY => 'Emergencia',
            self::TYPE_PATTERN => 'Patrón de comportamiento',
            self::TYPE_SAFETY_VIOLATION => 'Violación de seguridad',
            self::TYPE_TAMPERING => 'Manipulación',
            self::TYPE_UNKNOWN => 'Desconocido',
            default => $this->incident_type,
        };
    }

    /**
     * Get human-readable status label.
     */
    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN => 'Abierto',
            self::STATUS_INVESTIGATING => 'En investigación',
            self::STATUS_PENDING_ACTION => 'Pendiente de acción',
            self::STATUS_RESOLVED => 'Resuelto',
            self::STATUS_FALSE_POSITIVE => 'Falso positivo',
            default => $this->status,
        };
    }

    /**
     * Get human-readable priority label.
     */
    public function getPriorityLabel(): string
    {
        return match ($this->priority) {
            self::PRIORITY_P1 => 'P1 - Crítico',
            self::PRIORITY_P2 => 'P2 - Alto',
            self::PRIORITY_P3 => 'P3 - Medio',
            self::PRIORITY_P4 => 'P4 - Bajo',
            default => $this->priority,
        };
    }

    /**
     * Get human-readable severity label.
     */
    public function getSeverityLabel(): string
    {
        return match ($this->severity) {
            self::SEVERITY_CRITICAL => 'Crítico',
            self::SEVERITY_WARNING => 'Advertencia',
            self::SEVERITY_INFO => 'Información',
            default => $this->severity,
        };
    }

    /**
     * Generate a dedupe key for this incident.
     */
    public static function generateDedupeKey(
        string $type,
        ?string $subjectType,
        ?string $subjectId,
        \DateTimeInterface $detectedAt,
        int $windowMinutes = 30
    ): string {
        $windowStart = \Carbon\Carbon::parse($detectedAt)
            ->floorMinutes($windowMinutes)
            ->format('Y-m-d-H-i');

        return implode(':', array_filter([
            $type,
            $subjectType,
            $subjectId,
            $windowStart,
        ]));
    }
}
