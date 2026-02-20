<?php

namespace App\Models;

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

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForVehicle($query, string $vehicleId)
    {
        return $query->where('vehicle_id', $vehicleId);
    }

    public function scopeFromSource($query, string $source)
    {
        return $query->where('source', $source);
    }

    public function scopeSince($query, $dateTime)
    {
        return $query->where('occurred_at', '>=', $dateTime);
    }

    public function scopeUntil($query, $dateTime)
    {
        return $query->where('occurred_at', '<=', $dateTime);
    }
}
