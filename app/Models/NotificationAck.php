<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationAck extends Model
{
    use HasFactory;
    public $timestamps = false;

    protected $fillable = [
        'alert_id',
        'notification_result_id',
        'company_id',
        'ack_type',
        'ack_by_user_id',
        'ack_payload',
        'created_at',
    ];

    protected $casts = [
        'ack_payload' => 'array',
        'created_at' => 'datetime',
    ];

    const TYPE_UI = 'ui';
    const TYPE_REPLY = 'reply';
    const TYPE_IVR = 'ivr';

    // ── Relationships ────────────────────────────────────────────

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function notificationResult(): BelongsTo
    {
        return $this->belongsTo(NotificationResult::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ack_by_user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // ── Scopes ───────────────────────────────────────────────────

    public function scopeForAlert($query, int $alertId)
    {
        return $query->where('alert_id', $alertId);
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('ack_type', $type);
    }
}
