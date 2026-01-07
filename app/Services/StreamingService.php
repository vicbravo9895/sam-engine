<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

/**
 * Servicio para manejar streaming de mensajes usando Redis.
 * 
 * Almacena el estado del stream en Redis para que los endpoints SSE
 * puedan leerlo periódicamente y enviar actualizaciones al cliente.
 */
class StreamingService
{
    /**
     * Prefijo para las keys de Redis
     */
    private const PREFIX = 'copilot:stream:';
    
    /**
     * TTL para el estado del stream (10 minutos)
     */
    private const STATE_TTL = 600;

    /**
     * Inicializa un nuevo stream para un thread.
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
     */
    public function publishChunk(string $threadId, string $chunk): void
    {
        $key = self::PREFIX . $threadId;
        
        // Obtener contenido actual y concatenar
        $currentContent = Redis::hget($key, 'content') ?? '';
        $newContent = $currentContent . $chunk;
        
        Redis::hset($key, [
            'content' => $newContent,
            'last_update' => now()->toISOString(),
            'active_tool' => '', // Limpiar tool cuando hay contenido
        ]);
        
        Redis::expire($key, self::STATE_TTL);
    }

    /**
     * Marca inicio de tool call.
     */
    public function publishToolStart(string $threadId, string $toolName, array $toolInfo): void
    {
        $key = self::PREFIX . $threadId;
        
        Redis::hset($key, [
            'active_tool' => json_encode($toolInfo),
            'last_update' => now()->toISOString(),
        ]);
        
        Redis::expire($key, self::STATE_TTL);
    }

    /**
     * Marca fin de tool call.
     */
    public function publishToolEnd(string $threadId): void
    {
        $key = self::PREFIX . $threadId;
        
        Redis::hset($key, [
            'active_tool' => '',
            'last_update' => now()->toISOString(),
        ]);
        
        Redis::expire($key, self::STATE_TTL);
    }

    /**
     * Finaliza el stream exitosamente.
     */
    public function finishStream(string $threadId, array $tokenStats = []): void
    {
        $key = self::PREFIX . $threadId;
        
        Redis::hset($key, [
            'status' => 'completed',
            'last_update' => now()->toISOString(),
            'completed_at' => now()->toISOString(),
            'tokens' => json_encode($tokenStats),
        ]);
        
        // Mantener por 1 minuto después de completar para que el cliente pueda reconectarse
        Redis::expire($key, 60);
    }

    /**
     * Marca el stream como fallido.
     */
    public function failStream(string $threadId, string $error): void
    {
        $key = self::PREFIX . $threadId;
        
        Redis::hset($key, [
            'status' => 'failed',
            'error' => $error,
            'last_update' => now()->toISOString(),
            'failed_at' => now()->toISOString(),
        ]);
        
        Redis::expire($key, 60);
    }

    /**
     * Obtiene el estado actual del stream.
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
     * Limpia el estado del stream de Redis.
     */
    public function cleanupStream(string $threadId): void
    {
        $key = self::PREFIX . $threadId;
        Redis::del($key);
    }
}
