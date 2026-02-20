<?php

namespace Tests\Feature\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class CompanyControllerTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdmin();
    }

    public function test_edit_shows_company_settings(): void
    {
        $this->actingAs($this->user)
            ->get('/company')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('company/edit')
                ->has('company')
            );
    }

    public function test_update_company_settings(): void
    {
        $this->actingAs($this->user)
            ->put('/company', [
                'name' => 'Updated Company Name',
                'email' => 'updated@test.com',
                'phone' => '+521234567890',
                'timezone' => 'America/Mexico_City',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('companies', [
            'id' => $this->company->id,
            'name' => 'Updated Company Name',
        ]);
    }

    public function test_update_samsara_key(): void
    {
        $this->actingAs($this->user)
            ->put('/company/samsara-key', [
                'samsara_api_key' => 'samsara_api_12345678901234567890',
            ])
            ->assertRedirect();

        $this->company->refresh();
        $this->assertNotNull($this->company->samsara_api_key);
    }

    public function test_remove_samsara_key(): void
    {
        $this->actingAs($this->user)
            ->delete('/company/samsara-key')
            ->assertRedirect();

        $this->company->refresh();
        $this->assertNull($this->company->samsara_api_key);
    }

    public function test_edit_ai_settings_page(): void
    {
        $this->actingAs($this->user)
            ->get('/company/ai-settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('company')
                ->has('aiConfig')
            );
    }

    public function test_update_ai_settings(): void
    {
        $this->actingAs($this->user)
            ->put('/company/ai-settings', [
                'investigation_windows' => [
                    'correlation_window_minutes' => 30,
                    'media_window_seconds' => 120,
                    'safety_events_before_minutes' => 15,
                    'safety_events_after_minutes' => 10,
                    'vehicle_stats_before_minutes' => 5,
                    'vehicle_stats_after_minutes' => 5,
                    'camera_media_window_minutes' => 5,
                ],
                'monitoring' => [
                    'confidence_threshold' => 0.8,
                    'max_revalidations' => 5,
                    'check_intervals' => [10, 15, 30],
                ],
                'channels_enabled' => [
                    'sms' => true,
                    'whatsapp' => true,
                    'call' => false,
                    'email' => false,
                ],
            ])
            ->assertRedirect();
    }

    public function test_reset_ai_settings(): void
    {
        $this->company->update([
            'settings' => array_merge($this->company->settings ?? [], [
                'ai_config' => ['custom' => 'value'],
            ]),
        ]);

        $this->actingAs($this->user)
            ->post('/company/ai-settings/reset')
            ->assertRedirect();
    }

    public function test_edit_detection_rules(): void
    {
        $this->actingAs($this->user)
            ->get('/company/detection-rules')
            ->assertOk();
    }

    public function test_update_detection_rules(): void
    {
        $this->actingAs($this->user)
            ->put('/company/detection-rules', [
                'safety_stream_notify' => [
                    'enabled' => true,
                    'rules' => [
                        [
                            'id' => 'rule-1',
                            'conditions' => ['harshBrake'],
                            'action' => 'ai_pipeline',
                        ],
                    ],
                ],
            ])
            ->assertRedirect();
    }

    public function test_update_stale_vehicle_monitor(): void
    {
        $this->actingAs($this->user)
            ->put('/company/stale-vehicle-monitor', [
                'enabled' => true,
                'threshold_minutes' => 120,
                'channels' => ['sms'],
                'recipients' => ['monitoring_team'],
                'cooldown_minutes' => 60,
                'inactive_after_days' => 30,
            ])
            ->assertRedirect();
    }
}
