<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Webhooks que no pudieron asociarse a un vehículo/empresa.
 * Se reprocesan automáticamente via ProcessPendingWebhooksJob.
 */
class PendingWebhook extends Model
{
    protected $fillable = [
        'vehicle_samsara_id',
        'event_type',
        'raw_payload',
        'attempts',
        'max_attempts',
        'last_attempted_at',
        'resolved_at',
        'resolution_note',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'last_attempted_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /**
     * Scope: webhooks pendientes de resolver.
     */
    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at')
            ->whereColumn('attempts', '<', 'max_attempts');
    }

    /**
     * Scope: webhooks que excedieron el máximo de intentos.
     */
    public function scopeExhausted($query)
    {
        return $query->whereNull('resolved_at')
            ->whereColumn('attempts', '>=', 'max_attempts');
    }

    /**
     * Marcar como resuelto.
     */
    public function markResolved(string $note = 'Vehículo encontrado'): void
    {
        $this->update([
            'resolved_at' => now(),
            'resolution_note' => $note,
        ]);
    }

    /**
     * Incrementar intento fallido.
     */
    public function incrementAttempt(): void
    {
        $this->update([
            'attempts' => $this->attempts + 1,
            'last_attempted_at' => now(),
        ]);
    }
}
