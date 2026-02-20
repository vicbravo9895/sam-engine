<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Default AI configuration values.
     * These are used when a company hasn't customized their settings.
     */
    public const DEFAULT_AI_CONFIG = [
        'investigation_windows' => [
            'correlation_window_minutes' => 20,
            'media_window_seconds' => 120,
            'safety_events_before_minutes' => 30,
            'safety_events_after_minutes' => 10,
            'vehicle_stats_before_minutes' => 5,
            'vehicle_stats_after_minutes' => 2,
            'camera_media_window_minutes' => 2,
        ],
        'monitoring' => [
            'confidence_threshold' => 0.80,
            'check_intervals' => [5, 15, 30, 60],
            'max_revalidations' => 5,
        ],
        'usage_limits' => [
            'max_copilot_messages_per_month' => 500,
            'max_revalidations_per_event' => 3,
            'warn_at_percentage' => 80,
        ],
        'shift_summary' => [
            'enabled' => false,
            'hours' => [7, 15, 23], // Hours to generate summaries (shift boundaries)
            'shift_duration_hours' => 8,
        ],
        'escalation_matrix' => [
            'emergency' => [
                'channels' => ['call', 'whatsapp', 'sms'],
                'recipients' => ['operator', 'monitoring', 'supervisor'],
            ],
            'call' => [
                'channels' => ['call', 'whatsapp'],
                'recipients' => ['operator', 'monitoring'],
            ],
            'warn' => [
                'channels' => ['whatsapp', 'sms'],
                'recipients' => ['monitoring'],
            ],
            'monitor' => [
                'channels' => [],
                'recipients' => [],
            ],
        ],
        'safety_stream_notify' => [
            'enabled' => true,
            'rules' => [
                ['id' => 'default-crash', 'conditions' => ['Crash'], 'action' => 'ai_pipeline', 'channels' => [], 'recipients' => []],
                ['id' => 'default-fcw', 'conditions' => ['ForwardCollisionWarning'], 'action' => 'ai_pipeline', 'channels' => [], 'recipients' => []],
                ['id' => 'default-speeding', 'conditions' => ['SevereSpeeding'], 'action' => 'ai_pipeline', 'channels' => [], 'recipients' => []],
            ],
        ],
        'stale_vehicle_monitor' => [
            'enabled' => false,
            'threshold_minutes' => 30,
            'channels' => ['whatsapp', 'sms'],
            'recipients' => ['monitoring_team', 'supervisor'],
            'cooldown_minutes' => 60,
            'inactive_after_days' => 20,
        ],
        'sla_policies' => [
            'critical' => ['ack_minutes' => 5, 'resolve_minutes' => 30],
            'warning'  => ['ack_minutes' => 15, 'resolve_minutes' => 120],
            'info'     => ['ack_minutes' => 60, 'resolve_minutes' => 1440],
        ],
        'escalation_policy' => [
            'enabled' => true,
            'max_escalations' => 3,
            'escalation_interval_minutes' => 10,
        ],
    ];

    /**
     * Default notification configuration values.
     * Controls which notification channels are enabled for the company.
     */
    public const DEFAULT_NOTIFICATION_CONFIG = [
        'channels_enabled' => [
            'sms' => true,
            'whatsapp' => true,
            'call' => true,
            'email' => false,
        ],
    ];

    protected $fillable = [
        'name',
        'slug',
        'legal_name',
        'tax_id',
        'email',
        'phone',
        'address',
        'city',
        'state',
        'country',
        'timezone',
        'postal_code',
        'samsara_api_key',
        'logo_path',
        'is_active',
        'settings',
        // Safety events stream daemon
        'safety_stream_cursor',
        'safety_stream_start_time',
        'safety_stream_last_sync',
    ];

    protected function casts(): array
    {
        return [
            'samsara_api_key' => 'encrypted',
            'settings' => 'array',
            'is_active' => 'boolean',
            'safety_stream_last_sync' => 'datetime',
        ];
    }

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'samsara_api_key',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($company) {
            if (empty($company->slug)) {
                $company->slug = Str::slug($company->name);
            }
        });
    }

    /**
     * Get the users for this company.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the vehicles for this company.
     */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /**
     * Get the tags for this company.
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * Get the conversations for this company.
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Get the alerts for this company.
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    /**
     * Get the signals for this company.
     */
    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class);
    }

    /**
     * Get the contacts for this company.
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * Get the safety signals for this company.
     */
    public function safetySignals(): HasMany
    {
        return $this->hasMany(SafetySignal::class);
    }

    /**
     * Get the incidents for this company.
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    /**
     * Check if the company has a valid Samsara API key configured.
     */
    public function hasSamsaraApiKey(): bool
    {
        return !empty($this->samsara_api_key);
    }

    /**
     * Get the decrypted Samsara API key.
     * Note: The 'encrypted' cast handles decryption automatically.
     */
    public function getSamsaraApiKey(): ?string
    {
        return $this->samsara_api_key;
    }

    /**
     * Get the logo URL.
     */
    public function getLogoUrlAttribute(): ?string
    {
        if (empty($this->logo_path)) {
            return null;
        }

        return Storage::disk(config('filesystems.media'))->url($this->logo_path);
    }

    /**
     * Get a setting value with optional default.
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /**
     * Set a setting value.
     */
    public function setSetting(string $key, mixed $value): void
    {
        $settings = $this->settings ?? [];
        data_set($settings, $key, $value);
        $this->settings = $settings;
        $this->save();
    }

    /**
     * Get AI configuration with defaults.
     * 
     * @param string|null $key Dot notation key (e.g., 'monitoring.confidence_threshold')
     * @param mixed $default Default value if key not found
     * @return mixed Full config array if key is null, or specific value
     */
    public function getAiConfig(?string $key = null, mixed $default = null): mixed
    {
        $companyConfig = $this->getSetting('ai_config', []);
        $mergedConfig = $this->mergeConfigRecursive(self::DEFAULT_AI_CONFIG, $companyConfig);

        if ($key === null) {
            return $mergedConfig;
        }

        return data_get($mergedConfig, $key, $default);
    }

    /**
     * Get notification configuration with defaults.
     * 
     * @param string|null $key Dot notation key (e.g., 'channels_enabled.sms')
     * @param mixed $default Default value if key not found
     * @return mixed Full config array if key is null, or specific value
     */
    public function getNotificationConfig(?string $key = null, mixed $default = null): mixed
    {
        $companyConfig = $this->getSetting('notifications', []);
        $mergedConfig = $this->mergeConfigRecursive(self::DEFAULT_NOTIFICATION_CONFIG, $companyConfig);

        if ($key === null) {
            return $mergedConfig;
        }

        return data_get($mergedConfig, $key, $default);
    }

    /**
     * Check if a specific notification channel is enabled.
     * 
     * @param string $channel Channel name (sms, whatsapp, call, email)
     * @return bool
     */
    public function isNotificationChannelEnabled(string $channel): bool
    {
        return (bool) $this->getNotificationConfig("channels_enabled.{$channel}", false);
    }

    /**
     * Get enabled notification channels as an array.
     * 
     * @return array List of enabled channel names
     */
    public function getEnabledNotificationChannels(): array
    {
        $channels = $this->getNotificationConfig('channels_enabled', []);
        
        return array_keys(array_filter($channels, fn($enabled) => $enabled === true));
    }

    /**
     * Get safety_stream_notify config with rules (migrating legacy labels format).
     *
     * If the stored config uses the old `labels` array, it is converted to
     * the new `rules` format on the fly (one rule per label).
     *
     * @return array{enabled: bool, rules: array<int, array{id: string, conditions: string[], action: string}>}
     */
    public function getSafetyStreamNotifyConfig(): array
    {
        $stored = $this->getSetting('ai_config.safety_stream_notify');

        if ($stored === null) {
            return self::DEFAULT_AI_CONFIG['safety_stream_notify'];
        }

        // Migrate legacy labels → rules
        if (isset($stored['labels']) && !isset($stored['rules'])) {
            $stored['rules'] = array_map(
                fn (string $label) => [
                    'id' => 'migrated-' . Str::slug($label),
                    'conditions' => [$label],
                    'action' => 'notify',
                ],
                $stored['labels']
            );
            unset($stored['labels']);
        }

        return [
            'enabled' => $stored['enabled'] ?? true,
            'rules' => $stored['rules'] ?? [],
        ];
    }

    /**
     * Recursively merge configuration arrays, with company config taking precedence.
     * 
     * @param array $defaults Default configuration
     * @param array $overrides Company-specific overrides
     * @return array Merged configuration
     */
    private function mergeConfigRecursive(array $defaults, array $overrides): array
    {
        $merged = $defaults;

        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                if (array_is_list($value) || array_is_list($merged[$key])) {
                    $merged[$key] = $value;
                } else {
                    $merged[$key] = $this->mergeConfigRecursive($merged[$key], $value);
                }
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Get the onboarding status for this company.
     * Computed from existing relationships - no migration needed.
     *
     * @return array{has_api_key: bool, has_vehicles: bool, has_contacts: bool, has_drivers: bool, is_complete: bool, completed_steps: int, total_steps: int}
     */
    public function getOnboardingStatus(): array
    {
        $hasApiKey = $this->hasSamsaraApiKey();
        $hasVehicles = $this->vehicles()->exists();
        $hasContacts = $this->contacts()->exists();
        $hasDrivers = $this->drivers()->exists();

        $steps = [$hasApiKey, $hasVehicles, $hasContacts, $hasDrivers];
        $completedSteps = count(array_filter($steps));

        return [
            'has_api_key' => $hasApiKey,
            'has_vehicles' => $hasVehicles,
            'has_contacts' => $hasContacts,
            'has_drivers' => $hasDrivers,
            'is_complete' => $completedSteps === count($steps),
            'completed_steps' => $completedSteps,
            'total_steps' => count($steps),
        ];
    }

    /**
     * Get the drivers for this company.
     */
    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    /**
     * Get the stale vehicle alerts for this company.
     */
    public function staleVehicleAlerts(): HasMany
    {
        return $this->hasMany(StaleVehicleAlert::class);
    }

    /**
     * Get stale vehicle monitor configuration with defaults.
     */
    public function getStaleVehicleMonitorConfig(): array
    {
        $stored = $this->getSetting('ai_config.stale_vehicle_monitor');

        if ($stored === null) {
            return self::DEFAULT_AI_CONFIG['stale_vehicle_monitor'];
        }

        return array_merge(self::DEFAULT_AI_CONFIG['stale_vehicle_monitor'], $stored);
    }

    /**
     * Scope a query to only include active companies.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

