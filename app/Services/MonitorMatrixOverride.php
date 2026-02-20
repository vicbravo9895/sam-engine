<?php

namespace App\Services;

use App\Models\Alert;
use Illuminate\Support\Facades\Log;

/**
 * When the AI returns should_notify=false for "monitor" (escalation_level=none), the company
 * may still want to send notifications per escalation_matrix.monitor (e.g. call to monitoring).
 * This service builds an override decision so Laravel dispatches SendNotificationJob.
 */
class MonitorMatrixOverride
{
    public function apply(
        Alert $alert,
        array $notificationDecision,
        array $assessment,
        ContactResolver $contactResolver,
        string $humanMessage
    ): ?array {
        if ($notificationDecision['should_notify'] ?? false) {
            return null;
        }
        if (($notificationDecision['escalation_level'] ?? '') !== 'none') {
            return null;
        }

        $company = $alert->company;
        if (!$company) {
            return null;
        }

        $matrix = $company->getAiConfig('escalation_matrix.monitor', null);
        if (!is_array($matrix)) {
            return null;
        }
        $channels = array_values(array_intersect(
            array_map('strval', (array) ($matrix['channels'] ?? [])),
            ['call', 'whatsapp', 'sms']
        ));
        $recipientTypes = array_values(array_map('strval', (array) ($matrix['recipients'] ?? [])));
        if ($channels === [] || $recipientTypes === []) {
            return null;
        }

        $signal = $alert->signal;
        $resolved = $contactResolver->resolve(
            $signal?->vehicle_id,
            $signal?->driver_id,
            $alert->company_id
        );
        $recipients = [];
        foreach ($recipientTypes as $type) {
            $typeKey = $type === 'monitoring' ? 'monitoring_team' : $type;
            $contactData = $resolved[$typeKey] ?? null;
            if ($contactData && (($contactData['phone'] ?? null) || ($contactData['whatsapp'] ?? null))) {
                $recipients[] = array_merge($contactData, ['recipient_type' => $typeKey]);
            }
        }
        if ($recipients === []) {
            Log::info('MonitorMatrixOverride: No contacts resolved', [
                'alert_id' => $alert->id,
                'recipient_types' => $recipientTypes,
            ]);
            return null;
        }

        $messageText = $notificationDecision['message_text'] ?? $humanMessage;
        $callScript = $notificationDecision['call_script'] ?? mb_substr($messageText, 0, 200);
        $dedupeKey = $notificationDecision['dedupe_key'] ?? $assessment['dedupe_key'] ?? 'monitor-' . $alert->id;

        Log::info('MonitorMatrixOverride: Applying — will notify per escalation_matrix.monitor', [
            'alert_id' => $alert->id,
            'channels' => $channels,
            'recipient_types' => array_column($recipients, 'recipient_type'),
        ]);

        return [
            'should_notify' => true,
            'escalation_level' => 'low',
            'channels_to_use' => $channels,
            'recipients' => $recipients,
            'message_text' => $messageText,
            'call_script' => $callScript,
            'dedupe_key' => $dedupeKey,
            'reason' => 'Notificación por nivel monitoreo según matriz de escalación.',
        ];
    }
}
