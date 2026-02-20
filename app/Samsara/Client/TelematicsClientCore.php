<?php

declare(strict_types=1);

namespace App\Samsara\Client;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

abstract class TelematicsClientCore
{
    protected string $bearerToken;
    protected string $baseUrl;

    public function __construct(?string $bearerToken = null, ?string $baseUrl = null)
    {
        $this->bearerToken = $bearerToken ?? config('services.samsara.api_token', '');
        $this->baseUrl = rtrim($baseUrl ?? config('services.samsara.base_url', 'https://api.samsara.com'), '/');
    }

    protected function client(): PendingRequest
    {
        $request = Http::withHeaders([
            'Authorization' => "Bearer {$this->bearerToken}",
            'Accept' => 'application/json',
        ])->baseUrl($this->baseUrl)
          ->timeout(15);

        $traceParent = $this->currentTraceParent();
        if ($traceParent) {
            $request = $request->withHeaders(['traceparent' => $traceParent]);
        }

        return $request;
    }

    /**
     * Single API request with structured error handling.
     */
    public function request(string $method, string $path, array $params = []): array
    {
        $response = match (strtoupper($method)) {
            'GET'    => $this->client()->get($path, $params),
            'POST'   => $this->client()->post($path, $params),
            'PUT'    => $this->client()->put($path, $params),
            'PATCH'  => $this->client()->patch($path, $params),
            'DELETE' => $this->client()->delete($path, $params),
            default  => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };

        $this->handleError($response, $path);

        return $response->json() ?? [];
    }

    /**
     * Cursor-based pagination that collects all pages into a Collection.
     */
    public function paginateAll(string $path, array $params = [], string $dataKey = 'data'): Collection
    {
        $all = [];
        $cursor = null;
        $hasNextPage = true;

        while ($hasNextPage) {
            $queryParams = $params;
            if ($cursor) {
                $queryParams['after'] = $cursor;
            }

            $response = $this->request('GET', $path, $queryParams);

            $page = $response[$dataKey] ?? [];
            if (is_array($page)) {
                $all = array_merge($all, $page);
            }

            $hasNextPage = $response['pagination']['hasNextPage'] ?? false;
            $cursor = $response['pagination']['endCursor'] ?? null;
        }

        return collect($all);
    }

    /**
     * Parallel HTTP requests using Http::pool().
     *
     * @param array<string, \Closure> $requests Keyed closures that receive a Pool instance
     * @return array<string, Response> Keyed responses
     */
    public function pool(array $requests): array
    {
        return Http::pool(function (Pool $pool) use ($requests) {
            $calls = [];
            foreach ($requests as $key => $callback) {
                $calls[] = $callback(
                    $pool->as($key)
                         ->withHeaders([
                             'Authorization' => "Bearer {$this->bearerToken}",
                             'Accept' => 'application/json',
                         ])
                         ->baseUrl($this->baseUrl)
                         ->timeout(15)
                );
            }
            return $calls;
        });
    }

    protected function handleError(Response $response, string $path = ''): void
    {
        if ($response->successful()) {
            return;
        }

        $context = [
            'path' => $path,
            'status' => $response->status(),
            'body' => substr($response->body(), 0, 500),
        ];

        if ($response->serverError()) {
            Log::error('Samsara API server error', $context);
            throw new \RuntimeException(
                "Samsara API server error ({$response->status()}) on {$path}: " . substr($response->body(), 0, 200),
                $response->status()
            );
        }

        if ($response->status() === 429) {
            Log::warning('Samsara API rate limited', $context);
            throw new \RuntimeException("Samsara API rate limited on {$path}", 429);
        }

        Log::warning('Samsara API client error', $context);
        throw new \RuntimeException(
            "Samsara API error ({$response->status()}) on {$path}: " . substr($response->body(), 0, 200),
            $response->status()
        );
    }

    protected function currentTraceParent(): ?string
    {
        return request()?->header('traceparent');
    }

    public function hasToken(): bool
    {
        return !empty($this->bearerToken);
    }
}
