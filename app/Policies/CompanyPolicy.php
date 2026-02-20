<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function view(User $user, Company $company): bool
    {
        return $user->company_id === $company->id;
    }

    public function update(User $user, Company $company): bool
    {
        return $user->company_id === $company->id && $user->isAdmin();
    }

    public function updateAiSettings(User $user, Company $company): bool
    {
        return $user->company_id === $company->id && $user->isAdmin();
    }

    public function updateSamsaraKey(User $user, Company $company): bool
    {
        return $user->company_id === $company->id && $user->isAdmin();
    }
}
