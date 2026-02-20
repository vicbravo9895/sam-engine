<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Contact;
use App\Models\Driver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Servicio para resolver contactos basados en el contexto de la alerta.
 * 
 * Lógica de resolución:
 * - OPERATOR: Se obtiene de la tabla drivers (conductor del vehículo)
 * - MONITORING_TEAM, SUPERVISOR, EMERGENCY, DISPATCH: Se obtienen de la tabla contacts
 * 
 * Para contactos de la tabla contacts, el orden de búsqueda es:
 * 1. Contacto específico del vehículo
 * 2. Contacto específico del conductor
 * 3. Contacto global por defecto
 * 4. Cualquier contacto global activo del tipo
 */
class ContactResolver
{
    /**
     * Resuelve los contactos para una alerta.
     * Vehicle/driver data is read from the alert's signal.
     */
    public function resolveForAlert(Alert $alert): array
    {
        $alert->loadMissing('signal');

        $vehicleId = $alert->signal?->vehicle_id;
        $driverId = $alert->signal?->driver_id;
        $companyId = $alert->company_id;

        return $this->resolve($vehicleId, $driverId, $companyId);
    }

    /**
     * Resuelve los contactos dados un vehicle_id y driver_id.
     * 
     * @param string|null $vehicleId ID del vehículo de Samsara
     * @param string|null $driverId ID del conductor de Samsara (samsara_id)
     * @param int|null $companyId ID de la compañía (multi-tenant isolation)
     * @return array Contactos organizados por tipo
     */
    public function resolve(?string $vehicleId, ?string $driverId, ?int $companyId = null): array
    {
        $contacts = [
            'operator' => null,
            'monitoring_team' => null,
            'supervisor' => null,
            'emergency' => null,
            'dispatch' => null,
        ];

        if ($driverId) {
            $operator = $this->resolveOperatorFromDriver($driverId, $companyId);
            if ($operator) {
                $contacts['operator'] = $operator;
            }
        }

        $contactTypes = ['monitoring_team', 'supervisor', 'emergency', 'dispatch'];
        
        foreach ($contactTypes as $type) {
            $contact = $this->resolveByType($type, $vehicleId, $driverId, $companyId);
            if ($contact) {
                $contacts[$type] = $contact->toNotificationPayload();
            }
        }

        return array_filter($contacts);
    }

    /**
     * Resuelve el operador (conductor) desde la tabla drivers.
     */
    private function resolveOperatorFromDriver(string $driverId, ?int $companyId = null): ?array
    {
        $query = Driver::where('samsara_id', $driverId);
        
        if ($companyId) {
            $query->where('company_id', $companyId);
        }
        
        $driver = $query->first();
        
        if (!$driver) {
            Log::warning('ContactResolver: Driver not found', [
                'driver_id' => $driverId,
                'company_id' => $companyId,
            ]);
            return null;
        }

        $phone = $driver->formatted_phone;
        $whatsapp = $driver->formatted_whatsapp;
        
        if (!$phone && !$whatsapp) {
            Log::warning('ContactResolver: Driver has no phone configured', [
                'driver_id' => $driverId,
                'driver_name' => $driver->name,
                'raw_phone' => $driver->phone,
                'country_code' => $driver->country_code,
            ]);
            return null;
        }

        Log::info('ContactResolver: Operator resolved from driver', [
            'driver_id' => $driverId,
            'driver_name' => $driver->name,
            'phone' => $phone,
            'whatsapp' => $whatsapp,
        ]);

        return [
            'name' => $driver->name,
            'role' => 'Conductor',
            'type' => 'operator',
            'phone' => $phone,
            'whatsapp' => $whatsapp,
            'email' => null,
            'priority' => 1,
        ];
    }

    /**
     * Resuelve el contacto para un tipo específico.
     * 
     * Orden de búsqueda:
     * 1. Contacto específico del vehículo
     * 2. Contacto específico del conductor
     * 3. Contacto global por defecto
     * 4. Cualquier contacto global activo del tipo
     */
    public function resolveByType(string $type, ?string $vehicleId, ?string $driverId, ?int $companyId = null): ?Contact
    {
        if ($vehicleId) {
            $query = Contact::active()
                ->ofType($type)
                ->forVehicle($vehicleId);
            
            if ($companyId) {
                $query->forCompany($companyId);
            }
            
            $contact = $query->orderByPriority()->first();

            if ($contact) {
                return $contact;
            }
        }

        if ($driverId) {
            $query = Contact::active()
                ->ofType($type)
                ->forDriver($driverId);
            
            if ($companyId) {
                $query->forCompany($companyId);
            }
            
            $contact = $query->orderByPriority()->first();

            if ($contact) {
                return $contact;
            }
        }

        $query = Contact::active()
            ->ofType($type)
            ->global()
            ->default();
        
        if ($companyId) {
            $query->forCompany($companyId);
        }
        
        $contact = $query->orderByPriority()->first();

        if ($contact) {
            return $contact;
        }

        $query = Contact::active()
            ->ofType($type)
            ->global();
        
        if ($companyId) {
            $query->forCompany($companyId);
        }
        
        return $query->orderByPriority()->first();
    }

    /**
     * Obtiene todos los contactos aplicables para un evento.
     */
    public function getAllApplicable(?string $vehicleId, ?string $driverId, ?int $companyId = null): Collection
    {
        $query = Contact::active();
        
        if ($companyId) {
            $query->forCompany($companyId);
        }

        $query->where(function ($q) use ($vehicleId, $driverId) {
            $q->whereNull('entity_type');

            if ($vehicleId) {
                $q->orWhere(function ($subQ) use ($vehicleId) {
                    $subQ->where('entity_type', Contact::ENTITY_VEHICLE)
                         ->where('entity_id', $vehicleId);
                });
            }

            if ($driverId) {
                $q->orWhere(function ($subQ) use ($driverId) {
                    $subQ->where('entity_type', Contact::ENTITY_DRIVER)
                         ->where('entity_id', $driverId);
                });
            }
        });

        return $query->orderByPriority()->get();
    }

    /**
     * Formatea los contactos para inyectarlos en el payload del AI Service.
     */
    public function formatForPayload(array $contacts): array
    {
        $operatorPhone = $contacts['operator']['phone'] ?? null;
        $monitoringTeamPhone = $contacts['monitoring_team']['phone'] ?? null;
        $supervisorPhone = $contacts['supervisor']['phone'] ?? null;

        return [
            'operator_phone' => $operatorPhone,
            'monitoring_team_number' => $monitoringTeamPhone,
            'supervisor_phone' => $supervisorPhone,
            'notification_contacts' => $contacts,
        ];
    }
}
