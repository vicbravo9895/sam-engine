<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service para enviar notificaciones via Twilio API.
 * 
 * Usa HTTP directamente (sin SDK) para SMS, WhatsApp y llamadas de voz.
 * Todas las credenciales vienen de config/services.php.
 */
class TwilioService
{
    private string $accountSid;
    private string $authToken;
    private string $phoneNumber;
    private string $whatsappNumber;
    private ?string $callbackUrl;
    private string $baseUrl;

    /**
     * Códigos de país que requieren el "1" móvil para WhatsApp.
     * En México, los números móviles necesitan el formato +521XXXXXXXXXX para WhatsApp.
     */
    private const WHATSAPP_MOBILE_PREFIX_COUNTRIES = [
        '52', // México
    ];

    public function __construct()
    {
        $this->accountSid = (string) config('services.twilio.sid', '');
        $this->authToken = (string) config('services.twilio.token', '');
        $this->phoneNumber = (string) config('services.twilio.from', '');
        $this->whatsappNumber = (string) config('services.twilio.whatsapp', '');
        $this->callbackUrl = config('services.twilio.callback_url');
        $this->baseUrl = "https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}";
    }

    /**
     * Verifica si Twilio está configurado.
     */
    public function isConfigured(): bool
    {
        return !empty($this->accountSid) && !empty($this->authToken);
    }

