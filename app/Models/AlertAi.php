<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertAi extends Model
{
    use HasFactory;
    protected $table = 'alert_ai';

    protected $fillable = [
        'alert_id',
        'monitoring_reason',
        'triage_notes',
        'investigation_strategy',
        'supporting_evidence',
        'raw_ai_output',
        'alert_context',
        'ai_assessment',
        'ai_actions',
        'ai_error',
        'investigation_count',
        'last_investigation_at',
        'next_check_minutes',
        'investigation_history',
        'correlation_window_minutes',
        'media_window_seconds',
        'safety_events_before_minutes',
        'safety_events_after_minutes',
    ];

    protected $casts = [
        'supporting_evidence' => 'array',
        'raw_ai_output' => 'array',
        'alert_context' => 'array',
        'ai_assessment' => 'array',
        'ai_actions' => 'array',
        'investigation_history' => 'array',
        'last_investigation_at' => 'datetime',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }
}
