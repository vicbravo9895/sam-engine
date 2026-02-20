<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canManageUsers();
    }

    public function create(User $actor): bool
    {
        return $actor->canManageUsers();
    }

    public function update(User $actor, User $target): bool
    {
        if ($target->company_id !== $actor->company_id) {
            return false;
        }

        return $actor->canManageUsers();
    }

    public function delete(User $actor, User $target): bool
    {
        if ($target->company_id !== $actor->company_id) {
            return false;
        }

        if ($target->id === $actor->id) {
            return false;
        }

        if ($target->isAdmin() && !$actor->isAdmin()) {
            return false;
        }

        return $actor->canManageUsers();
    }

    public function changeRole(User $actor, User $target): bool
    {
        if ($target->id === $actor->id) {
            return false;
        }

        if ($target->company_id !== $actor->company_id) {
            return false;
        }

        if ($target->isAdmin() && !$actor->isAdmin()) {
            return false;
        }

        return $actor->canManageUsers();
    }
}
