<?php

namespace Database\Factories;

use App\Models\Alert;
use App\Models\NotificationResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationResult> */
class NotificationResultFactory extends Factory
{
    protected $model = NotificationResult::class;

    public function definition(): array
    {
        return [
            'alert_id' => Alert::factory(),
            'channel' => 'sms',
            'recipient_type' => 'monitoring_team',
            'to_number' => '+521' . fake()->numerify('##########'),
            'success' => true,
            'timestamp_utc' => now(),
            'created_at' => now(),
        ];
    }

    public function sms(): static
    {
        return $this->state(fn () => [
            'channel' => 'sms',
            'message_sid' => 'SM' . fake()->regexify('[a-f0-9]{32}'),
        ]);
    }

    public function whatsapp(): static
    {
        return $this->state(fn () => [
            'channel' => 'whatsapp',
            'message_sid' => 'SM' . fake()->regexify('[a-f0-9]{32}'),
        ]);
    }

    public function call(): static
    {
        return $this->state(fn () => [
            'channel' => 'call',
            'call_sid' => 'CA' . fake()->regexify('[a-f0-9]{32}'),
        ]);
    }

    public function successful(): static
    {
        return $this->state(fn () => ['success' => true, 'error' => null]);
    }

    public function failed(string $error = 'Delivery failed'): static
    {
        return $this->state(fn () => ['success' => false, 'error' => $error]);
    }
}
