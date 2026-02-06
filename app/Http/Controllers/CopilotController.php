<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCopilotMessageJob;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\SamsaraEvent;
use App\Services\StreamingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Illuminate\Http\JsonResponse;

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
                'context_event_id' => $currentConversation->context_event_id,
                'context_payload' => $currentConversation->context_payload,
            ],
            'messages' => $messages,
        ]);
    }

    public function send(Request $request, StreamingService $streamingService): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:10000',
            'thread_id' => 'nullable|string',
            'context_event_id' => 'nullable|integer',
        ]);
        
        $user = $request->user();
        $message = $request->input('message');
        $threadId = $request->input('thread_id');
        $contextEventId = $request->input('context_event_id');
        $isNewConversation = false;
        
        // Validate user has a company
        if (!$user->company_id) {
            return response()->json([
                'error' => 'No estás asociado a ninguna empresa. Contacta al administrador.',
            ], 403);
        }

        // T5: Validate context event belongs to user's company (multi-tenant guard)
        $contextPayload = null;
        if ($contextEventId) {
            $event = SamsaraEvent::find($contextEventId);

            if (!$event || $event->company_id !== $user->company_id) {
                return response()->json([
                    'error' => 'Evento no encontrado o no pertenece a tu empresa.',
                ], 403);
            }

            $contextPayload = $this->buildEventContextPayload($event);
        }
        
        // Si no hay thread_id, crear una nueva conversación
        if (!$threadId) {
            $threadId = Str::uuid()->toString();
            $isNewConversation = true;
            
            // Generar título: si hay contexto de evento, usar info del evento
            $title = $contextEventId
                ? Str::limit("Alerta: " . ($contextPayload['vehicle_name'] ?? '') . " — " . ($contextPayload['event_description'] ?? ''), 60)
                : Str::limit($message, 50);
            
            Conversation::create([
                'thread_id' => $threadId,
                'user_id' => $user->id,
                'company_id' => $user->company_id,
                'title' => $title,
                'context_event_id' => $contextEventId,
                'context_payload' => $contextPayload,
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
     * Obtener el progreso del stream de una conversación (fallback/polling endpoint).
     * 
     * Now that we use WebSockets, this endpoint is mainly used as a fallback
     * for clients that can't connect to WebSocket (e.g., old browsers, proxies).
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
            'GetFleetStatus' => [
                'label' => 'Consultando estado de la flota...',
                'icon' => 'layout-grid',
            ],
            'GetDashcamMedia' => [
                'label' => 'Obteniendo imágenes de dashcam...',
                'icon' => 'camera',
            ],
            'GetSafetyEvents' => [
                'label' => 'Buscando eventos de seguridad...',
                'icon' => 'shield-alert',
            ],
            'GetTags' => [
                'label' => 'Consultando grupos y etiquetas...',
                'icon' => 'tags',
            ],
            'GetTrips' => [
                'label' => 'Obteniendo viajes recientes...',
                'icon' => 'route',
            ],
            'GetDrivers' => [
                'label' => 'Buscando información de conductores...',
                'icon' => 'users',
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

    /**
     * T5: Build the context payload from a SamsaraEvent for the copilot.
     * 
     * This payload is stored in the conversation and injected into the
     * FleetAgent system prompt so the copilot knows the operational context.
     */
    private function buildEventContextPayload(SamsaraEvent $event): array
    {
        $alertContext = $event->alert_context ?? [];

        return [
            'event_id' => $event->id,
            'samsara_event_id' => $event->samsara_event_id,
            'event_type' => $event->event_type,
            'event_description' => $event->event_description,
            'severity' => $event->severity,
            'vehicle_id' => $event->vehicle_id,
            'vehicle_name' => $event->vehicle_name,
            'driver_id' => $event->driver_id,
            'driver_name' => $event->driver_name,
            'occurred_at' => $event->occurred_at?->toISOString(),
            'location_description' => $alertContext['location_description'] ?? null,
            'alert_kind' => $event->alert_kind ?? ($alertContext['alert_kind'] ?? null),
            'ai_status' => $event->ai_status,
            'ai_message' => $event->ai_message,
            'verdict' => $event->verdict ?? ($event->ai_assessment['verdict'] ?? null),
            'likelihood' => $event->likelihood ?? ($event->ai_assessment['likelihood'] ?? null),
            'reasoning' => $event->reasoning ?? ($event->ai_assessment['reasoning'] ?? null),
            'time_window' => $alertContext['time_window'] ?? null,
        ];
    }
}

