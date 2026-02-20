<?php

namespace Database\Factories;

use App\Models\NotificationDeliveryEvent;
use App\Models\NotificationResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationDeliveryEvent> */
class NotificationDeliveryEventFactory extends Factory
{
    protected $model = NotificationDeliveryEvent::class;

    public function definition(): array
    {
        return [
            'notification_result_id' => NotificationResult::factory(),
            'provider_sid' => 'SM' . fake()->regexify('[a-f0-9]{32}'),
            'status' => 'delivered',
            'received_at' => now(),
        ];
    }

    public function delivered(): static
    {
        return $this->state(fn () => ['status' => 'delivered']);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'error_code' => '30003',
            'error_message' => 'Unreachable destination handset',
        ]);
    }
}
