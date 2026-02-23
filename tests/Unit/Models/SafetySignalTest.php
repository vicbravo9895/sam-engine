<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\Driver;
use App\Models\Incident;
use App\Models\SafetySignal;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class SafetySignalTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    public function test_normalize_behavior_label_returns_null_for_null(): void
    {
        $this->assertNull(SafetySignal::normalizeBehaviorLabel(null));
    }

    public function test_normalize_behavior_label_returns_null_for_empty_string(): void
    {
        $this->assertNull(SafetySignal::normalizeBehaviorLabel(''));
    }

    public function test_normalize_behavior_label_returns_label_when_not_in_canonical(): void
    {
        $this->assertSame('CustomLabel', SafetySignal::normalizeBehaviorLabel('CustomLabel'));
    }

    public function test_normalize_behavior_label_matches_canonical_case_insensitive(): void
    {
        config(['safety_signals.canonical_labels' => ['NoSeatbelt', 'RanRedLight']]);
        $this->assertSame('NoSeatbelt', SafetySignal::normalizeBehaviorLabel('noSeatbelt'));
        $this->assertSame('RanRedLight', SafetySignal::normalizeBehaviorLabel('ranredlight'));
    }

    public function test_get_normalized_labels_combines_primary_and_behavior_labels(): void
    {
        $signal = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-' . uniqid(),
            'primary_behavior_label' => 'Braking',
            'behavior_labels' => [['label' => 'Speeding'], 'HarshTurn'],
            'severity' => SafetySignal::SEVERITY_WARNING,
        ]);

        $labels = $signal->getNormalizedLabels();
        $this->assertContains('Braking', $labels);
        $this->assertContains('Speeding', $labels);
        $this->assertContains('HarshTurn', $labels);
        $this->assertCount(3, $labels);
    }

    public function test_get_normalized_labels_returns_empty_when_no_labels(): void
    {
        $signal = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-' . uniqid(),
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);

        $this->assertSame([], $signal->getNormalizedLabels());
    }

    public function test_get_matched_rule_returns_null_when_no_company(): void
    {
        $signal = SafetySignal::withoutEvents(fn () => SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-' . uniqid(),
            'primary_behavior_label' => 'Crash',
            'severity' => SafetySignal::SEVERITY_CRITICAL,
        ]));
        $signal->setRelation('company', null);

        $this->assertNull($signal->getMatchedRule());
    }

    public function test_get_matched_rule_returns_null_when_company_has_no_samsara_key(): void
    {
        $company = Company::factory()->create(['samsara_api_key' => null]);
        $signal = SafetySignal::create([
            'company_id' => $company->id,
            'samsara_event_id' => 'ev-' . uniqid(),
            'primary_behavior_label' => 'Crash',
            'severity' => SafetySignal::SEVERITY_CRITICAL,
        ]);

        $this->assertNull($signal->getMatchedRule());
    }

    public function test_get_matched_rule_returns_null_when_rule_disabled(): void
    {
        $this->company->setSetting('ai_config.safety_stream_notify', ['enabled' => false, 'rules' => []]);
        $signal = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-' . uniqid(),
            'primary_behavior_label' => 'Crash',
            'severity' => SafetySignal::SEVERITY_CRITICAL,
        ]);

        $this->assertNull($signal->getMatchedRule());
    }

    public function test_get_matched_rule_returns_null_when_no_rules(): void
    {
        $this->company->setSetting('ai_config.safety_stream_notify', ['enabled' => true, 'rules' => []]);
        $signal = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-' . uniqid(),
            'primary_behavior_label' => 'Crash',
            'severity' => SafetySignal::SEVERITY_CRITICAL,
        ]);

        $this->assertNull($signal->getMatchedRule());
    }

    public function test_get_matched_rule_returns_rule_when_signal_matches(): void
    {
        $this->company->setSetting('ai_config.safety_stream_notify', [
            'enabled' => true,
            'rules' => [
                ['id' => 'crash-rule', 'conditions' => ['Crash'], 'action' => 'ai_pipeline'],
            ],
        ]);
        $signal = SafetySignal::withoutEvents(fn () => SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-' . uniqid(),
            'primary_behavior_label' => 'Crash',
            'severity' => SafetySignal::SEVERITY_CRITICAL,
        ]));

        $rule = $signal->getMatchedRule();
        $this->assertNotNull($rule);
        $this->assertSame('crash-rule', $rule['id']);
        $this->assertSame(['Crash'], $rule['conditions']);
    }

    public function test_get_matched_rule_returns_null_when_no_label_match(): void
    {
        $this->company->setSetting('ai_config.safety_stream_notify', [
            'enabled' => true,
            'rules' => [
                ['id' => 'crash-rule', 'conditions' => ['Crash'], 'action' => 'ai_pipeline'],
            ],
        ]);
        $signal = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-' . uniqid(),
            'primary_behavior_label' => 'Speeding',
            'severity' => SafetySignal::SEVERITY_WARNING,
        ]);

        $this->assertNull($signal->getMatchedRule());
    }

    public function test_should_trigger_proactive_notify_returns_true_when_rule_matches(): void
    {
        $this->company->setSetting('ai_config.safety_stream_notify', [
            'enabled' => true,
            'rules' => [
                ['id' => 'crash-rule', 'conditions' => ['Crash'], 'action' => 'ai_pipeline'],
            ],
        ]);
        $signal = SafetySignal::withoutEvents(fn () => SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-' . uniqid(),
            'primary_behavior_label' => 'Crash',
            'severity' => SafetySignal::SEVERITY_CRITICAL,
        ]));

        $this->assertTrue($signal->shouldTriggerProactiveNotify());
    }

    public function test_should_trigger_proactive_notify_returns_false_when_no_rule(): void
    {
        $this->company->setSetting('ai_config.safety_stream_notify', ['enabled' => true, 'rules' => []]);
        $signal = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-' . uniqid(),
            'primary_behavior_label' => 'Speeding',
            'severity' => SafetySignal::SEVERITY_WARNING,
        ]);

        $this->assertFalse($signal->shouldTriggerProactiveNotify());
    }

    public function test_scope_for_company_filters_by_company(): void
    {
        SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);
        [$otherCompany] = $this->createOtherTenant();
        SafetySignal::create([
            'company_id' => $otherCompany->id,
            'samsara_event_id' => 'ev-2',
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);

        $result = SafetySignal::forCompany($this->company->id)->get();
        $this->assertCount(1, $result);
        $this->assertEquals($this->company->id, $result->first()->company_id);
    }

    public function test_scope_by_severity_filters_by_severity(): void
    {
        SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'severity' => SafetySignal::SEVERITY_CRITICAL,
        ]);
        SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-2',
            'severity' => SafetySignal::SEVERITY_WARNING,
        ]);

        $result = SafetySignal::forCompany($this->company->id)->bySeverity(SafetySignal::SEVERITY_CRITICAL)->get();
        $this->assertCount(1, $result);
        $this->assertEquals(SafetySignal::SEVERITY_CRITICAL, $result->first()->severity);
    }

    public function test_scope_for_vehicle_filters_by_vehicle_id(): void
    {
        SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'vehicle_id' => 'vehicle-123',
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);
        SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-2',
            'vehicle_id' => 'vehicle-456',
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);

        $result = SafetySignal::forCompany($this->company->id)->forVehicle('vehicle-123')->get();
        $this->assertCount(1, $result);
        $this->assertEquals('vehicle-123', $result->first()->vehicle_id);
    }

    public function test_scope_for_driver_filters_by_driver_id(): void
    {
        SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'driver_id' => 'driver-123',
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);
        SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-2',
            'driver_id' => 'driver-456',
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);

        $result = SafetySignal::forCompany($this->company->id)->forDriver('driver-123')->get();
        $this->assertCount(1, $result);
        $this->assertEquals('driver-123', $result->first()->driver_id);
    }

    public function test_scope_in_date_range_filters_by_occurred_at(): void
    {
        $start = now()->subDays(2);
        $end = now()->subDay();
        SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'occurred_at' => now()->subDays(1)->subHours(2),
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);
        SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-2',
            'occurred_at' => now()->subDays(5),
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);

        $result = SafetySignal::forCompany($this->company->id)->inDateRange($start, $end)->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_critical_filters_critical_severity(): void
    {
        SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'severity' => SafetySignal::SEVERITY_CRITICAL,
        ]);
        SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-2',
            'severity' => SafetySignal::SEVERITY_WARNING,
        ]);

        $result = SafetySignal::forCompany($this->company->id)->critical()->get();
        $this->assertCount(1, $result);
        $this->assertEquals(SafetySignal::SEVERITY_CRITICAL, $result->first()->severity);
    }

    public function test_scope_needs_review_filters_event_state(): void
    {
        SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'event_state' => SafetySignal::STATE_NEEDS_REVIEW,
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);
        SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-2',
            'event_state' => SafetySignal::STATE_DISMISSED,
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);

        $result = SafetySignal::forCompany($this->company->id)->needsReview()->get();
        $this->assertCount(1, $result);
        $this->assertEquals(SafetySignal::STATE_NEEDS_REVIEW, $result->first()->event_state);
    }

    public function test_scope_unlinked_excludes_signals_with_incidents(): void
    {
        $signal = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);
        $unlinked = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-2',
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);

        $incident = Incident::factory()->create(['company_id' => $this->company->id]);
        $incident->safetySignals()->attach($signal->id);

        $result = SafetySignal::forCompany($this->company->id)->unlinked()->get();
        $this->assertCount(1, $result);
        $this->assertEquals($unlinked->id, $result->first()->id);
    }

    public function test_used_in_evidence_attribute_returns_true_when_linked_to_incident(): void
    {
        $signal = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);
        $incident = Incident::factory()->create(['company_id' => $this->company->id]);
        $incident->safetySignals()->attach($signal->id);

        $signal->refresh();
        $this->assertTrue($signal->used_in_evidence);
    }

    public function test_used_in_evidence_attribute_returns_false_when_not_linked(): void
    {
        $signal = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);

        $this->assertFalse($signal->used_in_evidence);
    }

    public function test_primary_label_translated_attribute_returns_translation(): void
    {
        $signal = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'primary_behavior_label' => 'Braking',
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);

        $this->assertSame('Frenado brusco', $signal->primary_label_translated);
    }

    public function test_primary_label_translated_attribute_returns_null_when_no_label(): void
    {
        $signal = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);

        $this->assertNull($signal->primary_label_translated);
    }

    public function test_primary_label_data_attribute_returns_translation_array(): void
    {
        $signal = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'primary_behavior_label' => 'Braking',
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);

        $data = $signal->primary_label_data;
        $this->assertIsArray($data);
        $this->assertSame('Frenado brusco', $data['name']);
    }

    public function test_primary_label_data_attribute_returns_null_when_no_label(): void
    {
        $signal = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);

        $this->assertNull($signal->primary_label_data);
    }

    public function test_behavior_labels_translated_attribute_returns_translations(): void
    {
        $signal = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'behavior_labels' => [['label' => 'Braking'], ['label' => 'Speeding']],
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);

        $translated = $signal->behavior_labels_translated;
        $this->assertCount(2, $translated);
        $this->assertSame('Braking', $translated[0]['original']);
        $this->assertSame('Frenado brusco', $translated[0]['name']);
    }

    public function test_behavior_labels_translated_attribute_returns_empty_when_no_labels(): void
    {
        $signal = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);

        $this->assertSame([], $signal->behavior_labels_translated);
    }

    public function test_event_state_translated_attribute_returns_translation(): void
    {
        $signal = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'event_state' => SafetySignal::STATE_NEEDS_REVIEW,
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);

        $this->assertSame('Necesita revisión', $signal->event_state_translated);
    }

    public function test_event_state_translated_attribute_returns_null_when_no_state(): void
    {
        $signal = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);

        $this->assertNull($signal->event_state_translated);
    }

    public function test_severity_label_attribute_returns_spanish_labels(): void
    {
        $critical = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'severity' => SafetySignal::SEVERITY_CRITICAL,
        ]);
        $warning = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-2',
            'severity' => SafetySignal::SEVERITY_WARNING,
        ]);
        $info = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-3',
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);

        $this->assertSame('Crítico', $critical->severity_label);
        $this->assertSame('Advertencia', $warning->severity_label);
        $this->assertSame('Información', $info->severity_label);
    }

    public function test_company_relationship(): void
    {
        $signal = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);

        $this->assertNotNull($signal->company);
        $this->assertEquals($this->company->id, $signal->company->id);
    }

    public function test_incidents_relationship(): void
    {
        $signal = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);
        $incident = Incident::factory()->create(['company_id' => $this->company->id]);
        $incident->safetySignals()->attach($signal->id, ['role' => 'supporting']);

        $signal->refresh();
        $this->assertCount(1, $signal->incidents);
        $this->assertEquals($incident->id, $signal->incidents->first()->id);
    }

    public function test_vehicle_relationship(): void
    {
        $vehicle = Vehicle::factory()->forCompany($this->company)->create(['samsara_id' => 'vehicle-123']);
        $signal = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'vehicle_id' => 'vehicle-123',
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);

        $this->assertNotNull($signal->vehicle);
        $this->assertEquals($vehicle->id, $signal->vehicle->id);
    }

    public function test_driver_relationship(): void
    {
        $driver = Driver::factory()->forCompany($this->company)->create(['samsara_id' => 'driver-123']);
        $signal = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'driver_id' => 'driver-123',
            'severity' => SafetySignal::SEVERITY_INFO,
        ]);

        $this->assertNotNull($signal->driver);
        $this->assertEquals($driver->id, $signal->driver->id);
    }

    public function test_enrich_from_local_data_uses_vehicle_name_when_missing(): void
    {
        $vehicle = Vehicle::factory()->forCompany($this->company)->create([
            'samsara_id' => 'vehicle-123',
            'name' => 'T-012345',
        ]);

        $attributes = SafetySignal::enrichFromLocalData($this->company->id, [
            'vehicle_id' => 'vehicle-123',
            'vehicle_name' => '',
        ]);

        $this->assertSame('T-012345', $attributes['vehicle_name']);
    }

    public function test_enrich_from_local_data_uses_driver_name_when_missing(): void
    {
        $driver = Driver::factory()->forCompany($this->company)->create([
            'samsara_id' => 'driver-123',
            'name' => 'John Doe',
        ]);

        $attributes = SafetySignal::enrichFromLocalData($this->company->id, [
            'driver_id' => 'driver-123',
            'driver_name' => '',
        ]);

        $this->assertSame('John Doe', $attributes['driver_name']);
    }

    public function test_enrich_from_local_data_does_not_overwrite_existing_names(): void
    {
        $attributes = SafetySignal::enrichFromLocalData($this->company->id, [
            'vehicle_id' => 'vehicle-123',
            'vehicle_name' => 'Already Set',
            'driver_id' => 'driver-123',
            'driver_name' => 'Already Set',
        ]);

        $this->assertSame('Already Set', $attributes['vehicle_name']);
        $this->assertSame('Already Set', $attributes['driver_name']);
    }

    public function test_create_from_stream_event_creates_signal(): void
    {
        $eventData = [
            'id' => 'ev-stream-' . uniqid(),
            'asset' => ['id' => 'v1', 'name' => 'T-001'],
            'driver' => ['id' => 'd1', 'name' => 'Driver One'],
            'location' => ['latitude' => 19.43, 'longitude' => -99.13],
            'behaviorLabels' => [['label' => 'Braking']],
            'contextLabels' => [],
            'eventState' => 'needsReview',
            'startMs' => (int) (now()->subHour()->getTimestamp() * 1000),
        ];

        $signal = SafetySignal::withoutEvents(fn () => SafetySignal::createFromStreamEvent($this->company->id, $eventData));

        $this->assertInstanceOf(SafetySignal::class, $signal);
        $this->assertEquals($this->company->id, $signal->company_id);
        $this->assertEquals($eventData['id'], $signal->samsara_event_id);
        $this->assertEquals('Braking', $signal->primary_behavior_label);
        $this->assertEquals(SafetySignal::SEVERITY_WARNING, $signal->severity);
    }

    public function test_create_from_stream_event_uses_string_labels(): void
    {
        $eventData = [
            'id' => 'ev-stream-' . uniqid(),
            'asset' => [],
            'driver' => [],
            'location' => [],
            'behaviorLabels' => ['Crash'],
            'contextLabels' => [],
            'startMs' => (int) (now()->subHour()->getTimestamp() * 1000),
        ];

        $signal = SafetySignal::withoutEvents(fn () => SafetySignal::createFromStreamEvent($this->company->id, $eventData));

        $this->assertEquals('Crash', $signal->primary_behavior_label);
        $this->assertEquals(SafetySignal::SEVERITY_CRITICAL, $signal->severity);
    }

    public function test_create_from_stream_event_formats_address(): void
    {
        $eventData = [
            'id' => 'ev-stream-' . uniqid(),
            'asset' => [],
            'driver' => [],
            'location' => [
                'address' => [
                    'street' => 'Main St',
                    'city' => 'Mexico City',
                    'state' => 'CDMX',
                    'postalCode' => '06000',
                ],
            ],
            'behaviorLabels' => ['Speeding'],
            'contextLabels' => [],
            'startMs' => (int) (now()->subHour()->getTimestamp() * 1000),
        ];

        $signal = SafetySignal::withoutEvents(fn () => SafetySignal::createFromStreamEvent($this->company->id, $eventData));

        $this->assertSame('Main St, Mexico City, CDMX, 06000', $signal->address);
    }

    public function test_update_from_stream_event_updates_signal(): void
    {
        $signal = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'vehicle_id' => 'v1',
            'vehicle_name' => 'T-001',
            'primary_behavior_label' => 'Braking',
            'severity' => SafetySignal::SEVERITY_WARNING,
        ]);

        $eventData = [
            'id' => 'ev-1',
            'asset' => ['id' => 'v1', 'name' => 'T-001-Updated'],
            'driver' => ['id' => 'd1', 'name' => 'New Driver'],
            'location' => [],
            'behaviorLabels' => [['label' => 'Speeding']],
            'contextLabels' => [],
            'eventState' => 'dismissed',
        ];

        $signal->updateFromStreamEvent($eventData);

        $signal->refresh();
        $this->assertSame('T-001-Updated', $signal->vehicle_name);
        $this->assertSame('New Driver', $signal->driver_name);
        $this->assertSame('Speeding', $signal->primary_behavior_label);
        $this->assertSame('dismissed', $signal->event_state);
    }

    public function test_scope_severity_label_default_for_unknown(): void
    {
        $signal = SafetySignal::create([
            'company_id' => $this->company->id,
            'samsara_event_id' => 'ev-1',
            'severity' => 'unknown',
        ]);

        $this->assertSame('Información', $signal->severity_label);
    }
}
