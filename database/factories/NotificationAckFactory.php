<?php

namespace Database\Factories;

use App\Models\Alert;
use App\Models\Company;
use App\Models\NotificationAck;
use App\Models\NotificationResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationAck> */
class NotificationAckFactory extends Factory
{
    protected $model = NotificationAck::class;

    public function definition(): array
    {
        return [
            'alert_id' => Alert::factory(),
            'notification_result_id' => NotificationResult::factory(),
            'company_id' => Company::factory(),
            'ack_type' => 'whatsapp_reply',
            'ack_payload' => ['body' => 'OK'],
            'created_at' => now(),
        ];
    }

    public function whatsappReply(): static
    {
        return $this->state(fn () => [
            'ack_type' => 'whatsapp_reply',
            'ack_payload' => ['body' => 'Recibido', 'from' => '+5211234567890'],
        ]);
    }

    public function uiAck(): static
    {
        return $this->state(fn () => [
            'ack_type' => 'ui_ack',
            'ack_payload' => ['source' => 'web'],
        ]);
    }
}
