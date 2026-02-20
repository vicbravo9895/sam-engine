<?php

namespace Tests\Feature\Copilot;

use App\Models\ChatMessage;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class CopilotControllerTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    public function test_index_returns_copilot_page(): void
    {
        $this->actingAs($this->user)
            ->get('/copilot')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('copilot')
            );
    }

    public function test_show_returns_conversation(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        $this->actingAs($this->user)
            ->get("/copilot/{$conversation->thread_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('copilot')
                ->has('currentConversation')
            );
    }

    public function test_send_dispatches_processing_job(): void
    {
        Bus::fake();

        $this->actingAs($this->user)
            ->postJson('/copilot/send', [
                'message' => 'Hello, what is the status of vehicle T-001?',
            ])
            ->assertOk();

        Bus::assertDispatched(\App\Jobs\ProcessCopilotMessageJob::class);
    }

    public function test_send_creates_conversation_if_new(): void
    {
        Bus::fake();

        $this->actingAs($this->user)
            ->postJson('/copilot/send', [
                'message' => 'Hello copilot!',
            ])
            ->assertOk()
            ->assertJsonStructure(['thread_id']);

        $this->assertDatabaseHas('conversations', [
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);
    }

    public function test_send_reuses_existing_conversation(): void
    {
        Bus::fake();

        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        $this->actingAs($this->user)
            ->postJson('/copilot/send', [
                'message' => 'Follow up message',
                'thread_id' => $conversation->thread_id,
            ])
            ->assertOk();

        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_destroy_deletes_conversation(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        $this->actingAs($this->user)
            ->delete("/copilot/{$conversation->thread_id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('conversations', [
            'thread_id' => $conversation->thread_id,
        ]);
    }

    public function test_cannot_access_other_company_conversation(): void
    {
        [$otherCompany, $otherUser] = $this->createOtherTenant();

        $conversation = Conversation::factory()->create([
            'user_id' => $otherUser->id,
            'company_id' => $otherCompany->id,
        ]);

        $this->actingAs($this->user)
            ->get("/copilot/{$conversation->thread_id}")
            ->assertRedirect(route('copilot.index'));
    }
}
