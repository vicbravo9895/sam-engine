<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\ProcessAlertJob;
use App\Jobs\SendNotificationJob;
use App\Models\Alert;
use App\Models\SafetySignal;
use App\Models\Signal;
use App\Services\ContactResolver;
use Illuminate\Support\Facades\Log;

/**
 * When a new SafetySignal is created from the safety stream, evaluate
 * the company's detection rules and react based on the matched action:
 *
 *   - ai_pipeline: Create Signal+Alert + run AI pipeline (current behavior)
 *   - notify_immediate: Create Signal+Alert + send notifications immediately
 *   - both: Create Signal+Alert + send immediate notifications + run AI pipeline
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

        $existing = Alert::whereHas('signal', function ($q) use ($signal) {
                $q->where('samsara_event_id', $signal->samsara_event_id);
            })
            ->where('company_id', $signal->company_id)
            ->first();

        if ($existing) {
            Log::debug('DetectionEngine:Observer: Alert already exists, skipping', [
                'signal_id' => $signal->id,
                'samsara_event_id' => $signal->samsara_event_id,
                'existing_alert_id' => $existing->id,
                'company_id' => $signal->company_id,
            ]);
            return;
        }

        $action = $matchedRule['action'] ?? 'ai_pipeline';

        if ($action === 'notify') {
            $action = 'ai_pipeline';
        }

        $dbSignal = $this->findOrCreateSignal($signal);
        $alert = $this->createAlertFromSignal($signal, $dbSignal, $action);

        Log::info('DetectionEngine:Observer: Created Alert from matched rule', [
            'signal_id' => $signal->id,
            'samsara_event_id' => $signal->samsara_event_id,
            'alert_id' => $alert->id,
            'company_id' => $signal->company_id,
            'primary_behavior_label' => $signal->primary_behavior_label,
            'vehicle_name' => $signal->vehicle_name,
            'driver_name' => $signal->driver_name,
            'rule_id' => $matchedRule['id'] ?? 'unknown',
            'rule_conditions' => $matchedRule['conditions'] ?? [],
            'action' => $action,
        ]);

        if ($action === 'ai_pipeline') {
            ProcessAlertJob::dispatch($alert);
            Log::info('DetectionEngine:Observer: Dispatched AI pipeline job', [
                'alert_id' => $alert->id,
                'rule_id' => $matchedRule['id'] ?? 'unknown',
            ]);
        } elseif ($action === 'notify_immediate') {
            Log::info('DetectionEngine:Observer: Sending immediate notification (no AI)', [
                'alert_id' => $alert->id,
                'rule_id' => $matchedRule['id'] ?? 'unknown',
            ]);
            $this->sendImmediateNotification($alert, $signal, $matchedRule);
        } elseif ($action === 'both') {
            Log::info('DetectionEngine:Observer: Sending immediate notification + AI pipeline', [
                'alert_id' => $alert->id,
                'rule_id' => $matchedRule['id'] ?? 'unknown',
            ]);
            $this->sendImmediateNotification($alert, $signal, $matchedRule);
            ProcessAlertJob::dispatch($alert);
        }
    }

    /**
     * Send immediate notification without AI pipeline.
     * Uses per-rule channels/recipients when configured, falling back to the
     * company's escalation matrix for 'warn' level.
     */
    private function sendImmediateNotification(Alert $alert, SafetySignal $signal, array $matchedRule = []): void
    {
        $company = $alert->company;
        if (!$company) {
            Log::warning('DetectionEngine:Notify: No company found for alert', [
                'alert_id' => $alert->id,
            ]);
            return;
        }

        $description = $signal->primary_label_translated
            ?? $signal->primary_behavior_label
            ?? 'Evento de seguridad';

        $sig = $alert->signal;
        $vehicleName = $sig->vehicle_name ?? 'Unidad desconocida';
        $driverName = $sig->driver_name ?? 'No identificado';
        $occurredAt = $alert->occurred_at?->setTimezone($company->timezone ?? 'America/Mexico_City')
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
            'alert_id' => $alert->id,
            'vehicle_id' => $sig->vehicle_id,
            'driver_id' => $sig->driver_id,
            'channels' => $channels,
            'recipient_types' => $recipientTypes,
            'source' => $ruleChannels ? 'per-rule' : 'escalation_matrix',
        ]);

        $resolvedContacts = app(ContactResolver::class)->resolve(
            $sig->vehicle_id,
            $sig->driver_id,
            $alert->company_id
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
            'dedupe_key' => "immediate-{$sig->samsara_event_id}",
        ];

        $alert->update([
            'ai_status' => Alert::STATUS_COMPLETED,
            'ai_message' => $messageText,
        ]);

        SendNotificationJob::dispatch($alert, $decision);

        Log::info('DetectionEngine:Notify: Dispatched immediate notification', [
            'alert_id' => $alert->id,
            'signal_id' => $signal->id,
            'message_preview' => mb_substr($messageText, 0, 120),
            'channels' => $channels,
            'recipients_count' => count($recipients),
            'used_fallback_recipients' => $usedFallback,
            'recipient_types' => array_column($recipients, 'recipient_type'),
        ]);
    }

    /**
     * Find or create a Signal record from the SafetySignal.
     */
    private function findOrCreateSignal(SafetySignal $safetySignal): Signal
    {
        return Signal::firstOrCreate(
            [
                'company_id' => $safetySignal->company_id,
                'samsara_event_id' => $safetySignal->samsara_event_id,
            ],
            [
                'event_type' => 'AlertIncident',
                'event_description' => $safetySignal->primary_label_translated
                    ?? $safetySignal->primary_behavior_label
                    ?? 'Evento de seguridad',
                'vehicle_id' => $safetySignal->vehicle_id,
                'vehicle_name' => $safetySignal->vehicle_name,
                'driver_id' => $safetySignal->driver_id,
                'driver_name' => $safetySignal->driver_name,
                'severity' => $safetySignal->severity ?? Alert::SEVERITY_INFO,
                'occurred_at' => $safetySignal->occurred_at,
                'raw_payload' => $this->buildRawPayload($safetySignal),
            ]
        );
    }

    /**
     * Create an Alert linked to a Signal.
     */
    private function createAlertFromSignal(SafetySignal $safetySignal, Signal $signal, string $action): Alert
    {
        return Alert::create([
            'company_id' => $safetySignal->company_id,
            'signal_id' => $signal->id,
            'severity' => $safetySignal->severity ?? Alert::SEVERITY_INFO,
            'occurred_at' => $safetySignal->occurred_at,
            'event_description' => $safetySignal->primary_label_translated
                ?? $safetySignal->primary_behavior_label
                ?? 'Evento de seguridad',
            'ai_status' => Alert::STATUS_PENDING,
        ]);
    }

    /**
     * Build raw_payload from SafetySignal.
     */
    private function buildRawPayload(SafetySignal $signal): array
    {
        $occurredAt = $signal->occurred_at?->toIso8601String() ?? now()->toIso8601String();
        $description = $signal->primary_label_translated ?? $signal->primary_behavior_label ?? 'Evento de seguridad';

        return [
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
    }
}
