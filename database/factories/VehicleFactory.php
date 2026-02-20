<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vehicle> */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'samsara_id' => (string) fake()->numerify('##########'),
            'name' => 'T-' . fake()->numerify('######'),
            'vin' => strtoupper(fake()->bothify('?????????????????')),
            'license_plate' => strtoupper(fake()->bothify('???-##-##')),
            'make' => fake()->randomElement(['Kenworth', 'Freightliner', 'International', 'Volvo']),
            'model' => fake()->randomElement(['T680', 'Cascadia', 'LT', 'VNL']),
            'year' => fake()->numberBetween(2018, 2025),
        ];
    }

    public function withDriver(): static
    {
        return $this->state(fn () => [
            'assigned_driver_samsara_id' => (string) fake()->numerify('##########'),
            'static_assigned_driver' => [
                'id' => (string) fake()->numerify('##########'),
                'name' => fake()->name(),
            ],
        ]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id]);
    }
}
