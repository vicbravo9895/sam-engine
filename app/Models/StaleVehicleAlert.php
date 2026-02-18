<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaleVehicleAlert extends Model
{
    protected $fillable = [
        'company_id',
        'samsara_vehicle_id',
        'vehicle_name',
        'last_stat_at',
        'alerted_at',
        'resolved_at',
        'channels_used',
        'recipients_notified',
    ];

    protected function casts(): array
    {
        return [
            'last_stat_at' => 'datetime',
            'alerted_at' => 'datetime',
            'resolved_at' => 'datetime',
            'channels_used' => 'array',
            'recipients_notified' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForVehicle(Builder $query, string $samsaraVehicleId): Builder
    {
        return $query->where('samsara_vehicle_id', $samsaraVehicleId);
    }

    /**
     * Alerts that were sent more than $minutes ago (cooldown expired).
     */
    public function scopeCooldownExpired(Builder $query, int $minutes): Builder
    {
        return $query->where('alerted_at', '<', now()->subMinutes($minutes));
    }

    public function markResolved(): void
    {
        $this->update(['resolved_at' => now()]);
    }
}
