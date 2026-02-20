<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * W3C Trace Context middleware for distributed tracing.
 *
 * Supports the standard `traceparent` header (version 00):
 *   00-{trace_id_32hex}-{span_id_16hex}-{flags_2hex}
 *
 * Maintains backward compatibility with the legacy `X-Trace-ID` header.
 * Priority: traceparent > X-Trace-ID > generate new.
 *
 * @see https://www.w3.org/TR/trace-context/
 */
class TraceId
{
    private const TRACEPARENT_REGEX = '/^00-([a-f0-9]{32})-([a-f0-9]{16})-([a-f0-9]{2})$/';

    public function handle(Request $request, Closure $next): Response
    {
        $incomingTraceparent = $request->header('traceparent');
        $incomingTracestate = $request->header('tracestate');

        if ($incomingTraceparent && preg_match(self::TRACEPARENT_REGEX, $incomingTraceparent, $matches)) {
            $traceId = $matches[1];
            $parentSpanId = $matches[2];
            $flags = $matches[3];
        } else {
            $traceId = bin2hex(random_bytes(16));
            $parentSpanId = null;
            $flags = '01';
        }

        $spanId = bin2hex(random_bytes(8));
        $traceparent = "00-{$traceId}-{$spanId}-{$flags}";

        $request->headers->set('traceparent', $traceparent);

        if ($incomingTracestate) {
            $request->headers->set('tracestate', $incomingTracestate);
        }

        // Legacy header for backward compatibility
        $request->headers->set('X-Trace-ID', $traceId);

        app()->instance('traceparent', $traceparent);
        app()->instance('trace_id', $traceId);

        $response = $next($request);

        $response->headers->set('traceparent', $traceparent);
        $response->headers->set('X-Trace-ID', $traceId);

        if ($incomingTracestate) {
            $response->headers->set('tracestate', $incomingTracestate);
        }

        return $response;
    }
}
