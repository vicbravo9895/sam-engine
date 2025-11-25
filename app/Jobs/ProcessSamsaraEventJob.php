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

class ProcessSamsaraEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número de intentos antes de fallar
     */
    public $tries = 3;

    /**
     * Timeout en segundos (5 minutos)
     */
    public $timeout = 300;

    /**
     * Tiempo de espera entre reintentos (en segundos)
     */
    public $backoff = [30, 60, 120];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public SamsaraEvent $event
    ) {
        // El job se ejecutará en la cola 'samsara-events'
        $this->onQueue('samsara-events');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("Processing Samsara event", [
            'event_id' => $this->event->id,
            'event_type' => $this->event->event_type,
            'severity' => $this->event->severity,
        ]);

        try {
            // Marcar como procesando
            $this->event->markAsProcessing();

            // Llamar al servicio de IA (FastAPI)
            $aiServiceUrl = config('services.ai_engine.url');

            $response = Http::timeout(120)
                ->post("{$aiServiceUrl}/alerts/ingest", [
                    'event_id' => $this->event->id,
                    'payload' => $this->event->raw_payload,
                ]);

            if ($response->failed()) {
                throw new \Exception("AI service returned error: " . $response->body());
            }

            $result = $response->json();

            // Actualizar el evento con los resultados
            $this->event->markAsCompleted(
                assessment: $result['assessment'] ?? [],
                message: $result['message'] ?? 'No message provided',
                actions: $result['actions'] ?? null
            );

            Log::info("Samsara event processed successfully", [
                'event_id' => $this->event->id,
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to process Samsara event", [
                'event_id' => $this->event->id,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // Si es el último intento, marcar como fallido
            if ($this->attempts() >= $this->tries) {
                $this->event->markAsFailed($e->getMessage());
            }

            // Re-lanzar la excepción para que Laravel maneje el retry
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Samsara event job failed permanently", [
            'event_id' => $this->event->id,
            'error' => $exception->getMessage(),
        ]);

        $this->event->markAsFailed($exception->getMessage());
    }
}
