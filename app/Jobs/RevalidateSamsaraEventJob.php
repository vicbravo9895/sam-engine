<?php

namespace App\Jobs;

use App\Models\SamsaraEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
    public function handle(): void
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
                    'verdict' => 'needs_human_review',
                    'likelihood' => 'unknown',
                    'reasoning' => 'Máximo de revalidaciones alcanzado. Requiere revisión humana.',
                ]),
                message: 'Este evento requiere revisión manual después de ' . SamsaraEvent::getMaxInvestigations() . ' análisis automáticos.',
                actions: $this->event->ai_actions
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
            $aiServiceUrl = config('services.ai_engine.url');

            // Construir contexto de revalidación
            $revalidationContext = [
                'is_revalidation' => true,
                'original_event_time' => $this->event->occurred_at?->toIso8601String(),
                'first_investigation_time' => $this->event->created_at->toIso8601String(),
                'last_investigation_time' => $this->event->last_investigation_at?->toIso8601String(),
                'investigation_count' => $this->event->investigation_count,
                'previous_assessment' => $this->event->ai_assessment,
                'investigation_history' => $this->event->investigation_history ?? [],
            ];

            $response = Http::timeout(120)
                ->post("{$aiServiceUrl}/alerts/revalidate", [
                    'event_id' => $this->event->id,
                    'payload' => $this->event->raw_payload,
                    'context' => $revalidationContext,
                ]);

            if ($response->failed()) {
                throw new \Exception("AI service returned error: " . $response->body());
            }

            $result = $response->json();

            // Verificar si aún requiere monitoreo
            if ($result['requires_monitoring'] ?? false) {
                $nextCheckMinutes = $result['next_check_minutes'] ?? 30;

                $this->event->markAsInvestigating(
                    assessment: $result['assessment'] ?? $this->event->ai_assessment ?? [],
                    message: $result['message'] ?? 'Evento bajo investigación continua',
                    nextCheckMinutes: $nextCheckMinutes,
                    actions: $result['actions'] ?? $this->event->ai_actions
                );

                $this->event->addInvestigationRecord(
                    reason: $result['monitoring_reason'] ?? 'Requiere más tiempo para contexto'
                );

                // Programar siguiente revalidación
                self::dispatch($this->event)
                    ->delay(now()->addMinutes($nextCheckMinutes))
                    ->onQueue('samsara-revalidation');

                Log::info("Event continues under investigation", [
                    'event_id' => $this->event->id,
                    'next_check_minutes' => $nextCheckMinutes,
                    'investigation_count' => $this->event->investigation_count,
                ]);
            } else {
                // La AI ahora está segura - completar
                $this->event->markAsCompleted(
                    assessment: $result['assessment'] ?? [],
                    message: $result['message'] ?? 'Investigación completada',
                    actions: $result['actions'] ?? null
                );

                Log::info("Event investigation completed", [
                    'event_id' => $this->event->id,
                    'final_verdict' => $result['assessment']['verdict'] ?? 'unknown',
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
}
