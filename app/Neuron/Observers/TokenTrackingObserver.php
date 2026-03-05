<?php

declare(strict_types=1);

namespace App\Neuron\Observers;

use App\Models\TokenUsage;
use NeuronAI\Observability\Events\MessageSaved;
use NeuronAI\Observability\ObserverInterface;
use Psr\Log\LoggerInterface;

class TokenTrackingObserver implements ObserverInterface
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

    public function onEvent(string $event, object $source, mixed $data = null): void
    {
        if (!$data instanceof MessageSaved) {
            return;
        }

        $message = $data->message;
        $usage = $message->getUsage();

        if ($usage === null) {
            return;
        }

        $inputTokens = $usage->inputTokens;
        $outputTokens = $usage->outputTokens;

        if ($inputTokens === 0 && $outputTokens === 0) {
            return;
        }

        $this->totalInputTokens += $inputTokens;
        $this->totalOutputTokens += $outputTokens;

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

