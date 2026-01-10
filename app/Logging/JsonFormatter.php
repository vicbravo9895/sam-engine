<?php

namespace App\Logging;

use Monolog\Formatter\JsonFormatter as BaseJsonFormatter;
use Monolog\LogRecord;

/**
 * Custom JSON Formatter for structured logging.
 * 
 * This formatter outputs logs in a standardized JSON format compatible with
 * Grafana Loki and other log aggregation systems.
 * 
 * Standard fields (always present):
 * - timestamp: ISO8601 format with timezone
 * - level: Log level (info, error, warning, debug)
 * - service: Service name (laravel, horizon, scheduler, artisan)
 * - environment: Current environment (production, staging, local)
 * - trace_id: Unique request identifier for distributed tracing
 * - message: Log message
 * 
 * Multi-tenant fields (when available):
 * - company_id: ID de la empresa (para filtrar en Grafana)
 * - company_name: Nombre de la empresa
 * - user_id: ID del usuario autenticado
 * - user_email: Email del usuario
 * 
 * Context fields:
 * - context: Additional contextual data from Log::info('msg', [...])
 */
class JsonFormatter extends BaseJsonFormatter
{
    /**
     * Format a log record into a JSON string.
     *
     * @param LogRecord $record The log record to format
     * @return string JSON formatted log entry
     */
    public function format(LogRecord $record): string
    {
        $data = [
            'timestamp' => $record->datetime->format('c'),
            'level' => strtolower($record->level->name),
            'service' => $this->resolveServiceName(),
            'environment' => config('app.env', 'production'),
            'trace_id' => $this->resolveTraceId(),
            'message' => $record->message,
        ];

        // Add multi-tenant context (company_id, user_id)
        $tenantContext = $this->resolveTenantContext();
        if (!empty($tenantContext)) {
            $data = array_merge($data, $tenantContext);
        }

        // Add channel info if not default
        if ($record->channel !== 'local' && $record->channel !== 'production') {
            $data['channel'] = $record->channel;
        }

        // Add sanitized context
        $context = $this->sanitizeContext($record->context);
        if (!empty($context)) {
            $data['context'] = $context;
        }

        // Add extra data if present (from processors)
        if (!empty($record->extra)) {
            $data['extra'] = $record->extra;
        }

        return $this->toJson($data) . "\n";
    }

    /**
     * Resolve multi-tenant context (company_id, user_id).
     * 
     * This allows filtering logs by company in Grafana:
     * {app="sam", company_id="123"}
     */
    private function resolveTenantContext(): array
    {
        $context = [];

        // Try to get from app container first (set by jobs/commands)
        if (app()->bound('log_company_id')) {
            $context['company_id'] = app('log_company_id');
        }
        if (app()->bound('log_company_name')) {
            $context['company_name'] = app('log_company_name');
        }

        // Try to get from authenticated user
        try {
            if (function_exists('auth') && auth()->check()) {
                $user = auth()->user();
                
                if (!isset($context['company_id']) && isset($user->company_id)) {
                    $context['company_id'] = $user->company_id;
                }
                
                if (!isset($context['company_name']) && isset($user->company) && $user->company) {
                    $context['company_name'] = $user->company->name;
                }
                
                $context['user_id'] = $user->id;
                $context['user_email'] = $user->email;
            }
        } catch (\Throwable $e) {
            // Ignore auth errors (might be in console context)
        }

        return $context;
    }

    /**
     * Resolve the current trace ID from various sources.
     */
    private function resolveTraceId(): string
    {
        // Try to get from request header (set by TraceId middleware)
        if (function_exists('request') && request()) {
            $traceId = request()->header('X-Trace-ID');
            if ($traceId) {
                return $traceId;
            }
        }

        // Try to get from app container (for queue jobs)
        if (app()->bound('trace_id')) {
            return app('trace_id');
        }

        // Fallback to generating a new one
        return 'gen-' . substr(md5(uniqid('', true)), 0, 16);
    }

    /**
     * Resolve the service name based on execution context.
     */
    private function resolveServiceName(): string
    {
        // Check if running in Horizon (queue worker)
        if (app()->runningInConsole()) {
            $command = $_SERVER['argv'][1] ?? '';
            if (str_contains($command, 'horizon') || str_contains($command, 'queue:work')) {
                return 'horizon';
            }
            if (str_contains($command, 'schedule')) {
                return 'scheduler';
            }
            return 'artisan';
        }

        return 'laravel';
    }

    /**
     * Sanitize context data to ensure it's JSON serializable.
     */
    private function sanitizeContext(array $context): array
    {
        $sanitized = [];
        
        foreach ($context as $key => $value) {
            // Skip exception key (handled separately by Monolog)
            if ($key === 'exception') {
                continue;
            }

            // Handle objects
            if (is_object($value)) {
                if (method_exists($value, 'toArray')) {
                    $sanitized[$key] = $value->toArray();
                } elseif (method_exists($value, '__toString')) {
                    $sanitized[$key] = (string) $value;
                } else {
                    $sanitized[$key] = get_class($value);
                }
                continue;
            }

            // Handle resources
            if (is_resource($value)) {
                $sanitized[$key] = 'resource:' . get_resource_type($value);
                continue;
            }

            // Recursively sanitize arrays
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeContext($value);
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }
}
