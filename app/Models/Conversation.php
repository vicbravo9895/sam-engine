<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        'thread_id', 'user_id', 'company_id', 'title', 'meta',
        'total_input_tokens', 'total_output_tokens', 'total_tokens'
    ];
    
    protected $casts = [
        'meta' => 'array',
        'total_input_tokens' => 'integer',
        'total_output_tokens' => 'integer',
        'total_tokens' => 'integer',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'thread_id', 'thread_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Scope a query to only include conversations for a specific company.
     */
    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Get total tokens as an accessor (for backward compatibility).
     */
    public function getTotalTokensAttribute(): int
    {
        return $this->attributes['total_tokens'] ?? 0;
    }
}

