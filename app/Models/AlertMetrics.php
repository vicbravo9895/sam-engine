<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertMetrics extends Model
{
    use HasFactory;
    protected $table = 'alert_metrics';

    protected $fillable = [
        'alert_id',
        'ai_started_at',
        'ai_finished_at',
        'pipeline_latency_ms',
        'ai_tokens',
        'ai_cost_estimate',
        'notification_sent_at',
    ];

    protected $casts = [
        'ai_started_at' => 'datetime',
        'ai_finished_at' => 'datetime',
        'notification_sent_at' => 'datetime',
        'ai_cost_estimate' => 'decimal:6',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }
}
