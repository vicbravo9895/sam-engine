<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\DomainEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DomainEvent> */
class DomainEventFactory extends Factory
{
    protected $model = DomainEvent::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'occurred_at' => now(),
            'entity_type' => 'alert',
            'entity_id' => (string) fake()->randomNumber(5),
            'event_type' => 'alert.created',
            'actor_type' => 'system',
            'actor_id' => null,
            'traceparent' => null,
            'correlation_id' => null,
            'schema_version' => 1,
            'payload' => ['status' => 'pending'],
            'created_at' => now(),
        ];
    }

    public function alertCreated(): static
    {
        return $this->state(fn () => [
            'entity_type' => 'alert',
            'event_type' => 'alert.created',
        ]);
    }

    public function notificationSent(): static
    {
        return $this->state(fn () => [
            'entity_type' => 'notification',
            'event_type' => 'notification.sent',
        ]);
    }

    public function byUser(int $userId): static
    {
        return $this->state(fn () => [
            'actor_type' => 'user',
            'actor_id' => (string) $userId,
        ]);
    }
}
