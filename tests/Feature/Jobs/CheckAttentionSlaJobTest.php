<?php

namespace Tests\Feature\Jobs;

use App\Jobs\CheckAttentionSlaJob;
use App\Models\Alert;
use App\Services\AttentionEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;
use Tests\Traits\CreatesAlertPipeline;

class CheckAttentionSlaJobTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant, CreatesAlertPipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    public function test_calls_attention_engine_check(): void
    {
        $mock = $this->mock(AttentionEngine::class);
        $mock->shouldReceive('checkAndEscalateOverdue')->once()->andReturn(0);

        (new CheckAttentionSlaJob())->handle($mock);
    }

    public function test_returns_escalation_count(): void
    {
        $mock = $this->mock(AttentionEngine::class);
        $mock->shouldReceive('checkAndEscalateOverdue')->once()->andReturn(3);

        (new CheckAttentionSlaJob())->handle($mock);
    }
}
