<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDeliveryEvent extends Model
{
    use HasFactory;
    public $timestamps = false;

    protected $fillable = [
        'notification_result_id',
        'provider_sid',
        'status',
        'error_code',
        'error_message',
        'raw_callback',
        'received_at',
    ];

    protected $casts = [
        'raw_callback' => 'array',
        'received_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────

    public function notificationResult(): BelongsTo
    {
        return $this->belongsTo(NotificationResult::class);
    }

    // ── Scopes ───────────────────────────────────────────────────

    public function scopeForSid($query, string $sid)
    {
        return $query->where('provider_sid', $sid);
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderByDesc('received_at');
    }

    // ── Helpers ──────────────────────────────────────────────────

    public function isTerminal(): bool
    {
        return in_array($this->status, ['delivered', 'read', 'failed', 'undelivered']);
    }
}
