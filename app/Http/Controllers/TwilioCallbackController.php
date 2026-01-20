<?php

namespace App\Http\Controllers;

use App\Jobs\SendPanicEscalationJob;
use App\Models\SamsaraEvent;
use App\Models\SamsaraEventActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class TwilioCallbackController extends Controller
{
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
        $eventId = $request->input('event_id');
        $digits = $request->input('Digits');
        $callSid = $request->input('CallSid');
        $callStatus = $request->input('CallStatus');

        Log::channel('stack')->info('=== PANIC CALLBACK RECEIVED ===', [
            'event_id' => $eventId,
            'digits' => $digits,
            'call_sid' => $callSid,
            'call_status' => $callStatus,
            'all_params' => $request->all(),
        ]);

        if (!$eventId) {
            Log::error('Panic callback: event_id missing');
            return $this->twimlResponse('<Say language="es-MX" voice="Polly.Mia">Error: evento no identificado.</Say>');
        }

        $event = SamsaraEvent::find($eventId);

        if (!$event) {
            Log::error('Panic callback: event not found', ['event_id' => $eventId]);
            return $this->twimlResponse('<Say language="es-MX" voice="Polly.Mia">Evento no encontrado.</Say>');
        }

        // Determinar la respuesta del operador
        $isConfirmed = $digits === '1';
        $isDenied = $digits === '2';
        
        $responseType = match (true) {
            $isConfirmed => 'confirmed_panic',
            $isDenied => 'false_alarm',
            default => 'no_response',
        };

        // Registrar la respuesta del operador
        $callResponse = [
            'response_type' => $responseType,
            'is_real_panic' => $isConfirmed,
            'is_false_alarm' => $isDenied,
            'keypress' => $digits,
            'call_sid' => $callSid,
            'operator_responded_at' => now()->toIso8601String(),
            'vehicle_name' => $event->vehicle_name,
            'driver_name' => $event->driver_name,
        ];

        // Determinar el nuevo estado de notificación
        $notificationStatus = match (true) {
            $isConfirmed => 'panic_confirmed',
            $isDenied => 'false_alarm',
            default => 'operator_no_response',
        };

        $event->update([
            'call_response' => $callResponse,
            'notification_status' => $notificationStatus,
        ]);

        Log::channel('stack')->info('=== PANIC RESPONSE RECORDED ===', [
            'event_id' => $event->id,
            'response_type' => $responseType,
            'notification_status' => $notificationStatus,
            'is_real_panic' => $isConfirmed,
        ]);

        // Registrar actividad para el timeline del evento
        try {
            SamsaraEventActivity::create([
                'samsara_event_id' => $event->id,
                'company_id' => $event->company_id,
                'action' => $isConfirmed ? 'panic_confirmed_by_operator' : ($isDenied ? 'panic_denied_by_operator' : 'operator_callback_timeout'),
                'data' => $callResponse,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to log activity', ['error' => $e->getMessage()]);
        }

        // SI CONFIRMA PÁNICO REAL → Escalar a monitoreo y emergencia
        if ($isConfirmed) {
            Log::channel('stack')->info('=== DISPATCHING PANIC ESCALATION ===', [
                'event_id' => $event->id,
                'vehicle_name' => $event->vehicle_name,
            ]);

            // Despachar job para notificar a monitoreo y emergencia
            SendPanicEscalationJob::dispatch($event);

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

        // SI DENIEGA → Solo registrar
        if ($isDenied) {
            Log::channel('stack')->info('=== PANIC DENIED - FALSE ALARM ===', [
                'event_id' => $event->id,
                'vehicle_name' => $event->vehicle_name,
            ]);

            return $this->twimlResponse(
                '<Say voice="Polly.Mia" language="es-MX">Entendido. Se ha registrado como activación accidental.</Say>' .
                '<Pause length="1"/>' .
                '<Say voice="Polly.Mia" language="es-MX">La alerta ha sido cancelada y no se enviará ninguna notificación.</Say>' .
                '<Pause length="1"/>' .
                '<Say voice="Polly.Mia" language="es-MX">Gracias por confirmar. Que tenga un buen viaje.</Say>'
            );
        }

        // Respuesta para tecla no válida - volver a pedir
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

    /**
     * Handle Twilio voice status updates (call ended, failed, etc.)
     */
    public function voiceStatus(Request $request): Response
    {
        Log::info('Twilio voice status update', $request->all());

        $callSid = $request->input('CallSid');
        $callStatus = $request->input('CallStatus');

        // Find event by call SID
        $event = SamsaraEvent::where('twilio_call_sid', $callSid)->first();

        if ($event) {
            $currentResponse = $event->call_response ?? [];
            $currentResponse['call_status'] = $callStatus;
            $currentResponse['status_updated_at'] = now()->toIso8601String();

            $updateData = ['call_response' => $currentResponse];

            // Update notification status based on call status
            if (in_array($callStatus, ['completed', 'busy', 'no-answer', 'failed', 'canceled'])) {
                if ($event->notification_status === 'sent' && $callStatus !== 'completed') {
                    $updateData['notification_status'] = 'call_' . str_replace('-', '_', $callStatus);
                }
            }

            $event->update($updateData);

            Log::info('Call status updated', [
                'event_id' => $event->id,
                'call_status' => $callStatus,
            ]);
        }

        return response('', 200);
    }

    /**
     * Generate TwiML response
     */
    private function twimlResponse(string $content): Response
    {
        $twiml = '<?xml version="1.0" encoding="UTF-8"?><Response>' . $content . '</Response>';

        return response($twiml, 200)
            ->header('Content-Type', 'text/xml');
    }
}
