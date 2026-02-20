<?php

namespace App\Policies;

use App\Models\Alert;
use App\Models\User;

class AlertPolicy
{
    public function view(User $user, Alert $alert): bool
    {
        return $user->company_id === $alert->company_id;
    }

    public function review(User $user, Alert $alert): bool
    {
        return $user->company_id === $alert->company_id;
    }

    public function acknowledge(User $user, Alert $alert): bool
    {
        return $user->company_id === $alert->company_id;
    }

    public function assign(User $user, Alert $alert): bool
    {
        return $user->company_id === $alert->company_id && $user->canManageUsers();
    }

    public function reprocess(User $user, Alert $alert): bool
    {
        return $user->company_id === $alert->company_id && $user->isAdmin();
    }
}
