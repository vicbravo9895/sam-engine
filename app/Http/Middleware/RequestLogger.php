<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to automatically log HTTP requests and responses.
 * 
 * This middleware logs:
 * - Request start: method, path, user, IP
 * - Request end: status code, duration
 * - Errors: exceptions with full context
 * 
 * Designed for production use with minimal performance impact.
 */
class RequestLogger
{
    /**
     * Paths to exclude from logging (health checks, static assets, etc.)
     */
    private const EXCLUDED_PATHS = [
        'health',
        'up',
        'livewire',
        '_debugbar',
        'telescope',
        'horizon/api',
        'sanctum/csrf-cookie',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip logging for excluded paths
        if ($this->shouldSkip($request)) {
            return $next($request);
        }

        $startTime = microtime(true);
        $traceId = $request->header('X-Trace-ID', 'unknown');

        // Log request start (debug level - can be filtered in production)
        Log::debug('Request started', [
            'trace_id' => $traceId,
            'method' => $request->method(),
            'path' => $request->path(),
            'query' => $this->sanitizeQuery($request->query()),
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        try {
            $response = $next($request);
            
            $duration = $this->calculateDuration($startTime);
            $statusCode = $response->getStatusCode();
            
            // Determine log level based on status code
            $level = $this->getLogLevel($statusCode);
            
            // Log request completion
            Log::log($level, 'Request completed', [
                'trace_id' => $traceId,
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $statusCode,
                'duration_ms' => $duration,
                'user_id' => $request->user()?->id,
                'response_size' => strlen($response->getContent()),
            ]);

            return $response;

        } catch (\Throwable $e) {
            $duration = $this->calculateDuration($startTime);
            
            // Log error with full context
            Log::error('Request failed', [
                'trace_id' => $traceId,
                'method' => $request->method(),
                'path' => $request->path(),
                'duration_ms' => $duration,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
            ]);

            throw $e;
        }
    }

    /**
     * Check if request should be excluded from logging.
     */
    private function shouldSkip(Request $request): bool
    {
        $path = $request->path();
        
        foreach (self::EXCLUDED_PATHS as $excluded) {
            if (str_starts_with($path, $excluded)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate request duration in milliseconds.
     */
    private function calculateDuration(float $startTime): float
    {
        return round((microtime(true) - $startTime) * 1000, 2);
    }

    /**
     * Get appropriate log level based on HTTP status code.
     */
    private function getLogLevel(int $statusCode): string
    {
        if ($statusCode >= 500) {
            return 'error';
        }
        
        if ($statusCode >= 400) {
            return 'warning';
        }
        
        return 'info';
    }

    /**
     * Sanitize query parameters (remove sensitive data).
     */
    private function sanitizeQuery(array $query): array
    {
        $sensitive = ['password', 'token', 'secret', 'key', 'api_key', 'authorization'];
        
        foreach ($query as $key => $value) {
            if (in_array(strtolower($key), $sensitive)) {
                $query[$key] = '[REDACTED]';
            }
        }

        return $query;
    }
}
