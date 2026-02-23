<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class IncidentTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    public function test_scope_for_company_filters_correctly(): void
    {
        Incident::factory()->create(['company_id' => $this->company->id]);
        $result = Incident::forCompany($this->company->id)->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_for_company_filters_by_company_id(): void
    {
        Incident::factory()->create(['company_id' => $this->company->id]);
        [$otherCompany] = $this->createOtherTenant();
        Incident::factory()->create(['company_id' => $otherCompany->id]);

        $result = Incident::forCompany($this->company->id)->get();
        $this->assertCount(1, $result);
        $this->assertEquals($this->company->id, $result->first()->company_id);
    }

    public function test_scope_open_filters_status_open(): void
    {
        Incident::factory()->create(['company_id' => $this->company->id, 'status' => Incident::STATUS_OPEN]);
        Incident::factory()->create(['company_id' => $this->company->id, 'status' => Incident::STATUS_RESOLVED]);

        $result = Incident::forCompany($this->company->id)->open()->get();
        $this->assertCount(1, $result);
        $this->assertEquals(Incident::STATUS_OPEN, $result->first()->status);
    }

    public function test_scope_unresolved_excludes_resolved_and_false_positive(): void
    {
        Incident::factory()->create(['company_id' => $this->company->id, 'status' => Incident::STATUS_OPEN]);
        Incident::factory()->create(['company_id' => $this->company->id, 'status' => Incident::STATUS_INVESTIGATING]);
        Incident::factory()->create(['company_id' => $this->company->id, 'status' => Incident::STATUS_RESOLVED]);
        Incident::factory()->create(['company_id' => $this->company->id, 'status' => Incident::STATUS_FALSE_POSITIVE]);

        $result = Incident::forCompany($this->company->id)->unresolved()->get();
        $this->assertCount(2, $result);
        $statuses = $result->pluck('status')->toArray();
        $this->assertContains(Incident::STATUS_OPEN, $statuses);
        $this->assertContains(Incident::STATUS_INVESTIGATING, $statuses);
    }

    public function test_scope_by_priority_filters_by_priority(): void
    {
        Incident::factory()->create(['company_id' => $this->company->id, 'priority' => Incident::PRIORITY_P1]);
        Incident::factory()->create(['company_id' => $this->company->id, 'priority' => Incident::PRIORITY_P3]);

        $result = Incident::forCompany($this->company->id)->byPriority(Incident::PRIORITY_P1)->get();
        $this->assertCount(1, $result);
        $this->assertEquals(Incident::PRIORITY_P1, $result->first()->priority);
    }

    public function test_scope_by_type_filters_by_incident_type(): void
    {
        Incident::factory()->create(['company_id' => $this->company->id, 'incident_type' => Incident::TYPE_COLLISION]);
        Incident::factory()->create(['company_id' => $this->company->id, 'incident_type' => Incident::TYPE_EMERGENCY]);

        $result = Incident::forCompany($this->company->id)->byType(Incident::TYPE_COLLISION)->get();
        $this->assertCount(1, $result);
        $this->assertEquals(Incident::TYPE_COLLISION, $result->first()->incident_type);
    }

    public function test_scope_by_status_filters_by_status(): void
    {
        Incident::factory()->create(['company_id' => $this->company->id, 'status' => Incident::STATUS_INVESTIGATING]);
        Incident::factory()->create(['company_id' => $this->company->id, 'status' => Incident::STATUS_OPEN]);

        $result = Incident::forCompany($this->company->id)->byStatus(Incident::STATUS_INVESTIGATING)->get();
        $this->assertCount(1, $result);
        $this->assertEquals(Incident::STATUS_INVESTIGATING, $result->first()->status);
    }

    public function test_scope_for_subject_filters_by_subject_type_and_id(): void
    {
        $subjectId = 'driver-123';
        Incident::factory()->create([
            'company_id' => $this->company->id,
            'subject_type' => Incident::SUBJECT_DRIVER,
            'subject_id' => $subjectId,
        ]);
        Incident::factory()->create([
            'company_id' => $this->company->id,
            'subject_type' => Incident::SUBJECT_VEHICLE,
            'subject_id' => 'vehicle-456',
        ]);

        $result = Incident::forCompany($this->company->id)->forSubject(Incident::SUBJECT_DRIVER, $subjectId)->get();
        $this->assertCount(1, $result);
        $this->assertEquals(Incident::SUBJECT_DRIVER, $result->first()->subject_type);
        $this->assertEquals($subjectId, $result->first()->subject_id);
    }

    public function test_scope_high_priority_filters_p1_and_p2(): void
    {
        Incident::factory()->create(['company_id' => $this->company->id, 'priority' => Incident::PRIORITY_P1]);
        Incident::factory()->create(['company_id' => $this->company->id, 'priority' => Incident::PRIORITY_P2]);
        Incident::factory()->create(['company_id' => $this->company->id, 'priority' => Incident::PRIORITY_P3]);

        $result = Incident::forCompany($this->company->id)->highPriority()->get();
        $this->assertCount(2, $result);
        $priorities = $result->pluck('priority')->toArray();
        $this->assertContains(Incident::PRIORITY_P1, $priorities);
        $this->assertContains(Incident::PRIORITY_P2, $priorities);
    }

    public function test_scope_order_by_priority_asc_orders_p1_first(): void
    {
        Incident::factory()->create(['company_id' => $this->company->id, 'priority' => Incident::PRIORITY_P4]);
        Incident::factory()->create(['company_id' => $this->company->id, 'priority' => Incident::PRIORITY_P1]);
        Incident::factory()->create(['company_id' => $this->company->id, 'priority' => Incident::PRIORITY_P3]);

        $result = Incident::forCompany($this->company->id)->orderByPriority('asc')->get();
        $this->assertEquals(Incident::PRIORITY_P1, $result->first()->priority);
    }

    public function test_scope_order_by_priority_desc_orders_p4_first(): void
    {
        Incident::factory()->create(['company_id' => $this->company->id, 'priority' => Incident::PRIORITY_P1]);
        Incident::factory()->create(['company_id' => $this->company->id, 'priority' => Incident::PRIORITY_P4]);
        Incident::factory()->create(['company_id' => $this->company->id, 'priority' => Incident::PRIORITY_P2]);

        $result = Incident::forCompany($this->company->id)->orderByPriority('desc')->get();
        $this->assertEquals(Incident::PRIORITY_P4, $result->first()->priority);
    }

    public function test_is_resolved_returns_true_for_resolved(): void
    {
        $incident = Incident::factory()->create([
            'company_id' => $this->company->id,
            'status' => Incident::STATUS_RESOLVED,
        ]);
        $this->assertTrue($incident->isResolved());
    }

    public function test_is_resolved_returns_true_for_false_positive(): void
    {
        $incident = Incident::factory()->create([
            'company_id' => $this->company->id,
            'status' => Incident::STATUS_FALSE_POSITIVE,
        ]);
        $this->assertTrue($incident->isResolved());
    }

    public function test_is_resolved_returns_false_for_open(): void
    {
        $incident = Incident::factory()->create([
            'company_id' => $this->company->id,
            'status' => Incident::STATUS_OPEN,
        ]);
        $this->assertFalse($incident->isResolved());
    }

    public function test_is_high_priority_returns_true_for_p1_and_p2(): void
    {
        $p1 = Incident::factory()->create(['company_id' => $this->company->id, 'priority' => Incident::PRIORITY_P1]);
        $p2 = Incident::factory()->create(['company_id' => $this->company->id, 'priority' => Incident::PRIORITY_P2]);
        $this->assertTrue($p1->isHighPriority());
        $this->assertTrue($p2->isHighPriority());
    }

    public function test_is_high_priority_returns_false_for_p3_and_p4(): void
    {
        $p3 = Incident::factory()->create(['company_id' => $this->company->id, 'priority' => Incident::PRIORITY_P3]);
        $p4 = Incident::factory()->create(['company_id' => $this->company->id, 'priority' => Incident::PRIORITY_P4]);
        $this->assertFalse($p3->isHighPriority());
        $this->assertFalse($p4->isHighPriority());
    }

    public function test_mark_as_resolved_sets_status_and_resolved_at(): void
    {
        $incident = Incident::factory()->create([
            'company_id' => $this->company->id,
            'status' => Incident::STATUS_OPEN,
            'resolved_at' => null,
        ]);

        $incident->markAsResolved('Resolved summary');

        $incident->refresh();
        $this->assertEquals(Incident::STATUS_RESOLVED, $incident->status);
        $this->assertNotNull($incident->resolved_at);
        $this->assertEquals('Resolved summary', $incident->ai_summary);
    }

    public function test_mark_as_resolved_preserves_ai_summary_when_null(): void
    {
        $incident = Incident::factory()->create([
            'company_id' => $this->company->id,
            'status' => Incident::STATUS_OPEN,
            'ai_summary' => 'Original summary',
        ]);

        $incident->markAsResolved(null);

        $incident->refresh();
        $this->assertEquals('Original summary', $incident->ai_summary);
    }

    public function test_mark_as_false_positive_sets_status(): void
    {
        $incident = Incident::factory()->create([
            'company_id' => $this->company->id,
            'status' => Incident::STATUS_OPEN,
        ]);

        $incident->markAsFalsePositive('False alarm');

        $incident->refresh();
        $this->assertEquals(Incident::STATUS_FALSE_POSITIVE, $incident->status);
        $this->assertNotNull($incident->resolved_at);
        $this->assertEquals('False alarm', $incident->ai_summary);
    }

    public function test_get_type_label_returns_spanish_labels(): void
    {
        $labels = [
            Incident::TYPE_COLLISION => 'Colisión',
            Incident::TYPE_EMERGENCY => 'Emergencia',
            Incident::TYPE_PATTERN => 'Patrón de comportamiento',
            Incident::TYPE_SAFETY_VIOLATION => 'Violación de seguridad',
            Incident::TYPE_TAMPERING => 'Manipulación',
            Incident::TYPE_UNKNOWN => 'Desconocido',
        ];

        foreach ($labels as $type => $expected) {
            $incident = Incident::factory()->create([
                'company_id' => $this->company->id,
                'incident_type' => $type,
            ]);
            $this->assertEquals($expected, $incident->getTypeLabel());
        }
    }

    public function test_get_status_label_returns_spanish_labels(): void
    {
        $labels = [
            Incident::STATUS_OPEN => 'Abierto',
            Incident::STATUS_INVESTIGATING => 'En investigación',
            Incident::STATUS_PENDING_ACTION => 'Pendiente de acción',
            Incident::STATUS_RESOLVED => 'Resuelto',
            Incident::STATUS_FALSE_POSITIVE => 'Falso positivo',
        ];

        foreach ($labels as $status => $expected) {
            $incident = Incident::factory()->create([
                'company_id' => $this->company->id,
                'status' => $status,
            ]);
            $this->assertEquals($expected, $incident->getStatusLabel());
        }
    }

    public function test_get_priority_label_returns_formatted_labels(): void
    {
        $labels = [
            Incident::PRIORITY_P1 => 'P1 - Crítico',
            Incident::PRIORITY_P2 => 'P2 - Alto',
            Incident::PRIORITY_P3 => 'P3 - Medio',
            Incident::PRIORITY_P4 => 'P4 - Bajo',
        ];

        foreach ($labels as $priority => $expected) {
            $incident = Incident::factory()->create([
                'company_id' => $this->company->id,
                'priority' => $priority,
            ]);
            $this->assertEquals($expected, $incident->getPriorityLabel());
        }
    }

    public function test_get_severity_label_returns_spanish_labels(): void
    {
        $labels = [
            Incident::SEVERITY_CRITICAL => 'Crítico',
            Incident::SEVERITY_WARNING => 'Advertencia',
            Incident::SEVERITY_INFO => 'Información',
        ];

        foreach ($labels as $severity => $expected) {
            $incident = Incident::factory()->create([
                'company_id' => $this->company->id,
                'severity' => $severity,
            ]);
            $this->assertEquals($expected, $incident->getSeverityLabel());
        }
    }

    public function test_generate_dedupe_key_returns_expected_format(): void
    {
        $detectedAt = new \DateTimeImmutable('2025-02-22 14:45:00');
        $key = Incident::generateDedupeKey(
            Incident::TYPE_COLLISION,
            Incident::SUBJECT_DRIVER,
            'driver-123',
            $detectedAt,
            30
        );

        $this->assertStringContainsString(Incident::TYPE_COLLISION, $key);
        $this->assertStringContainsString(Incident::SUBJECT_DRIVER, $key);
        $this->assertStringContainsString('driver-123', $key);
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}-\d{2}-\d{2}/', $key);
    }

    public function test_generate_dedupe_key_filters_null_subject(): void
    {
        $detectedAt = new \DateTimeImmutable('2025-02-22 14:45:00');
        $key = Incident::generateDedupeKey(
            Incident::TYPE_EMERGENCY,
            null,
            null,
            $detectedAt,
            30
        );

        $this->assertStringStartsWith(Incident::TYPE_EMERGENCY . ':', $key);
    }
}
