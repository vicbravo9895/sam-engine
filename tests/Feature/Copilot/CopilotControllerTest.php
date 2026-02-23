<?php

namespace Tests\Feature\Copilot;

use App\Models\Alert;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Services\StreamingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;
use Tests\Traits\CreatesAlertPipeline;

class CopilotControllerTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant, CreatesAlertPipeline;

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

    public function test_index_returns_error_when_user_has_no_company(): void
    {
        $userNoCompany = \App\Models\User::factory()->create(['company_id' => null]);

        $this->actingAs($userNoCompany)
            ->get('/copilot')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('copilot')
                ->where('error', 'No estás asociado a ninguna empresa. Contacta al administrador.')
                ->where('conversations', [])
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

    public function test_show_redirects_when_user_has_no_company(): void
    {
        $userNoCompany = \App\Models\User::factory()->create(['company_id' => null]);
        $conversation = Conversation::factory()->create([
            'user_id' => $userNoCompany->id,
            'company_id' => $this->company->id,
        ]);

        $this->actingAs($userNoCompany)
            ->get("/copilot/{$conversation->thread_id}")
            ->assertRedirect(route('copilot.index'));
    }

    public function test_show_redirects_when_conversation_not_found(): void
    {
        $this->actingAs($this->user)
            ->get('/copilot/non-existent-thread-id')
            ->assertRedirect(route('copilot.index'));
    }

    public function test_send_dispatches_processing_job(): void
    {
        Bus::fake();
        $this->mock(StreamingService::class, fn ($mock) => $mock->shouldReceive('initStream')->once());

        $this->actingAs($this->user)
            ->postJson('/copilot/send', [
                'message' => 'Hello, what is the status of vehicle T-001?',
            ])
            ->assertOk();

        Bus::assertDispatched(\App\Jobs\ProcessCopilotMessageJob::class);
    }

    public function test_send_validates_message_required(): void
    {
        Bus::fake();

        $this->actingAs($this->user)
            ->postJson('/copilot/send', ['message' => ''])
            ->assertUnprocessable();

        $this->actingAs($this->user)
            ->postJson('/copilot/send', [])
            ->assertUnprocessable();
    }

    public function test_send_returns_403_when_user_has_no_company(): void
    {
        Bus::fake();
        $userNoCompany = \App\Models\User::factory()->create(['company_id' => null]);

        $this->actingAs($userNoCompany)
            ->postJson('/copilot/send', ['message' => 'Hello'])
            ->assertForbidden()
            ->assertJson(['error' => 'No estás asociado a ninguna empresa. Contacta al administrador.']);
    }

    public function test_send_returns_403_when_context_alert_not_found(): void
    {
        Bus::fake();

        $this->actingAs($this->user)
            ->postJson('/copilot/send', [
                'message' => 'Tell me about this alert',
                'context_event_id' => 99999,
            ])
            ->assertForbidden()
            ->assertJson(['error' => 'Alerta no encontrada o no pertenece a tu empresa.']);
    }

    public function test_send_returns_403_when_context_alert_belongs_to_other_company(): void
    {
        Bus::fake();
        [$otherCompany] = $this->createOtherTenant();
        ['alert' => $alert] = $this->createFullAlert($otherCompany);

        $this->actingAs($this->user)
            ->postJson('/copilot/send', [
                'message' => 'Tell me about this alert',
                'context_event_id' => $alert->id,
            ])
            ->assertForbidden()
            ->assertJson(['error' => 'Alerta no encontrada o no pertenece a tu empresa.']);
    }

    public function test_send_creates_new_conversation_with_title_from_message(): void
    {
        Bus::fake();
        $this->mock(StreamingService::class, fn ($mock) => $mock->shouldReceive('initStream')->once());

        $this->actingAs($this->user)
            ->postJson('/copilot/send', [
                'message' => 'Tell me about vehicle T-001',
            ])
            ->assertOk()
            ->assertJson(['is_new_conversation' => true]);

        $conversation = Conversation::where('user_id', $this->user->id)->first();
        $this->assertNotNull($conversation);
        $this->assertStringContainsString('Tell me about vehicle', $conversation->title);
    }

    public function test_send_creates_conversation_if_new(): void
    {
        Bus::fake();
        $this->mock(StreamingService::class, fn ($mock) => $mock->shouldReceive('initStream')->once());

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
        $this->mock(StreamingService::class, fn ($mock) => $mock->shouldReceive('initStream')->once());

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

    public function test_stream_progress_returns_404_when_conversation_not_found(): void
    {
        $this->actingAs($this->user)
            ->getJson('/copilot/stream/non-existent-thread')
            ->assertNotFound()
            ->assertJson(['error' => 'Conversación no encontrada o no tienes acceso.']);
    }

    public function test_stream_progress_returns_redis_state_when_available(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        $streamingService = $this->mock(StreamingService::class);
        $streamingService->shouldReceive('getStreamState')
            ->with($conversation->thread_id)
            ->andReturn([
                'status' => 'streaming',
                'content' => 'Partial response...',
                'active_tool' => ['label' => 'Consultando vehículos...', 'icon' => 'truck'],
                'error' => null,
            ]);

        $this->actingAs($this->user)
            ->getJson("/copilot/stream/{$conversation->thread_id}")
            ->assertOk()
            ->assertJson([
                'thread_id' => $conversation->thread_id,
                'is_streaming' => true,
                'content' => 'Partial response...',
                'is_completed' => false,
            ]);
    }

    public function test_stream_progress_fallback_to_db_meta_when_no_redis_state(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'meta' => [
                'streaming' => false,
                'streaming_content' => 'Final content',
                'active_tool' => 'GetVehicles',
                'last_error' => null,
            ],
        ]);

        $streamingService = $this->mock(StreamingService::class);
        $streamingService->shouldReceive('getStreamState')
            ->with($conversation->thread_id)
            ->andReturn(null);

        $this->actingAs($this->user)
            ->getJson("/copilot/stream/{$conversation->thread_id}")
            ->assertOk()
            ->assertJson([
                'thread_id' => $conversation->thread_id,
                'is_streaming' => false,
                'content' => 'Final content',
                'is_completed' => true,
            ]);
    }

    public function test_stream_progress_fallback_includes_error_when_present(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'meta' => [
                'streaming' => false,
                'streaming_content' => '',
                'last_error' => 'AI service timeout',
            ],
        ]);

        $streamingService = $this->mock(StreamingService::class);
        $streamingService->shouldReceive('getStreamState')->andReturn(null);

        $this->actingAs($this->user)
            ->getJson("/copilot/stream/{$conversation->thread_id}")
            ->assertOk()
            ->assertJson([
                'is_failed' => true,
                'error' => 'AI service timeout',
            ]);
    }

    public function test_destroy_deletes_conversation(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        $this->actingAs($this->user)
            ->delete("/copilot/{$conversation->thread_id}")
            ->assertRedirect(route('copilot.index'));

        $this->assertDatabaseMissing('conversations', [
            'thread_id' => $conversation->thread_id,
        ]);
    }

    public function test_destroy_redirects_when_conversation_does_not_exist(): void
    {
        $this->actingAs($this->user)
            ->delete('/copilot/non-existent-thread')
            ->assertRedirect(route('copilot.index'));
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

    // ─── show() with streaming meta and active tool ──────────────────

    public function test_show_returns_streaming_state_from_conversation_meta(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'meta' => [
                'streaming' => true,
                'streaming_content' => 'Partial AI response...',
                'active_tool' => 'GetVehicles',
            ],
        ]);

        $this->actingAs($this->user)
            ->get("/copilot/{$conversation->thread_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('copilot')
                ->where('currentConversation.is_streaming', true)
                ->where('currentConversation.streaming_content', 'Partial AI response...')
                ->where('currentConversation.active_tool.label', 'Consultando vehículos de la flota...')
                ->where('currentConversation.active_tool.icon', 'truck')
            );
    }

    public function test_show_returns_null_active_tool_when_not_streaming(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'meta' => [
                'streaming' => false,
                'streaming_content' => '',
                'active_tool' => null,
            ],
        ]);

        $this->actingAs($this->user)
            ->get("/copilot/{$conversation->thread_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('copilot')
                ->where('currentConversation.is_streaming', false)
                ->where('currentConversation.active_tool', null)
            );
    }

    public function test_show_returns_context_event_id_and_payload(): void
    {
        ['alert' => $alert] = $this->createFullAlert($this->company);

        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'context_event_id' => $alert->id,
            'context_payload' => ['alert_kind' => 'safety', 'severity' => 'warning'],
        ]);

        $this->actingAs($this->user)
            ->get("/copilot/{$conversation->thread_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('copilot')
                ->where('currentConversation.context_event_id', $alert->id)
                ->has('currentConversation.context_payload')
            );
    }

    public function test_show_includes_total_tokens(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'total_tokens' => 4500,
        ]);

        $this->actingAs($this->user)
            ->get("/copilot/{$conversation->thread_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('copilot')
                ->where('currentConversation.total_tokens', 4500)
            );
    }

    // ─── show() formatted messages ───────────────────────────────────

    public function test_show_returns_formatted_messages(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        ChatMessage::create([
            'thread_id' => $conversation->thread_id,
            'role' => 'user',
            'content' => 'What is the status of T-001?',
            'status' => 'completed',
        ]);

        ChatMessage::create([
            'thread_id' => $conversation->thread_id,
            'role' => 'assistant',
            'content' => ['text' => 'Vehicle T-001 is currently active.'],
            'status' => 'completed',
        ]);

        $this->actingAs($this->user)
            ->get("/copilot/{$conversation->thread_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('copilot')
                ->has('messages', 2)
            );
    }

    public function test_show_filters_out_tool_call_messages(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        ChatMessage::create([
            'thread_id' => $conversation->thread_id,
            'role' => 'user',
            'content' => 'Show me the fleet status',
            'status' => 'completed',
        ]);

        ChatMessage::create([
            'thread_id' => $conversation->thread_id,
            'role' => 'assistant',
            'content' => ['type' => 'tool_call', 'tool' => 'GetFleetStatus', 'args' => []],
            'status' => 'completed',
        ]);

        ChatMessage::create([
            'thread_id' => $conversation->thread_id,
            'role' => 'assistant',
            'content' => ['type' => 'tool_call_result', 'result' => ['data' => []]],
            'status' => 'completed',
        ]);

        ChatMessage::create([
            'thread_id' => $conversation->thread_id,
            'role' => 'assistant',
            'content' => ['text' => 'Here is the fleet status.'],
            'status' => 'completed',
        ]);

        $this->actingAs($this->user)
            ->get("/copilot/{$conversation->thread_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('copilot')
                ->has('messages', 2)
            );
    }

    public function test_show_filters_out_empty_messages(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        ChatMessage::create([
            'thread_id' => $conversation->thread_id,
            'role' => 'user',
            'content' => 'Hello',
            'status' => 'completed',
        ]);

        ChatMessage::create([
            'thread_id' => $conversation->thread_id,
            'role' => 'assistant',
            'content' => ['text' => ''],
            'status' => 'completed',
        ]);

        $this->actingAs($this->user)
            ->get("/copilot/{$conversation->thread_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('copilot')
                ->has('messages', 1)
            );
    }

    public function test_show_uses_streaming_content_for_streaming_messages(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        ChatMessage::create([
            'thread_id' => $conversation->thread_id,
            'role' => 'assistant',
            'content' => null,
            'streaming_content' => 'Streaming partial response...',
            'status' => 'streaming',
        ]);

        $this->actingAs($this->user)
            ->get("/copilot/{$conversation->thread_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('copilot')
                ->has('messages', 1)
            );
    }

    // ─── send() with context_event_id ────────────────────────────────

    public function test_send_with_valid_context_event_creates_conversation_with_alert_title(): void
    {
        Bus::fake();
        $this->mock(StreamingService::class, fn ($mock) => $mock->shouldReceive('initStream')->once());

        ['alert' => $alert, 'signal' => $signal] = $this->createFullAlert($this->company, [
            'vehicle_name' => 'T-012345',
        ], [
            'event_description' => 'Frenado brusco',
        ]);

        $this->actingAs($this->user)
            ->postJson('/copilot/send', [
                'message' => 'Tell me about this alert',
                'context_event_id' => $alert->id,
            ])
            ->assertOk()
            ->assertJson(['is_new_conversation' => true]);

        $conversation = Conversation::where('user_id', $this->user->id)
            ->whereNotNull('context_event_id')
            ->first();

        $this->assertNotNull($conversation);
        $this->assertEquals($alert->id, $conversation->context_event_id);
        $this->assertNotNull($conversation->context_payload);
        $this->assertStringContainsString('Alerta:', $conversation->title);
    }

    public function test_send_with_context_stores_alert_context_payload(): void
    {
        Bus::fake();
        $this->mock(StreamingService::class, fn ($mock) => $mock->shouldReceive('initStream')->once());

        ['alert' => $alert] = $this->createFullAlert($this->company, [
            'vehicle_name' => 'T-999',
            'event_type' => 'safetyEvent',
        ], [
            'event_description' => 'Exceso de velocidad',
            'severity' => 'warning',
            'verdict' => 'confirmed_violation',
            'ai_message' => 'Speeding violation detected.',
        ]);

        $this->actingAs($this->user)
            ->postJson('/copilot/send', [
                'message' => 'Analyze this',
                'context_event_id' => $alert->id,
            ])
            ->assertOk();

        $conversation = Conversation::where('user_id', $this->user->id)->latest()->first();
        $payload = $conversation->context_payload;

        $this->assertEquals($alert->id, $payload['alert_id']);
        $this->assertEquals('warning', $payload['severity']);
        $this->assertEquals('confirmed_violation', $payload['verdict']);
    }

    public function test_send_validates_message_max_length(): void
    {
        Bus::fake();

        $this->actingAs($this->user)
            ->postJson('/copilot/send', [
                'message' => str_repeat('a', 10001),
            ])
            ->assertUnprocessable();
    }

    public function test_send_sets_streaming_meta_on_conversation(): void
    {
        Bus::fake();
        $this->mock(StreamingService::class, fn ($mock) => $mock->shouldReceive('initStream')->once());

        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        $this->actingAs($this->user)
            ->postJson('/copilot/send', [
                'message' => 'Follow up question',
                'thread_id' => $conversation->thread_id,
            ])
            ->assertOk();

        $conversation->refresh();
        $meta = $conversation->meta;
        $this->assertTrue($meta['streaming']);
        $this->assertArrayHasKey('streaming_started_at', $meta);
    }

    // ─── streamProgress() paths ─────────────────────────────────────

    public function test_stream_progress_returns_completed_state_from_redis(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        $streamingService = $this->mock(StreamingService::class);
        $streamingService->shouldReceive('getStreamState')
            ->with($conversation->thread_id)
            ->andReturn([
                'status' => 'completed',
                'content' => 'Full response here.',
                'active_tool' => null,
                'error' => null,
            ]);

        $this->actingAs($this->user)
            ->getJson("/copilot/stream/{$conversation->thread_id}")
            ->assertOk()
            ->assertJson([
                'is_streaming' => false,
                'is_completed' => true,
                'content' => 'Full response here.',
            ]);
    }

    public function test_stream_progress_returns_failed_state_from_redis(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        $streamingService = $this->mock(StreamingService::class);
        $streamingService->shouldReceive('getStreamState')
            ->with($conversation->thread_id)
            ->andReturn([
                'status' => 'failed',
                'content' => '',
                'active_tool' => null,
                'error' => 'AI service timeout',
            ]);

        $this->actingAs($this->user)
            ->getJson("/copilot/stream/{$conversation->thread_id}")
            ->assertOk()
            ->assertJson([
                'is_streaming' => false,
                'is_completed' => false,
                'is_failed' => true,
                'error' => 'AI service timeout',
            ]);
    }

    public function test_stream_progress_db_fallback_with_active_tool_display_info(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'meta' => [
                'streaming' => true,
                'streaming_content' => 'Processing...',
                'active_tool' => 'GetDashcamMedia',
                'last_error' => null,
            ],
        ]);

        $streamingService = $this->mock(StreamingService::class);
        $streamingService->shouldReceive('getStreamState')->andReturn(null);

        $this->actingAs($this->user)
            ->getJson("/copilot/stream/{$conversation->thread_id}")
            ->assertOk()
            ->assertJson([
                'is_streaming' => true,
                'content' => 'Processing...',
                'active_tool' => [
                    'label' => 'Obteniendo imágenes de dashcam...',
                    'icon' => 'camera',
                ],
            ]);
    }

    public function test_stream_progress_db_fallback_with_unknown_tool(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'meta' => [
                'streaming' => true,
                'streaming_content' => 'Working...',
                'active_tool' => 'SomeUnknownTool',
                'last_error' => null,
            ],
        ]);

        $streamingService = $this->mock(StreamingService::class);
        $streamingService->shouldReceive('getStreamState')->andReturn(null);

        $this->actingAs($this->user)
            ->getJson("/copilot/stream/{$conversation->thread_id}")
            ->assertOk()
            ->assertJson([
                'active_tool' => [
                    'label' => 'Procesando...',
                    'icon' => 'loader',
                ],
            ]);
    }

    public function test_stream_progress_includes_total_tokens(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'total_tokens' => 3200,
        ]);

        $streamingService = $this->mock(StreamingService::class);
        $streamingService->shouldReceive('getStreamState')
            ->andReturn([
                'status' => 'completed',
                'content' => 'Done.',
                'active_tool' => null,
                'error' => null,
            ]);

        $this->actingAs($this->user)
            ->getJson("/copilot/stream/{$conversation->thread_id}")
            ->assertOk()
            ->assertJson([
                'total_tokens' => 3200,
            ]);
    }

    // ─── destroy() deletes messages ──────────────────────────────────

    public function test_destroy_also_deletes_chat_messages(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        ChatMessage::create([
            'thread_id' => $conversation->thread_id,
            'role' => 'user',
            'content' => 'Hello',
            'status' => 'completed',
        ]);

        ChatMessage::create([
            'thread_id' => $conversation->thread_id,
            'role' => 'assistant',
            'content' => ['text' => 'Hi there!'],
            'status' => 'completed',
        ]);

        $this->actingAs($this->user)
            ->delete("/copilot/{$conversation->thread_id}")
            ->assertRedirect(route('copilot.index'));

        $this->assertDatabaseMissing('chat_messages', [
            'thread_id' => $conversation->thread_id,
        ]);
    }

    public function test_destroy_does_not_delete_other_company_conversation(): void
    {
        [$otherCompany, $otherUser] = $this->createOtherTenant();

        $conversation = Conversation::factory()->create([
            'user_id' => $otherUser->id,
            'company_id' => $otherCompany->id,
        ]);

        $this->actingAs($this->user)
            ->delete("/copilot/{$conversation->thread_id}")
            ->assertRedirect(route('copilot.index'));

        $this->assertDatabaseHas('conversations', [
            'thread_id' => $conversation->thread_id,
        ]);
    }

    // ─── index() returns vehicles, drivers, tags pickers ────────────

    public function test_index_returns_vehicle_picker_data(): void
    {
        \App\Models\Vehicle::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'T-001',
        ]);

        $this->actingAs($this->user)
            ->get('/copilot')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('copilot')
                ->has('vehicles', 1)
            );
    }

    public function test_index_returns_driver_picker_data(): void
    {
        \App\Models\Driver::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'John Doe',
            'is_deactivated' => false,
            'driver_activation_status' => 'active',
        ]);

        $this->actingAs($this->user)
            ->get('/copilot')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('copilot')
                ->has('drivers', 1)
            );
    }

    public function test_index_returns_tag_picker_data(): void
    {
        \App\Models\Tag::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Heavy Vehicles',
        ]);

        $this->actingAs($this->user)
            ->get('/copilot')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('copilot')
                ->has('tags', 1)
            );
    }

    // ─── show() returns picker data too ──────────────────────────────

    public function test_show_also_returns_picker_data(): void
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        \App\Models\Vehicle::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'T-100',
        ]);

        $this->actingAs($this->user)
            ->get("/copilot/{$conversation->thread_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('copilot')
                ->has('vehicles', 1)
                ->has('drivers')
                ->has('tags')
            );
    }
}
