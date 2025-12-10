<?php

namespace App\Http\Controllers;

use App\Models\SamsaraEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class TwilioCallbackController extends Controller
{
    /**
     * Handle Twilio voice callback when operator responds.
     * This is called when the operator presses a key during the call.
     */
    public function voiceCallback(Request $request): Response
    {
        Log::info('Twilio voice callback received', $request->all());

        $eventId = $request->input('event_id');
        $digits = $request->input('Digits');
        $callSid = $request->input('CallSid');

        if (!$eventId) {
            return $this->twimlResponse('<Say language="es-MX">Error: evento no identificado.</Say>');
        }

        $event = SamsaraEvent::find($eventId);

        if (!$event) {
            return $this->twimlResponse('<Say language="es-MX">Evento no encontrado.</Say>');
        }

        // Record the operator's response
        $response = [
            'acknowledged' => $digits === '1',
            'keypress' => $digits,
            'timestamp' => now()->toIso8601String(),
            'call_sid' => $callSid,
        ];

        $event->update([
            'call_response' => $response,
            'notification_status' => $digits === '1' ? 'acknowledged' : 'responded',
        ]);

        Log::info('Call response recorded', [
            'event_id' => $eventId,
            'response' => $response,
        ]);

        // Respond with confirmation
        if ($digits === '1') {
            return $this->twimlResponse(
                '<Say language="es-MX">Confirmación recibida. La alerta ha sido reconocida. Gracias.</Say>'
            );
        } elseif ($digits === '2') {
            return $this->twimlResponse(
                '<Say language="es-MX">Entendido. Se escalará a supervisión. Gracias.</Say>'
            );
        } else {
            return $this->twimlResponse(
                '<Say language="es-MX">Respuesta registrada. Gracias.</Say>'
            );
        }
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
