<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Neuron\CompanyContext;
use App\Neuron\FleetAgent;
use App\Neuron\Observers\TokenTrackingObserver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Observability\LogObserver;
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
        
        return Inertia::render('copilot', [
            'conversations' => $conversations,
            'currentConversation' => [
                ...$currentConversation->toArray(),
                'total_tokens' => $currentConversation->total_tokens,
            ],
            'messages' => $messages,
        ]);
    }

    public function send(Request $request): StreamedResponse|JsonResponse
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
                'company_id' => $user->company_id, // Associate with company
                'title' => $title,
            ]);
        }
        
        // Actualizar el timestamp de la conversación (only for user's company)
        Conversation::where('thread_id', $threadId)
            ->where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->update(['updated_at' => now()]);
        
        $userId = $user->id;
        $model = config('services.openai.standard_model');
        
        // Initialize company context for the request
        CompanyContext::fromUser($user);

        return new StreamedResponse(function () use ($message, $threadId, $isNewConversation, $userId, $model, $user) {
            // Deshabilitar output buffering para streaming
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            
            // Función helper para flush seguro
            $safeFlush = function () {
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };
            
            // Enviar evento inicial con thread_id
            echo "data: " . json_encode([
                'type' => 'start',
                'thread_id' => $threadId,
                'is_new_conversation' => $isNewConversation,
            ]) . "\n\n";
            $safeFlush();
            
            // Observer para tracking de tokens
            $tokenObserver = new TokenTrackingObserver(
                userId: $userId,
                threadId: $threadId,
                model: $model,
                logger: Log::channel('neuron')
            );

            // Crear el agente con el thread correcto y contexto de empresa
            $agent = (new FleetAgent())
                ->forUser($user)
                ->withThread($threadId)
                ->observe(new LogObserver(Log::channel('neuron')))
                ->observe($tokenObserver);
            
            // Usar streaming para obtener la respuesta chunk por chunk
            $stream = $agent->stream(new UserMessage($message));
            
            foreach ($stream as $chunk) {
                // Enviar evento cuando se está llamando a una herramienta
                if ($chunk instanceof ToolCallMessage) {
                    foreach ($chunk->getTools() as $tool) {
                        $toolName = $tool->getName();
                        $toolDescription = $this->getToolDisplayInfo($toolName);
                        
                        echo "data: " . json_encode([
                            'type' => 'tool_start',
                            'tool' => $toolName,
                            'label' => $toolDescription['label'],
                            'icon' => $toolDescription['icon'],
                        ]) . "\n\n";
                        $safeFlush();
                    }
                    continue;
                }
                
                // Enviar evento cuando una herramienta termina
                if ($chunk instanceof ToolCallResultMessage) {
                    echo "data: " . json_encode([
                        'type' => 'tool_end',
                    ]) . "\n\n";
                    $safeFlush();
                    continue;
                }
                
                // Only send string chunks
                if (!is_string($chunk)) {
                    continue;
                }
                
                // Enviar cada chunk como evento SSE
                echo "data: " . json_encode([
                    'type' => 'chunk',
                    'content' => $chunk,
                ]) . "\n\n";
                $safeFlush();
            }
            
            // Obtener estadísticas de tokens
            $tokenStats = $tokenObserver->getTotalTokens();

            // Enviar evento de finalización con tokens
            echo "data: " . json_encode([
                'type' => 'done',
                'thread_id' => $threadId,
                'tokens' => $tokenStats,
            ]) . "\n\n";
            $safeFlush();
            
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
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
                
                // Manejar diferentes formatos de contenido
                if (is_array($content)) {
                    // Skip tool_call and tool_call_result messages (they have no visible content)
                    $type = $content['type'] ?? null;
                    if (in_array($type, ['tool_call', 'tool_call_result'])) {
                        return null;
                    }
                    
                    $content = $content['text'] ?? (is_string($content) ? $content : null);
                }
                
                // Skip empty messages
                if (empty($content) || (is_string($content) && trim($content) === '')) {
                    return null;
                }
                
                return [
                    'id' => $message->id,
                    'role' => $message->role,
                    'content' => $content,
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

