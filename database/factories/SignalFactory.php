<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Signal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Signal> */
class SignalFactory extends Factory
{
    protected $model = Signal::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'source' => 'webhook',
            'samsara_event_id' => (string) Str::uuid(),
            'event_type' => fake()->randomElement([
                'AlertIncident', 'SafetyEvent', 'DeviceConnectionStatus',
            ]),
            'event_description' => fake()->sentence(),
            'vehicle_id' => (string) fake()->numerify('##########'),
            'vehicle_name' => 'T-' . fake()->numerify('######'),
            'driver_id' => (string) fake()->numerify('##########'),
            'driver_name' => fake()->name(),
            'severity' => fake()->randomElement(['info', 'warning', 'critical']),
            'occurred_at' => now()->subMinutes(fake()->numberBetween(1, 60)),
            'raw_payload' => [
                'eventId' => (string) Str::uuid(),
                'eventType' => 'AlertIncident',
                'happenedAtTime' => now()->toIso8601String(),
                'vehicle' => ['id' => '123', 'name' => 'T-001'],
            ],
        ];
    }

    public function webhook(): static
    {
        return $this->state(fn () => ['source' => 'webhook']);
    }

    public function stream(): static
    {
        return $this->state(fn () => ['source' => 'stream']);
    }

    public function panicButton(): static
    {
        return $this->state(fn () => [
            'event_type' => 'AlertIncident',
            'event_description' => 'Botón de pánico activado',
            'severity' => 'critical',
            'raw_payload' => [
                'eventType' => 'AlertIncident',
                'alertType' => 'panicButtonPressed',
                'happenedAtTime' => now()->toIso8601String(),
                'vehicle' => ['id' => '123', 'name' => 'T-001'],
                'conditionName' => 'Botón de pánico',
            ],
        ]);
    }

    public function safetyEvent(): static
    {
        return $this->state(fn () => [
            'event_type' => 'SafetyEvent',
            'event_description' => 'Harsh braking detected',
            'severity' => 'warning',
            'raw_payload' => [
                'eventType' => 'SafetyEvent',
                'safetyEventType' => 'harshBrake',
                'happenedAtTime' => now()->toIso8601String(),
                'vehicle' => ['id' => '123', 'name' => 'T-001'],
                'behaviorLabel' => ['name' => 'Frenado brusco'],
            ],
        ]);
    }
}
