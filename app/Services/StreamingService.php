<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

/**
 * Servicio para manejar streaming de mensajes usando Redis.
 *
 * Camino A: SSE real con Redis Streams.
 * - Un canal (stream) por conversación: copilot:stream:{threadId}:events
 * - El job escribe cada chunk/evento con XADD; el endpoint SSE lee con XREAD BLOCK
 *   (sin polling: bajo CPU y escalable a 200+ conexiones concurrentes).
 * - El hash por thread se mantiene para estado (status, content acumulado para
 *   streamProgress) y compatibilidad.
 */
class StreamingService
{
    /**
     * Prefijo para las keys de Redis
     */
    private const PREFIX = 'copilot:stream:';

    /**
     * Sufijo del stream de eventos (Redis Stream) por thread
     */
    private const STREAM_SUFFIX = ':events';

    /**
     * TTL para el estado del stream (10 minutos)
     */
    private const STATE_TTL = 600;

    /**
     * Máximo de entradas en el stream por thread (evitar crecimiento indefinido)
     */
    private const STREAM_MAXLEN = 10000;

    /**
     * Inicializa un nuevo stream para un thread (hash de estado + primer evento en stream).
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

        $streamKey = $this->streamKey($threadId);
        $this->xAdd($streamKey, 'stream_start', [
            'timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Agrega un chunk de texto al stream (Redis Stream) y al hash (para streamProgress).
     */
    public function publishChunk(string $threadId, string $chunk): void
    {
        $key = self::PREFIX . $threadId;
        $streamKey = $this->streamKey($threadId);

        $this->xAdd($streamKey, 'chunk', [
            'content' => $chunk,
            'timestamp' => now()->toISOString(),
        ]);

        $currentContent = Redis::hget($key, 'content') ?? '';
        Redis::hset($key, [
            'content' => $currentContent . $chunk,
            'last_update' => now()->toISOString(),
            'active_tool' => '',
        ]);
        Redis::expire($key, self::STATE_TTL);
    }

    /**
     * Marca inicio de tool call (stream + hash).
     */
    public function publishToolStart(string $threadId, string $toolName, array $toolInfo): void
    {
        $key = self::PREFIX . $threadId;
        $streamKey = $this->streamKey($threadId);

        $this->xAdd($streamKey, 'tool_start', [
            'tool_info' => json_encode($toolInfo),
            'timestamp' => now()->toISOString(),
        ]);

        Redis::hset($key, [
            'active_tool' => json_encode($toolInfo),
            'last_update' => now()->toISOString(),
        ]);
        Redis::expire($key, self::STATE_TTL);
    }

    /**
     * Marca fin de tool call (stream + hash).
     */
    public function publishToolEnd(string $threadId): void
    {
        $key = self::PREFIX . $threadId;
        $streamKey = $this->streamKey($threadId);

        $this->xAdd($streamKey, 'tool_end', [
            'timestamp' => now()->toISOString(),
        ]);

        Redis::hset($key, [
            'active_tool' => '',
            'last_update' => now()->toISOString(),
        ]);
        Redis::expire($key, self::STATE_TTL);
    }

    /**
     * Finaliza el stream exitosamente (stream + hash).
     */
    public function finishStream(string $threadId, array $tokenStats = []): void
    {
        $key = self::PREFIX . $threadId;
        $streamKey = $this->streamKey($threadId);

        $this->xAdd($streamKey, 'stream_end', [
            'tokens' => json_encode($tokenStats),
            'timestamp' => now()->toISOString(),
        ]);

        Redis::hset($key, [
            'status' => 'completed',
            'last_update' => now()->toISOString(),
            'completed_at' => now()->toISOString(),
            'tokens' => json_encode($tokenStats),
        ]);
        Redis::expire($key, 60);
    }

