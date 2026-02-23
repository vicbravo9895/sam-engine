<?php

namespace App\Jobs;

use App\Models\Alert;
use App\Models\NotificationDecision;
use App\Models\NotificationRecipient;
use App\Models\NotificationResult;
use App\Pulse\Recorders\NotificationRecorder;
use App\Services\ContactResolver;
use App\Services\DomainEventEmitter;
use App\Services\NotificationDedupeService;
use App\Services\TwilioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 120;
    public $backoff = [10, 30, 60];

    public function __construct(
        public Alert $alert,
        public array $decision
    ) {
        $this->onQueue('notifications');
    }

    public function handle(
        TwilioService $twilioService,
        NotificationDedupeService $dedupeService
    ): void {
        $startTime = microtime(true);

        $this->alert->load('signal');
        $signal = $this->alert->signal;

        $this->decision['recipients'] = $this->normalizeRecipients($this->decision['recipients'] ?? []);

        Log::info('SendNotificationJob: Iniciando', [
            'alert_id' => $this->alert->id,
            'should_notify' => $this->decision['should_notify'] ?? false,
            'escalation_level' => $this->decision['escalation_level'] ?? 'none',
            'channels' => $this->decision['channels_to_use'] ?? [],
        ]);

        if (!($this->decision['should_notify'] ?? false)) {
            $this->persistDecisionOnly();
            return;
        }

        $dedupeKey = $this->decision['dedupe_key'] ?? '';
        $checkResult = $dedupeService->shouldSend(
            dedupeKey: $dedupeKey,
            vehicleId: $signal?->vehicle_id,
            driverId: $signal?->driver_id,
            eventId: $this->alert->id
        );

        if (!$checkResult['should_send']) {
            $this->persistDecisionWithThrottle($checkResult);
            return;
        }

        $notificationDecision = $this->persistDecision();
        $results = $this->sendNotifications($twilioService, $notificationDecision);
        $this->persistResults($results);
        $this->updateAlertNotificationStatus($results);

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        Log::info('SendNotificationJob: Completado', [
            'alert_id' => $this->alert->id,
            'total_notifications' => count($results),
            'successful' => collect($results)->where('success', true)->count(),
            'failed' => collect($results)->where('success', false)->count(),
            'duration_ms' => $duration,
        ]);

        foreach ($results as $result) {
            NotificationRecorder::recordNotification(
                channel: $result['channel'] ?? 'unknown',
                success: $result['success'] ?? false,
                durationMs: (int) $duration,
                companyId: $this->alert->company_id,
                escalationLevel: $this->decision['escalation_level'] ?? 'high'
            );
        }
    }

    private function sendNotifications(
        TwilioService $twilioService,
        NotificationDecision $notificationDecision
    ): array {
        $results = [];
        $signal = $this->alert->signal;

        $channels = $this->decision['channels_to_use'] ?? [];
        $recipients = $this->decision['recipients'] ?? [];
        $messageText = $this->decision['message_text'] ?? '';
        $callScript = $this->decision['call_script'] ?? mb_substr($messageText, 0, 200);
        $escalationLevel = $this->decision['escalation_level'] ?? 'low';

        $channels = $this->restrictChannelsByEscalationMatrix($channels, $escalationLevel);
        $channels = $this->filterChannelsByCompanyConfig($channels);

        if (empty($channels)) {
            return $results;
        }

        usort($recipients, fn($a, $b) => ($a['priority'] ?? 999) <=> ($b['priority'] ?? 999));

        foreach (['call', 'whatsapp', 'sms'] as $channel) {
            if (!in_array($channel, $channels)) {
                continue;
            }

            foreach ($recipients as $recipient) {
                $result = $this->sendToRecipient(
                    twilioService: $twilioService,
                    channel: $channel,
                    recipient: $recipient,
                    message: $messageText,
                    callScript: $callScript,
                    escalationLevel: $escalationLevel
                );

                if ($result) {
                    $results[] = $result;
                }
            }
        }

        if (!empty($channels) && !empty($recipients) && empty($results)) {
            Log::warning('SendNotificationJob: No notification sent — no recipient has phone or whatsapp', [
                'alert_id' => $this->alert->id,
                'channels' => $channels,
                'recipient_types' => array_column($recipients, 'recipient_type'),
            ]);
        }

        return $results;
    }

    /**
     * Restrict channels to those allowed by the company's escalation_matrix for this level.
     * So if the matrix has only ["call"] for that level, we only send call even if the AI returned more.
     */
    private function restrictChannelsByEscalationMatrix(array $channels, string $escalationLevel): array
    {
        $company = $this->alert->company;
        if (!$company) {
            return $channels;
        }

        $matrixKey = match (strtolower($escalationLevel)) {
            'critical' => 'emergency',
            'high' => 'call',
            'low' => 'warn',
            'none' => 'monitor',
            default => null,
        };

        if ($matrixKey === null) {
            return $channels;
        }

        $matrix = $company->getAiConfig("escalation_matrix.{$matrixKey}", null);
        if (!is_array($matrix) || !array_key_exists('channels', $matrix)) {
            return $channels;
        }

        $allowed = array_values(array_intersect(
            array_map('strval', (array) $matrix['channels']),
            ['call', 'whatsapp', 'sms']
        ));

        return array_values(array_intersect($channels, $allowed));
    }

    private function filterChannelsByCompanyConfig(array $channels): array
    {
        $company = $this->alert->company;
        if (!$company) {
            return $channels;
        }

        $filteredChannels = [];
        foreach ($channels as $channel) {
            if ($company->isNotificationChannelEnabled($channel)) {
                $filteredChannels[] = $channel;
            }
        }

        return $filteredChannels;
    }

    /**
     * Ensures every recipient entry is a proper array with phone/whatsapp keys.
     * String entries (e.g. "operator") are resolved via ContactResolver.
     */
    private function normalizeRecipients(array $recipients): array
    {
        $normalized = [];
        $needsResolution = false;

        foreach ($recipients as $entry) {
            if (is_array($entry) && isset($entry['recipient_type'])) {
                $normalized[] = $entry;
            } else {
                $needsResolution = true;
            }
        }

        if (!$needsResolution) {
            return $normalized;
        }

        Log::warning('SendNotificationJob: Recipients contain non-array entries, resolving from contacts', [
            'alert_id' => $this->alert->id,
            'raw_recipients' => $recipients,
        ]);

        $signal = $this->alert->signal;
        $resolved = app(ContactResolver::class)->resolve(
            $signal?->vehicle_id,
            $signal?->driver_id,
            $this->alert->company_id
        );

        foreach ($recipients as $entry) {
            if (is_array($entry) && isset($entry['recipient_type'])) {
                continue;
            }
            if (is_string($entry)) {
                $typeKey = $entry === 'monitoring' ? 'monitoring_team' : $entry;
                $contactData = $resolved[$typeKey] ?? null;
                if ($contactData && (($contactData['phone'] ?? null) || ($contactData['whatsapp'] ?? null))) {
                    $normalized[] = array_merge($contactData, ['recipient_type' => $typeKey]);
                }
            }
        }

        if (empty($normalized)) {
            foreach ($resolved as $type => $contactData) {
                if ($contactData && (($contactData['phone'] ?? null) || ($contactData['whatsapp'] ?? null))) {
                    $normalized[] = array_merge($contactData, ['recipient_type' => $type]);
                }
            }
        }

        return $normalized;
    }

    private function sendToRecipient(
        TwilioService $twilioService,
        string $channel,
        array $recipient,
        string $message,
        string $callScript,
        string $escalationLevel
    ): ?array {
        $recipientType = $recipient['recipient_type'] ?? 'unknown';
        $phone = $recipient['phone'] ?? null;
        $whatsapp = $recipient['whatsapp'] ?? null;
        $signal = $this->alert->signal;
        $vehicleName = $signal?->vehicle_name ?? 'Unidad';

        $result = null;

        if ($channel === 'call' && $phone) {
            $isPanic = $this->isPanicButtonEvent();

            if ($isPanic && in_array($escalationLevel, ['critical', 'high'])) {
                $response = $twilioService->makePanicCallWithCallback(
                    to: $phone,
                    vehicleName: $vehicleName,
                    eventId: $this->alert->id
                );
            } elseif (in_array($escalationLevel, ['critical', 'high'])) {
                $response = $twilioService->makeCallWithCallback(
                    to: $phone,
                    message: $callScript,
                    eventId: $this->alert->id
                );
            } else {
                $response = $twilioService->makeCall(to: $phone, message: $callScript);
            }

            $result = [
                'channel' => 'call',
                'to' => $phone,
                'recipient_type' => $recipientType,
                'success' => $response['success'] ?? false,
                'error' => $response['error'] ?? null,
                'call_sid' => $response['sid'] ?? null,
                'message_sid' => null,
            ];
        } elseif ($channel === 'whatsapp' && ($whatsapp || $phone)) {
            $target = $whatsapp ?: $phone;

            $templateSid = $this->getWhatsAppTemplateSid();
            $templateVars = $this->buildWhatsAppTemplateVariables();

            $response = $twilioService->sendWhatsappTemplate(
                to: $target,
                templateSid: $templateSid,
                variables: $templateVars
            );

            $result = [
                'channel' => 'whatsapp_template',
                'to' => $target,
                'recipient_type' => $recipientType,
                'success' => $response['success'] ?? false,
                'error' => $response['error'] ?? null,
                'call_sid' => null,
                'message_sid' => $response['sid'] ?? null,
            ];
        } elseif ($channel === 'sms' && $phone) {
            $response = $twilioService->sendSms(to: $phone, message: $message);

            $result = [
                'channel' => 'sms',
                'to' => $phone,
                'recipient_type' => $recipientType,
                'success' => $response['success'] ?? false,
                'error' => $response['error'] ?? null,
                'call_sid' => null,
                'message_sid' => $response['sid'] ?? null,
            ];
        }

        return $result;
    }

    private function isPanicButtonEvent(): bool
    {
        $signal = $this->alert->signal;
        $eventType = strtolower($signal?->event_type ?? '');
        $description = strtolower($this->alert->event_description ?? $signal?->event_description ?? '');

        return str_contains($eventType, 'panic')
            || str_contains($description, 'pánico')
            || str_contains($description, 'panic');
    }

    private function getWhatsAppTemplateSid(): string
    {
        $signal = $this->alert->signal;
        $combined = strtolower(($signal?->event_type ?? '') . ' ' . ($this->alert->event_description ?? ''));

        if (str_contains($combined, 'panic') || str_contains($combined, 'pánico') || str_contains($combined, 'emergency')) {
            return TwilioService::TEMPLATE_EMERGENCY_ALERT;
        }

        if (str_contains($combined, 'collision') || str_contains($combined, 'colisión')
            || str_contains($combined, 'harsh') || str_contains($combined, 'frenada')
            || str_contains($combined, 'crash') || str_contains($combined, 'safety')) {
            return TwilioService::TEMPLATE_SAFETY_ALERT;
        }

        return TwilioService::TEMPLATE_FLEET_ALERT;
    }

    private function buildWhatsAppTemplateVariables(): array
    {
        $signal = $this->alert->signal;
        $timezone = $this->alert->company?->timezone ?? 'America/Mexico_City';
        $occurredAt = $this->alert->occurred_at?->setTimezone($timezone)->format('d/m/Y H:i') ?? 'N/A';
        $aiMessage = mb_substr($this->decision['message_text'] ?? $this->alert->ai_message ?? 'Revisar evento', 0, 500);
        $location = $this->extractLocationFromPayload();

        $severity = match ($this->alert->severity ?? 'info') {
            'critical' => 'Crítico',
            'warning' => 'Alto',
            'info' => 'Medio',
            default => $this->alert->severity ?? 'Info',
        };

        $templateSid = $this->getWhatsAppTemplateSid();

        if ($templateSid === TwilioService::TEMPLATE_EMERGENCY_ALERT) {
            return [
                '1' => $signal?->vehicle_name ?? 'Unidad desconocida',
                '2' => $signal?->driver_name ?? 'No identificado',
                '3' => $location,
                '4' => $occurredAt,
                '5' => $aiMessage,
            ];
        }

        if ($templateSid === TwilioService::TEMPLATE_SAFETY_ALERT) {
            return [
                '1' => $this->alert->event_description ?? 'Evento de Seguridad',
                '2' => $signal?->vehicle_name ?? 'Unidad desconocida',
                '3' => $signal?->driver_name ?? 'No identificado',
                '4' => $severity,
                '5' => $aiMessage,
                '6' => $occurredAt,
            ];
        }

        return [
            '1' => $this->alert->event_description ?? $signal?->event_type ?? 'Alerta de Flota',
            '2' => $signal?->vehicle_name ?? 'Unidad desconocida',
            '3' => $signal?->driver_name ?? 'No identificado',
            '4' => $occurredAt,
            '5' => $aiMessage,
        ];
    }

    private function extractLocationFromPayload(): string
    {
        $signal = $this->alert->signal;
        $payload = $signal?->raw_payload ?? [];

        if (isset($payload['data']['location']['formattedAddress'])) {
            return $payload['data']['location']['formattedAddress'];
        }

        if (isset($payload['data']['location']['latitude'], $payload['data']['location']['longitude'])) {
            $lat = round($payload['data']['location']['latitude'], 6);
            $lng = round($payload['data']['location']['longitude'], 6);
            return "{$lat}, {$lng}";
        }

        return 'Ubicación no disponible';
    }

    private function persistDecision(): NotificationDecision
    {
        $decision = NotificationDecision::updateOrCreate(
            ['alert_id' => $this->alert->id],
            [
                'should_notify' => $this->decision['should_notify'] ?? false,
                'escalation_level' => NotificationDecision::normalizeEscalationLevel($this->decision['escalation_level'] ?? null),
                'message_text' => $this->decision['message_text'] ?? null,
                'call_script' => $this->decision['call_script'] ?? null,
                'reason' => $this->decision['reason'] ?? null,
            ]
        );

        $decision->recipients()->delete();

        $validRecipientTypes = ['operator', 'monitoring_team', 'supervisor', 'emergency', 'dispatch', 'other'];

        foreach ($this->decision['recipients'] ?? [] as $recipientData) {
            $recipientType = $recipientData['recipient_type'] ?? 'other';
            if (!in_array($recipientType, $validRecipientTypes)) {
                $recipientType = 'other';
            }

            NotificationRecipient::create([
                'notification_decision_id' => $decision->id,
                'recipient_type' => $recipientType,
                'phone' => $recipientData['phone'] ?? null,
                'whatsapp' => $recipientData['whatsapp'] ?? null,
                'priority' => $recipientData['priority'] ?? 999,
            ]);
        }

        return $decision;
    }

    private function persistDecisionOnly(): void
    {
        NotificationDecision::updateOrCreate(
            ['alert_id' => $this->alert->id],
            [
                'should_notify' => false,
                'escalation_level' => NotificationDecision::normalizeEscalationLevel($this->decision['escalation_level'] ?? null),
                'message_text' => $this->decision['message_text'] ?? null,
                'call_script' => $this->decision['call_script'] ?? null,
                'reason' => $this->decision['reason'] ?? 'should_notify is false',
            ]
        );
    }

    private function persistDecisionWithThrottle(array $checkResult): void
    {
        NotificationDecision::updateOrCreate(
            ['alert_id' => $this->alert->id],
            [
                'should_notify' => true,
                'escalation_level' => NotificationDecision::normalizeEscalationLevel($this->decision['escalation_level'] ?? null),
                'message_text' => $this->decision['message_text'] ?? null,
                'call_script' => $this->decision['call_script'] ?? null,
                'reason' => $checkResult['reason'] ?? 'Bloqueado por dedupe/throttle',
            ]
        );

        $this->alert->update([
            'notification_status' => $checkResult['throttled'] ? 'throttled' : 'dedupe_blocked',
        ]);
    }

    private function persistResults(array $results): void
    {
        foreach ($results as $result) {
            $channel = $result['channel'] === 'whatsapp_template' ? 'whatsapp' : $result['channel'];

            $notificationResult = NotificationResult::create([
                'alert_id' => $this->alert->id,
                'channel' => $channel,
                'recipient_type' => $result['recipient_type'] ?? 'unknown',
                'to_number' => $result['to'],
                'success' => $result['success'],
                'error' => $result['error'],
                'call_sid' => $result['call_sid'],
                'message_sid' => $result['message_sid'],
                'status_current' => $result['success'] ? 'sent' : 'failed',
                'timestamp_utc' => now(),
            ]);

            if ($result['success']) {
                $providerSid = $result['call_sid'] ?? $result['message_sid'];

                DomainEventEmitter::emit(
                    companyId: $this->alert->company_id,
                    entityType: 'notification',
                    entityId: (string) $notificationResult->id,
                    eventType: 'notification.sent',
                    payload: [
                        'channel' => $channel,
                        'to' => $result['to'],
                        'provider_sid' => $providerSid,
                        'recipient_type' => $result['recipient_type'] ?? 'unknown',
                    ],
                    correlationId: (string) $this->alert->id,
                );

                RecordUsageEventJob::dispatch(
                    companyId: $this->alert->company_id,
                    meter: "notifications_{$channel}",
                    qty: 1,
                    idempotencyKey: "{$this->alert->company_id}:notifications_{$channel}:{$providerSid}",
                    dimensions: ['recipient_type' => $result['recipient_type'] ?? 'unknown'],
                );
            }
        }
    }

    private function updateAlertNotificationStatus(array $results): void
    {
        $hasSuccess = collect($results)->contains('success', true);

        $callSid = null;
        foreach ($results as $result) {
            if ($result['channel'] === 'call' && $result['success'] && !empty($result['call_sid'])) {
                $callSid = $result['call_sid'];
                break;
            }
        }

        $updateData = [
            'notification_status' => $hasSuccess ? 'sent' : 'failed',
            'notification_sent_at' => $hasSuccess ? now() : null,
            'notification_channels' => collect($results)
                ->where('success', true)
                ->pluck('channel')
                ->unique()
                ->values()
                ->toArray(),
        ];

        if ($callSid) {
            $updateData['twilio_call_sid'] = $callSid;
        }

        $this->alert->update($updateData);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendNotificationJob: Falló permanentemente', [
            'alert_id' => $this->alert->id,
            'error' => $exception->getMessage(),
        ]);

        $this->alert->update(['notification_status' => 'failed']);
    }
}
