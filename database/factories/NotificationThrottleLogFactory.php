<?php

namespace Database\Factories;

use App\Models\Alert;
use App\Models\NotificationThrottleLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationThrottleLog> */
class NotificationThrottleLogFactory extends Factory
{
    protected $model = NotificationThrottleLog::class;

    public function definition(): array
    {
        return [
            'throttle_key' => 'vehicle:' . fake()->numerify('######') . ':sms',
            'notification_timestamp' => now(),
            'alert_id' => Alert::factory(),
        ];
    }
}
