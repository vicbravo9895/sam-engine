<?php

namespace Tests\Feature\Jobs;

use App\Jobs\PersistMediaAssetJob;
use App\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;
use Tests\Traits\CreatesAlertPipeline;

class PersistMediaAssetJobTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant, CreatesAlertPipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
        Storage::fake('public');
    }

    public function test_downloads_and_persists_media(): void
    {
        Http::fake([
            'https://example.com/image.jpg' => Http::response('fake-image-content', 200),
        ]);

        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        $mediaAsset = MediaAsset::create([
            'company_id' => $this->company->id,
            'assetable_type' => 'App\\Models\\AlertAi',
            'assetable_id' => $alert->ai->id,
            'category' => MediaAsset::CATEGORY_EVIDENCE,
            'source_url' => 'https://example.com/image.jpg',
            'storage_path' => 'evidence/pending.jpg',
            'status' => MediaAsset::STATUS_PENDING,
            'disk' => 'public',
        ]);

        $job = new PersistMediaAssetJob($mediaAsset);
        $job->handle();

        Http::assertSentCount(1);
    }

    public function test_handles_download_failure(): void
    {
        Http::fake([
            'https://example.com/image.jpg' => Http::response('Not Found', 404),
        ]);

        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        $mediaAsset = MediaAsset::create([
            'company_id' => $this->company->id,
            'assetable_type' => 'App\\Models\\AlertAi',
            'assetable_id' => $alert->ai->id,
            'category' => MediaAsset::CATEGORY_EVIDENCE,
            'source_url' => 'https://example.com/image.jpg',
            'storage_path' => 'evidence/pending.jpg',
            'status' => MediaAsset::STATUS_PENDING,
            'disk' => 'public',
        ]);

        $job = new PersistMediaAssetJob($mediaAsset);

        $this->expectException(\RuntimeException::class);
        $job->handle();
    }
}
