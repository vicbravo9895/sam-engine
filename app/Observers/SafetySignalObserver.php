<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\ProcessSamsaraEventJob;
use App\Jobs\SendNotificationJob;
use App\Models\SafetySignal;
use App\Models\SamsaraEvent;
use App\Services\ContactResolver;
use Illuminate\Support\Facades\Log;

/**
 * When a new SafetySignal is created from the safety stream, evaluate
 * the company's detection rules and react based on the matched action:
 *
 *   - ai_pipeline: Create SamsaraEvent + run AI pipeline (current behavior)
 *   - notify_immediate: Create SamsaraEvent + send notifications immediately
 *   - both: Create SamsaraEvent + send immediate notifications + run AI pipeline
 *   - notify (legacy): Treated as ai_pipeline
 */
class SafetySignalObserver
{
    public function created(SafetySignal $signal): void
    {
        Log::debug('DetectionEngine:Observer: SafetySignal created, evaluating rules', [
            'signal_id' => $signal->id,
            'company_id' => $signal->company_id,
            'samsara_event_id' => $signal->samsara_event_id,
            'primary_behavior_label' => $signal->primary_behavior_label,
            'vehicle_name' => $signal->vehicle_name,
            'driver_name' => $signal->driver_name,
        ]);

        $matchedRule = $signal->getMatchedRule();

        if ($matchedRule === null) {
            Log::debug('DetectionEngine:Observer: No rule matched, skipping', [
                'signal_id' => $signal->id,
                'company_id' => $signal->company_id,
                'primary_behavior_label' => $signal->primary_behavior_label,
            ]);
            return;
        }

        $existing = SamsaraEvent::where('company_id', $signal->company_id)
            ->where('samsara_event_id', $signal->samsara_event_id)
            ->first();

        if ($existing) {
            Log::debug('DetectionEngine:Observer: SamsaraEvent already exists, skipping', [
                'signal_id' => $signal->id,
                'samsara_event_id' => $signal->samsara_event_id,
                'existing_event_id' => $existing->id,
                'company_id' => $signal->company_id,
            ]);
            return;
        }

        $action = $matchedRule['action'] ?? 'ai_pipeline';

        if ($action === 'notify') {
            $action = 'ai_pipeline';
        }

        $eventData = $this->buildEventDataFromSignal($signal, $action);
        $event = SamsaraEvent::create($eventData);

        Log::info('DetectionEngine:Observer: Created SamsaraEvent from matched rule', [
            'signal_id' => $signal->id,
            'samsara_event_id' => $signal->samsara_event_id,
            'samsara_event_db_id' => $event->id,
            'company_id' => $signal->company_id,
            'primary_behavior_label' => $signal->primary_behavior_label,
            'vehicle_name' => $signal->vehicle_name,
            'driver_name' => $signal->driver_name,
            'rule_id' => $matchedRule['id'] ?? 'unknown',
            'rule_conditions' => $matchedRule['conditions'] ?? [],
            'action' => $action,
        ]);

        if ($action === 'ai_pipeline') {
            ProcessSamsaraEventJob::dispatch($event);
            Log::info('DetectionEngine:Observer: Dispatched AI pipeline job', [
                'event_id' => $event->id,
                'rule_id' => $matchedRule['id'] ?? 'unknown',
            ]);
        } elseif ($action === 'notify_immediate') {
            Log::info('DetectionEngine:Observer: Sending immediate notification (no AI)', [
                'event_id' => $event->id,
                'rule_id' => $matchedRule['id'] ?? 'unknown',
            ]);
            $this->sendImmediateNotification($event, $signal, $matchedRule);
        } elseif ($action === 'both') {
            Log::info('DetectionEngine:Observer: Sending immediate notification + AI pipeline', [
                'event_id' => $event->id,
                'rule_id' => $matchedRule['id'] ?? 'unknown',
            ]);
            $this->sendImmediateNotification($event, $signal, $matchedRule);
            ProcessSamsaraEventJob::dispatch($event);
        }
    }

