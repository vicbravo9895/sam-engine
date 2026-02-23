<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\SafetySignal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SafetySignal> */
class SafetySignalFactory extends Factory
{
    protected $model = SafetySignal::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'samsara_event_id' => (string) Str::uuid(),
            'vehicle_id' => (string) fake()->numerify('##########'),
            'vehicle_name' => 'T-' . fake()->numerify('######'),
            'driver_id' => (string) fake()->numerify('##########'),
            'driver_name' => fake()->name(),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'primary_behavior_label' => fake()->randomElement(['Harsh Braking', 'Speeding', 'Distracted Driving']),
            'behavior_labels' => ['Harsh Braking'],
            'severity' => fake()->randomElement(['info', 'warning', 'critical']),
            'occurred_at' => now()->subMinutes(fake()->numberBetween(1, 60)),
        ];
    }
}
