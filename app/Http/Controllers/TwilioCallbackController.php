<?php

namespace App\Http\Controllers;

use App\Jobs\SendPanicEscalationJob;
use App\Models\Alert;
use App\Models\AlertActivity;
use App\Models\Company;
use App\Models\NotificationAck;
use App\Models\NotificationDeliveryEvent;
use App\Models\NotificationResult;
use App\Services\DomainEventEmitter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

class TwilioCallbackController extends Controller
{
    // ── Voice: Panic IVR callback ────────────────────────────────

    /**
     * Handle Twilio voice callback when operator responds to panic button call.
     *
     * FLUJO BOTÓN DE PÁNICO:
     * - Presionar 1: CONFIRMAR que es un pánico REAL → Escalar a monitoreo y emergencia
     * - Presionar 2: DENEGAR, fue un error/falsa alarma → Solo registrar, no escalar
     * - No presionar nada: Timeout → Solo registrar, no escalar
     */
    public function voiceCallback(Request $request): Response
    {
        $alertId = $request->input('event_id');
        $digits = $request->input('Digits');
        $callSid = $request->input('CallSid');
        $callStatus = $request->input('CallStatus');

        Log::channel('stack')->info('=== PANIC CALLBACK RECEIVED ===', [
            'alert_id' => $alertId,
            'digits' => $digits,
            'call_sid' => $callSid,
            'call_status' => $callStatus,
            'all_params' => $request->all(),
        ]);

        if (!$alertId) {
            Log::error('Panic callback: alert_id missing');
            return $this->twimlResponse('<Say language="es-MX" voice="Polly.Mia">Error: evento no identificado.</Say>');
        }

        $alert = Alert::find($alertId);

        if (!$alert) {
            Log::error('Panic callback: alert not found', ['alert_id' => $alertId]);
            return $this->twimlResponse('<Say language="es-MX" voice="Polly.Mia">Evento no encontrado.</Say>');
        }

        $isConfirmed = $digits === '1';
        $isDenied = $digits === '2';

        $responseType = match (true) {
            $isConfirmed => 'confirmed_panic',
            $isDenied => 'false_alarm',
            default => 'no_response',
        };

        $signal = $alert->signal;

        $callResponse = [
            'response_type' => $responseType,
            'is_real_panic' => $isConfirmed,
            'is_false_alarm' => $isDenied,
            'keypress' => $digits,
            'call_sid' => $callSid,
            'operator_responded_at' => now()->toIso8601String(),
            'vehicle_name' => $signal?->vehicle_name,
            'driver_name' => $signal?->driver_name,
        ];

        $notificationStatus = match (true) {
            $isConfirmed => 'panic_confirmed',
            $isDenied => 'false_alarm',
            default => 'operator_no_response',
        };

        $alert->update([
            'call_response' => $callResponse,
            'notification_status' => $notificationStatus,
        ]);

        Log::channel('stack')->info('=== PANIC RESPONSE RECORDED ===', [
            'alert_id' => $alert->id,
            'response_type' => $responseType,
            'notification_status' => $notificationStatus,
            'is_real_panic' => $isConfirmed,
        ]);

        AlertActivity::logAiAction(
            $alert->id,
            $alert->company_id,
            $isConfirmed ? 'panic_confirmed_by_operator' : ($isDenied ? 'panic_denied_by_operator' : 'operator_callback_timeout'),
            $callResponse,
        );

        if ($isConfirmed || $isDenied) {
            $this->recordIvrAck($alert, $callSid, $responseType, $callResponse);
        }

        if ($isConfirmed) {
            Log::channel('stack')->info('=== DISPATCHING PANIC ESCALATION ===', [
                'alert_id' => $alert->id,
                'vehicle_name' => $signal?->vehicle_name,
            ]);

            SendPanicEscalationJob::dispatch($alert);

            return $this->twimlResponse(
                '<Say voice="Polly.Mia" language="es-MX">Emergencia confirmada.</Say>' .
                '<Pause length="1"/>' .
                '<Say voice="Polly.Mia" language="es-MX">Se está notificando al equipo de monitoreo y servicios de emergencia.</Say>' .
                '<Pause length="1"/>' .
                '<Say voice="Polly.Mia" language="es-MX">Por favor, mantenga la calma. La ayuda está en camino.</Say>' .
                '<Pause length="1"/>' .
                '<Say voice="Polly.Mia" language="es-MX">Si es posible, permanezca en un lugar seguro hasta que llegue la asistencia.</Say>' .
                '<Pause length="1"/>' .
                '<Say voice="Polly.Mia" language="es-MX">Gracias por confirmar.</Say>'
            );
        }

        if ($isDenied) {
            Log::channel('stack')->info('=== PANIC DENIED - FALSE ALARM ===', [
                'alert_id' => $alert->id,
                'vehicle_name' => $signal?->vehicle_name,
            ]);

            return $this->twimlResponse(
                '<Say voice="Polly.Mia" language="es-MX">Entendido. Se ha registrado como activación accidental.</Say>' .
                '<Pause length="1"/>' .
                '<Say voice="Polly.Mia" language="es-MX">La alerta ha sido cancelada y no se enviará ninguna notificación.</Say>' .
                '<Pause length="1"/>' .
                '<Say voice="Polly.Mia" language="es-MX">Gracias por confirmar. Que tenga un buen viaje.</Say>'
            );
        }

        $callbackUrl = htmlspecialchars($request->fullUrl(), ENT_XML1, 'UTF-8');
        return $this->twimlResponse(
            '<Say voice="Polly.Mia" language="es-MX">Opción no válida.</Say>' .
            '<Pause length="1"/>' .
            '<Gather numDigits="1" action="' . $callbackUrl . '" method="POST" timeout="10">' .
                '<Say voice="Polly.Mia" language="es-MX">Por favor, presione uno si es una emergencia real.</Say>' .
                '<Pause length="1"/>' .
                '<Say voice="Polly.Mia" language="es-MX">Presione dos si fue una activación accidental.</Say>' .
            '</Gather>'
        );
    }

