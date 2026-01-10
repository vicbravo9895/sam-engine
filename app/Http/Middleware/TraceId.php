<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to generate and propagate trace IDs for distributed tracing.
 * 
 * This middleware:
 * 1. Checks for an existing X-Trace-ID header (from upstream services)
 * 2. Generates a new trace ID if none exists
 * 3. Stores it in the app container for access throughout the request
 * 4. Adds it to the response headers for downstream correlation
 * 
 * The trace ID format is: {timestamp}-{random} for easy sorting and uniqueness.
 */
class TraceId
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get existing trace ID from header or generate new one
        $traceId = $request->header('X-Trace-ID') ?? $this->generateTraceId();
        
        // Store in request header for consistency
        $request->headers->set('X-Trace-ID', $traceId);
        
        // Bind to app container for access in non-request contexts (jobs, etc.)
        app()->instance('trace_id', $traceId);
        
        // Process request
        $response = $next($request);
        
        // Add trace ID to response headers for client correlation
        $response->headers->set('X-Trace-ID', $traceId);
        
        return $response;
    }

    /**
     * Generate a unique trace ID.
     * 
     * Format: {hex_timestamp}-{random_hex}
     * Example: 678123ab-a1b2c3d4e5f6
     * 
     * Using hex timestamp allows for:
     * - Chronological sorting
     * - Compact representation
     * - Easy identification of request time
     */
    private function generateTraceId(): string
    {
        $timestamp = dechex((int) (microtime(true) * 1000));
        $random = Str::random(12);
        
        return "{$timestamp}-{$random}";
    }
}
