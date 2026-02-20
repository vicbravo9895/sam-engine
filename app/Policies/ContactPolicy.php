<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;

class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canManageUsers();
    }

    public function create(User $user): bool
    {
        return $user->canManageUsers();
    }

    public function update(User $user, Contact $contact): bool
    {
        return $user->company_id === $contact->company_id && $user->canManageUsers();
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $user->company_id === $contact->company_id && $user->canManageUsers();
    }
}
