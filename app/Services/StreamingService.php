<?php

namespace App\Services;

use App\Events\CopilotStreamEvent;
use Illuminate\Support\Facades\Redis;

/**
 * Servicio para manejar streaming de mensajes del Copilot.
 *
 * Usa WebSockets (Laravel Reverb) para enviar eventos en tiempo real.
 * Mantiene un hash de Redis para estado y fallback (streamProgress polling).
 */
class StreamingService
{
    /**
     * Prefijo para las keys de Redis (hash de estado)
     */
    private const PREFIX = 'copilot:stream:';

    /**
     * TTL para el estado del stream (10 minutos)
     */
    private const STATE_TTL = 600;

    /**
     * Inicializa un nuevo stream para un thread.
     * Crea el hash de estado en Redis.
     */
    public function initStream(string $threadId): void
    {
        $key = self::PREFIX . $threadId;

        Redis::hset($key, [
            'status' => 'streaming',
            'content' => '',
            'active_tool' => '',
            'started_at' => now()->toISOString(),
            'last_update' => now()->toISOString(),
        ]);
        Redis::expire($key, self::STATE_TTL);
    }

    /**
     * Agrega un chunk de texto al stream.
     * Broadcasts via WebSocket y actualiza el hash de estado.
     */
    public function publishChunk(string $threadId, string $chunk): void
    {
        // Broadcast via WebSocket
        broadcast(CopilotStreamEvent::chunk($threadId, $chunk));

        // Update Redis state for fallback/reconnection
        $key = self::PREFIX . $threadId;
        $currentContent = Redis::hget($key, 'content') ?? '';
        Redis::hset($key, [
            'content' => $currentContent . $chunk,
            'last_update' => now()->toISOString(),
            'active_tool' => '',
        ]);
        Redis::expire($key, self::STATE_TTL);
    }

    /**
     * Marca inicio de tool call.
     * Broadcasts via WebSocket y actualiza el hash de estado.
     */
    public function publishToolStart(string $threadId, string $toolName, array $toolInfo): void
    {
        // Broadcast via WebSocket
        broadcast(CopilotStreamEvent::toolStart($threadId, $toolInfo));

        // Update Redis state
        $key = self::PREFIX . $threadId;
        Redis::hset($key, [
            'active_tool' => json_encode($toolInfo),
            'last_update' => now()->toISOString(),
        ]);
        Redis::expire($key, self::STATE_TTL);
    }

    /**
     * Marca fin de tool call.
     * Broadcasts via WebSocket y actualiza el hash de estado.
     */
    public function publishToolEnd(string $threadId): void
    {
        // Broadcast via WebSocket
        broadcast(CopilotStreamEvent::toolEnd($threadId));

        // Update Redis state
        $key = self::PREFIX . $threadId;
        Redis::hset($key, [
            'active_tool' => '',
            'last_update' => now()->toISOString(),
        ]);
        Redis::expire($key, self::STATE_TTL);
    }

    /**
     * Finaliza el stream exitosamente.
     * Broadcasts via WebSocket y actualiza el hash de estado.
     */
    public function finishStream(string $threadId, array $tokenStats = []): void
    {
        // Broadcast via WebSocket
        broadcast(CopilotStreamEvent::streamEnd($threadId, $tokenStats));

        // Update Redis state
        $key = self::PREFIX . $threadId;
        Redis::hset($key, [
            'status' => 'completed',
            'last_update' => now()->toISOString(),
            'completed_at' => now()->toISOString(),
            'tokens' => json_encode($tokenStats),
        ]);
        // Short TTL after completion - state no longer needed
        Redis::expire($key, 60);
    }

    /**
     * Marca el stream como fallido.
     * Broadcasts via WebSocket y actualiza el hash de estado.
     */
    public function failStream(string $threadId, string $error): void
    {
        // Broadcast via WebSocket
        broadcast(CopilotStreamEvent::streamError($threadId, $error));

        // Update Redis state
        $key = self::PREFIX . $threadId;
        Redis::hset($key, [
            'status' => 'failed',
            'error' => $error,
            'last_update' => now()->toISOString(),
            'failed_at' => now()->toISOString(),
        ]);
        // Short TTL after failure
        Redis::expire($key, 60);
    }

    /**
     * Obtiene el estado actual del stream.
     * Used by streamProgress fallback endpoint.
     */
    public function getStreamState(string $threadId): ?array
    {
        $key = self::PREFIX . $threadId;
        $data = Redis::hgetall($key);
        
        if (empty($data)) {
            return null;
        }
        
        return [
            'status' => $data['status'] ?? 'unknown',
            'content' => $data['content'] ?? '',
            'active_tool' => !empty($data['active_tool']) ? json_decode($data['active_tool'], true) : null,
            'started_at' => $data['started_at'] ?? null,
            'last_update' => $data['last_update'] ?? null,
            'completed_at' => $data['completed_at'] ?? null,
            'error' => $data['error'] ?? null,
            'tokens' => !empty($data['tokens']) ? json_decode($data['tokens'], true) : null,
        ];
    }

    /**
     * Verifica si hay un stream activo para el thread.
     */
    public function isStreaming(string $threadId): bool
    {
        $state = $this->getStreamState($threadId);
        return $state && $state['status'] === 'streaming';
    }

    /**
     * Limpia el estado de Redis.
     */
    public function cleanupStream(string $threadId): void
    {
        $key = self::PREFIX . $threadId;
        Redis::del($key);
    }
}
