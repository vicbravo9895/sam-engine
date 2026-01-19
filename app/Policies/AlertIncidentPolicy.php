<?php

namespace App\Policies;

use App\Models\AlertIncident;
use App\Models\User;

class AlertIncidentPolicy
{
    /**
     * Determine if user can view any incidents.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine if user can view the incident.
     */
    public function view(User $user, AlertIncident $incident): bool
    {
        return $user->company_id === $incident->company_id;
    }

    /**
     * Determine if user can update the incident.
     */
    public function update(User $user, AlertIncident $incident): bool
    {
        return $user->company_id === $incident->company_id;
    }

    /**
     * Determine if user can delete the incident.
     */
    public function delete(User $user, AlertIncident $incident): bool
    {
        // Only allow deletion by admins or super admins
        return $user->company_id === $incident->company_id && $user->is_admin;
    }
}
