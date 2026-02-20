<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertSource extends Model
{
    use HasFactory;
    protected $fillable = [
        'alert_id',
        'signal_id',
        'role',
        'relevance',
    ];

    protected $casts = [
        'relevance' => 'decimal:2',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }

    public function scopePrimary($query)
    {
        return $query->where('role', 'primary');
    }

    public function scopeCorrelated($query)
    {
        return $query->where('role', 'correlated');
    }
}
