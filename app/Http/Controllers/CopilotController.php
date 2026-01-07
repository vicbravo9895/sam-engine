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
     * Usa un loop que lee el estado de Redis periódicamente.
     */
    public function stream(Request $request, string $threadId, StreamingService $streamingService): StreamedResponse
    {
        $user = $request->user();
        
        Log::info('SSE stream requested', ['thread_id' => $threadId, 'user_id' => $user->id]);
        
        // Verificar que el thread pertenece al usuario
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

        return response()->stream(function () use ($threadId, $streamingService) {
            // Desactivar buffering para SSE
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', false);
            while (ob_get_level()) ob_end_clean();
            
            Log::info('SSE stream started', ['thread_id' => $threadId]);
            
            $lastEventId = 0;
            $lastContent = '';
            $lastToolState = null;
            $maxIterations = 3000; // 5 minutos máximo (3000 * 100ms = 300 segundos)
            $iteration = 0;
            $waitingForStream = true;
            $lastHeartbeat = time();
            
            // Loop que lee el estado de Redis periódicamente
            while ($iteration < $maxIterations) {
                $iteration++;
                
                // Verificar si el cliente se desconectó
                if (connection_aborted()) {
                    Log::info('SSE stream: client disconnected', ['thread_id' => $threadId]);
                    break;
                }
                
                $state = $streamingService->getStreamState($threadId);
                
                if (!$state) {
                    if ($waitingForStream && $iteration % 10 === 0) {
                        Log::debug('SSE stream: waiting for Redis state', ['thread_id' => $threadId, 'iteration' => $iteration]);
                    }
                    // Esperar a que el Job inicialice el stream
                    usleep(100000); // 100ms
                    continue;
                }
                
                if ($waitingForStream) {
                    Log::info('SSE stream: Redis state found', ['thread_id' => $threadId, 'status' => $state['status']]);
                    $waitingForStream = false;
                }
                
                // Enviar chunk si hay contenido nuevo
                $currentContent = $state['content'] ?? '';
                if ($currentContent !== $lastContent) {
                    $newContent = substr($currentContent, strlen($lastContent));
                    if ($newContent !== '') {
                        $lastEventId++;
                        echo "id: {$lastEventId}\n";
                        echo "data: " . json_encode([
                            'type' => 'chunk',
                            'content' => $newContent,
                            'timestamp' => now()->toISOString(),
                        ]) . "\n\n";
                        if (ob_get_level()) ob_flush();
                        flush();
                        $lastHeartbeat = time();
                    }
                    $lastContent = $currentContent;
                }
                
                // Enviar cambio de tool si es diferente
                $currentTool = $state['active_tool'];
                if ($currentTool !== $lastToolState) {
                    $lastEventId++;
                    echo "id: {$lastEventId}\n";
                    if ($currentTool) {
                        echo "data: " . json_encode([
                            'type' => 'tool_start',
                            'tool_info' => $currentTool,
                            'timestamp' => now()->toISOString(),
                        ]) . "\n\n";
                    } else if ($lastToolState) {
                        echo "data: " . json_encode([
                            'type' => 'tool_end',
                            'timestamp' => now()->toISOString(),
                        ]) . "\n\n";
                    }
                    if (ob_get_level()) ob_flush();
                    flush();
                    $lastToolState = $currentTool;
                    $lastHeartbeat = time();
                }
                
                // Enviar heartbeat cada 15 segundos para mantener la conexión viva
                if (time() - $lastHeartbeat >= 15) {
                    echo ": heartbeat\n\n";
                    if (ob_get_level()) ob_flush();
                    flush();
                    $lastHeartbeat = time();
                }
                
                // Si el stream terminó, enviar evento final y salir
                if ($state['status'] === 'completed') {
                    $lastEventId++;
                    echo "id: {$lastEventId}\n";
                    echo "data: " . json_encode([
                        'type' => 'stream_end',
                        'tokens' => $state['tokens'],
                        'timestamp' => now()->toISOString(),
                    ]) . "\n\n";
                    if (ob_get_level()) ob_flush();
                    flush();
                    break;
                }
                
                if ($state['status'] === 'failed') {
                    $lastEventId++;
                    echo "id: {$lastEventId}\n";
                    echo "data: " . json_encode([
                        'type' => 'stream_error',
                        'error' => $state['error'] ?? 'Error desconocido',
                        'timestamp' => now()->toISOString(),
                    ]) . "\n\n";
                    if (ob_get_level()) ob_flush();
                    flush();
                    break;
                }
                
                // Esperar antes de la próxima iteración
                usleep(100000); // 100ms
            }
        }, 200, $this->getSseHeaders());
    }

    /**
     * Endpoint SSE para reconexión.
     * Primero envía todo el contenido acumulado, luego continúa el stream si está activo.
     */
    public function resume(Request $request, string $threadId, StreamingService $streamingService): StreamedResponse
    {
        $user = $request->user();
        
        Log::info('SSE resume requested', ['thread_id' => $threadId, 'user_id' => $user->id]);
        
        // Verificar que el thread pertenece al usuario
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

        return response()->stream(function () use ($threadId, $streamingService, $conversation) {
            // Desactivar buffering para SSE
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', false);
            while (ob_get_level()) ob_end_clean();
            
            Log::info('SSE resume stream started', ['thread_id' => $threadId]);
            
            $lastEventId = 0;
            
            // 1. Enviar estado inicial (contenido acumulado)
            $state = $streamingService->getStreamState($threadId);
            
            Log::info('SSE resume: Redis state', [
                'thread_id' => $threadId,
                'has_state' => $state !== null,
                'status' => $state['status'] ?? 'no-state',
                'content_length' => strlen($state['content'] ?? ''),
            ]);
            
            // Si hay estado en Redis, usarlo; si no, usar el de la BD
            if ($state) {
                $lastEventId++;
                echo "id: {$lastEventId}\n";
                echo "event: resume_state\n";
                echo "data: " . json_encode([
                    'type' => 'resume_state',
                    'content' => $state['content'],
                    'active_tool' => $state['active_tool'],
                    'status' => $state['status'],
                    'timestamp' => now()->toISOString(),
                ]) . "\n\n";
                if (ob_get_level()) ob_flush();
                flush();
                
                // Si ya terminó, enviar evento de fin y salir
                if ($state['status'] !== 'streaming') {
                    $lastEventId++;
                    echo "id: {$lastEventId}\n";
                    
                    if ($state['status'] === 'completed') {
                        echo "data: " . json_encode([
                            'type' => 'stream_end',
                            'tokens' => $state['tokens'],
                            'timestamp' => now()->toISOString(),
                        ]) . "\n\n";
                    } else {
                        echo "data: " . json_encode([
                            'type' => 'stream_error',
                            'error' => $state['error'] ?? 'Error desconocido',
                            'timestamp' => now()->toISOString(),
                        ]) . "\n\n";
                    }
                    if (ob_get_level()) ob_flush();
                    flush();
                    return;
                }
            } else {
                // No hay estado en Redis - el stream probablemente ya terminó o falló
                // Limpiar el flag de streaming en la BD si estaba activo
                $meta = $conversation->fresh()->meta ?? [];
                $isStreaming = $meta['streaming'] ?? false;
                
                if ($isStreaming) {
                    Log::warning('SSE resume: BD says streaming but no Redis state', ['thread_id' => $threadId]);
                    // Limpiar flag de streaming stale
                    unset($meta['streaming'], $meta['streaming_started_at'], $meta['streaming_content'], $meta['active_tool']);
                    $conversation->update(['meta' => $meta]);
                }
                
                // No hay stream activo
                $lastEventId++;
                echo "id: {$lastEventId}\n";
                echo "data: " . json_encode([
                    'type' => 'no_active_stream',
                    'timestamp' => now()->toISOString(),
                ]) . "\n\n";
                if (ob_get_level()) ob_flush();
                flush();
                return;
            }
            
            // 2. Continuar leyendo el estado de Redis para chunks futuros
            $lastContent = $state['content'] ?? '';
            $lastToolState = $state['active_tool'] ?? null;
            $maxIterations = 3000; // 5 minutos máximo (3000 * 100ms = 300s)
            $iteration = 0;
            $lastHeartbeat = time();
            
            while ($iteration < $maxIterations) {
                $iteration++;
                
                if (connection_aborted()) {
                    break;
                }
                
                $state = $streamingService->getStreamState($threadId);
                
                if (!$state) {
                    usleep(100000);
                    continue;
                }
                
                // Enviar chunk si hay contenido nuevo
                $currentContent = $state['content'] ?? '';
                if ($currentContent !== $lastContent) {
                    $newContent = substr($currentContent, strlen($lastContent));
                    if ($newContent !== '') {
                        $lastEventId++;
                        echo "id: {$lastEventId}\n";
                        echo "data: " . json_encode([
                            'type' => 'chunk',
                            'content' => $newContent,
                            'timestamp' => now()->toISOString(),
                        ]) . "\n\n";
                        if (ob_get_level()) ob_flush();
                        flush();
                        $lastHeartbeat = time();
                    }
                    $lastContent = $currentContent;
                }
                
                // Enviar cambio de tool
                $currentTool = $state['active_tool'];
                if ($currentTool !== $lastToolState) {
                    $lastEventId++;
                    echo "id: {$lastEventId}\n";
                    if ($currentTool) {
                        echo "data: " . json_encode([
                            'type' => 'tool_start',
                            'tool_info' => $currentTool,
                            'timestamp' => now()->toISOString(),
                        ]) . "\n\n";
                    } else if ($lastToolState) {
                        echo "data: " . json_encode([
                            'type' => 'tool_end',
                            'timestamp' => now()->toISOString(),
                        ]) . "\n\n";
                    }
                    if (ob_get_level()) ob_flush();
                    flush();
                    $lastToolState = $currentTool;
                    $lastHeartbeat = time();
                }
                
                // Enviar heartbeat cada 15 segundos para mantener la conexión viva
                if (time() - $lastHeartbeat >= 15) {
                    echo ": heartbeat\n\n";
                    if (ob_get_level()) ob_flush();
                    flush();
                    $lastHeartbeat = time();
                }
                
                // Si el stream terminó
                if ($state['status'] === 'completed') {
                    $lastEventId++;
                    echo "id: {$lastEventId}\n";
                    echo "data: " . json_encode([
                        'type' => 'stream_end',
                        'tokens' => $state['tokens'],
                        'timestamp' => now()->toISOString(),
                    ]) . "\n\n";
                    if (ob_get_level()) ob_flush();
                    flush();
                    break;
                }
                
                if ($state['status'] === 'failed') {
                    $lastEventId++;
                    echo "id: {$lastEventId}\n";
                    echo "data: " . json_encode([
                        'type' => 'stream_error',
                        'error' => $state['error'] ?? 'Error desconocido',
                        'timestamp' => now()->toISOString(),
                    ]) . "\n\n";
                    if (ob_get_level()) ob_flush();
                    flush();
                    break;
                }
                
                usleep(100000); // 100ms
            }
        }, 200, $this->getSseHeaders());
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

