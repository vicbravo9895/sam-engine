<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\RequestLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RequestLoggerTest extends TestCase
{
    private RequestLogger $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new RequestLogger();
    }

    public function test_logs_request_and_response_for_normal_path(): void
    {
        Log::shouldReceive('debug')->once()->withArgs(function (string $msg) {
            return $msg === 'Request started';
        });
        Log::shouldReceive('log')->once()->withArgs(function (string $level, string $msg) {
            return $level === 'info' && $msg === 'Request completed';
        });

        $request = Request::create('/dashboard', 'GET');
        $response = $this->middleware->handle($request, fn () => new Response('OK', 200));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_skips_health_check_paths(): void
    {
        Log::shouldReceive('debug')->never();
        Log::shouldReceive('log')->never();

        $request = Request::create('/health', 'GET');
        $response = $this->middleware->handle($request, fn () => new Response('OK', 200));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_skips_up_path(): void
    {
        Log::shouldReceive('debug')->never();
        Log::shouldReceive('log')->never();

        $request = Request::create('/up', 'GET');
        $this->middleware->handle($request, fn () => new Response('OK', 200));
    }

    public function test_skips_livewire_path(): void
    {
        Log::shouldReceive('debug')->never();
        Log::shouldReceive('log')->never();

        $request = Request::create('/livewire/something', 'GET');
        $this->middleware->handle($request, fn () => new Response('OK', 200));
    }

    public function test_skips_debugbar_path(): void
    {
        Log::shouldReceive('debug')->never();
        Log::shouldReceive('log')->never();

        $request = Request::create('/_debugbar/something', 'GET');
        $this->middleware->handle($request, fn () => new Response('OK', 200));
    }

    public function test_skips_telescope_path(): void
    {
        Log::shouldReceive('debug')->never();
        Log::shouldReceive('log')->never();

        $request = Request::create('/telescope/requests', 'GET');
        $this->middleware->handle($request, fn () => new Response('OK', 200));
    }

    public function test_skips_horizon_api_path(): void
    {
        Log::shouldReceive('debug')->never();
        Log::shouldReceive('log')->never();

        $request = Request::create('/horizon/api/stats', 'GET');
        $this->middleware->handle($request, fn () => new Response('OK', 200));
    }

    public function test_skips_sanctum_csrf_path(): void
    {
        Log::shouldReceive('debug')->never();
        Log::shouldReceive('log')->never();

        $request = Request::create('/sanctum/csrf-cookie', 'GET');
        $this->middleware->handle($request, fn () => new Response('OK', 200));
    }

    public function test_logs_warning_for_4xx_status(): void
    {
        Log::shouldReceive('debug')->once();
        Log::shouldReceive('log')->once()->withArgs(function (string $level) {
            return $level === 'warning';
        });

        $request = Request::create('/api/missing', 'GET');
        $this->middleware->handle($request, fn () => new Response('Not Found', 404));
    }

    public function test_logs_error_for_5xx_status(): void
    {
        Log::shouldReceive('debug')->once();
        Log::shouldReceive('log')->once()->withArgs(function (string $level) {
            return $level === 'error';
        });

        $request = Request::create('/api/broken', 'GET');
        $this->middleware->handle($request, fn () => new Response('Server Error', 500));
    }

    public function test_logs_error_and_rethrows_on_exception(): void
    {
        Log::shouldReceive('debug')->once();
        Log::shouldReceive('error')->once()->withArgs(function (string $msg, array $context) {
            return $msg === 'Request failed'
                && $context['error'] === 'Something broke'
                && $context['error_class'] === \RuntimeException::class;
        });

        $request = Request::create('/api/crash', 'POST');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Something broke');

        $this->middleware->handle($request, function () {
            throw new \RuntimeException('Something broke');
        });
    }

    public function test_sanitizes_sensitive_query_params(): void
    {
        Log::shouldReceive('debug')->once()->withArgs(function (string $msg, array $context) {
            return $context['query']['password'] === '[REDACTED]'
                && $context['query']['token'] === '[REDACTED]'
                && $context['query']['api_key'] === '[REDACTED]'
                && $context['query']['name'] === 'test';
        });
        Log::shouldReceive('log')->once();

        $request = Request::create('/search?password=secret&token=abc&api_key=key123&name=test', 'GET');
        $this->middleware->handle($request, fn () => new Response('OK', 200));
    }

    public function test_includes_trace_id_from_header(): void
    {
        Log::shouldReceive('debug')->once()->withArgs(function (string $msg, array $context) {
            return $context['trace_id'] === 'my-trace-123';
        });
        Log::shouldReceive('log')->once()->withArgs(function (string $level, string $msg, array $context) {
            return $context['trace_id'] === 'my-trace-123';
        });

        $request = Request::create('/api/data', 'GET');
        $request->headers->set('X-Trace-ID', 'my-trace-123');

        $this->middleware->handle($request, fn () => new Response('OK', 200));
    }

    public function test_uses_unknown_when_no_trace_id(): void
    {
        Log::shouldReceive('debug')->once()->withArgs(function (string $msg, array $context) {
            return $context['trace_id'] === 'unknown';
        });
        Log::shouldReceive('log')->once();

        $request = Request::create('/api/data', 'GET');
        $this->middleware->handle($request, fn () => new Response('OK', 200));
    }

    public function test_logs_response_size(): void
    {
        Log::shouldReceive('debug')->once();
        Log::shouldReceive('log')->once()->withArgs(function (string $level, string $msg, array $context) {
            return $context['response_size'] === 12;
        });

        $request = Request::create('/api/data', 'GET');
        $this->middleware->handle($request, fn () => new Response('Hello World!', 200));
    }

    public function test_logs_duration_as_float(): void
    {
        Log::shouldReceive('debug')->once();
        Log::shouldReceive('log')->once()->withArgs(function (string $level, string $msg, array $context) {
            return is_float($context['duration_ms']) && $context['duration_ms'] >= 0;
        });

        $request = Request::create('/api/test', 'GET');
        $this->middleware->handle($request, fn () => new Response('OK', 200));
    }

    public function test_sanitizes_secret_and_key_params(): void
    {
        Log::shouldReceive('debug')->once()->withArgs(function (string $msg, array $context) {
            return $context['query']['secret'] === '[REDACTED]'
                && $context['query']['key'] === '[REDACTED]'
                && $context['query']['authorization'] === '[REDACTED]';
        });
        Log::shouldReceive('log')->once();

        $request = Request::create('/search?secret=x&key=y&authorization=z', 'GET');
        $this->middleware->handle($request, fn () => new Response('OK', 200));
    }
}
