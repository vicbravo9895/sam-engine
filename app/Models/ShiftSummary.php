<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resumen de turno generado automáticamente con IA.
 */
class ShiftSummary extends Model
{
    protected $fillable = [
        'company_id',
        'shift_label',
        'period_start',
        'period_end',
        'summary_text',
        'metrics',
        'model_used',
        'tokens_used',
        'delivered_to',
        'delivered_at',
    ];

    protected $casts = [
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'metrics' => 'array',
        'delivered_to' => 'array',
        'delivered_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Scope: summaries for a company.
     */
    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