    /**
     * Marca el stream como fallido (stream + hash).
     */
    public function failStream(string $threadId, string $error): void
    {
        $key = self::PREFIX . $threadId;
        $streamKey = $this->streamKey($threadId);

        $this->xAdd($streamKey, 'stream_error', [
            'error' => $error,
            'timestamp' => now()->toISOString(),
        ]);

        Redis::hset($key, [
            'status' => 'failed',
            'error' => $error,
            'last_update' => now()->toISOString(),
            'failed_at' => now()->toISOString(),
        ]);
        Redis::expire($key, 60);
    }

    /**
     * Añade un evento al Redis Stream (XADD con MAXLEN para no crecer indefinidamente).
     */
    private function xAdd(string $streamKey, string $eventType, array $fields): void
    {
        $client = $this->redisClient();
        $payload = array_merge(['event' => $eventType], $fields);
        $flat = [];
        foreach ($payload as $k => $v) {
            $flat[] = $k;
            $flat[] = $v;
        }
        $client->xAdd($streamKey, '*', $flat, self::STREAM_MAXLEN, true);
    }

    /**
     * Lee eventos del stream con bloqueo (XREAD BLOCK). Sin polling: bajo CPU.
     *
     * @param string $threadId
     * @param string $lastId ID del último evento recibido; '0' = desde el inicio, '$' = solo nuevos
     * @param int $blockMs Milisegundos de bloqueo (0 = no bloquear)
     * @return array<int, array{id: string, event: string, data: array}> Lista de eventos; vacía si timeout
     */
    public function readStreamEvents(string $threadId, string $lastId = '0', int $blockMs = 5000): array
    {
        $streamKey = $this->streamKey($threadId);
        $client = $this->redisClient();

        $result = $client->xRead([$streamKey], [$lastId], $blockMs);

        if ($result === false || empty($result[$streamKey])) {
            return [];
        }

        $out = [];
        foreach ($result[$streamKey] as $id => $entry) {
            $entry = $this->streamEntryToAssoc($entry);
            $event = $entry['event'] ?? 'chunk';
            $data = [];
            if (isset($entry['content'])) {
                $data['content'] = $entry['content'];
            }
            if (isset($entry['tool_info'])) {
                $data['tool_info'] = is_string($entry['tool_info']) ? json_decode($entry['tool_info'], true) : $entry['tool_info'];
            }
            if (isset($entry['tokens'])) {
                $data['tokens'] = is_string($entry['tokens']) ? json_decode($entry['tokens'], true) : $entry['tokens'];
            }
            if (isset($entry['error'])) {
                $data['error'] = $entry['error'];
            }
            if (isset($entry['timestamp'])) {
                $data['timestamp'] = $entry['timestamp'];
            }
            $out[] = ['id' => $id, 'event' => $event, 'data' => $data];
        }
        return $out;
    }

    /**
     * Convierte entrada del stream (asociativa o plana f1,v1,f2,v2) a array asociativo.
     */
    private function streamEntryToAssoc(array $entry): array
    {
        if (array_is_list($entry)) {
            $assoc = [];
            for ($i = 0; $i < count($entry) - 1; $i += 2) {
                $assoc[$entry[$i]] = $entry[$i + 1];
            }
            return $assoc;
        }
        return $entry;
    }

    /**
     * Cliente Redis (phpredis) para comandos de streams.
     */
    private function redisClient(): \Redis
    {
        $conn = Redis::connection();
        return $conn->client();
    }

    /**
     * Key completa del stream de eventos (con prefijo Laravel si existe).
     */
    private function streamKey(string $threadId): string
    {
        $key = self::PREFIX . $threadId . self::STREAM_SUFFIX;
        $prefix = (string) config('database.redis.options.prefix', '');
        return $prefix . $key;
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
     * Limpia el estado y el stream de eventos de Redis.
     */
    public function cleanupStream(string $threadId): void
    {
        $key = self::PREFIX . $threadId;
        Redis::del($key);
        $client = $this->redisClient();
        $streamKey = $this->streamKey($threadId);
        $client->del($streamKey);
    }
}
