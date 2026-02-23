<?php

namespace Tests\Feature\Jobs;

use App\Http\Controllers\SamsaraWebhookController;
use App\Jobs\ProcessPendingWebhooksJob;
use App\Models\PendingWebhook;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class ProcessPendingWebhooksJobTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    private function createPendingWebhook(array $overrides = []): PendingWebhook
    {
        return PendingWebhook::create(array_merge([
            'vehicle_samsara_id' => '1234567890',
            'event_type' => 'AlertIncident',
            'raw_payload' => $this->fakeSamsaraPayload(),
            'attempts' => 0,
            'max_attempts' => 5,
        ], $overrides));
    }

    private function fakeSamsaraPayload(string $vehicleSamsaraId = '1234567890'): array
    {
        return [
            'eventId' => 'evt-' . fake()->uuid(),
            'eventType' => 'AlertIncident',
            'data' => [
                'alerts' => [[
                    'vehicle' => ['id' => $vehicleSamsaraId, 'name' => 'T-001'],
                    'incidentType' => 'panicButton',
                    'resolvedAtTime' => null,
                ]],
            ],
        ];
    }

    private function runJob(): void
    {
        app()->call([new ProcessPendingWebhooksJob(), 'handle']);
    }

    // ──────────────────────────────────────────────────
    // Configuration
    // ──────────────────────────────────────────────────

    public function test_job_has_correct_configuration(): void
    {
        $job = new ProcessPendingWebhooksJob();

        $this->assertSame(1, $job->tries);
        $this->assertSame(120, $job->timeout);
    }

    // ──────────────────────────────────────────────────
    // Empty queue
    // ──────────────────────────────────────────────────

    public function test_does_nothing_when_no_pending_webhooks(): void
    {
        $this->runJob();

        $this->assertSame(0, PendingWebhook::count());
    }

    public function test_does_nothing_when_all_webhooks_resolved(): void
    {
        $this->createPendingWebhook([
            'resolved_at' => now(),
            'resolution_note' => 'Already resolved',
        ]);

        $this->runJob();

        $this->assertDatabaseHas('pending_webhooks', [
            'resolution_note' => 'Already resolved',
        ]);
    }

    // ──────────────────────────────────────────────────
    // Vehicle not found — increment attempts
    // ──────────────────────────────────────────────────

    public function test_increments_attempt_when_vehicle_not_found(): void
    {
        $pending = $this->createPendingWebhook([
            'vehicle_samsara_id' => 'unknown-vehicle',
            'attempts' => 0,
        ]);

        $this->runJob();

        $pending->refresh();
        $this->assertSame(1, $pending->attempts);
        $this->assertNull($pending->resolved_at);
        $this->assertNotNull($pending->last_attempted_at);
    }

    public function test_exhausts_webhook_at_max_attempts(): void
    {
        $pending = $this->createPendingWebhook([
            'vehicle_samsara_id' => 'unknown-vehicle',
            'attempts' => 4,
            'max_attempts' => 5,
        ]);

        $this->runJob();

        $pending->refresh();
        $this->assertSame(5, $pending->attempts);
        $this->assertNull($pending->resolved_at);
    }

    public function test_exhausted_webhooks_not_picked_up_again(): void
    {
        $pending = $this->createPendingWebhook([
            'vehicle_samsara_id' => 'unknown-vehicle',
            'attempts' => 5,
            'max_attempts' => 5,
        ]);

        $this->runJob();

        $pending->refresh();
        // Still 5 — wasn't processed again because unresolved() scope excludes it
        $this->assertSame(5, $pending->attempts);
    }

    // ──────────────────────────────────────────────────
    // Vehicle found — resolve webhook
    // ──────────────────────────────────────────────────

    public function test_resolves_webhook_when_vehicle_found(): void
    {
        $vehicle = Vehicle::factory()->forCompany($this->company)->create([
            'samsara_id' => 'known-vehicle',
        ]);

        $pending = $this->createPendingWebhook([
            'vehicle_samsara_id' => 'known-vehicle',
            'raw_payload' => $this->fakeSamsaraPayload('known-vehicle'),
        ]);

        $controller = \Mockery::mock(SamsaraWebhookController::class);
        $controller->shouldReceive('handle')
            ->once()
            ->andReturn(new JsonResponse(['ok' => true], 200));
        $this->app->instance(SamsaraWebhookController::class, $controller);

        $this->runJob();

        $pending->refresh();
        $this->assertNotNull($pending->resolved_at);
        $this->assertStringContainsString('company_id', $pending->resolution_note);
    }

    public function test_increments_attempt_when_controller_returns_error_status(): void
    {
        Vehicle::factory()->forCompany($this->company)->create([
            'samsara_id' => 'known-vehicle',
        ]);

        $pending = $this->createPendingWebhook([
            'vehicle_samsara_id' => 'known-vehicle',
            'raw_payload' => $this->fakeSamsaraPayload('known-vehicle'),
            'attempts' => 1,
        ]);

        $controller = \Mockery::mock(SamsaraWebhookController::class);
        $controller->shouldReceive('handle')
            ->once()
            ->andReturn(new JsonResponse(['error' => 'duplicate'], 422));
        $this->app->instance(SamsaraWebhookController::class, $controller);

        $this->runJob();

        $pending->refresh();
        $this->assertSame(2, $pending->attempts);
        $this->assertNull($pending->resolved_at);
    }

    // ──────────────────────────────────────────────────
    // Controller exception handling
    // ──────────────────────────────────────────────────

    public function test_handles_controller_exception_gracefully(): void
    {
        Vehicle::factory()->forCompany($this->company)->create([
            'samsara_id' => 'crash-vehicle',
        ]);

        $pending = $this->createPendingWebhook([
            'vehicle_samsara_id' => 'crash-vehicle',
            'raw_payload' => $this->fakeSamsaraPayload('crash-vehicle'),
            'attempts' => 0,
        ]);

        $controller = \Mockery::mock(SamsaraWebhookController::class);
        $controller->shouldReceive('handle')
            ->once()
            ->andThrow(new \Exception('Database deadlock'));
        $this->app->instance(SamsaraWebhookController::class, $controller);

        $this->runJob();

        $pending->refresh();
        $this->assertSame(1, $pending->attempts);
        $this->assertNull($pending->resolved_at);
    }

    // ──────────────────────────────────────────────────
    // Batch size limit
    // ──────────────────────────────────────────────────

    public function test_limits_batch_to_50(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $this->createPendingWebhook([
                'vehicle_samsara_id' => "vehicle-{$i}",
            ]);
        }

        // All vehicles are unknown, so attempts will increment
        $this->runJob();

        $processed = PendingWebhook::where('attempts', '>', 0)->count();
        $untouched = PendingWebhook::where('attempts', 0)->count();

        $this->assertSame(50, $processed);
        $this->assertSame(5, $untouched);
    }

    // ──────────────────────────────────────────────────
    // Mixed batch
    // ──────────────────────────────────────────────────

    public function test_processes_mixed_batch_correctly(): void
    {
        $vehicle = Vehicle::factory()->forCompany($this->company)->create([
            'samsara_id' => 'found-vehicle',
        ]);

        $resolvable = $this->createPendingWebhook([
            'vehicle_samsara_id' => 'found-vehicle',
            'raw_payload' => $this->fakeSamsaraPayload('found-vehicle'),
        ]);
        $unresolvable = $this->createPendingWebhook([
            'vehicle_samsara_id' => 'missing-vehicle',
        ]);
        $nearExhaustion = $this->createPendingWebhook([
            'vehicle_samsara_id' => 'also-missing',
            'attempts' => 4,
            'max_attempts' => 5,
        ]);

        $controller = \Mockery::mock(SamsaraWebhookController::class);
        $controller->shouldReceive('handle')
            ->once()
            ->andReturn(new JsonResponse(['ok' => true], 200));
        $this->app->instance(SamsaraWebhookController::class, $controller);

        $this->runJob();

        $resolvable->refresh();
        $unresolvable->refresh();
        $nearExhaustion->refresh();

        $this->assertNotNull($resolvable->resolved_at);
        $this->assertSame(1, $unresolvable->attempts);
        $this->assertSame(5, $nearExhaustion->attempts);
    }

    // ──────────────────────────────────────────────────
    // Vehicle without company_id is ignored
    // ──────────────────────────────────────────────────

    public function test_ignores_vehicle_without_company_id(): void
    {
        Vehicle::factory()->create([
            'samsara_id' => 'orphan-vehicle',
            'company_id' => null,
        ]);

        $pending = $this->createPendingWebhook([
            'vehicle_samsara_id' => 'orphan-vehicle',
        ]);

        $this->runJob();

        $pending->refresh();
        $this->assertSame(1, $pending->attempts);
        $this->assertNull($pending->resolved_at);
    }

}
