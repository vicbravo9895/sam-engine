<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertActivity extends Model
{
    use HasFactory;
    public $timestamps = false;

    protected $fillable = [
        'alert_id',
        'company_id',
        'user_id',
        'action',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    const ACTION_AI_PROCESSING_STARTED = 'ai_processing_started';
    const ACTION_AI_COMPLETED = 'ai_completed';
    const ACTION_AI_FAILED = 'ai_failed';
    const ACTION_AI_INVESTIGATING = 'ai_investigating';
    const ACTION_AI_REVALIDATED = 'ai_revalidated';

    const ACTION_HUMAN_REVIEWED = 'human_reviewed';
    const ACTION_HUMAN_STATUS_CHANGED = 'human_status_changed';
    const ACTION_COMMENT_ADDED = 'comment_added';
    const ACTION_MARKED_FALSE_POSITIVE = 'marked_false_positive';
    const ACTION_MARKED_RESOLVED = 'marked_resolved';
    const ACTION_MARKED_FLAGGED = 'marked_flagged';

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->created_at) {
                $model->created_at = now();
            }
        });
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeAiActions($query)
    {
        return $query->whereNull('user_id');
    }

    public function scopeHumanActions($query)
    {
        return $query->whereNotNull('user_id');
    }

    public function scopeOfAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function isAiAction(): bool
    {
        return $this->user_id === null;
    }

    public function isHumanAction(): bool
    {
        return $this->user_id !== null;
    }

    public static function logAiAction(int $alertId, ?int $companyId, string $action, ?array $metadata = null): self
    {
        return static::create([
            'alert_id' => $alertId,
            'company_id' => $companyId,
            'user_id' => null,
            'action' => $action,
            'metadata' => $metadata,
        ]);
    }

    public static function logHumanAction(int $alertId, ?int $companyId, int $userId, string $action, ?array $metadata = null): self
    {
        return static::create([
            'alert_id' => $alertId,
            'company_id' => $companyId,
            'user_id' => $userId,
            'action' => $action,
            'metadata' => $metadata,
        ]);
    }
}
