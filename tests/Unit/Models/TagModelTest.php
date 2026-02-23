<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class TagModelTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    public function test_company_relationship(): void
    {
        $tag = Tag::factory()->create(['company_id' => $this->company->id]);

        $this->assertNotNull($tag->company);
        $this->assertEquals($this->company->id, $tag->company->id);
    }

    public function test_parent_relationship(): void
    {
        $parent = Tag::factory()->create([
            'company_id' => $this->company->id,
            'samsara_id' => 'parent-tag-100',
        ]);
        $child = Tag::factory()->withParent('parent-tag-100')->create([
            'company_id' => $this->company->id,
        ]);

        $this->assertNotNull($child->parent);
        $this->assertEquals($parent->id, $child->parent->id);
    }

    public function test_children_relationship(): void
    {
        $parent = Tag::factory()->create([
            'company_id' => $this->company->id,
            'samsara_id' => 'parent-tag-200',
        ]);
        Tag::factory()->withParent('parent-tag-200')->count(3)->create([
            'company_id' => $this->company->id,
        ]);

        $this->assertCount(3, $parent->children);
    }

    public function test_scope_for_company_with_valid_company_id(): void
    {
        Tag::factory()->create(['company_id' => $this->company->id]);
        [$otherCompany] = $this->createOtherTenant();
        Tag::factory()->create(['company_id' => $otherCompany->id]);

        $result = Tag::forCompany($this->company->id)->get();

        $this->assertCount(1, $result);
        $this->assertEquals($this->company->id, $result->first()->company_id);
    }

    public function test_scope_for_company_with_null_returns_no_rows(): void
    {
        Tag::factory()->create(['company_id' => $this->company->id]);

        $result = Tag::forCompany(null)->get();

        $this->assertCount(0, $result);
    }

    public function test_generate_data_hash_returns_consistent_hash(): void
    {
        $data = ['id' => '123', 'name' => 'Fleet A', 'parentTagId' => null];
        $hash1 = Tag::generateDataHash($data);
        $hash2 = Tag::generateDataHash($data);

        $this->assertSame($hash1, $hash2);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $hash1);
    }

    public function test_generate_data_hash_is_key_order_independent(): void
    {
        $hash1 = Tag::generateDataHash(['name' => 'A', 'id' => '1']);
        $hash2 = Tag::generateDataHash(['id' => '1', 'name' => 'A']);

        $this->assertSame($hash1, $hash2);
    }

    public function test_generate_data_hash_differs_for_different_data(): void
    {
        $hash1 = Tag::generateDataHash(['id' => '1', 'name' => 'A']);
        $hash2 = Tag::generateDataHash(['id' => '1', 'name' => 'B']);

        $this->assertNotSame($hash1, $hash2);
    }

    public function test_has_data_changed_returns_true_when_hash_differs(): void
    {
        $tag = Tag::factory()->create([
            'company_id' => $this->company->id,
            'data_hash' => Tag::generateDataHash(['id' => '1', 'name' => 'Old']),
        ]);

        $this->assertTrue($tag->hasDataChanged(['id' => '1', 'name' => 'New']));
    }

    public function test_has_data_changed_returns_false_when_hash_matches(): void
    {
        $data = ['id' => '1', 'name' => 'Same'];
        $tag = Tag::factory()->create([
            'company_id' => $this->company->id,
            'data_hash' => Tag::generateDataHash($data),
        ]);

        $this->assertFalse($tag->hasDataChanged($data));
    }

    public function test_sync_from_samsara_creates_new_tag(): void
    {
        $samsaraData = [
            'id' => 'samsara-tag-' . uniqid(),
            'name' => 'Fleet Operations',
            'parentTagId' => null,
            'vehicles' => [['id' => 'v1'], ['id' => 'v2']],
            'drivers' => [['id' => 'd1']],
        ];

        $tag = Tag::syncFromSamsara($samsaraData, $this->company->id);

        $this->assertInstanceOf(Tag::class, $tag);
        $this->assertEquals($this->company->id, $tag->company_id);
        $this->assertEquals($samsaraData['id'], $tag->samsara_id);
        $this->assertEquals('Fleet Operations', $tag->name);
        $this->assertNull($tag->parent_tag_id);
        $this->assertCount(2, $tag->vehicles);
        $this->assertCount(1, $tag->drivers);
    }

    public function test_sync_from_samsara_updates_existing_when_data_changed(): void
    {
        $samsaraId = 'samsara-tag-' . uniqid();
        Tag::factory()->create([
            'company_id' => $this->company->id,
            'samsara_id' => $samsaraId,
            'name' => 'Old Name',
            'data_hash' => Tag::generateDataHash(['id' => $samsaraId, 'name' => 'Old Name']),
        ]);

        $newData = ['id' => $samsaraId, 'name' => 'New Name'];
        $tag = Tag::syncFromSamsara($newData, $this->company->id);

        $this->assertEquals('New Name', $tag->name);
        $this->assertDatabaseCount('tags', 1);
    }

    public function test_sync_from_samsara_skips_when_hash_unchanged(): void
    {
        $samsaraId = 'samsara-tag-' . uniqid();
        $samsaraData = ['id' => $samsaraId, 'name' => 'Same'];
        $original = Tag::factory()->create([
            'company_id' => $this->company->id,
            'samsara_id' => $samsaraId,
            'name' => 'Same',
            'data_hash' => Tag::generateDataHash($samsaraData),
        ]);

        $tag = Tag::syncFromSamsara($samsaraData, $this->company->id);

        $this->assertEquals($original->id, $tag->id);
        $this->assertEquals($original->updated_at->toDateTimeString(), $tag->updated_at->toDateTimeString());
    }

    public function test_is_root_returns_true_for_root_tag(): void
    {
        $tag = Tag::factory()->create([
            'company_id' => $this->company->id,
            'parent_tag_id' => null,
        ]);

        $this->assertTrue($tag->isRoot());
    }

    public function test_is_root_returns_false_for_child_tag(): void
    {
        $parent = Tag::factory()->create([
            'company_id' => $this->company->id,
            'samsara_id' => 'parent-root-check',
        ]);
        $child = Tag::factory()->withParent('parent-root-check')->create([
            'company_id' => $this->company->id,
        ]);

        $this->assertFalse($child->isRoot());
    }

    public function test_get_hierarchy_path_for_root_tag(): void
    {
        $tag = Tag::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Root',
            'parent_tag_id' => null,
        ]);

        $this->assertEquals(['Root'], $tag->getHierarchyPath());
    }

    public function test_get_hierarchy_path_for_nested_tags(): void
    {
        $grandparent = Tag::factory()->create([
            'company_id' => $this->company->id,
            'samsara_id' => 'gp-001',
            'name' => 'All',
            'parent_tag_id' => null,
        ]);
        $parent = Tag::factory()->create([
            'company_id' => $this->company->id,
            'samsara_id' => 'p-001',
            'name' => 'Region Norte',
            'parent_tag_id' => 'gp-001',
        ]);
        $child = Tag::factory()->create([
            'company_id' => $this->company->id,
            'samsara_id' => 'c-001',
            'name' => 'Monterrey',
            'parent_tag_id' => 'p-001',
        ]);

        $this->assertEquals(['All', 'Region Norte', 'Monterrey'], $child->getHierarchyPath());
    }

    public function test_vehicle_count_accessor(): void
    {
        $tag = Tag::factory()->create([
            'company_id' => $this->company->id,
            'vehicles' => [['id' => 'v1'], ['id' => 'v2'], ['id' => 'v3']],
        ]);

        $this->assertEquals(3, $tag->vehicle_count);
    }

    public function test_vehicle_count_returns_zero_when_null(): void
    {
        $tag = Tag::factory()->create([
            'company_id' => $this->company->id,
            'vehicles' => null,
        ]);

        $this->assertEquals(0, $tag->vehicle_count);
    }

    public function test_driver_count_accessor(): void
    {
        $tag = Tag::factory()->create([
            'company_id' => $this->company->id,
            'drivers' => [['id' => 'd1'], ['id' => 'd2']],
        ]);

        $this->assertEquals(2, $tag->driver_count);
    }

    public function test_driver_count_returns_zero_when_null(): void
    {
        $tag = Tag::factory()->create([
            'company_id' => $this->company->id,
            'drivers' => null,
        ]);

        $this->assertEquals(0, $tag->driver_count);
    }

    public function test_asset_count_accessor(): void
    {
        $tag = Tag::factory()->create([
            'company_id' => $this->company->id,
            'assets' => [['id' => 'a1']],
        ]);

        $this->assertEquals(1, $tag->asset_count);
    }

    public function test_asset_count_returns_zero_when_null(): void
    {
        $tag = Tag::factory()->create([
            'company_id' => $this->company->id,
            'assets' => null,
        ]);

        $this->assertEquals(0, $tag->asset_count);
    }
}
