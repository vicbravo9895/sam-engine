<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\ProcessSamsaraEventJob;
use App\Models\SafetySignal;
use App\Models\SamsaraEvent;
use Illuminate\Support\Facades\Log;

/**
 * When a new SafetySignal is created from the safety stream, optionally create
 * a SamsaraEvent and run the AI pipeline (proactive notifications).
 *
 * Triggered only if the signal's primary_behavior_label is in the company's
 * safety_stream_notify.labels config. Deduplicates by samsara_event_id.
 */
class SafetySignalObserver
{
    public function created(SafetySignal $signal): void
    {
        if (!$signal->shouldTriggerProactiveNotify()) {
            return;
        }

        $existing = SamsaraEvent::where('company_id', $signal->company_id)
            ->where('samsara_event_id', $signal->samsara_event_id)
            ->first();

        if ($existing) {
            Log::debug('SafetySignalObserver: SamsaraEvent already exists for this signal', [
                'samsara_event_id' => $signal->samsara_event_id,
                'company_id' => $signal->company_id,
            ]);
            return;
        }

        $eventData = $this->buildEventDataFromSignal($signal);
        $event = SamsaraEvent::create($eventData);
        ProcessSamsaraEventJob::dispatch($event);

        Log::info('SafetySignalObserver: Created SamsaraEvent from safety signal (proactive)', [
            'samsara_event_id' => $signal->samsara_event_id,
            'samsara_event_db_id' => $event->id,
            'company_id' => $signal->company_id,
            'primary_behavior_label' => $signal->primary_behavior_label,
            'vehicle_id' => $signal->vehicle_id,
        ]);
    }

    /**
     * Build SamsaraEvent attributes and raw_payload from SafetySignal.
     * raw_payload shape must allow SamsaraClient::extractEventContext() to find vehicle_id and happened_at_time.
     */
    private function buildEventDataFromSignal(SafetySignal $signal): array
    {
        $occurredAt = $signal->occurred_at?->toIso8601String() ?? now()->toIso8601String();
        $description = $signal->primary_label_translated ?? $signal->primary_behavior_label ?? 'Evento de seguridad';

        $rawPayload = [
            'eventId' => $signal->samsara_event_id,
            'id' => $signal->samsara_event_id,
            'eventType' => 'AlertIncident',
            'vehicleId' => $signal->vehicle_id,
            'vehicle' => [
                'id' => $signal->vehicle_id,
                'name' => $signal->vehicle_name,
            ],
            'eventTime' => $occurredAt,
            'data' => [
                'happenedAtTime' => $occurredAt,
                'conditions' => [
                    [
                        'description' => $description,
                    ],
                ],
            ],
            'driver' => $signal->driver_id ? [
                'id' => $signal->driver_id,
                'name' => $signal->driver_name,
            ] : null,
            '_source' => 'safety_stream',
        ];

        return [
            'company_id' => $signal->company_id,
            'event_type' => 'AlertIncident',
            'event_description' => $description,
            'samsara_event_id' => $signal->samsara_event_id,
            'vehicle_id' => $signal->vehicle_id,
            'vehicle_name' => $signal->vehicle_name,
            'driver_id' => $signal->driver_id,
            'driver_name' => $signal->driver_name,
            'severity' => $signal->severity ?? SamsaraEvent::SEVERITY_INFO,
            'occurred_at' => $signal->occurred_at,
            'raw_payload' => $rawPayload,
            'ai_status' => SamsaraEvent::STATUS_PENDING,
        ];
    }
}
