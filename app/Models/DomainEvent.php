<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainEvent extends Model
{
    use HasFactory;
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'occurred_at',
        'entity_type',
        'entity_id',
        'event_type',
        'actor_type',
        'actor_id',
        'traceparent',
        'correlation_id',
        'schema_version',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'schema_version' => 'integer',
        ];
    }

    // ── Relationships ──────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // ── Scopes ─────────────────────────────────────────────────────

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForEntity(Builder $query, string $type, string $id): Builder
    {
        return $query->where('entity_type', $type)->where('entity_id', $id);
    }

    public function scopeOfType(Builder $query, string $eventType): Builder
    {
        return $query->where('event_type', $eventType);
    }

    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('occurred_at');
    }

    public function scopeReverseChronological(Builder $query): Builder
    {
        return $query->orderByDesc('occurred_at');
    }
}
