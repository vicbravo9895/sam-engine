<?php

namespace App\Jobs;

use App\Models\SamsaraEvent;
use App\Services\ContactResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Job para revalidar eventos de Samsara que requieren monitoreo continuo.
 * 
 * ACTUALIZADO: Nuevo contrato de respuesta del AI Service.
 */
class RevalidateSamsaraEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número de intentos antes de fallar
     */
    public $tries = 2;

    /**
     * Timeout en segundos (5 minutos)
     */
    public $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public SamsaraEvent $event
    ) {
        $this->onQueue('samsara-revalidation');
    }

    /**
     * Execute the job.
     */
    public function handle(ContactResolver $contactResolver): void
    {
        // Verificar que el evento aún esté en investigating
        if ($this->event->ai_status !== SamsaraEvent::STATUS_INVESTIGATING) {
            Log::info("Event no longer investigating, skipping revalidation", [
                'event_id' => $this->event->id,
                'current_status' => $this->event->ai_status,
            ]);
            return;
        }

        // Verificar límite de investigaciones
        if ($this->event->investigation_count >= SamsaraEvent::getMaxInvestigations()) {
            $this->event->markAsCompleted(
                assessment: array_merge($this->event->ai_assessment ?? [], [
                    'verdict' => 'needs_review',
                    'likelihood' => 'medium',
                    'confidence' => 0.5,
                    'reasoning' => 'Máximo de revalidaciones alcanzado. Requiere revisión humana.',
                    'risk_escalation' => 'warn',
                    'requires_monitoring' => false,
                ]),
                humanMessage: 'Este evento requiere revisión manual después de ' . SamsaraEvent::getMaxInvestigations() . ' análisis automáticos.',
                alertContext: $this->event->alert_context,
                notificationDecision: $this->event->notification_decision,
                notificationExecution: null,
                execution: $this->event->ai_actions
            );

            Log::warning("Event reached max investigations", [
                'event_id' => $this->event->id,
                'investigation_count' => $this->event->investigation_count,
            ]);
            return;
        }

        Log::info("Revalidating event", [
            'event_id' => $this->event->id,
            'investigation_count' => $this->event->investigation_count,
        ]);

        try {
            // Resolver contactos para notificaciones
            $contacts = $contactResolver->resolveForEvent($this->event);
            $contactPayload = $contactResolver->formatForPayload($contacts);

            // Enriquecer el payload con los contactos
            $enrichedPayload = array_merge($this->event->raw_payload, $contactPayload);

            $aiServiceUrl = config('services.ai_engine.url');

            // Construir contexto de revalidación
            $revalidationContext = [
                'is_revalidation' => true,
                'original_event_time' => $this->event->occurred_at?->toIso8601String(),
                'first_investigation_time' => $this->event->created_at->toIso8601String(),
                'last_investigation_time' => $this->event->last_investigation_at?->toIso8601String(),
                'investigation_count' => $this->event->investigation_count,
                'previous_assessment' => $this->event->ai_assessment,
                'previous_alert_context' => $this->event->alert_context,
                'investigation_history' => $this->event->investigation_history ?? [],
            ];

            $response = Http::timeout(120)
                ->post("{$aiServiceUrl}/alerts/revalidate", [
                    'event_id' => $this->event->id,
                    'payload' => $enrichedPayload,
                    'context' => $revalidationContext,
                ]);

            // Manejar 503 (Service at Capacity) - el AI Service está sobrecargado
            // Laravel reintentará automáticamente después del backoff
            if ($response->status() === 503) {
                $stats = $response->json('stats', []);
                Log::warning("AI service at capacity during revalidation, will retry", [
                    'event_id' => $this->event->id,
                    'attempt' => $this->attempts(),
                    'ai_stats' => $stats,
                ]);
                $activeRequests = $stats['active_requests'] ?? '?';
                $pendingRequests = $stats['pending_requests'] ?? '?';
                throw new \Exception("AI service at capacity. Active: {$activeRequests}, Pending: {$pendingRequests}");
            }

            if ($response->failed()) {
                throw new \Exception("AI service returned error: " . $response->body());
            }

            $result = $response->json();

            Log::info("Revalidation response received", [
                'event_id' => $this->event->id,
                'status' => $result['status'] ?? 'unknown',
                'has_assessment' => isset($result['assessment']),
                'has_human_message' => isset($result['human_message']),
            ]);

            // Extraer datos del nuevo contrato
            $alertContext = $result['alert_context'] ?? $this->event->alert_context;
            $assessment = $result['assessment'] ?? [];
            $humanMessage = $result['human_message'] ?? 'Revalidación completada';
            $notificationDecision = $result['notification_decision'] ?? null;
            $notificationExecution = $result['notification_execution'] ?? null;
            $execution = $result['execution'] ?? $this->event->ai_actions;

            // Extraer información de monitoreo desde el assessment
            $requiresMonitoring = $assessment['requires_monitoring'] ?? false;
            $nextCheckMinutes = $assessment['next_check_minutes'] ?? 30;
            $monitoringReason = $assessment['monitoring_reason'] ?? null;

            // Verificar si aún requiere monitoreo
            if ($requiresMonitoring) {
                $this->event->markAsInvestigating(
                    assessment: $assessment,
                    humanMessage: $humanMessage,
                    nextCheckMinutes: $nextCheckMinutes,
                    alertContext: $alertContext,
                    notificationDecision: $notificationDecision,
                    notificationExecution: $notificationExecution,
                    execution: $execution
                );

                $this->event->addInvestigationRecord(
                    reason: $monitoringReason ?? 'Requiere más tiempo para contexto'
                );

                // Guardar twilio_call_sid si hubo llamada exitosa (para callbacks)
                $this->persistTwilioCallSid($notificationExecution);

                // Programar siguiente revalidación
                self::dispatch($this->event)
                    ->delay(now()->addMinutes($nextCheckMinutes))
                    ->onQueue('samsara-revalidation');

                Log::info("Event continues under investigation", [
                    'event_id' => $this->event->id,
                    'next_check_minutes' => $nextCheckMinutes,
                    'investigation_count' => $this->event->investigation_count,
                    'verdict' => $assessment['verdict'] ?? 'unknown',
                    'risk_escalation' => $assessment['risk_escalation'] ?? 'unknown',
                ]);
            } else {
                // La AI ahora está segura - completar
                $this->event->markAsCompleted(
                    assessment: $assessment,
                    humanMessage: $humanMessage,
                    alertContext: $alertContext,
                    notificationDecision: $notificationDecision,
                    notificationExecution: $notificationExecution,
                    execution: $execution
                );

                // Guardar twilio_call_sid si hubo llamada exitosa (para callbacks)
                $this->persistTwilioCallSid($notificationExecution);

                Log::info("Event investigation completed", [
                    'event_id' => $this->event->id,
                    'final_verdict' => $assessment['verdict'] ?? 'unknown',
                    'risk_escalation' => $assessment['risk_escalation'] ?? 'unknown',
                    'total_investigations' => $this->event->investigation_count,
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Failed to revalidate event", [
                'event_id' => $this->event->id,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // En caso de error, programar reintento
            if ($this->attempts() < $this->tries) {
                throw $e;
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Revalidation job failed permanently", [
            'event_id' => $this->event->id,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Guarda el twilio_call_sid del primer call exitoso para que el
     * TwilioCallbackController pueda encontrar el evento por CallSid.
     * 
     * @param array|null $notificationExecution Resultados de ejecución de notificaciones
     */
    private function persistTwilioCallSid(?array $notificationExecution): void
    {
        if (!$notificationExecution || !($notificationExecution['attempted'] ?? false)) {
            return;
        }

        $results = $notificationExecution['results'] ?? [];
        
        foreach ($results as $result) {
            // Buscar el primer call exitoso con call_sid
            if (
                ($result['channel'] ?? '') === 'call' &&
                ($result['success'] ?? false) &&
                !empty($result['call_sid'])
            ) {
                $this->event->update([
                    'twilio_call_sid' => $result['call_sid'],
                    'notification_status' => 'sent',
                    'notification_sent_at' => now(),
                ]);

                Log::debug("Twilio call_sid persisted for callbacks (revalidation)", [
                    'event_id' => $this->event->id,
                    'call_sid' => $result['call_sid'],
                ]);

                break; // Solo guardamos el primer call_sid
            }
        }
    }
}

