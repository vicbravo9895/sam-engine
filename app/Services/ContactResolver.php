<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\SamsaraEvent;
use Illuminate\Support\Collection;

/**
 * Servicio para resolver contactos basados en el contexto del evento.
 * 
 * Lógica de resolución:
 * 1. Busca contactos específicos del vehículo
 * 2. Busca contactos específicos del conductor
 * 3. Fallback a contactos globales por defecto
 * 
 * Para cada tipo de contacto, selecciona el de mayor prioridad.
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

        return $this->resolve($vehicleId, $driverId);
    }

    /**
     * Resuelve los contactos dados un vehicle_id y driver_id.
     * 
     * @param string|null $vehicleId ID del vehículo de Samsara
     * @param string|null $driverId ID del conductor de Samsara
     * @return array Contactos organizados por tipo
     */
    public function resolve(?string $vehicleId, ?string $driverId): array
    {
        $contacts = [
            'operator' => null,
            'monitoring_team' => null,
            'supervisor' => null,
            'emergency' => null,
            'dispatch' => null,
        ];

        $types = array_keys($contacts);

        foreach ($types as $type) {
            $contact = $this->resolveByType($type, $vehicleId, $driverId);
            if ($contact) {
                $contacts[$type] = $contact->toNotificationPayload();
            }
        }

        // Filtrar nulls para una respuesta más limpia
        return array_filter($contacts);
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
     * @return Contact|null
     */
    public function resolveByType(string $type, ?string $vehicleId, ?string $driverId): ?Contact
    {
        // 1. Buscar contacto específico del vehículo
        if ($vehicleId) {
            $contact = Contact::active()
                ->ofType($type)
                ->forVehicle($vehicleId)
                ->orderByPriority()
                ->first();

            if ($contact) {
                return $contact;
            }
        }

        // 2. Buscar contacto específico del conductor
        if ($driverId) {
            $contact = Contact::active()
                ->ofType($type)
                ->forDriver($driverId)
                ->orderByPriority()
                ->first();

            if ($contact) {
                return $contact;
            }
        }

        // 3. Buscar contacto global por defecto
        $contact = Contact::active()
            ->ofType($type)
            ->global()
            ->default()
            ->orderByPriority()
            ->first();

        if ($contact) {
            return $contact;
        }

        // 4. Fallback: cualquier contacto global activo del tipo
        return Contact::active()
            ->ofType($type)
            ->global()
            ->orderByPriority()
            ->first();
    }

    /**
     * Obtiene todos los contactos aplicables para un evento.
     * 
     * @param string|null $vehicleId ID del vehículo
     * @param string|null $driverId ID del conductor
     * @return Collection Colección de todos los contactos aplicables
     */
    public function getAllApplicable(?string $vehicleId, ?string $driverId): Collection
    {
        $query = Contact::active();

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

