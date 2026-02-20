<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageDailySummary extends Model
{
    use HasFactory;
    protected $fillable = [
        'company_id',
        'date',
        'meter',
        'total_qty',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total_qty' => 'decimal:4',
            'computed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForMeter($query, string $meter)
    {
        return $query->where('meter', $meter);
    }

    public function scopeForMonth($query, int $year, int $month)
    {
        return $query->whereYear('date', $year)->whereMonth('date', $month);
    }
}
