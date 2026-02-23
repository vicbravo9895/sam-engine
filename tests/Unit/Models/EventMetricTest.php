<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\EventMetric;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class EventMetricTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    private function createMetric(array $overrides = []): EventMetric
    {
        return EventMetric::create(array_merge([
            'company_id' => $this->company->id,
            'metric_date' => now()->toDateString(),
            'total_events' => 0,
            'critical_events' => 0,
            'warning_events' => 0,
            'info_events' => 0,
            'false_positive_count' => 0,
            'notifications_sent' => 0,
            'notifications_failed' => 0,
            'incidents_detected' => 0,
            'incidents_resolved' => 0,
        ], $overrides));
    }

    public function test_company_relationship(): void
    {
        $metric = $this->createMetric();

        $this->assertNotNull($metric->company);
        $this->assertEquals($this->company->id, $metric->company->id);
    }

    public function test_scope_for_company(): void
    {
        $this->createMetric();
        [$otherCompany] = $this->createOtherTenant();
        EventMetric::create([
            'company_id' => $otherCompany->id,
            'metric_date' => now()->toDateString(),
            'total_events' => 0,
            'critical_events' => 0,
            'warning_events' => 0,
            'info_events' => 0,
        ]);

        $result = EventMetric::forCompany($this->company->id)->get();

        $this->assertCount(1, $result);
        $this->assertEquals($this->company->id, $result->first()->company_id);
    }

    public function test_scope_date_range(): void
    {
        $this->createMetric(['metric_date' => '2026-01-10']);
        $this->createMetric(['metric_date' => '2026-01-15']);
        $this->createMetric(['metric_date' => '2026-01-20']);

        $result = EventMetric::dateRange('2026-01-10', '2026-01-15')->get();

        $this->assertCount(2, $result);
    }

    public function test_scope_last_days(): void
    {
        $this->createMetric(['metric_date' => now()->toDateString()]);
        $this->createMetric(['metric_date' => now()->subDays(3)->toDateString()]);
        $this->createMetric(['metric_date' => now()->subDays(10)->toDateString()]);

        $result = EventMetric::lastDays(7)->get();

        $this->assertCount(2, $result);
    }

    public function test_get_false_positive_rate_with_events(): void
    {
        $metric = $this->createMetric([
            'total_events' => 100,
            'false_positive_count' => 25,
        ]);

        $this->assertEquals(25.0, $metric->getFalsePositiveRate());
    }

    public function test_get_false_positive_rate_with_zero_events(): void
    {
        $metric = $this->createMetric(['total_events' => 0, 'false_positive_count' => 0]);

        $this->assertEquals(0.0, $metric->getFalsePositiveRate());
    }

    public function test_get_notification_success_rate_normal(): void
    {
        $metric = $this->createMetric([
            'notifications_sent' => 90,
            'notifications_failed' => 10,
        ]);

        $this->assertEquals(90.0, $metric->getNotificationSuccessRate());
    }

    public function test_get_notification_success_rate_with_zero_notifications(): void
    {
        $metric = $this->createMetric([
            'notifications_sent' => 0,
            'notifications_failed' => 0,
        ]);

        $this->assertEquals(100.0, $metric->getNotificationSuccessRate());
    }

    public function test_get_incident_resolution_rate_normal(): void
    {
        $metric = $this->createMetric([
            'incidents_detected' => 10,
            'incidents_resolved' => 8,
        ]);

        $this->assertEquals(80.0, $metric->getIncidentResolutionRate());
    }

    public function test_get_incident_resolution_rate_with_zero_incidents(): void
    {
        $metric = $this->createMetric([
            'incidents_detected' => 0,
            'incidents_resolved' => 0,
        ]);

        $this->assertEquals(100.0, $metric->getIncidentResolutionRate());
    }

    public function test_get_or_create_creates_new_metric(): void
    {
        $date = '2026-03-01';
        $metric = EventMetric::getOrCreate($this->company->id, $date);

        $this->assertInstanceOf(EventMetric::class, $metric);
        $this->assertEquals($this->company->id, $metric->company_id);
        $this->assertEquals($date, $metric->metric_date->toDateString());
        $this->assertEquals(0, $metric->total_events);
    }

    public function test_get_or_create_returns_existing_metric(): void
    {
        $date = '2026-03-01';
        $existing = $this->createMetric([
            'metric_date' => $date,
            'total_events' => 50,
        ]);

        $metric = EventMetric::getOrCreate($this->company->id, $date);

        $this->assertEquals($existing->id, $metric->id);
        $this->assertEquals(50, $metric->total_events);
    }

    public function test_increment_metric(): void
    {
        $metric = $this->createMetric(['total_events' => 5]);

        $metric->incrementMetric('total_events', 3);

        $this->assertEquals(8, $metric->fresh()->total_events);
    }

    public function test_increment_metric_defaults_to_one(): void
    {
        $metric = $this->createMetric(['critical_events' => 0]);

        $metric->incrementMetric('critical_events');

        $this->assertEquals(1, $metric->fresh()->critical_events);
    }

    public function test_to_summary_array_returns_correct_structure(): void
    {
        $metric = $this->createMetric([
            'metric_date' => '2026-02-15',
            'total_events' => 100,
            'critical_events' => 10,
            'false_positive_count' => 20,
            'notifications_sent' => 80,
            'notifications_failed' => 5,
            'incidents_detected' => 15,
            'incidents_resolved' => 12,
            'avg_processing_time_ms' => 1500,
            'avg_response_time_minutes' => 3,
        ]);

        $summary = $metric->toSummaryArray();

        $this->assertEquals('2026-02-15', $summary['date']);
        $this->assertEquals(100, $summary['total_events']);
        $this->assertEquals(10, $summary['critical_events']);
        $this->assertEquals(20.0, $summary['false_positive_rate']);
        $this->assertArrayHasKey('notification_success_rate', $summary);
        $this->assertArrayHasKey('incident_resolution_rate', $summary);
        $this->assertEquals(1500, $summary['avg_processing_time_ms']);
        $this->assertEquals(3, $summary['avg_response_time_minutes']);
    }

    public function test_created_at_is_auto_set_on_creation(): void
    {
        Carbon::setTestNow('2026-02-15 10:00:00');

        $metric = $this->createMetric();

        $this->assertNotNull($metric->created_at);
        $this->assertEquals('2026-02-15 10:00:00', $metric->created_at->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_created_at_is_not_overridden_when_explicitly_set(): void
    {
        $explicit = '2026-01-01 00:00:00';
        $metric = $this->createMetric(['created_at' => $explicit]);

        $this->assertEquals($explicit, $metric->created_at->toDateTimeString());
    }
}
