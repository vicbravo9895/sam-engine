<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Deal extends Model
{
    public const STATUS_NEW = 'new';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_QUALIFIED = 'qualified';
    public const STATUS_PROPOSAL = 'proposal';
    public const STATUS_WON = 'won';
    public const STATUS_LOST = 'lost';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_CONTACTED,
        self::STATUS_QUALIFIED,
        self::STATUS_PROPOSAL,
        self::STATUS_WON,
        self::STATUS_LOST,
    ];

    public const STATUS_LABELS = [
        self::STATUS_NEW => 'Nuevo',
        self::STATUS_CONTACTED => 'Contactado',
        self::STATUS_QUALIFIED => 'Calificado',
        self::STATUS_PROPOSAL => 'Propuesta',
        self::STATUS_WON => 'Ganado',
        self::STATUS_LOST => 'Perdido',
    ];

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'company_name',
        'position',
        'fleet_size',
        'country',
        'challenges',
        'status',
        'internal_notes',
        'contacted_at',
        'qualified_at',
        'closed_at',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'contacted_at' => 'datetime',
            'qualified_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function markAsContacted(): void
    {
        $this->update([
            'status' => self::STATUS_CONTACTED,
            'contacted_at' => now(),
        ]);
    }

    public function markAsQualified(): void
    {
        $this->update([
            'status' => self::STATUS_QUALIFIED,
            'qualified_at' => now(),
        ]);
    }

    public function markAsClosed(string $outcome): void
    {
        $this->update([
            'status' => $outcome,
            'closed_at' => now(),
        ]);
    }
}
