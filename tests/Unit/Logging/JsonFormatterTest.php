<?php

namespace Tests\Unit\Logging;

use App\Logging\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class JsonFormatterTest extends TestCase
{
    private JsonFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new JsonFormatter();
    }

    private function makeRecord(
        string $message = 'Test message',
        Level $level = Level::Info,
        array $context = [],
        array $extra = [],
        string $channel = 'testing',
    ): LogRecord {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: $channel,
            level: $level,
            message: $message,
            context: $context,
            extra: $extra,
        );
    }

    private function formatAndDecode(LogRecord $record): array
    {
        $json = $this->formatter->format($record);

        return json_decode(trim($json), true);
    }

    public function test_format_returns_valid_json_with_newline(): void
    {
        $record = $this->makeRecord();
        $output = $this->formatter->format($record);

        $this->assertStringEndsWith("\n", $output);
        $this->assertNotNull(json_decode(trim($output), true));
    }

    public function test_format_includes_standard_fields(): void
    {
        $data = $this->formatAndDecode($this->makeRecord('Hello world', Level::Warning));

        $this->assertArrayHasKey('timestamp', $data);
        $this->assertArrayHasKey('level', $data);
        $this->assertArrayHasKey('service', $data);
        $this->assertArrayHasKey('environment', $data);
        $this->assertArrayHasKey('trace_id', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertEquals('Hello world', $data['message']);
        $this->assertEquals('warning', $data['level']);
    }

    public function test_level_is_lowercase(): void
    {
        $data = $this->formatAndDecode($this->makeRecord(level: Level::Error));
        $this->assertEquals('error', $data['level']);

        $data = $this->formatAndDecode($this->makeRecord(level: Level::Debug));
        $this->assertEquals('debug', $data['level']);
    }

    public function test_service_name_is_resolved(): void
    {
        $data = $this->formatAndDecode($this->makeRecord());
        $this->assertContains($data['service'], ['laravel', 'artisan', 'horizon', 'scheduler']);
    }

    public function test_trace_id_is_present(): void
    {
        $data = $this->formatAndDecode($this->makeRecord());
        $this->assertNotEmpty($data['trace_id']);
    }

    public function test_context_is_included_when_present(): void
    {
        $record = $this->makeRecord(context: ['alert_id' => 42, 'channel' => 'sms']);
        $data = $this->formatAndDecode($record);

        $this->assertArrayHasKey('context', $data);
        $this->assertEquals(42, $data['context']['alert_id']);
        $this->assertEquals('sms', $data['context']['channel']);
    }

    public function test_context_is_omitted_when_empty(): void
    {
        $data = $this->formatAndDecode($this->makeRecord(context: []));

        $this->assertArrayNotHasKey('context', $data);
    }

    public function test_extra_is_included_when_present(): void
    {
        $record = $this->makeRecord(extra: ['memory' => '64MB']);
        $data = $this->formatAndDecode($record);

        $this->assertArrayHasKey('extra', $data);
        $this->assertEquals('64MB', $data['extra']['memory']);
    }

    public function test_extra_is_omitted_when_empty(): void
    {
        $data = $this->formatAndDecode($this->makeRecord(extra: []));

        $this->assertArrayNotHasKey('extra', $data);
    }

    public function test_channel_is_omitted_for_local_and_production(): void
    {
        $data = $this->formatAndDecode($this->makeRecord(channel: 'local'));
        $this->assertArrayNotHasKey('channel', $data);

        $data = $this->formatAndDecode($this->makeRecord(channel: 'production'));
        $this->assertArrayNotHasKey('channel', $data);
    }

    public function test_channel_is_included_for_custom_channels(): void
    {
        $data = $this->formatAndDecode($this->makeRecord(channel: 'slack'));
        $this->assertArrayHasKey('channel', $data);
        $this->assertEquals('slack', $data['channel']);
    }

    public function test_sanitize_context_skips_exception_key(): void
    {
        $record = $this->makeRecord(context: [
            'exception' => new \RuntimeException('boom'),
            'foo' => 'bar',
        ]);
        $data = $this->formatAndDecode($record);

        $this->assertArrayNotHasKey('exception', $data['context']);
        $this->assertEquals('bar', $data['context']['foo']);
    }

    public function test_sanitize_context_handles_object_with_to_array(): void
    {
        $obj = new class {
            public function toArray(): array
            {
                return ['key' => 'value'];
            }
        };

        $record = $this->makeRecord(context: ['obj' => $obj]);
        $data = $this->formatAndDecode($record);

        $this->assertEquals(['key' => 'value'], $data['context']['obj']);
    }

    public function test_sanitize_context_handles_object_with_to_string(): void
    {
        $obj = new class {
            public function __toString(): string
            {
                return 'stringified';
            }
        };

        $record = $this->makeRecord(context: ['obj' => $obj]);
        $data = $this->formatAndDecode($record);

        $this->assertEquals('stringified', $data['context']['obj']);
    }

    public function test_sanitize_context_handles_generic_object(): void
    {
        $obj = new \stdClass();

        $record = $this->makeRecord(context: ['obj' => $obj]);
        $data = $this->formatAndDecode($record);

        $this->assertEquals('stdClass', $data['context']['obj']);
    }

    public function test_sanitize_context_handles_nested_arrays(): void
    {
        $record = $this->makeRecord(context: [
            'nested' => [
                'deep' => ['value' => 123],
            ],
        ]);
        $data = $this->formatAndDecode($record);

        $this->assertEquals(123, $data['context']['nested']['deep']['value']);
    }

    public function test_sanitize_context_handles_scalar_values(): void
    {
        $record = $this->makeRecord(context: [
            'string' => 'hello',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'null' => null,
        ]);
        $data = $this->formatAndDecode($record);

        $this->assertEquals('hello', $data['context']['string']);
        $this->assertEquals(42, $data['context']['int']);
        $this->assertEquals(3.14, $data['context']['float']);
        $this->assertTrue($data['context']['bool']);
        $this->assertNull($data['context']['null']);
    }

    public function test_environment_comes_from_config(): void
    {
        config(['app.env' => 'testing']);
        $data = $this->formatAndDecode($this->makeRecord());

        $this->assertEquals('testing', $data['environment']);
    }

    public function test_trace_id_from_request_header(): void
    {
        $this->app['request']->headers->set('X-Trace-ID', 'req-trace-abc123');

        $data = $this->formatAndDecode($this->makeRecord());

        $this->assertEquals('req-trace-abc123', $data['trace_id']);
    }

    public function test_trace_id_from_container_binding(): void
    {
        $this->app['request']->headers->remove('X-Trace-ID');
        $this->app->instance('trace_id', 'container-trace-xyz');

        $data = $this->formatAndDecode($this->makeRecord());

        $this->assertEquals('container-trace-xyz', $data['trace_id']);
    }

    public function test_trace_id_fallback_generates_prefixed_id(): void
    {
        $this->app['request']->headers->remove('X-Trace-ID');

        if ($this->app->bound('trace_id')) {
            $this->app->forgetInstance('trace_id');
        }

        $data = $this->formatAndDecode($this->makeRecord());

        $this->assertStringStartsWith('gen-', $data['trace_id']);
        $this->assertEquals(20, strlen($data['trace_id']));
    }

    public function test_tenant_context_from_container_bindings(): void
    {
        $this->app->instance('log_company_id', 42);
        $this->app->instance('log_company_name', 'Acme Corp');

        $data = $this->formatAndDecode($this->makeRecord());

        $this->assertEquals(42, $data['company_id']);
        $this->assertEquals('Acme Corp', $data['company_name']);
    }

    public function test_timestamp_is_iso8601(): void
    {
        $data = $this->formatAndDecode($this->makeRecord());

        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $data['timestamp']);
        $this->assertNotFalse($parsed);
    }
}
