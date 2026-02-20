<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessCopilotMessageJob;
use App\Models\ChatMessage;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class ProcessCopilotMessageJobTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    public function test_creates_conversation_record(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        $this->assertDatabaseHas('conversations', [
            'thread_id' => $conversation->thread_id,
            'company_id' => $this->company->id,
        ]);
    }

    public function test_conversation_scoped_to_company(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        [$otherCompany, $otherUser] = $this->createOtherTenant();
        $otherConversation = Conversation::factory()->create([
            'user_id' => $otherUser->id,
            'company_id' => $otherCompany->id,
        ]);

        $companyConversations = Conversation::forCompany($this->company->id)->get();
        $this->assertCount(1, $companyConversations);
        $this->assertEquals($conversation->id, $companyConversations->first()->id);
    }
}
