<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MediaAsset extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    const CATEGORY_EVIDENCE = 'evidence';
    const CATEGORY_DASHCAM = 'dashcam-media';
    const CATEGORY_SIGNAL = 'signal-media';

    protected $fillable = [
        'company_id',
        'assetable_type',
        'assetable_id',
        'category',
        'disk',
        'source_url',
        'storage_path',
        'local_url',
        'status',
        'mime_type',
        'file_size',
        'attempts',
        'error_message',
        'metadata',
        'completed_at',
        'failed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'file_size' => 'integer',
        'attempts' => 'integer',
    ];

    // ─── Relationships ───────────────────────────────────────────

    public function assetable(): MorphTo
    {
        return $this->morphTo();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // ─── Status transitions ──────────────────────────────────────

    public function markAsProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'attempts' => $this->attempts + 1,
        ]);
    }

    public function markAsCompleted(string $localUrl, ?string $mimeType = null, ?int $fileSize = null): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'local_url' => $localUrl,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
            'completed_at' => now(),
            'error_message' => null,
        ]);
    }

    public function markAsFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $error,
            'failed_at' => now(),
        ]);
    }

    // ─── Scopes ──────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    // ─── Helpers ─────────────────────────────────────────────────

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function resolvedUrl(): string
    {
        return $this->local_url ?? $this->source_url;
    }
}