    /**
     * Envía un SMS.
     *
     * @param string $to Número en formato E.164 (ej: +5218117658890)
     * @param string $message Contenido del mensaje
     * @return array ['success' => bool, 'sid' => ?string, 'status' => ?string, 'error' => ?string]
     */
    public function sendSms(string $to, string $message): array
    {
        if (!$this->isConfigured()) {
            return $this->notConfiguredResponse('sms', $to, $message);
        }

        if (empty($this->phoneNumber)) {
            return [
                'success' => false,
                'channel' => 'sms',
                'error' => 'Número de origen de Twilio (TWILIO_PHONE_NUMBER) no configurado',
                'to' => $to,
            ];
        }

        try {
            $response = Http::withBasicAuth($this->accountSid, $this->authToken)
                ->asForm()
                ->timeout(30)
                ->post("{$this->baseUrl}/Messages.json", [
                    'From' => $this->phoneNumber,
                    'To' => $to,
                    'Body' => $message,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                Log::info('TwilioService: SMS enviado', [
                    'to' => $to,
                    'sid' => $data['sid'] ?? null,
                    'status' => $data['status'] ?? null,
                ]);

                return [
                    'success' => true,
                    'channel' => 'sms',
                    'sid' => $data['sid'] ?? null,
                    'status' => $data['status'] ?? 'queued',
                    'to' => $to,
                    'message_preview' => mb_substr($message, 0, 100),
                ];
            }

            $error = $response->json()['message'] ?? $response->body();
            Log::error('TwilioService: Error enviando SMS', [
                'to' => $to,
                'error' => $error,
                'status_code' => $response->status(),
            ]);

            return [
                'success' => false,
                'channel' => 'sms',
                'error' => $error,
                'to' => $to,
            ];

        } catch (\Exception $e) {
            Log::error('TwilioService: Excepción enviando SMS', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'channel' => 'sms',
                'error' => $e->getMessage(),
                'to' => $to,
            ];
        }
    }

    /**
     * Envía un mensaje de WhatsApp.
     *
     * @param string $to Número en formato E.164 (se agrega prefijo whatsapp: automáticamente)
     * @param string $message Contenido del mensaje
     * @return array ['success' => bool, 'sid' => ?string, 'status' => ?string, 'error' => ?string]
     */
    public function sendWhatsapp(string $to, string $message): array
    {
        if (!$this->isConfigured()) {
            return $this->notConfiguredResponse('whatsapp', $to, $message);
        }

        if (empty($this->whatsappNumber)) {
            return [
                'success' => false,
                'channel' => 'whatsapp',
                'error' => 'Número de WhatsApp de Twilio (TWILIO_WHATSAPP_NUMBER) no configurado',
                'to' => $to,
            ];
        }

        // Formatear el número para WhatsApp (maneja particularidad de México)
        $formattedTo = $this->formatPhoneForWhatsapp($to);

        // Asegurar prefijo whatsapp:
        $whatsappTo = str_starts_with($formattedTo, 'whatsapp:') ? $formattedTo : "whatsapp:{$formattedTo}";

        try {
            $response = Http::withBasicAuth($this->accountSid, $this->authToken)
                ->asForm()
                ->timeout(30)
                ->post("{$this->baseUrl}/Messages.json", [
                    'From' => $this->whatsappNumber,
                    'To' => $whatsappTo,
                    'Body' => $message,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                Log::info('TwilioService: WhatsApp enviado', [
                    'to' => $whatsappTo,
                    'sid' => $data['sid'] ?? null,
                    'status' => $data['status'] ?? null,
                ]);

                return [
                    'success' => true,
                    'channel' => 'whatsapp',
                    'sid' => $data['sid'] ?? null,
                    'status' => $data['status'] ?? 'queued',
                    'to' => $whatsappTo,
                    'message_preview' => mb_substr($message, 0, 100),
                ];
            }

            $error = $response->json()['message'] ?? $response->body();
            Log::error('TwilioService: Error enviando WhatsApp', [
                'to' => $whatsappTo,
                'error' => $error,
                'status_code' => $response->status(),
            ]);

            return [
                'success' => false,
                'channel' => 'whatsapp',
                'error' => $error,
                'to' => $whatsappTo,
            ];

        } catch (\Exception $e) {
            Log::error('TwilioService: Excepción enviando WhatsApp', [
                'to' => $whatsappTo,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'channel' => 'whatsapp',
                'error' => $e->getMessage(),
                'to' => $whatsappTo,
            ];
        }
    }

    /**
     * Realiza una llamada telefónica simple con TTS.
     * La llamada reproduce el mensaje y termina sin esperar respuesta.
     *
     * @param string $to Número en formato E.164
     * @param string $message Mensaje que se reproducirá con TTS en español
     * @return array ['success' => bool, 'sid' => ?string, 'status' => ?string, 'error' => ?string]
     */
    public function makeCall(string $to, string $message): array
    {
        if (!$this->isConfigured()) {
            return $this->notConfiguredResponse('call', $to, $message);
        }

        if (empty($this->phoneNumber)) {
            return [
                'success' => false,
                'channel' => 'call',
                'error' => 'Número de origen de Twilio (TWILIO_PHONE_NUMBER) no configurado',
                'to' => $to,
            ];
        }

        // TwiML para TTS simple
        $twiml = sprintf(
            '<Response><Say language="es-MX" voice="Polly.Mia">%s</Say></Response>',
            htmlspecialchars($message, ENT_XML1, 'UTF-8')
        );

        try {
            $response = Http::withBasicAuth($this->accountSid, $this->authToken)
                ->asForm()
                ->timeout(30)
                ->post("{$this->baseUrl}/Calls.json", [
                    'From' => $this->phoneNumber,
                    'To' => $to,
                    'Twiml' => $twiml,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                Log::info('TwilioService: Llamada iniciada', [
                    'to' => $to,
                    'sid' => $data['sid'] ?? null,
                    'status' => $data['status'] ?? null,
                ]);

                return [
                    'success' => true,
                    'channel' => 'call',
                    'sid' => $data['sid'] ?? null,
                    'status' => $data['status'] ?? 'queued',
                    'to' => $to,
                    'message_preview' => mb_substr($message, 0, 100),
                ];
            }

            $error = $response->json()['message'] ?? $response->body();
            Log::error('TwilioService: Error iniciando llamada', [
                'to' => $to,
                'error' => $error,
                'status_code' => $response->status(),
            ]);

            return [
                'success' => false,
                'channel' => 'call',
                'error' => $error,
                'to' => $to,
            ];

        } catch (\Exception $e) {
            Log::error('TwilioService: Excepción iniciando llamada', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'channel' => 'call',
                'error' => $e->getMessage(),
                'to' => $to,
            ];
        }
    }

    /**
     * Realiza una llamada con opción de confirmar (callback).
     * El operador puede presionar teclas para confirmar o escalar.
     *
     * @param string $to Número en formato E.164
     * @param string $message Mensaje de alerta que se reproducirá
     * @param int $eventId ID del evento para el callback
     * @return array ['success' => bool, 'sid' => ?string, 'status' => ?string, 'error' => ?string]
     */
    public function makeCallWithCallback(string $to, string $message, int $eventId): array
    {
        if (!$this->isConfigured()) {
            return $this->notConfiguredResponse('call_callback', $to, $message);
        }

        if (empty($this->phoneNumber)) {
            return [
                'success' => false,
                'channel' => 'call',
                'error' => 'Número de origen de Twilio (TWILIO_PHONE_NUMBER) no configurado',
                'to' => $to,
            ];
        }

        if (empty($this->callbackUrl)) {
            // Sin callback URL, hacer llamada simple
            Log::warning('TwilioService: TWILIO_CALLBACK_URL no configurado, usando llamada simple', [
                'to' => $to,
                'event_id' => $eventId,
            ]);
            return $this->makeCall($to, $message);
        }

        // Callback URLs
        $voiceCallbackUrl = "{$this->callbackUrl}/voice-callback?event_id={$eventId}";
        $statusCallbackUrl = "{$this->callbackUrl}/voice-status";

        // TwiML con Gather para capturar respuesta
        $escapedMessage = htmlspecialchars($message, ENT_XML1, 'UTF-8');
        $escapedCallbackUrl = htmlspecialchars($voiceCallbackUrl, ENT_XML1, 'UTF-8');
        
        $twiml = <<<TWIML
<Response>
    <Say language="es-MX" voice="Polly.Mia">{$escapedMessage}</Say>
    <Gather numDigits="1" action="{$escapedCallbackUrl}" method="POST" timeout="10">
        <Say language="es-MX" voice="Polly.Mia">
            Presione 1 para confirmar recepción de la alerta.
            Presione 2 para escalar a supervisión.
        </Say>
    </Gather>
    <Say language="es-MX" voice="Polly.Mia">
        No se recibió respuesta. La alerta quedará pendiente.
    </Say>
</Response>
TWIML;

        try {
            $response = Http::withBasicAuth($this->accountSid, $this->authToken)
                ->asForm()
                ->timeout(30)
                ->post("{$this->baseUrl}/Calls.json", [
                    'From' => $this->phoneNumber,
                    'To' => $to,
                    'Twiml' => $twiml,
                    'StatusCallback' => $statusCallbackUrl,
                    'StatusCallbackEvent' => ['completed', 'busy', 'no-answer', 'failed', 'canceled'],
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                Log::info('TwilioService: Llamada con callback iniciada', [
                    'to' => $to,
                    'event_id' => $eventId,
                    'sid' => $data['sid'] ?? null,
                    'status' => $data['status'] ?? null,
                    'callback_url' => $voiceCallbackUrl,
                ]);

                return [
                    'success' => true,
                    'channel' => 'call',
                    'sid' => $data['sid'] ?? null,
                    'status' => $data['status'] ?? 'queued',
                    'to' => $to,
                    'event_id' => $eventId,
                    'callback_url' => $voiceCallbackUrl,
                    'message_preview' => mb_substr($message, 0, 100),
                ];
            }

            $error = $response->json()['message'] ?? $response->body();
            Log::error('TwilioService: Error iniciando llamada con callback', [
                'to' => $to,
                'event_id' => $eventId,
                'error' => $error,
                'status_code' => $response->status(),
            ]);

            return [
                'success' => false,
                'channel' => 'call',
                'error' => $error,
                'to' => $to,
                'event_id' => $eventId,
            ];

        } catch (\Exception $e) {
            Log::error('TwilioService: Excepción iniciando llamada con callback', [
                'to' => $to,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'channel' => 'call',
                'error' => $e->getMessage(),
                'to' => $to,
                'event_id' => $eventId,
            ];
        }
    }

    /**
     * Respuesta cuando Twilio no está configurado.
     */
    private function notConfiguredResponse(string $channel, string $to, string $message): array
    {
        Log::warning('TwilioService: Twilio no configurado', [
            'channel' => $channel,
            'to' => $to,
        ]);

        return [
            'success' => false,
            'channel' => $channel,
            'error' => 'Twilio no está configurado. Credenciales faltantes (TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN).',
            'simulated' => true,
            'to' => $to,
            'message_preview' => mb_substr($message, 0, 100),
        ];
    }

    /**
     * Formatea un número de teléfono para WhatsApp.
     * 
     * Para México (+52), WhatsApp requiere el formato +521XXXXXXXXXX
     * donde el "1" es un indicador de número móvil.
     * 
     * @param string $phone Número en formato E.164 (ej: +528117658890)
     * @return string Número formateado para WhatsApp (ej: +5218117658890)
     */
    public function formatPhoneForWhatsapp(string $phone): string
    {
        // Eliminar el prefijo whatsapp: si existe
        $phone = preg_replace('/^whatsapp:/', '', $phone);
        
        // Normalizar: solo dígitos y +
        $phone = preg_replace('/[^0-9+]/', '', $phone);

        // Si no tiene formato E.164, devolverlo tal cual
        if (!str_starts_with($phone, '+')) {
            Log::warning('TwilioService: Número sin formato E.164 para WhatsApp', [
                'phone' => $phone,
            ]);
            return $phone;
        }

        // Verificar cada código de país que necesita el "1" móvil
        foreach (self::WHATSAPP_MOBILE_PREFIX_COUNTRIES as $countryCode) {
            $prefix = '+' . $countryCode;
            
            if (str_starts_with($phone, $prefix)) {
                $nationalNumber = substr($phone, strlen($prefix));
                
                // Si ya tiene el "1" móvil, no agregarlo de nuevo
                if (str_starts_with($nationalNumber, '1')) {
                    return $phone;
                }
                
                // Agregar el "1" móvil
                $formatted = $prefix . '1' . $nationalNumber;
                
                Log::debug('TwilioService: Número formateado para WhatsApp', [
                    'original' => $phone,
                    'formatted' => $formatted,
                    'country_code' => $countryCode,
                ]);
                
                return $formatted;
            }
        }

        // Para otros países, devolver el número tal cual
        return $phone;
    }

    /**
     * Formatea un número de teléfono para SMS/llamadas (formato E.164 estándar).
     * 
     * A diferencia de WhatsApp, SMS y llamadas NO necesitan el "1" móvil
     * adicional para México.
     * 
     * @param string $phone Número (puede tener o no formato E.164)
     * @param string|null $countryCode Código de país sin + (ej: "52")
     * @return string Número formateado en E.164
     */
    public function formatPhoneForSms(string $phone, ?string $countryCode = null): string
    {
        // Normalizar: solo dígitos y +
        $phone = preg_replace('/[^0-9+]/', '', $phone);

        // Si ya tiene formato E.164, devolverlo
        if (str_starts_with($phone, '+')) {
            return $phone;
        }

        // Si tenemos código de país, agregarlo
        if ($countryCode) {
            $countryCode = ltrim($countryCode, '+');
            return '+' . $countryCode . $phone;
        }

        Log::warning('TwilioService: Número sin código de país para SMS', [
            'phone' => $phone,
        ]);

        return $phone;
    }
}
