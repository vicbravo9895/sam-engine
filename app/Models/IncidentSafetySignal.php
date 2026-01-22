<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot model for Incident <-> SafetySignal relationship.
 * 
 * Stores metadata about how each safety signal relates to an incident:
 * - role: supporting, contradicting, or context
 * - relevance_score: 0.00 to 1.00 indicating importance
 */
class IncidentSafetySignal extends Pivot
{
    protected $table = 'incident_safety_signals';

    public $incrementing = true;

    // Roles
    public const ROLE_SUPPORTING = 'supporting';
    public const ROLE_CONTRADICTING = 'contradicting';
    public const ROLE_CONTEXT = 'context';

    protected $fillable = [
        'incident_id',
        'safety_signal_id',
        'role',
        'relevance_score',
    ];

    protected function casts(): array
    {
        return [
            'relevance_score' => 'decimal:2',
        ];
    }

    /**
     * Get the incident.
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * Get the safety signal.
     */
    public function safetySignal(): BelongsTo
    {
        return $this->belongsTo(SafetySignal::class);
    }

    /**
     * Get human-readable role label.
     */
    public function getRoleLabel(): string
    {
        return match ($this->role) {
            self::ROLE_SUPPORTING => 'Evidencia de apoyo',
            self::ROLE_CONTRADICTING => 'Evidencia contradictoria',
            self::ROLE_CONTEXT => 'Contexto adicional',
            default => $this->role,
        };
    }
}
