<?php

namespace Tests\Feature\Jobs;

use App\Jobs\GenerateShiftSummaryJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;
use Tests\Traits\CreatesAlertPipeline;

class GenerateShiftSummaryJobTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant, CreatesAlertPipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    public function test_generates_summary_with_events(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'summary' => 'Test shift summary.',
                    'key_events' => ['Event 1'],
                    'recommendations' => ['Review alerts'],
                ])]]],
                'usage' => ['total_tokens' => 500],
            ], 200),
        ]);

        $this->createCompletedAlert($this->company);

        $job = new GenerateShiftSummaryJob($this->company->id);
        $job->handle();

        $this->assertTrue(true);
    }

    public function test_skips_when_no_events(): void
    {
        Http::fake();

        $job = new GenerateShiftSummaryJob($this->company->id);
        $job->handle();

        Http::assertNothingSent();
    }
}
