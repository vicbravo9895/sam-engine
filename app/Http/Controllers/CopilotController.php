<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCopilotMessageJob;
use App\Models\Alert;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Driver;
use App\Models\Tag;
use App\Models\Vehicle;
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
            'vehicles' => $this->getVehiclesForPicker($user->company_id),
            'drivers' => $this->getDriversForPicker($user->company_id),
            'tags' => $this->getTagsForPicker($user->company_id),
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
            'vehicles' => $this->getVehiclesForPicker($user->company_id),
            'drivers' => $this->getDriversForPicker($user->company_id),
            'tags' => $this->getTagsForPicker($user->company_id),
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

        $contextPayload = null;
        if ($contextEventId) {
            $alert = Alert::with('signal')->find($contextEventId);

            if (!$alert || $alert->company_id !== $user->company_id) {
                return response()->json([
                    'error' => 'Alerta no encontrada o no pertenece a tu empresa.',
                ], 403);
            }

            $contextPayload = $this->buildAlertContextPayload($alert);
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
     * Get a lightweight list of vehicles for the copilot vehicle picker.
     * 
     * Returns only the fields needed for search/display, ordered by name.
     */
    private function getVehiclesForPicker(int $companyId): array
    {
        return Vehicle::forCompany($companyId)
            ->orderBy('name')
            ->get(['id', 'name', 'license_plate', 'make', 'model', 'year'])
            ->map(fn (Vehicle $v) => [
                'id' => $v->id,
                'name' => $v->name,
                'license_plate' => $v->license_plate,
                'make' => $v->make,
                'model' => $v->model,
                'year' => $v->year,
            ])
            ->toArray();
    }

    /**
     * Get a lightweight list of active drivers for the copilot driver picker.
     */
    private function getDriversForPicker(int $companyId): array
    {
        return Driver::forCompany($companyId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'license_number', 'driver_activation_status', 'static_assigned_vehicle'])
            ->map(fn (Driver $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'phone' => $d->phone,
                'license_number' => $d->license_number,
                'status' => $d->driver_activation_status,
                'assigned_vehicle_name' => $d->assigned_vehicle_name,
            ])
            ->toArray();
    }

    /**
     * Get a lightweight list of tags for the copilot tag picker.
     */
    private function getTagsForPicker(int $companyId): array
    {
        return Tag::forCompany($companyId)
            ->orderBy('name')
            ->get(['id', 'samsara_id', 'name', 'parent_tag_id', 'vehicles', 'drivers'])
            ->map(fn (Tag $t) => [
                'id' => $t->id,
                'samsara_id' => $t->samsara_id,
                'name' => $t->name,
                'parent_tag_id' => $t->parent_tag_id,
                'vehicle_count' => $t->vehicle_count,
                'driver_count' => $t->driver_count,
            ])
            ->toArray();
    }

    /**
     * Build the context payload from an Alert for the copilot.
     * 
     * This payload is stored in the conversation and injected into the
     * FleetAgent system prompt so the copilot knows the operational context.
     */
    private function buildAlertContextPayload(Alert $alert): array
    {
        $signal = $alert->signal;
        $ai = $alert->ai;
        $alertContext = $ai?->alert_context ?? [];

        return [
            'alert_id' => $alert->id,
            'samsara_event_id' => $signal?->samsara_event_id,
            'event_type' => $signal?->event_type,
            'event_description' => $alert->event_description,
            'severity' => $alert->severity,
            'vehicle_id' => $signal?->vehicle_id,
            'vehicle_name' => $signal?->vehicle_name,
            'driver_id' => $signal?->driver_id,
            'driver_name' => $signal?->driver_name,
            'occurred_at' => $alert->occurred_at?->toISOString(),
            'location_description' => $alertContext['location_description'] ?? null,
            'alert_kind' => $alert->alert_kind ?? ($alertContext['alert_kind'] ?? null),
            'ai_status' => $alert->ai_status,
            'ai_message' => $alert->ai_message,
            'verdict' => $alert->verdict,
            'likelihood' => $alert->likelihood,
            'reasoning' => $alert->reasoning,
            'time_window' => $alertContext['time_window'] ?? null,
        ];
    }
}

