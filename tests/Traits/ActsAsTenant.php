<?php

namespace Tests\Traits;

use App\Models\Company;
use App\Models\User;

trait ActsAsTenant
{
    protected Company $company;
    protected User $user;

    protected function setUpTenant(array $companyOverrides = [], array $userOverrides = []): void
    {
        $this->company = Company::factory()
            ->withSamsaraApiKey()
            ->create($companyOverrides);

        $this->user = User::factory()
            ->forCompany($this->company)
            ->create($userOverrides);
    }

    protected function setUpSuperAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    protected function setUpAdmin(array $companyOverrides = []): void
    {
        $this->company = Company::factory()
            ->withSamsaraApiKey()
            ->create($companyOverrides);

        $this->user = User::factory()
            ->admin()
            ->forCompany($this->company)
            ->create();
    }

    protected function createOtherTenant(): array
    {
        $otherCompany = Company::factory()->withSamsaraApiKey()->create();
        $otherUser = User::factory()->forCompany($otherCompany)->create();

        return [$otherCompany, $otherUser];
    }
}
