<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Driver;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class CompanyModelTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    public function test_get_ai_config_returns_full_merged_config(): void
    {
        $config = $this->company->getAiConfig();
        $this->assertIsArray($config);
        $this->assertArrayHasKey('investigation_windows', $config);
        $this->assertArrayHasKey('monitoring', $config);
        $this->assertSame(0.80, $config['monitoring']['confidence_threshold']);
    }

    public function test_get_ai_config_with_key_returns_specific_value(): void
    {
        $threshold = $this->company->getAiConfig('monitoring.confidence_threshold');
        $this->assertSame(0.80, $threshold);
    }

    public function test_get_ai_config_merges_company_overrides(): void
    {
        $this->company->setSetting('ai_config', [
            'monitoring' => ['confidence_threshold' => 0.90],
        ]);

        $threshold = $this->company->getAiConfig('monitoring.confidence_threshold');
        $this->assertSame(0.90, $threshold);
    }

    public function test_get_ai_config_returns_default_when_key_not_found(): void
    {
        $value = $this->company->getAiConfig('nonexistent.key', 'fallback');
        $this->assertSame('fallback', $value);
    }

    public function test_get_notification_config_returns_full_merged_config(): void
    {
        $config = $this->company->getNotificationConfig();
        $this->assertIsArray($config);
        $this->assertArrayHasKey('channels_enabled', $config);
        $this->assertTrue($config['channels_enabled']['sms']);
        $this->assertFalse($config['channels_enabled']['email']);
    }

    public function test_get_notification_config_with_key(): void
    {
        $smsEnabled = $this->company->getNotificationConfig('channels_enabled.sms');
        $this->assertTrue($smsEnabled);
    }

    public function test_get_notification_config_merges_company_overrides(): void
    {
        $this->company->setSetting('notifications', [
            'channels_enabled' => ['sms' => false, 'email' => true],
        ]);

        $this->assertFalse($this->company->getNotificationConfig('channels_enabled.sms'));
        $this->assertTrue($this->company->getNotificationConfig('channels_enabled.email'));
    }

    public function test_is_notification_channel_enabled(): void
    {
        $this->assertTrue($this->company->isNotificationChannelEnabled('sms'));
        $this->assertTrue($this->company->isNotificationChannelEnabled('whatsapp'));
        $this->assertFalse($this->company->isNotificationChannelEnabled('email'));
    }

    public function test_is_notification_channel_enabled_with_override(): void
    {
        $this->company->setSetting('notifications', ['channels_enabled' => ['sms' => false]]);
        $this->assertFalse($this->company->isNotificationChannelEnabled('sms'));
    }

    public function test_get_enabled_notification_channels(): void
    {
        $channels = $this->company->getEnabledNotificationChannels();
        $this->assertContains('sms', $channels);
        $this->assertContains('whatsapp', $channels);
        $this->assertContains('call', $channels);
        $this->assertNotContains('email', $channels);
    }

    public function test_get_safety_stream_notify_config_returns_default_when_empty(): void
    {
        $config = $this->company->getSafetyStreamNotifyConfig();
        $this->assertTrue($config['enabled']);
        $this->assertIsArray($config['rules']);
        $this->assertNotEmpty($config['rules']);
    }

    public function test_get_safety_stream_notify_config_with_stored_rules(): void
    {
        $this->company->setSetting('ai_config', [
            'safety_stream_notify' => [
                'enabled' => false,
                'rules' => [
                    ['id' => 'custom-rule', 'conditions' => ['CustomEvent'], 'action' => 'notify'],
                ],
            ],
        ]);

        $config = $this->company->getSafetyStreamNotifyConfig();
        $this->assertFalse($config['enabled']);
        $this->assertCount(1, $config['rules']);
        $this->assertSame('custom-rule', $config['rules'][0]['id']);
    }

    public function test_get_safety_stream_notify_config_migrates_legacy_labels(): void
    {
        $this->company->setSetting('ai_config', [
            'safety_stream_notify' => [
                'labels' => ['Crash', 'HarshBraking'],
            ],
        ]);

        $config = $this->company->getSafetyStreamNotifyConfig();
        $this->assertCount(2, $config['rules']);
        $this->assertSame('migrated-crash', $config['rules'][0]['id']);
        $this->assertSame(['Crash'], $config['rules'][0]['conditions']);
        $this->assertSame('migrated-harshbraking', $config['rules'][1]['id']);
    }

    public function test_get_stale_vehicle_monitor_config_returns_default_when_empty(): void
    {
        $config = $this->company->getStaleVehicleMonitorConfig();
        $this->assertFalse($config['enabled']);
        $this->assertSame(30, $config['threshold_minutes']);
    }

    public function test_get_stale_vehicle_monitor_config_merges_overrides(): void
    {
        $this->company->setSetting('ai_config', [
            'stale_vehicle_monitor' => ['enabled' => true, 'threshold_minutes' => 60],
        ]);

        $config = $this->company->getStaleVehicleMonitorConfig();
        $this->assertTrue($config['enabled']);
        $this->assertSame(60, $config['threshold_minutes']);
    }

    public function test_scope_active_filters_inactive_companies(): void
    {
        $activeCompany = Company::factory()->create(['is_active' => true]);
        Company::factory()->inactive()->create();

        $active = Company::active()->get();
        $this->assertTrue($active->contains($activeCompany));
        $this->assertSame(2, $active->count());
    }

    public function test_get_onboarding_status(): void
    {
        $status = $this->company->getOnboardingStatus();
        $this->assertTrue($status['has_api_key']);
        $this->assertFalse($status['has_vehicles']);
        $this->assertFalse($status['has_contacts']);
        $this->assertFalse($status['has_drivers']);
        $this->assertFalse($status['is_complete']);
        $this->assertSame(1, $status['completed_steps']);
        $this->assertSame(4, $status['total_steps']);
    }

    public function test_get_onboarding_status_complete(): void
    {
        Vehicle::factory()->forCompany($this->company)->create();
        Contact::factory()->forCompany($this->company)->create();
        Driver::factory()->forCompany($this->company)->create();

        $status = $this->company->getOnboardingStatus();
        $this->assertTrue($status['is_complete']);
        $this->assertSame(4, $status['completed_steps']);
    }

    public function test_get_setting(): void
    {
        $this->company->settings = ['custom_key' => 'custom_value'];
        $this->company->save();

        $this->assertSame('custom_value', $this->company->getSetting('custom_key'));
        $this->assertSame('default', $this->company->getSetting('missing', 'default'));
    }

    public function test_set_setting(): void
    {
        $this->company->setSetting('nested.key', 'value');
        $this->company->refresh();

        $this->assertSame('value', $this->company->getSetting('nested.key'));
    }

    public function test_slug_auto_generated_on_creating(): void
    {
        $company = Company::factory()->create(['name' => 'Test Company', 'slug' => null]);
        $this->assertSame('test-company', $company->slug);
    }

    public function test_has_samsara_api_key(): void
    {
        $this->assertTrue($this->company->hasSamsaraApiKey());

        $companyWithNoKey = Company::factory()->create(['samsara_api_key' => null]);
        $this->assertFalse($companyWithNoKey->hasSamsaraApiKey());
    }

    public function test_get_logo_url_returns_null_when_empty(): void
    {
        $this->assertNull($this->company->getLogoUrlAttribute());
    }

    public function test_get_logo_url_returns_url_when_set(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create(['logo_path' => 'logos/company.png']);
        config(['filesystems.media' => 'public']);

        $url = $company->getLogoUrlAttribute();
        $this->assertNotNull($url);
        $this->assertStringContainsString('logos/company.png', $url);
    }
}