    /**
     * Send immediate notification without AI pipeline.
     * Uses per-rule channels/recipients when configured, falling back to the
     * company's escalation matrix for 'warn' level.
     */
    private function sendImmediateNotification(SamsaraEvent $event, SafetySignal $signal, array $matchedRule = []): void
    {
        $company = $event->company;
        if (!$company) {
            Log::warning('DetectionEngine:Notify: No company found for event', [
                'event_id' => $event->id,
            ]);
            return;
        }

        $description = $signal->primary_label_translated
            ?? $signal->primary_behavior_label
            ?? 'Evento de seguridad';

        $vehicleName = $event->vehicle_name ?? 'Unidad desconocida';
        $driverName = $event->driver_name ?? 'No identificado';
        $occurredAt = $event->occurred_at?->setTimezone($company->timezone ?? 'America/Mexico_City')
            ->format('d/m/Y H:i') ?? 'N/A';

        $messageText = "Alerta de seguridad: {$description} — Vehículo: {$vehicleName}, Conductor: {$driverName}. {$occurredAt}";

        $ruleChannels = !empty($matchedRule['channels']) ? $matchedRule['channels'] : null;
        $ruleRecipients = !empty($matchedRule['recipients']) ? $matchedRule['recipients'] : null;

        $escalation = $company->getAiConfig('escalation_matrix.warn', [
            'channels' => ['whatsapp', 'sms'],
            'recipients' => ['monitoring'],
        ]);

        $channels = $ruleChannels ?? $escalation['channels'] ?? ['whatsapp', 'sms'];
        $recipientTypes = $ruleRecipients ?? $escalation['recipients'] ?? ['monitoring'];

        Log::debug('DetectionEngine:Notify: Resolving contacts', [
            'event_id' => $event->id,
            'vehicle_id' => $event->vehicle_id,
            'driver_id' => $event->driver_id,
            'channels' => $channels,
            'recipient_types' => $recipientTypes,
            'source' => $ruleChannels ? 'per-rule' : 'escalation_matrix',
        ]);

        $resolvedContacts = app(ContactResolver::class)->resolve(
            $event->vehicle_id,
            $event->driver_id,
            $event->company_id
        );

        $recipients = [];
        foreach ($resolvedContacts as $type => $contactData) {
            if ($contactData && in_array($type, $recipientTypes, true)) {
                $recipients[] = array_merge($contactData, ['recipient_type' => $type]);
            }
        }

        $usedFallback = false;
        if (empty($recipients)) {
            $usedFallback = true;
            foreach ($resolvedContacts as $type => $contactData) {
                if ($contactData) {
                    $recipients[] = array_merge($contactData, ['recipient_type' => $type]);
                }
            }
        }

        $decision = [
            'should_notify' => true,
            'escalation_level' => 'high',
            'channels_to_use' => $channels,
            'message_text' => $messageText,
            'call_script' => mb_substr($messageText, 0, 200),
            'recipients' => $recipients,
            'reason' => 'Alerta inmediata por regla de detección',
            'dedupe_key' => "immediate-{$event->samsara_event_id}",
        ];

        $event->update([
            'ai_status' => SamsaraEvent::STATUS_COMPLETED,
            'ai_message' => $messageText,
        ]);

        SendNotificationJob::dispatch($event, $decision);

        Log::info('DetectionEngine:Notify: Dispatched immediate notification', [
            'event_id' => $event->id,
            'signal_id' => $signal->id,
            'message_preview' => mb_substr($messageText, 0, 120),
            'channels' => $channels,
            'recipients_count' => count($recipients),
            'used_fallback_recipients' => $usedFallback,
            'recipient_types' => array_column($recipients, 'recipient_type'),
        ]);
    }

    /**
     * Build SamsaraEvent attributes and raw_payload from SafetySignal.
     */
    private function buildEventDataFromSignal(SafetySignal $signal, string $action): array
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
            '_rule_action' => $action,
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
