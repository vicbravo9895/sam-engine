<?php

declare(strict_types=1);

namespace App\Neuron\Observers;

use App\Models\TokenUsage;
use NeuronAI\Observability\Events\MessageSaved;
use Psr\Log\LoggerInterface;
use SplObserver;
use SplSubject;

class TokenTrackingObserver implements SplObserver
{
    protected int $totalInputTokens = 0;
    protected int $totalOutputTokens = 0;

    public function __construct(
        protected int $userId,
        protected ?string $threadId = null,
        protected ?string $model = null,
        protected ?LoggerInterface $logger = null
    ) {
    }

    public function update(SplSubject $subject, ?string $event = null, mixed $data = null): void
    {
        // Solo nos interesa el evento message-saved
        if ($event !== 'message-saved' || !$data instanceof MessageSaved) {
            return;
        }

        $message = $data->message;
        $usage = $message->getUsage();

        if ($usage === null) {
            return;
        }

        $inputTokens = $usage->inputTokens;
        $outputTokens = $usage->outputTokens;

        // Solo registrar si hay tokens
        if ($inputTokens === 0 && $outputTokens === 0) {
            return;
        }

        // Acumular tokens
        $this->totalInputTokens += $inputTokens;
        $this->totalOutputTokens += $outputTokens;

        // Registrar en la base de datos
        TokenUsage::record(
            userId: $this->userId,
            threadId: $this->threadId,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            model: $this->model,
            requestType: $message->getRole(),
            meta: [
                'content_length' => is_string($message->getContent()) 
                    ? strlen($message->getContent()) 
                    : 0,
            ]
        );

        // Log si hay logger
        if ($this->logger) {
            $this->logger->info('tokens-recorded', [
                'user_id' => $this->userId,
                'thread_id' => $this->threadId,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens,
            ]);
        }
    }

    /**
     * Obtener el total de tokens usados en esta sesión
     */
    public function getTotalTokens(): array
    {
        return [
            'input_tokens' => $this->totalInputTokens,
            'output_tokens' => $this->totalOutputTokens,
            'total_tokens' => $this->totalInputTokens + $this->totalOutputTokens,
        ];
    }
}

