<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Driver;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Driver> */
class DriverFactory extends Factory
{
    protected $model = Driver::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'samsara_id' => (string) fake()->numerify('##########'),
            'name' => fake()->name(),
            'username' => fake()->userName(),
            'phone' => '+521' . fake()->numerify('##########'),
            'driver_activation_status' => 'active',
            'is_deactivated' => false,
        ];
    }

    public function withPhone(string $phone = null): static
    {
        return $this->state(fn () => [
            'phone' => $phone ?? '+521' . fake()->numerify('##########'),
        ]);
    }

    public function deactivated(): static
    {
        return $this->state(fn () => [
            'is_deactivated' => true,
            'driver_activation_status' => 'deactivated',
        ]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id]);
    }
}
