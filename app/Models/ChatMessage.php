<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $fillable = [
        'thread_id', 'role', 'status', 'content', 'streaming_content', 
        'streaming_started_at', 'streaming_completed_at', 'meta'
    ];
    
    protected $casts = [
        'content' => 'array', 
        'meta' => 'array',
        'streaming_started_at' => 'datetime',
        'streaming_completed_at' => 'datetime',
    ];
    
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'thread_id', 'thread_id');
    }
}

