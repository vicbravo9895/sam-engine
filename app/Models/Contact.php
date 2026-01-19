<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use HasFactory, SoftDeletes;

    // Tipos de contacto
    // NOTA: "operator" ya NO es un tipo de contacto válido.
    // Los operadores (conductores) se obtienen de la tabla drivers.
    const TYPE_MONITORING_TEAM = 'monitoring_team';
    const TYPE_SUPERVISOR = 'supervisor';
    const TYPE_EMERGENCY = 'emergency';
    const TYPE_DISPATCH = 'dispatch';

    // Tipos de entidad
    const ENTITY_VEHICLE = 'vehicle';
    const ENTITY_DRIVER = 'driver';

    protected $fillable = [
        'company_id',
        'name',
        'role',
        'type',
        'phone',
        'phone_whatsapp',
        'email',
        'entity_type',
        'entity_id',
        'is_default',
        'priority',
        'is_active',
        'notification_preferences',
        'notes',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'priority' => 'integer',
        'notification_preferences' => 'array',
    ];

    /**
     * Get the company that owns this contact.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Scope a query to only include contacts for a specific company.
     */
    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Obtiene el número de WhatsApp (usa phone si phone_whatsapp no está definido)
     */
    public function getWhatsappNumberAttribute(): ?string
    {
        return $this->phone_whatsapp ?? $this->phone;
    }

    /**
     * Scope: Solo contactos activos
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Solo contactos por defecto
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope: Filtrar por tipo
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope: Contactos globales (sin entidad asociada)
     */
    public function scopeGlobal($query)
    {
        return $query->whereNull('entity_type');
    }

    /**
     * Scope: Contactos asociados a un vehículo
     */
    public function scopeForVehicle($query, string $vehicleId)
    {
        return $query->where('entity_type', self::ENTITY_VEHICLE)
                     ->where('entity_id', $vehicleId);
    }

    /**
     * Scope: Contactos asociados a un conductor
     */
    public function scopeForDriver($query, string $driverId)
    {
        return $query->where('entity_type', self::ENTITY_DRIVER)
                     ->where('entity_id', $driverId);
    }

    /**
     * Scope: Ordenar por prioridad (descendente)
     */
    public function scopeOrderByPriority($query)
    {
        return $query->orderByDesc('priority')->orderBy('name');
    }

    /**
     * Verifica si el contacto tiene un teléfono válido
     */
    public function hasPhone(): bool
    {
        return !empty($this->phone);
    }

    /**
     * Verifica si el contacto puede recibir WhatsApp
     */
    public function canReceiveWhatsapp(): bool
    {
        return !empty($this->whatsapp_number);
    }

    /**
     * Verifica si el contacto puede recibir email
     */
    public function canReceiveEmail(): bool
    {
        return !empty($this->email);
    }

    /**
     * Obtiene el array de datos para el payload del AI Service
     */
    public function toNotificationPayload(): array
    {
        return [
            'name' => $this->name,
            'role' => $this->role,
            'type' => $this->type,
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp_number,
            'email' => $this->email,
            'priority' => $this->priority,
        ];
    }

    /**
     * Obtiene todos los tipos disponibles.
     * 
     * NOTA: "operator" ya no es un tipo válido.
     * Los operadores (conductores) se configuran en /drivers.
     */
    public static function getTypes(): array
    {
        return [
            self::TYPE_MONITORING_TEAM => 'Equipo de Monitoreo',
            self::TYPE_SUPERVISOR => 'Supervisor',
            self::TYPE_EMERGENCY => 'Emergencia',
            self::TYPE_DISPATCH => 'Despacho',
        ];
    }

    /**
     * Obtiene el label del tipo
     */
    public function getTypeLabel(): string
    {
        return self::getTypes()[$this->type] ?? $this->type;
    }
}

