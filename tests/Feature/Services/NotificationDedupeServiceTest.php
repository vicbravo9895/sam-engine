<?php

namespace Tests\Feature\Services;

use App\Models\NotificationDedupeLog;
use App\Models\NotificationThrottleLog;
use App\Services\NotificationDedupeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class NotificationDedupeServiceTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    protected NotificationDedupeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
        $this->service = new NotificationDedupeService(24, 30, 5);
    }

    public function test_should_send_returns_true_for_new_dedupe_key(): void
    {
        $result = $this->service->shouldSend(
            'vehicle123:2024-01-15T10:00:00Z:AlertIncident',
            'vehicle123',
            'driver456',
            1
        );

        $this->assertTrue($result['should_send']);
        $this->assertNull($result['reason']);
        $this->assertFalse($result['throttled']);
    }

    public function test_should_send_returns_false_for_duplicate_dedupe_key(): void
    {
        $dedupeKey = 'vehicle123:2024-01-15T10:00:00Z:AlertIncident';

        $this->service->shouldSend($dedupeKey, 'vehicle123', 'driver456', 1);
        $result = $this->service->shouldSend($dedupeKey, 'vehicle123', 'driver456', 2);

        $this->assertFalse($result['should_send']);
        $this->assertStringContainsString('duplicada', $result['reason']);
        $this->assertFalse($result['throttled']);
    }

    public function test_is_duplicate_returns_false_for_new_key(): void
    {
        $this->assertFalse($this->service->isDuplicate('new-key-123'));
    }

    public function test_is_duplicate_returns_true_for_existing_key(): void
    {
        $dedupeKey = 'vehicle:event:type';
        $this->service->isDuplicate($dedupeKey);
        $this->assertTrue($this->service->isDuplicate($dedupeKey));
    }

    public function test_is_duplicate_returns_false_for_empty_key(): void
    {
        $this->assertFalse($this->service->isDuplicate(''));
    }

    public function test_should_throttle_returns_false_when_under_limit(): void
    {
        $throttleKey = NotificationThrottleLog::generateKey('v1', 'd1');
        [$shouldThrottle, $reason] = $this->service->shouldThrottle($throttleKey);

        $this->assertFalse($shouldThrottle);
        $this->assertNull($reason);
    }

    public function test_should_throttle_returns_true_when_over_limit(): void
    {
        $service = new NotificationDedupeService(24, 60, 2);
        $throttleKey = NotificationThrottleLog::generateKey('v1', 'd1');

        NotificationThrottleLog::record($throttleKey, 1);
        NotificationThrottleLog::record($throttleKey, 2);
        [$shouldThrottle, $reason] = $service->shouldThrottle($throttleKey);

        $this->assertTrue($shouldThrottle);
        $this->assertStringContainsString('Límite', $reason);
    }

    public function test_should_send_returns_false_when_throttled(): void
    {
        $service = new NotificationDedupeService(24, 60, 1);
        $throttleKey = NotificationThrottleLog::generateKey('v1', 'd1');

        NotificationThrottleLog::record($throttleKey, 1);

        $result = $service->shouldSend(
            'unique-key-1:' . now()->toIso8601String() . ':Alert',
            'v1',
            'd1',
            2
        );

        $this->assertFalse($result['should_send']);
        $this->assertTrue($result['throttled']);
    }

    public function test_record_notification_creates_throttle_log_entry(): void
    {
        $throttleKey = NotificationThrottleLog::generateKey('v1', 'd1');

        $this->service->recordNotification($throttleKey, 42);

        $this->assertDatabaseHas('notification_throttle_logs', [
            'throttle_key' => $throttleKey,
            'alert_id' => 42,
        ]);
    }

    public function test_get_dedupe_stats_returns_null_for_unknown_key(): void
    {
        $this->assertNull($this->service->getDedupeStats('nonexistent-key'));
    }

    public function test_get_dedupe_stats_returns_stats_after_key_seen(): void
    {
        $dedupeKey = 'vehicle:event:type';
        $this->service->isDuplicate($dedupeKey);

        $stats = $this->service->getDedupeStats($dedupeKey);

        $this->assertIsArray($stats);
        $this->assertSame($dedupeKey, $stats['dedupe_key']);
        $this->assertArrayHasKey('first_seen_at', $stats);
        $this->assertArrayHasKey('last_seen_at', $stats);
        $this->assertArrayHasKey('count', $stats);
    }

    public function test_get_throttle_count_returns_count_in_window(): void
    {
        $throttleKey = NotificationThrottleLog::generateKey('v1', 'd1');
        NotificationThrottleLog::record($throttleKey, 1);
        NotificationThrottleLog::record($throttleKey, 2);

        $count = $this->service->getThrottleCount($throttleKey);

        $this->assertSame(2, $count);
    }

    public function test_cleanup_returns_deleted_counts(): void
    {
        $result = $this->service->cleanup();

        $this->assertArrayHasKey('dedupe_deleted', $result);
        $this->assertArrayHasKey('throttle_deleted', $result);
        $this->assertIsInt($result['dedupe_deleted']);
        $this->assertIsInt($result['throttle_deleted']);
    }

    public function test_mark_as_processed_creates_dedupe_entry(): void
    {
        $dedupeKey = 'processed-key-123';
        $this->service->markAsProcessed($dedupeKey);

        $this->assertTrue($this->service->isDuplicate($dedupeKey));
    }

    public function test_mark_as_processed_ignores_empty_key(): void
    {
        $this->service->markAsProcessed('');

        $this->assertDatabaseCount('notification_dedupe_logs', 0);
    }

    public function test_reset_throttle_deletes_entries(): void
    {
        $throttleKey = NotificationThrottleLog::generateKey('v1', 'd1');
        NotificationThrottleLog::record($throttleKey, 1);

        $deleted = $this->service->resetThrottle($throttleKey);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('notification_throttle_logs', [
            'throttle_key' => $throttleKey,
        ]);
    }

    public function test_generate_dedupe_key_formats_correctly(): void
    {
        $key = NotificationDedupeService::generateDedupeKey(
            'vehicle123',
            '2024-01-15T10:00:00Z',
            'AlertIncident'
        );

        $this->assertSame('vehicle123:2024-01-15T10:00:00Z:AlertIncident', $key);
    }

    public function test_generate_throttle_key_with_vehicle_and_driver(): void
    {
        $key = NotificationDedupeService::generateThrottleKey('v123', 'd456');

        $this->assertSame('v:v123:d:d456', $key);
    }

    public function test_generate_throttle_key_with_vehicle_only(): void
    {
        $key = NotificationDedupeService::generateThrottleKey('v123', null);

        $this->assertSame('v:v123', $key);
    }

    public function test_generate_throttle_key_with_null_ids_returns_global(): void
    {
        $key = NotificationDedupeService::generateThrottleKey(null, null);

        $this->assertSame('global', $key);
    }

    public function test_constructor_uses_config_defaults(): void
    {
        $service = new NotificationDedupeService();

        $result = $service->shouldSend('config-test:' . now()->toIso8601String() . ':Test', null, null, null);

        $this->assertTrue($result['should_send']);
    }

    public function test_constructor_accepts_custom_settings(): void
    {
        $service = new NotificationDedupeService(48, 15, 3);

        $result = $service->shouldSend('custom:' . now()->toIso8601String() . ':Test', null, null, null);

        $this->assertTrue($result['should_send']);
    }
}
