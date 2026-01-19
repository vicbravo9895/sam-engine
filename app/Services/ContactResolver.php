<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Driver;
use App\Models\SamsaraEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Servicio para resolver contactos basados en el contexto del evento.
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
     * Resuelve los contactos para un evento de Samsara.
     * 
     * @param SamsaraEvent $event El evento para el cual resolver contactos
     * @return array Contactos organizados por tipo para el AI Service
     */
    public function resolveForEvent(SamsaraEvent $event): array
    {
        $vehicleId = $event->vehicle_id;
        $driverId = $event->driver_id;
        $companyId = $event->company_id;

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

        // OPERATOR: Resolver desde la tabla drivers
        if ($driverId) {
            $operator = $this->resolveOperatorFromDriver($driverId, $companyId);
            if ($operator) {
                $contacts['operator'] = $operator;
            }
        }

        // Otros tipos: Resolver desde la tabla contacts
        $contactTypes = ['monitoring_team', 'supervisor', 'emergency', 'dispatch'];
        
        foreach ($contactTypes as $type) {
            $contact = $this->resolveByType($type, $vehicleId, $driverId, $companyId);
            if ($contact) {
                $contacts[$type] = $contact->toNotificationPayload();
            }
        }

        // Filtrar nulls para una respuesta más limpia
        return array_filter($contacts);
    }

    /**
     * Resuelve el operador (conductor) desde la tabla drivers.
     * 
     * @param string $driverId ID del conductor de Samsara (samsara_id)
     * @param int|null $companyId ID de la compañía
     * @return array|null Datos del operador para notificaciones
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

        // Verificar que tenga teléfono configurado
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
            'priority' => 1, // Máxima prioridad
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
     * 
     * @param string $type Tipo de contacto
     * @param string|null $vehicleId ID del vehículo
     * @param string|null $driverId ID del conductor
     * @param int|null $companyId ID de la compañía (multi-tenant isolation)
     * @return Contact|null
     */
    public function resolveByType(string $type, ?string $vehicleId, ?string $driverId, ?int $companyId = null): ?Contact
    {
        // 1. Buscar contacto específico del vehículo
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

        // 2. Buscar contacto específico del conductor
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

        // 3. Buscar contacto global por defecto
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

        // 4. Fallback: cualquier contacto global activo del tipo
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
     * 
     * @param string|null $vehicleId ID del vehículo
     * @param string|null $driverId ID del conductor
     * @param int|null $companyId ID de la compañía (multi-tenant isolation)
     * @return Collection Colección de todos los contactos aplicables
     */
    public function getAllApplicable(?string $vehicleId, ?string $driverId, ?int $companyId = null): Collection
    {
        $query = Contact::active();
        
        // Filter by company_id if provided (multi-tenant isolation)
        if ($companyId) {
            $query->forCompany($companyId);
        }

        $query->where(function ($q) use ($vehicleId, $driverId) {
            // Contactos globales
            $q->whereNull('entity_type');

            // Contactos del vehículo
            if ($vehicleId) {
                $q->orWhere(function ($subQ) use ($vehicleId) {
                    $subQ->where('entity_type', Contact::ENTITY_VEHICLE)
                         ->where('entity_id', $vehicleId);
                });
            }

            // Contactos del conductor
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
     * 
     * @param array $contacts Array de contactos resueltos
     * @return array Estructura para el payload
     */
    public function formatForPayload(array $contacts): array
    {
        // Extraer teléfonos principales para compatibilidad con el prompt actual
        $operatorPhone = $contacts['operator']['phone'] ?? null;
        $monitoringTeamPhone = $contacts['monitoring_team']['phone'] ?? null;
        $supervisorPhone = $contacts['supervisor']['phone'] ?? null;

        return [
            // Campos de compatibilidad con el prompt actual
            'operator_phone' => $operatorPhone,
            'monitoring_team_number' => $monitoringTeamPhone,
            'supervisor_phone' => $supervisorPhone,
            
            // Estructura completa de contactos
            'notification_contacts' => $contacts,
        ];
    }
}

