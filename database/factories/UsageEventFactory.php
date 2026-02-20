<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\UsageEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<UsageEvent> */
class UsageEventFactory extends Factory
{
    protected $model = UsageEvent::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'occurred_at' => now(),
            'meter' => fake()->randomElement([
                'alerts_processed', 'ai_tokens', 'notifications_sms',
                'notifications_whatsapp', 'notifications_call', 'copilot_messages',
            ]),
            'qty' => 1,
            'dimensions' => null,
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function alertsProcessed(): static
    {
        return $this->state(fn () => ['meter' => 'alerts_processed', 'qty' => 1]);
    }

    public function aiTokens(int $tokens = 1500): static
    {
        return $this->state(fn () => ['meter' => 'ai_tokens', 'qty' => $tokens]);
    }
}
