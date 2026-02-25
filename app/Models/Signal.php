<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Signal extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'company_id',
        'source',
        'samsara_event_id',
        'event_type',
        'event_description',
        'vehicle_id',
        'vehicle_name',
        'driver_id',
        'driver_name',
        'severity',
        'occurred_at',
        'raw_payload',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    // =========================================================================
    // Relationships
    // =========================================================================

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function alertSources(): HasMany
    {
        return $this->hasMany(AlertSource::class);
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForVehicle(Builder $query, string $vehicleId): Builder
    {
        return $query->where('vehicle_id', $vehicleId);
    }

    public function scopeFromSource(Builder $query, string $source): Builder
    {
        return $query->where('source', $source);
    }

    public function scopeSince(Builder $query, \DateTimeInterface|string $dateTime): Builder
    {
        return $query->where('occurred_at', '>=', $dateTime);
    }

    public function scopeUntil(Builder $query, \DateTimeInterface|string $dateTime): Builder
    {
        return $query->where('occurred_at', '<=', $dateTime);
    }
}
