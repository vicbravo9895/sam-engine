<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $fillable = [
        'thread_id', 'role', 'content', 'meta'
    ];
    
    protected $casts = [
        'content' => 'array', 
        'meta' => 'array'
    ];
    
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'thread_id', 'thread_id');
    }
}

