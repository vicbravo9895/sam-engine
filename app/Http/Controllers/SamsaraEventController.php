<?php

namespace App\Http\Controllers;

use App\Models\SamsaraEvent;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SamsaraEventController extends Controller
{
    /**
     * Listar eventos con filtros
     * 
     * GET /api/events?status=pending&severity=critical&limit=50
     */
    public function index(Request $request)
    {
        $query = SamsaraEvent::query();

        // Filtros
        if ($request->has('status')) {
            $query->where('ai_status', $request->status);
        }

        if ($request->has('severity')) {
            $query->where('severity', $request->severity);
        }

        if ($request->has('vehicle_id')) {
            $query->where('vehicle_id', $request->vehicle_id);
        }

        // Ordenar por más recientes
        $query->orderBy('created_at', 'desc');

        // Paginación
        $limit = min($request->get('limit', 50), 100);
        $events = $query->paginate($limit);

        return response()->json($events);
    }

    /**
     * Obtener un evento específico
     * 
     * GET /api/events/{id}
     */
    public function show(int $id)
    {
        $event = SamsaraEvent::findOrFail($id);

        return response()->json($event);
    }

    /**
     * Stream SSE del progreso de procesamiento de un evento
     * 
     * GET /api/events/{id}/stream
     * 
     * Este endpoint devuelve Server-Sent Events (SSE) con el progreso
     * del análisis de IA en tiempo real.
     */
    public function stream(int $id)
    {
        $event = SamsaraEvent::findOrFail($id);

        return new StreamedResponse(function () use ($event) {
            // Configurar para evitar buffering
            if (ob_get_level()) {
                ob_end_clean();
            }

            // Enviar evento inicial con el estado actual
            $this->sendSSE([
                'type' => 'initial',
                'event' => $event->fresh(),
            ]);

            // Si ya está procesado, enviar resultado y terminar
            if ($event->isProcessed()) {
                $this->sendSSE([
                    'type' => 'completed',
                    'event' => $event->fresh(),
                ]);

                $this->sendSSE(['type' => 'close']);
                return;
            }

            // Polling cada 2 segundos hasta que se complete
            $maxWaitTime = 300; // 5 minutos máximo
            $startTime = time();
            $lastStatus = $event->ai_status;

            while (time() - $startTime < $maxWaitTime) {
                // Refrescar el evento desde la DB
                $event->refresh();

                // Si cambió el estado, enviar update
                if ($event->ai_status !== $lastStatus) {
                    $this->sendSSE([
                        'type' => 'status_change',
                        'event' => $event,
                    ]);
                    $lastStatus = $event->ai_status;
                }

                // Si ya terminó, enviar resultado final y salir
                if ($event->isProcessed()) {
                    $this->sendSSE([
                        'type' => 'completed',
                        'event' => $event,
                    ]);
                    break;
                }

                // Enviar heartbeat para mantener la conexión viva
                $this->sendSSE([
                    'type' => 'heartbeat',
                    'timestamp' => now()->toIso8601String(),
                ]);

                // Esperar 2 segundos antes del siguiente check
                sleep(2);
            }

            // Enviar evento de cierre
            $this->sendSSE(['type' => 'close']);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no', // Para nginx
        ]);
    }

    /**
     * Obtener solo el estado actual de un evento (para polling simple)
     * 
     * GET /api/events/{id}/status
     */
    public function status(int $id)
    {
        $event = SamsaraEvent::findOrFail($id);

        return response()->json([
            'id' => $event->id,
            'ai_status' => $event->ai_status,
            'is_processed' => $event->isProcessed(),
            'ai_message' => $event->ai_message,
            'ai_assessment' => $event->ai_assessment,
            'ai_processed_at' => $event->ai_processed_at,
            'ai_error' => $event->ai_error,
        ]);
    }

    /**
     * Helper para enviar eventos SSE
     */
    private function sendSSE(array $data): void
    {
        echo "data: " . json_encode($data) . "\n\n";

        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
}
