<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UsageEvent extends Model
{
    use HasFactory;
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'occurred_at',
        'meter',
        'qty',
        'dimensions',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'qty' => 'decimal:4',
            'dimensions' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Idempotent insert — silently ignores duplicates.
     */
    public static function record(
        int $companyId,
        string $meter,
        float|int $qty,
        string $idempotencyKey,
        ?array $dimensions = null,
        ?\DateTimeInterface $occurredAt = null,
    ): void {
        DB::table('usage_events')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'occurred_at' => ($occurredAt ?? now())->format('Y-m-d H:i:s'),
            'meter' => $meter,
            'qty' => $qty,
            'dimensions' => $dimensions ? json_encode($dimensions) : null,
            'idempotency_key' => $idempotencyKey,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForMeter($query, string $meter)
    {
        return $query->where('meter', $meter);
    }

    public function scopeBetween($query, $from, $to)
    {
        return $query->whereBetween('occurred_at', [$from, $to]);
    }
}
