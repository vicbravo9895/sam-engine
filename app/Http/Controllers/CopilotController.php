<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCopilotMessageJob;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Services\StreamingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CopilotController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        // Check if user has a company
        if (!$user->company_id) {
            return Inertia::render('copilot', [
                'conversations' => [],
                'currentConversation' => null,
                'messages' => [],
                'error' => 'No estás asociado a ninguna empresa. Contacta al administrador.',
            ]);
        }
        
        // Only show conversations for user's company
        $conversations = Conversation::where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->orderBy('updated_at', 'desc')
            ->get(['id', 'thread_id', 'title', 'created_at', 'updated_at']);
        
        return Inertia::render('copilot', [
            'conversations' => $conversations,
            'currentConversation' => null,
            'messages' => [],
        ]);
    }

    public function show(Request $request, string $threadId)
    {
        $user = $request->user();
        
        // Check if user has a company
        if (!$user->company_id) {
            return redirect()->route('copilot.index');
        }
        
        // Only show conversations for user's company
        $conversations = Conversation::where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->orderBy('updated_at', 'desc')
            ->get(['id', 'thread_id', 'title', 'created_at', 'updated_at']);
        
        // Ensure conversation belongs to user's company
        $currentConversation = Conversation::where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->where('thread_id', $threadId)
            ->first();
        
        if (!$currentConversation) {
            return redirect()->route('copilot.index');
        }
        
        $messages = $this->getFormattedMessages($threadId);
        
        // Obtener estado de streaming de la conversación
        $meta = $currentConversation->meta ?? [];
        $isStreaming = $meta['streaming'] ?? false;
        $streamingContent = $meta['streaming_content'] ?? '';
        $activeToolName = $meta['active_tool'] ?? null;
        
        // Obtener información de la herramienta activa
        $activeTool = null;
        if ($activeToolName) {
            $toolDescription = $this->getToolDisplayInfo($activeToolName);
            $activeTool = [
                'label' => $toolDescription['label'],
                'icon' => $toolDescription['icon'],
            ];
        }
        
        return Inertia::render('copilot', [
            'conversations' => $conversations,
            'currentConversation' => [
                ...$currentConversation->toArray(),
                'total_tokens' => $currentConversation->total_tokens,
                'is_streaming' => $isStreaming,
                'streaming_content' => $streamingContent,
                'active_tool' => $activeTool,
            ],
            'messages' => $messages,
        ]);
    }

    public function send(Request $request, StreamingService $streamingService): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:10000',
            'thread_id' => 'nullable|string',
        ]);
        
        $user = $request->user();
        $message = $request->input('message');
        $threadId = $request->input('thread_id');
        $isNewConversation = false;
        
        // Validate user has a company
        if (!$user->company_id) {
            return response()->json([
                'error' => 'No estás asociado a ninguna empresa. Contacta al administrador.',
            ], 403);
        }
        
        // Si no hay thread_id, crear una nueva conversación
        if (!$threadId) {
            $threadId = Str::uuid()->toString();
            $isNewConversation = true;
            
            // Generar título a partir del primer mensaje (máximo 50 caracteres)
            $title = Str::limit($message, 50);
            
            Conversation::create([
                'thread_id' => $threadId,
                'user_id' => $user->id,
                'company_id' => $user->company_id,
                'title' => $title,
            ]);
        }
        
        // Actualizar el timestamp de la conversación
        $conversation = Conversation::where('thread_id', $threadId)
            ->where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->first();
            
        if ($conversation) {
            $conversation->update([
                'updated_at' => now(),
                'meta' => array_merge($conversation->meta ?? [], [
                    'streaming' => true,
                    'streaming_started_at' => now()->toISOString(),
                    'streaming_content' => '',
                ]),
            ]);
        }

        // CRÍTICO: Inicializar Redis ANTES de despachar el Job
        // Esto evita la race condition donde el SSE conecta antes de que Redis esté listo
        Log::info('Initializing Redis stream before job dispatch', ['thread_id' => $threadId]);
        $streamingService->initStream($threadId);
        
        // Despachar job en background
        ProcessCopilotMessageJob::dispatch(
            message: $message,
            threadId: $threadId,
            userId: $user->id,
        );

        // Devolver inmediatamente con información del thread
        return response()->json([
            'thread_id' => $threadId,
            'is_new_conversation' => $isNewConversation,
            'status' => 'streaming',
        ]);
    }

    /**
     * Endpoint SSE para streaming en tiempo real.
     * Usa Redis Streams con XREAD BLOCK: sin polling, bajo CPU, escalable (200+ usuarios).
     * El id de cada evento SSE es el ID del stream en Redis para reconexión sin perder chunks.
     */
    public function stream(Request $request, string $threadId, StreamingService $streamingService): StreamedResponse
    {
        $user = $request->user();

        Log::info('SSE stream requested', ['thread_id' => $threadId, 'user_id' => $user->id]);

        $conversation = Conversation::where('thread_id', $threadId)
            ->where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->first();

        if (!$conversation) {
            Log::warning('SSE stream: conversation not found', ['thread_id' => $threadId]);
            return response()->stream(function () {
                echo "event: error\n";
                echo "data: " . json_encode(['error' => 'Conversación no encontrada']) . "\n\n";
                if (ob_get_level()) ob_flush();
                flush();
            }, 200, $this->getSseHeaders());
        }

        $lastEventId = $request->header('Last-Event-ID', '0');

        return response()->stream(function () use ($threadId, $streamingService, $lastEventId) {
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', false);
            while (ob_get_level()) {
                ob_end_clean();
            }

            Log::info('SSE stream started (Redis Streams)', ['thread_id' => $threadId, 'last_id' => $lastEventId]);

            $startId = $lastEventId !== '' ? $lastEventId : '0';
            $lastHeartbeat = time();
            $maxRuntime = 300; // 5 minutos
            $startTime = time();

            while (time() - $startTime < $maxRuntime) {
                if (connection_aborted()) {
                    Log::info('SSE stream: client disconnected', ['thread_id' => $threadId]);
                    break;
                }

                $events = $streamingService->readStreamEvents($threadId, $startId, 5000);

                if ($events !== []) {
                    foreach ($events as $ev) {
                        $id = $ev['id'];
                        $startId = $id;
                        $eventType = $ev['event'];
                        $data = $ev['data'];

                        if ($eventType === 'stream_start') {
                            continue;
                        }

                        $payload = ['type' => $eventType, 'timestamp' => $data['timestamp'] ?? now()->toISOString()];
                        if (isset($data['content'])) {
                            $payload['content'] = $data['content'];
                        }
                        if (isset($data['tool_info'])) {
                            $payload['tool_info'] = $data['tool_info'];
                        }
                        if (isset($data['tokens'])) {
                            $payload['tokens'] = $data['tokens'];
                        }
                        if (isset($data['error'])) {
                            $payload['error'] = $data['error'];
                        }

                        echo "id: {$id}\n";
                        echo "data: " . json_encode($payload) . "\n\n";
                        if (ob_get_level()) {
                            ob_flush();
                        }
                        flush();
                        $lastHeartbeat = time();
                    }

                    $last = end($events);
                    if ($last && in_array($last['event'], ['stream_end', 'stream_error'], true)) {
                        break;
                    }
                } else {
                    if (time() - $lastHeartbeat >= 15) {
                        echo ": heartbeat\n\n";
                        if (ob_get_level()) {
                            ob_flush();
                        }
                        flush();
                        $lastHeartbeat = time();
                    }
                }
            }
        }, 200, $this->getSseHeaders());
    }

    /**
     * Endpoint SSE para reconexión.
     * Lee desde Redis Stream desde Last-Event-ID (o desde el inicio); el cliente no pierde chunks.
     */
    public function resume(Request $request, string $threadId, StreamingService $streamingService): StreamedResponse
    {
        $user = $request->user();

        Log::info('SSE resume requested', ['thread_id' => $threadId, 'user_id' => $user->id]);

        $conversation = Conversation::where('thread_id', $threadId)
            ->where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->first();

        if (!$conversation) {
            Log::warning('SSE resume: conversation not found', ['thread_id' => $threadId]);
            return response()->stream(function () {
                echo "event: error\n";
                echo "data: " . json_encode(['error' => 'Conversación no encontrada']) . "\n\n";
                if (ob_get_level()) ob_flush();
                flush();
            }, 200, $this->getSseHeaders());
        }

        $state = $streamingService->getStreamState($threadId);
        $lastEventId = $request->header('Last-Event-ID', '0');

        if (!$state) {
            $meta = $conversation->fresh()->meta ?? [];
            if ($meta['streaming'] ?? false) {
                unset($meta['streaming'], $meta['streaming_started_at'], $meta['streaming_content'], $meta['active_tool']);
                $conversation->update(['meta' => $meta]);
            }
            return response()->stream(function () {
                @ini_set('output_buffering', 'off');
                while (ob_get_level()) {
                    ob_end_clean();
                }
                echo "id: 0\n";
                echo "data: " . json_encode([
                    'type' => 'no_active_stream',
                    'timestamp' => now()->toISOString(),
                ]) . "\n\n";
                if (ob_get_level()) ob_flush();
                flush();
            }, 200, $this->getSseHeaders());
        }

        return $this->stream($request, $threadId, $streamingService);
    }

    /**
     * Headers para Server-Sent Events
     */
    private function getSseHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no', // Desactivar buffering en nginx
        ];
    }

    /**
     * Obtener el progreso del stream de una conversación (para fallback/polling si SSE no funciona).
     */
    public function streamProgress(Request $request, string $threadId, StreamingService $streamingService): JsonResponse
    {
        $user = $request->user();
        
        // Verificar que el thread pertenece al usuario
        $conversation = Conversation::where('thread_id', $threadId)
            ->where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->first();
        
        if (!$conversation) {
            return response()->json([
                'error' => 'Conversación no encontrada o no tienes acceso.',
            ], 404);
        }

        // Intentar obtener estado de Redis primero (más actualizado)
        $state = $streamingService->getStreamState($threadId);
        
        if ($state) {
            return response()->json([
                'thread_id' => $threadId,
                'is_streaming' => $state['status'] === 'streaming',
                'content' => $state['content'],
                'is_completed' => $state['status'] === 'completed',
                'is_failed' => $state['status'] === 'failed',
                'error' => $state['error'],
                'active_tool' => $state['active_tool'],
                'total_tokens' => $conversation->total_tokens,
            ]);
        }

        // Fallback a la BD si no hay estado en Redis
        $meta = $conversation->meta ?? [];
        $isStreaming = $meta['streaming'] ?? false;
        $streamingContent = $meta['streaming_content'] ?? '';
        $activeToolName = $meta['active_tool'] ?? null;
        $lastError = $meta['last_error'] ?? null;

        // Obtener información de la herramienta activa
        $activeTool = null;
        if ($activeToolName) {
            $toolDescription = $this->getToolDisplayInfo($activeToolName);
            $activeTool = [
                'label' => $toolDescription['label'],
                'icon' => $toolDescription['icon'],
            ];
        }

        return response()->json([
            'thread_id' => $threadId,
            'is_streaming' => $isStreaming,
            'content' => $streamingContent,
            'is_completed' => !$isStreaming && empty($lastError),
            'is_failed' => !empty($lastError),
            'error' => $lastError,
            'active_tool' => $activeTool,
            'total_tokens' => $conversation->total_tokens,
        ]);
    }

    public function destroy(Request $request, string $threadId)
    {
        $user = $request->user();
        
        // Ensure conversation belongs to user's company
        $conversation = Conversation::where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->where('thread_id', $threadId)
            ->first();
        
        if ($conversation) {
            // Eliminar mensajes
            ChatMessage::where('thread_id', $threadId)->delete();
            
            // Eliminar conversación
            $conversation->delete();
        }
        
        return redirect()->route('copilot.index');
    }

    private function getFormattedMessages(string $threadId): array
    {
        return ChatMessage::where('thread_id', $threadId)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($message) {
                $content = $message->content;
                
                // Si el mensaje está en streaming, usar streaming_content
                if ($message->status === 'streaming' && $message->streaming_content) {
                    $content = $message->streaming_content;
                } else {
                    // Manejar diferentes formatos de contenido
                    if (is_array($content)) {
                        // Skip tool_call and tool_call_result messages (they have no visible content)
                        $type = $content['type'] ?? null;
                        if (in_array($type, ['tool_call', 'tool_call_result'])) {
                            return null;
                        }
                        
                        $content = $content['text'] ?? (is_string($content) ? $content : null);
                    }
                }
                
                // Skip empty messages
                if (empty($content) || (is_string($content) && trim($content) === '')) {
                    return null;
                }
                
                return [
                    'id' => $message->id,
                    'role' => $message->role,
                    'content' => $content,
                    'status' => $message->status,
                    'created_at' => $message->created_at->toISOString(),
                ];
            })
            ->filter() // Remove null entries
            ->values() // Re-index array
            ->toArray();
    }

    /**
     * Obtener información de display para cada herramienta
     */
    private function getToolDisplayInfo(string $toolName): array
    {
        $tools = [
            'GetVehicles' => [
                'label' => 'Consultando vehículos de la flota...',
                'icon' => 'truck',
            ],
            'GetVehicleStats' => [
                'label' => 'Obteniendo estadísticas en tiempo real...',
                'icon' => 'activity',
            ],
            'PGSQLSchemaTool' => [
                'label' => 'Explorando estructura de datos...',
                'icon' => 'database',
            ],
            'PGSQLSelectTool' => [
                'label' => 'Buscando información...',
                'icon' => 'search',
            ],
        ];

        return $tools[$toolName] ?? [
            'label' => 'Procesando...',
            'icon' => 'loader',
        ];
    }
}

