<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo para comentarios de monitoristas en alertas de Samsara.
 * 
 * Sistema simple: un comentario es un comentario.
 * Sin tipos, sin complejidad adicional.
 */
class SamsaraEventComment extends Model
{
    use HasFactory;

    protected $fillable = [
        'samsara_event_id',
        'user_id',
        'content',
    ];

    /**
     * Relación con el evento de Samsara.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(SamsaraEvent::class, 'samsara_event_id');
    }

    /**
     * Relación con el usuario que creó el comentario.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

