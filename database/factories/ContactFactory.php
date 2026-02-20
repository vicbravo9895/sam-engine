<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Contact> */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->name(),
            'role' => fake()->jobTitle(),
            'type' => 'monitoring_team',
            'phone' => '+521' . fake()->numerify('##########'),
            'phone_whatsapp' => '+521' . fake()->numerify('##########'),
            'email' => fake()->safeEmail(),
            'is_default' => false,
            'priority' => 1,
            'is_active' => true,
        ];
    }

    public function operator(): static
    {
        return $this->state(fn () => [
            'type' => 'operator',
            'role' => 'Operador',
        ]);
    }

    public function monitoringTeam(): static
    {
        return $this->state(fn () => [
            'type' => 'monitoring_team',
            'role' => 'Monitor',
        ]);
    }

    public function supervisor(): static
    {
        return $this->state(fn () => [
            'type' => 'supervisor',
            'role' => 'Supervisor',
        ]);
    }

    public function emergency(): static
    {
        return $this->state(fn () => [
            'type' => 'emergency',
            'role' => 'Contacto de emergencia',
            'priority' => 0,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id]);
    }
}
