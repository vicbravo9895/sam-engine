<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\User;
use App\Neuron\CompanyContext;
use App\Neuron\FleetAgent;
use App\Neuron\Observers\TokenTrackingObserver;
use App\Pulse\Recorders\CopilotRecorder;
use App\Pulse\Recorders\TokenUsageRecorder;
use App\Services\StreamingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\LogObserver;

/**
 * Job para procesar mensajes del copilot en background.
 * 
 * Este job permite que el streaming continúe aunque el usuario
 * salga de la conversación, similar a cómo funciona OpenAI/ChatGPT.
 * 
 * IMPORTANTE: NeuronAI guarda automáticamente los mensajes del usuario y asistente
 * a través de EloquentChatHistory. No debemos crear mensajes duplicados.
 */
class ProcessCopilotMessageJob implements ShouldQueue
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
        public string $message,
        public string $threadId,
        public int $userId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(StreamingService $streamingService): void
    {
        $jobStartTime = microtime(true);
        $user = User::findOrFail($this->userId);
        
        // Validate user has a company
        if (!$user->company_id) {
            Log::error('User does not have a company', ['user_id' => $this->userId]);
            $streamingService->failStream($this->threadId, 'Usuario no asociado a ninguna empresa');
            $this->markConversationError('Usuario no asociado a ninguna empresa');
            return;
        }

        // Initialize company context
        CompanyContext::fromUser($user);

        // Obtener conversación
        $conversation = Conversation::where('thread_id', $this->threadId)
            ->where('user_id', $this->userId)
            ->first();

        // NOTA: Redis ya fue inicializado en el Controller ANTES de despachar este Job
        // Esto evita la race condition donde el SSE conecta antes de que Redis esté listo
        Log::info('Job started, Redis stream should already be initialized', ['thread_id' => $this->threadId]);

        try {
            // Detectar si es una consulta compleja que requiere GPT-4o avanzado
            $useAdvancedModel = $this->isComplexQuery($this->message);
            $model = $useAdvancedModel 
                ? config('services.openai.advanced_model')   // gpt-4o
                : config('services.openai.standard_model');  // gpt-4o-mini
            
            Log::info('Model selection', [
                'thread_id' => $this->threadId,
                'model' => $model,
                'is_complex' => $useAdvancedModel,
            ]);
            
            // Asegurar que las clases estén cargadas
            if (!class_exists(\NeuronAI\Agent::class)) {
                throw new \RuntimeException('NeuronAI\Agent class not found. Please run: composer dump-autoload');
            }
            
            // Usar el canal 'neuron' o 'single' como fallback
            $logger = null;
            try {
                $logger = Log::channel('neuron');
            } catch (\InvalidArgumentException $e) {
                $logger = Log::channel('single');
            }
            
            $tokenObserver = new TokenTrackingObserver(
                userId: $this->userId,
                threadId: $this->threadId,
                model: $model,
                logger: $logger
            );

            // Crear el agente con el thread correcto y contexto de empresa
            $agent = (new FleetAgent())
                ->forUser($user)
                ->withThread($this->threadId)
                ->withAdvancedModel($useAdvancedModel);

            // T5: Inject event context if conversation is linked to an alert
            if ($conversation && $conversation->hasEventContext() && $conversation->context_payload) {
                $agent->withEventContext($conversation->context_payload);
                Log::info('Copilot with event context', [
                    'thread_id' => $this->threadId,
                    'context_event_id' => $conversation->context_event_id,
                ]);
            }

            $agent->observe(new LogObserver($logger))
                ->observe($tokenObserver);

            // Usar streaming para obtener la respuesta chunk por chunk
            // NeuronAI guardará automáticamente el mensaje del usuario y del asistente
            $stream = $agent->stream(new UserMessage($this->message));
            
            $fullContent = '';
            $toolMeta = [];
            $lastSyncLength = 0; // Para tracking de sincronización con BD

            foreach ($stream as $chunk) {
                // Verificar si el job fue cancelado
                if ($this->job && $this->job->isDeleted()) {
                    Log::info('Job was deleted, stopping stream', [
                        'thread_id' => $this->threadId,
                    ]);
                    break;
                }

                // Manejar tool calls
                if ($chunk instanceof ToolCallMessage) {
                    $tools = [];
                    foreach ($chunk->getTools() as $tool) {
                        $toolName = $tool->getName();
                        $toolInfo = $this->getToolDisplayInfo($toolName);
                        $tools[] = [
                            'name' => $toolName,
                            'inputs' => $tool->getInputs(),
                        ];
                        
                        // Publicar tool start a Redis
                        $streamingService->publishToolStart($this->threadId, $toolName, $toolInfo);
                    }
                    $toolMeta[] = [
                        'type' => 'tool_start',
                        'tools' => $tools,
                        'timestamp' => now()->toISOString(),
                    ];
                    
                    // También actualizar BD para reconexión
                    if ($conversation) {
                        $conversation->update([
                            'meta' => array_merge($conversation->meta ?? [], [
                                'active_tool' => $tools[0]['name'] ?? null,
                            ]),
                        ]);
                    }
                    continue;
                }

                // Manejar tool results
                if ($chunk instanceof ToolCallResultMessage) {
                    $toolMeta[] = [
                        'type' => 'tool_end',
                        'timestamp' => now()->toISOString(),
                    ];
                    
                    // Publicar tool end a Redis
                    $streamingService->publishToolEnd($this->threadId);
                    
                    // Limpiar herramienta activa en BD
                    if ($conversation) {
                        $conversation->update([
                            'meta' => array_merge($conversation->meta ?? [], [
                                'active_tool' => null,
                            ]),
                        ]);
                    }
                    continue;
                }

                // Solo procesar chunks de texto
                if (!is_string($chunk)) {
                    continue;
                }

                // Acumular contenido
                $fullContent .= $chunk;

                // Publicar chunk a Redis (para SSE en tiempo real)
                $streamingService->publishChunk($this->threadId, $chunk);

                // Actualizar BD cada ~500 caracteres (para reconexión)
                // Esto evita demasiados writes a BD mientras mantiene el progreso sincronizado
                if (strlen($fullContent) - $lastSyncLength > 500) {
                    if ($conversation) {
                        $conversation->update([
                            'meta' => array_merge($conversation->meta ?? [], [
                                'streaming_content' => $fullContent,
                                'active_tool' => null,
                            ]),
                        ]);
                    }
                    $lastSyncLength = strlen($fullContent);
                }
            }

            // Obtener estadísticas de tokens
            $tokenStats = $tokenObserver->getTotalTokens();

            // Finalizar stream en Redis
            $streamingService->finishStream($this->threadId, $tokenStats);

            // Actualizar conversación con tokens y marcar streaming como completado
            if ($conversation) {
                $conversation->increment('total_input_tokens', $tokenStats['input_tokens'] ?? 0);
                $conversation->increment('total_output_tokens', $tokenStats['output_tokens'] ?? 0);
                $conversation->increment('total_tokens', $tokenStats['total_tokens'] ?? 0);
                
                // Limpiar estado de streaming
                $meta = $conversation->meta ?? [];
                unset($meta['streaming'], $meta['streaming_started_at'], $meta['streaming_content'], $meta['active_tool']);
                $conversation->update(['meta' => $meta]);
            }

            // Obtener el último mensaje del asistente (que NeuronAI guardó)
            // y actualizar su metadata con tokens y tools
            $lastAssistantMessage = ChatMessage::where('thread_id', $this->threadId)
                ->where('role', 'assistant')
                ->orderBy('id', 'desc')
                ->first();

            if ($lastAssistantMessage) {
                $lastAssistantMessage->update([
                    'status' => 'completed',
                    'streaming_completed_at' => now(),
                    'meta' => array_merge($lastAssistantMessage->meta ?? [], [
                        'tokens' => $tokenStats,
                        'tools' => $toolMeta,
                    ]),
                ]);
            }

            // Calcular duración total del job para métricas
            $jobEndTime = microtime(true);
            $jobStartTime = $jobStartTime ?? $jobEndTime; // Fallback si no está definido
            $jobDurationMs = (int) round(($jobEndTime - ($jobStartTime ?? $jobEndTime)) * 1000);

            // Registrar métricas del Copilot en Pulse
            $toolsUsedNames = collect($toolMeta)
                ->filter(fn($t) => ($t['type'] ?? '') === 'tool_start')
                ->flatMap(fn($t) => collect($t['tools'] ?? [])->pluck('name'))
                ->unique()
                ->values()
                ->toArray();

            CopilotRecorder::recordCopilotMessage(
                userId: $this->userId,
                durationMs: $jobDurationMs,
                toolsUsed: $toolsUsedNames,
                model: $model
            );

            // Registrar consumo de tokens en Pulse
            if (($tokenStats['total_tokens'] ?? 0) > 0) {
                TokenUsageRecorder::recordTokenUsage(
                    model: $model,
                    inputTokens: $tokenStats['input_tokens'] ?? 0,
                    outputTokens: $tokenStats['output_tokens'] ?? 0,
                    userId: $this->userId,
                    requestType: 'copilot'
                );
            }

            Log::info('Copilot message processed successfully', [
                'thread_id' => $this->threadId,
                'message_id' => $lastAssistantMessage->id ?? null,
                'tokens' => $tokenStats,
            ]);

        } catch (\Throwable $e) {
            Log::error('Error processing copilot message', [
                'thread_id' => $this->threadId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Notificar error via Redis
            $streamingService->failStream($this->threadId, $e->getMessage());
            $this->markConversationError($e->getMessage());
            
            throw $e;
        }
    }
    
    /**
     * Detectar si el mensaje requiere el modelo avanzado (GPT-4o).
     * 
     * Usa GPT-4o para:
     * - Reportes completos/detallados
     * - Análisis multi-vehículo
     * - Consultas que requieren múltiples tools
     */
    private function isComplexQuery(string $message): bool
    {
        $messageLower = mb_strtolower($message);
        
        // Patrones que indican consulta compleja
        $complexPatterns = [
            'reporte completo',
            'reporte detallado',
            'estado completo',
            'resumen completo',
            'análisis completo',
            'informe completo',
            'dame todo',
            'toda la información',
            'todos los datos',
            'reporte de flota',
            'estado de la flota',
            'todos los vehículos',
            'comparar',
            'comparativo',
        ];
        
        foreach ($complexPatterns as $pattern) {
            if (str_contains($messageLower, $pattern)) {
                return true;
            }
        }
        
        return false;
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

    /**
     * Mark the conversation with an error.
     */
    private function markConversationError(string $error): void
    {
        $conversation = Conversation::where('thread_id', $this->threadId)
            ->where('user_id', $this->userId)
            ->first();

        if ($conversation) {
            $meta = $conversation->meta ?? [];
            unset($meta['streaming'], $meta['streaming_started_at'], $meta['streaming_content'], $meta['active_tool']);
            $meta['last_error'] = $error;
            $meta['error_at'] = now()->toISOString();
            $conversation->update(['meta' => $meta]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessCopilotMessageJob failed', [
            'thread_id' => $this->threadId,
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);

        $this->markConversationError($exception->getMessage());
    }
}