    // ── Voice: Call status ───────────────────────────────────────

    /**
     * Handle Twilio voice status updates (call ended, failed, etc.)
     */
    public function voiceStatus(Request $request): Response
    {
        Log::info('Twilio voice status update', $request->all());

        $callSid = $request->input('CallSid');
        $callStatus = $request->input('CallStatus');

        $alert = Alert::where('twilio_call_sid', $callSid)->first();

        if ($alert) {
            $currentResponse = $alert->call_response ?? [];
            $currentResponse['call_status'] = $callStatus;
            $currentResponse['status_updated_at'] = now()->toIso8601String();

            $updateData = ['call_response' => $currentResponse];

            if (in_array($callStatus, ['completed', 'busy', 'no-answer', 'failed', 'canceled'])) {
                if ($alert->notification_status === 'sent' && $callStatus !== 'completed') {
                    $updateData['notification_status'] = 'call_' . str_replace('-', '_', $callStatus);
                }
            }

            $alert->update($updateData);

            $this->recordCallDeliveryEvent($alert, $callSid, $callStatus, $request->all());

            Log::info('Call status updated', [
                'alert_id' => $alert->id,
                'call_status' => $callStatus,
            ]);
        }

        return response('', 200);
    }

    // ── Messages: delivery status ────────────────────────────────

    /**
     * Handle Twilio SMS/WhatsApp message status callbacks.
     *
     * Twilio sends: MessageSid, MessageStatus, ErrorCode (optional).
     * Statuses: queued → accepted → sending → sent → delivered → read (WA only)
     *           or → failed / undelivered at any point.
     */
    public function messageStatus(Request $request): Response
    {
        $messageSid = $request->input('MessageSid');
        $messageStatus = $request->input('MessageStatus');
        $errorCode = $request->input('ErrorCode');
        $errorMessage = $request->input('ErrorMessage');

        Log::info('Twilio message status callback', [
            'message_sid' => $messageSid,
            'status' => $messageStatus,
            'error_code' => $errorCode,
        ]);

        if (!$messageSid || !$messageStatus) {
            return response('', 204);
        }

        $notificationResult = NotificationResult::where('message_sid', $messageSid)->first();

        if (!$notificationResult) {
            Log::warning('Twilio message status: no matching notification_result', [
                'message_sid' => $messageSid,
            ]);
            return response('', 204);
        }

        $alert = $notificationResult->alert;
        $company = $alert?->company;

        if ($company && $this->isNotificationsV2Active($company)) {
            NotificationDeliveryEvent::create([
                'notification_result_id' => $notificationResult->id,
                'provider_sid' => $messageSid,
                'status' => $messageStatus,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'raw_callback' => $request->all(),
                'received_at' => now(),
            ]);
        }

        $notificationResult->updateStatusFromCallback($messageStatus);

        $this->emitDeliveryDomainEvent($notificationResult, $messageStatus);

        return response('', 204);
    }

    // ── Messages: inbound (WhatsApp replies) ─────────────────────

