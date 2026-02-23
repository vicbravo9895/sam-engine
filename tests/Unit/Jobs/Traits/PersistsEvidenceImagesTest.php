<?php

namespace Tests\Unit\Jobs\Traits;

use App\Jobs\PersistMediaAssetJob;
use App\Jobs\Traits\PersistsEvidenceImages;
use App\Models\Alert;
use App\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;
use Tests\Traits\CreatesAlertPipeline;

class PersistsEvidenceImagesTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant, CreatesAlertPipeline;

    private Alert $alert;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();

        ['alert' => $this->alert] = $this->createCompletedAlert($this->company);
    }

    private function makeSubject(): object
    {
        $alert = $this->alert;

        return new class($alert) {
            use PersistsEvidenceImages;

            public Alert $alert;

            public function __construct(Alert $alert)
            {
                $this->alert = $alert;
            }

            public function callPersist(?array $execution, ?array $cameraAnalysis = null): array
            {
                return $this->persistEvidenceImages($execution, $cameraAnalysis);
            }

            public function callIsRemoteUrl(string $url): bool
            {
                return $this->isRemoteUrl($url);
            }
        };
    }

    // ─── persistEvidenceImages: camera_analysis path ────────────

    public function test_dispatches_jobs_for_camera_analysis_media_urls(): void
    {
        Bus::fake(PersistMediaAssetJob::class);

        $subject = $this->makeSubject();

        $cameraAnalysis = [
            'media_urls' => [
                'https://samsara.com/media/img1.jpg',
                'https://samsara.com/media/img2.jpg',
            ],
        ];

        $subject->callPersist(null, $cameraAnalysis);

        Bus::assertDispatchedTimes(PersistMediaAssetJob::class, 2);

        $this->assertDatabaseCount('media_assets', 2);

        $assets = MediaAsset::all();
        $this->assertEquals($this->company->id, $assets[0]->company_id);
        $this->assertEquals(Alert::class, $assets[0]->assetable_type);
        $this->assertEquals($this->alert->id, $assets[0]->assetable_id);
        $this->assertEquals(MediaAsset::CATEGORY_EVIDENCE, $assets[0]->category);
        $this->assertEquals(MediaAsset::STATUS_PENDING, $assets[0]->status);
        $this->assertEquals('https://samsara.com/media/img1.jpg', $assets[0]->source_url);
        $this->assertEquals('camera_analysis', $assets[0]->metadata['origin']);
        $this->assertEquals(0, $assets[0]->metadata['index']);

        $this->assertEquals('https://samsara.com/media/img2.jpg', $assets[1]->source_url);
        $this->assertEquals(1, $assets[1]->metadata['index']);
    }

    // ─── persistEvidenceImages: execution (legacy) path ─────────

    public function test_dispatches_jobs_for_execution_tool_media_urls(): void
    {
        Bus::fake(PersistMediaAssetJob::class);

        $subject = $this->makeSubject();

        $execution = [
            'agents' => [
                [
                    'name' => 'investigator',
                    'tools' => [
                        [
                            'media_urls' => [
                                'https://samsara.com/exec/cam1.jpg',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $subject->callPersist($execution);

        Bus::assertDispatchedTimes(PersistMediaAssetJob::class, 1);

        $asset = MediaAsset::first();
        $this->assertEquals('https://samsara.com/exec/cam1.jpg', $asset->source_url);
        $this->assertEquals('execution_tool', $asset->metadata['origin']);
        $this->assertEquals('investigator', $asset->metadata['agent']);
        $this->assertEquals(0, $asset->metadata['tool_index']);
        $this->assertEquals(0, $asset->metadata['url_index']);
    }

    public function test_dispatches_for_both_camera_and_execution(): void
    {
        Bus::fake(PersistMediaAssetJob::class);

        $subject = $this->makeSubject();

        $cameraAnalysis = [
            'media_urls' => ['https://samsara.com/cam.jpg'],
        ];
        $execution = [
            'agents' => [
                [
                    'name' => 'triage',
                    'tools' => [
                        ['media_urls' => ['https://samsara.com/exec.jpg']],
                    ],
                ],
            ],
        ];

        $subject->callPersist($execution, $cameraAnalysis);

        Bus::assertDispatchedTimes(PersistMediaAssetJob::class, 2);
        $this->assertDatabaseCount('media_assets', 2);
    }

    // ─── persistEvidenceImages: skips non-remote URLs ───────────

    public function test_skips_local_urls_in_camera_analysis(): void
    {
        Bus::fake(PersistMediaAssetJob::class);

        $appUrl = config('app.url', 'http://localhost');
        $subject = $this->makeSubject();

        $cameraAnalysis = [
            'media_urls' => [
                "{$appUrl}/storage/evidence/already-local.jpg",
                '/storage/evidence/relative-local.jpg',
            ],
        ];

        $subject->callPersist(null, $cameraAnalysis);

        Bus::assertNotDispatched(PersistMediaAssetJob::class);
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_skips_local_urls_in_execution(): void
    {
        Bus::fake(PersistMediaAssetJob::class);

        $subject = $this->makeSubject();

        $execution = [
            'agents' => [
                [
                    'name' => 'agent_0',
                    'tools' => [
                        ['media_urls' => ['/storage/evidence/local.jpg']],
                    ],
                ],
            ],
        ];

        $subject->callPersist($execution);

        Bus::assertNotDispatched(PersistMediaAssetJob::class);
        $this->assertDatabaseCount('media_assets', 0);
    }

    // ─── persistEvidenceImages: empty / null inputs ─────────────

    public function test_handles_null_execution_and_null_camera_analysis(): void
    {
        Bus::fake(PersistMediaAssetJob::class);

        $subject = $this->makeSubject();
        [$exec, $cam] = $subject->callPersist(null, null);

        Bus::assertNotDispatched(PersistMediaAssetJob::class);
        $this->assertNull($exec);
        $this->assertNull($cam);
    }

    public function test_handles_empty_media_urls_array(): void
    {
        Bus::fake(PersistMediaAssetJob::class);

        $subject = $this->makeSubject();
        $subject->callPersist(null, ['media_urls' => []]);

        Bus::assertNotDispatched(PersistMediaAssetJob::class);
    }

    public function test_handles_execution_with_no_tools(): void
    {
        Bus::fake(PersistMediaAssetJob::class);

        $subject = $this->makeSubject();
        $execution = [
            'agents' => [
                ['name' => 'empty_agent', 'tools' => []],
            ],
        ];

        $subject->callPersist($execution);

        Bus::assertNotDispatched(PersistMediaAssetJob::class);
    }

    public function test_handles_execution_with_tools_without_media_urls(): void
    {
        Bus::fake(PersistMediaAssetJob::class);

        $subject = $this->makeSubject();
        $execution = [
            'agents' => [
                [
                    'name' => 'investigator',
                    'tools' => [
                        ['result' => 'some data'],
                    ],
                ],
            ],
        ];

        $subject->callPersist($execution);

        Bus::assertNotDispatched(PersistMediaAssetJob::class);
    }

    public function test_handles_execution_with_no_agents_key(): void
    {
        Bus::fake(PersistMediaAssetJob::class);

        $subject = $this->makeSubject();
        $subject->callPersist(['total_tokens' => 500]);

        Bus::assertNotDispatched(PersistMediaAssetJob::class);
    }

    // ─── persistEvidenceImages: return value ────────────────────

    public function test_returns_original_arrays_unmodified(): void
    {
        Bus::fake(PersistMediaAssetJob::class);

        $subject = $this->makeSubject();

        $execution = ['agents' => [], 'total_tokens' => 100];
        $cameraAnalysis = ['media_urls' => ['https://samsara.com/img.jpg'], 'analysis' => 'ok'];

        [$returnedExec, $returnedCam] = $subject->callPersist($execution, $cameraAnalysis);

        $this->assertSame($execution, $returnedExec);
        $this->assertSame($cameraAnalysis, $returnedCam);
    }

    // ─── persistEvidenceImages: storage path format ─────────────

    public function test_storage_path_uses_evidence_prefix_and_jpg_extension(): void
    {
        Bus::fake(PersistMediaAssetJob::class);

        $subject = $this->makeSubject();
        $subject->callPersist(null, [
            'media_urls' => ['https://samsara.com/img.png'],
        ]);

        $asset = MediaAsset::first();
        $this->assertStringStartsWith('evidence/', $asset->storage_path);
        $this->assertStringEndsWith('.jpg', $asset->storage_path);
    }

    // ─── persistEvidenceImages: uses config disk ────────────────

    public function test_uses_media_disk_from_config(): void
    {
        Bus::fake(PersistMediaAssetJob::class);
        config(['filesystems.media' => 's3']);

        $subject = $this->makeSubject();
        $subject->callPersist(null, [
            'media_urls' => ['https://samsara.com/img.jpg'],
        ]);

        $asset = MediaAsset::first();
        $this->assertEquals('s3', $asset->disk);
    }

    // ─── persistEvidenceImages: multiple agents & tools ─────────

    public function test_dispatches_for_multiple_agents_and_tools(): void
    {
        Bus::fake(PersistMediaAssetJob::class);

        $subject = $this->makeSubject();

        $execution = [
            'agents' => [
                [
                    'name' => 'triage',
                    'tools' => [
                        ['media_urls' => ['https://samsara.com/a.jpg', 'https://samsara.com/b.jpg']],
                    ],
                ],
                [
                    'name' => 'investigator',
                    'tools' => [
                        ['media_urls' => ['https://samsara.com/c.jpg']],
                        ['media_urls' => ['https://samsara.com/d.jpg']],
                    ],
                ],
            ],
        ];

        $subject->callPersist($execution);

        Bus::assertDispatchedTimes(PersistMediaAssetJob::class, 4);
        $this->assertDatabaseCount('media_assets', 4);

        $agents = MediaAsset::pluck('metadata')->map(fn ($m) => $m['agent'])->toArray();
        $this->assertContains('triage', $agents);
        $this->assertContains('investigator', $agents);
    }

    // ─── isRemoteUrl ────────────────────────────────────────────

    public function test_is_remote_url_returns_true_for_https(): void
    {
        $subject = $this->makeSubject();
        $this->assertTrue($subject->callIsRemoteUrl('https://samsara.com/media/img.jpg'));
    }

    public function test_is_remote_url_returns_true_for_http(): void
    {
        $subject = $this->makeSubject();
        $this->assertTrue($subject->callIsRemoteUrl('http://samsara.com/media/img.jpg'));
    }

    public function test_is_remote_url_returns_false_for_app_url(): void
    {
        config(['app.url' => 'http://localhost']);
        $subject = $this->makeSubject();
        $this->assertFalse($subject->callIsRemoteUrl('http://localhost/storage/evidence/img.jpg'));
    }

    public function test_is_remote_url_returns_false_for_storage_path(): void
    {
        $subject = $this->makeSubject();
        $this->assertFalse($subject->callIsRemoteUrl('/storage/evidence/img.jpg'));
    }

    public function test_is_remote_url_returns_false_for_relative_path(): void
    {
        $subject = $this->makeSubject();
        $this->assertFalse($subject->callIsRemoteUrl('evidence/img.jpg'));
    }

    public function test_is_remote_url_returns_false_for_empty_string(): void
    {
        $subject = $this->makeSubject();
        $this->assertFalse($subject->callIsRemoteUrl(''));
    }

    // ─── Mixed remote and local URLs ────────────────────────────

    public function test_only_dispatches_for_remote_urls_in_mixed_list(): void
    {
        Bus::fake(PersistMediaAssetJob::class);

        $appUrl = config('app.url', 'http://localhost');
        $subject = $this->makeSubject();

        $cameraAnalysis = [
            'media_urls' => [
                'https://samsara.com/remote.jpg',
                "{$appUrl}/storage/evidence/local.jpg",
                '/storage/evidence/another-local.jpg',
                'https://cdn.samsara.com/remote2.jpg',
            ],
        ];

        $subject->callPersist(null, $cameraAnalysis);

        Bus::assertDispatchedTimes(PersistMediaAssetJob::class, 2);
        $this->assertDatabaseCount('media_assets', 2);
    }

    // ─── Agent name fallback ────────────────────────────────────

    public function test_uses_fallback_agent_name_when_not_provided(): void
    {
        Bus::fake(PersistMediaAssetJob::class);

        $subject = $this->makeSubject();

        $execution = [
            'agents' => [
                [
                    'tools' => [
                        ['media_urls' => ['https://samsara.com/x.jpg']],
                    ],
                ],
            ],
        ];

        $subject->callPersist($execution);

        $asset = MediaAsset::first();
        $this->assertEquals('agent_0', $asset->metadata['agent']);
    }
}
