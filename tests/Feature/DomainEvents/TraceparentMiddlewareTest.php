<?php

namespace Tests\Feature\DomainEvents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TraceparentMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_traceparent_when_no_headers_sent(): void
    {
        $response = $this->get('/up');

        $traceparent = $response->headers->get('traceparent');

        $this->assertNotNull($traceparent);
        $this->assertMatchesRegularExpression(
            '/^00-[a-f0-9]{32}-[a-f0-9]{16}-[a-f0-9]{2}$/',
            $traceparent
        );
    }

    public function test_propagates_incoming_traceparent_with_new_span_id(): void
    {
        $incomingTrace = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

        $response = $this->withHeaders([
            'traceparent' => $incomingTrace,
        ])->get('/up');

        $traceparent = $response->headers->get('traceparent');
        $this->assertNotNull($traceparent);

        $parts = explode('-', $traceparent);
        $this->assertCount(4, $parts);
        $this->assertEquals('00', $parts[0]);
        // Same trace-id as incoming
        $this->assertEquals('4bf92f3577b34da6a3ce929d0e0e4736', $parts[1]);
        // Different span-id (new for this service)
        $this->assertNotEquals('00f067aa0ba902b7', $parts[2]);
        $this->assertEquals('01', $parts[3]);
    }

    public function test_generates_new_traceparent_for_invalid_format(): void
    {
        $response = $this->withHeaders([
            'traceparent' => 'invalid-value',
        ])->get('/up');

        $traceparent = $response->headers->get('traceparent');

        $this->assertNotNull($traceparent);
        $this->assertMatchesRegularExpression(
            '/^00-[a-f0-9]{32}-[a-f0-9]{16}-[a-f0-9]{2}$/',
            $traceparent
        );
    }

    public function test_legacy_x_trace_id_header_is_still_propagated(): void
    {
        $response = $this->get('/up');

        $traceId = $response->headers->get('X-Trace-ID');
        $traceparent = $response->headers->get('traceparent');

        $this->assertNotNull($traceId);
        $this->assertNotNull($traceparent);

        // X-Trace-ID should match the trace-id portion of traceparent
        $traceIdFromParent = explode('-', $traceparent)[1];
        $this->assertEquals($traceIdFromParent, $traceId);
    }

    public function test_tracestate_is_propagated_when_present(): void
    {
        $response = $this->withHeaders([
            'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
            'tracestate' => 'congo=t61rcWkgMzE',
        ])->get('/up');

        $this->assertEquals('congo=t61rcWkgMzE', $response->headers->get('tracestate'));
    }
}