    /**
     * Handle inbound WhatsApp messages (user replies).
     *
     * Matches the sender phone to a recent notification_result
     * within a 24-hour window to create an acknowledgement.
     */
    public function messageInbound(Request $request): Response
    {
        $from = $request->input('From');
        $body = $request->input('Body');
        $messageSid = $request->input('MessageSid');

        Log::info('Twilio inbound message received', [
            'from' => $from,
            'body' => mb_substr($body ?? '', 0, 200),
            'message_sid' => $messageSid,
        ]);

        if (!$from) {
            return response('', 204);
        }

        $normalizedFrom = str_replace('whatsapp:', '', $from);

        $notificationResult = NotificationResult::where('to_number', 'LIKE', '%' . $normalizedFrom . '%')
            ->where('success', true)
            ->where('created_at', '>=', now()->subHours(24))
            ->orderByDesc('created_at')
            ->first();

        if (!$notificationResult) {
            Log::info('Twilio inbound: no recent notification found for sender', [
                'from' => $normalizedFrom,
            ]);
            return response('', 204);
        }

        $alert = $notificationResult->alert;
        if (!$alert) {
            return response('', 204);
        }

        $company = $alert->company;

        if ($company && $this->isNotificationsV2Active($company)) {
            $ack = NotificationAck::create([
                'alert_id' => $alert->id,
                'notification_result_id' => $notificationResult->id,
                'company_id' => $alert->company_id,
                'ack_type' => NotificationAck::TYPE_REPLY,
                'ack_payload' => [
                    'from' => $from,
                    'body' => $body,
                    'message_sid' => $messageSid,
                ],
            ]);

            DomainEventEmitter::emit(
                companyId: $alert->company_id,
                entityType: 'notification',
                entityId: (string) $notificationResult->id,
                eventType: 'notification.acked',
                payload: [
                    'ack_type' => 'reply',
                    'ack_id' => $ack->id,
                    'from' => $from,
                    'body_preview' => mb_substr($body ?? '', 0, 100),
                ],
                correlationId: (string) $alert->id,
            );

            AlertActivity::logAiAction(
                $alert->id,
                $alert->company_id,
                'notification_acked_via_reply',
                [
                    'from' => $from,
                    'body_preview' => mb_substr($body ?? '', 0, 100),
                    'channel' => $notificationResult->channel,
                ],
            );
        }

        return response('', 204);
    }

    // ── Private helpers ──────────────────────────────────────────

    /**
     * Record an IVR acknowledgement from a voice callback.
     */
    private function recordIvrAck(Alert $alert, ?string $callSid, string $responseType, array $callResponse): void
    {
        $company = $alert->company;
        if (!$company || !$this->isNotificationsV2Active($company)) {
            return;
        }

        $notificationResult = $callSid
            ? NotificationResult::where('call_sid', $callSid)->first()
            : null;

        $ack = NotificationAck::create([
            'alert_id' => $alert->id,
            'notification_result_id' => $notificationResult?->id,
            'company_id' => $alert->company_id,
            'ack_type' => NotificationAck::TYPE_IVR,
            'ack_payload' => $callResponse,
        ]);

        DomainEventEmitter::emit(
            companyId: $alert->company_id,
            entityType: 'notification',
            entityId: (string) ($notificationResult?->id ?? $alert->id),
            eventType: 'notification.acked',
            payload: [
                'ack_type' => 'ivr',
                'ack_id' => $ack->id,
                'response_type' => $responseType,
                'call_sid' => $callSid,
            ],
            correlationId: (string) $alert->id,
        );
    }

    /**
     * Record a call delivery event and update the notification_result status.
     */
    private function recordCallDeliveryEvent(
        Alert $alert,
        string $callSid,
        string $callStatus,
        array $rawCallback,
    ): void {
        $company = $alert->company;
        if (!$company || !$this->isNotificationsV2Active($company)) {
            return;
        }

        $notificationResult = NotificationResult::where('call_sid', $callSid)->first();

        if (!$notificationResult) {
            return;
        }

        $normalizedStatus = match ($callStatus) {
            'completed' => 'delivered',
            'busy', 'no-answer', 'canceled' => 'failed',
            'failed' => 'failed',
            'in-progress', 'ringing' => 'sent',
            'queued' => 'queued',
            default => $callStatus,
        };

        NotificationDeliveryEvent::create([
            'notification_result_id' => $notificationResult->id,
            'provider_sid' => $callSid,
            'status' => $normalizedStatus,
            'error_code' => null,
            'error_message' => in_array($callStatus, ['busy', 'no-answer', 'failed', 'canceled'])
                ? "Call status: {$callStatus}"
                : null,
            'raw_callback' => $rawCallback,
            'received_at' => now(),
        ]);

        $notificationResult->updateStatusFromCallback($normalizedStatus);

        $this->emitDeliveryDomainEvent($notificationResult, $normalizedStatus);
    }

    /**
     * Emit a domain event for a delivery status change.
     */
    private function emitDeliveryDomainEvent(NotificationResult $result, string $status): void
    {
        $eventType = match ($status) {
            'delivered' => 'notification.delivered',
            'read' => 'notification.read',
            'failed', 'undelivered' => 'notification.failed',
            default => null,
        };

        if (!$eventType) {
            return;
        }

        $alert = $result->alert;
        if (!$alert) {
            return;
        }

        DomainEventEmitter::emit(
            companyId: $alert->company_id,
            entityType: 'notification',
            entityId: (string) $result->id,
            eventType: $eventType,
            payload: [
                'channel' => $result->channel,
                'to' => $result->to_number,
                'provider_sid' => $result->getTwilioSid(),
                'status' => $status,
            ],
            correlationId: (string) $alert->id,
        );
    }

    private function isNotificationsV2Active(Company $company): bool
    {
        return Feature::for($company)->active('notifications-v2');
    }

    private function twimlResponse(string $content): Response
    {
        $twiml = '<?xml version="1.0" encoding="UTF-8"?><Response>' . $content . '</Response>';

        return response($twiml, 200)
            ->header('Content-Type', 'text/xml');
    }
}
