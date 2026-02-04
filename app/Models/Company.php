<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
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
     * Get the Samsara events for this company.
     */
    public function samsaraEvents(): HasMany
    {
        return $this->hasMany(SamsaraEvent::class);
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

        return asset('storage/' . $this->logo_path);
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
                // Recursively merge nested arrays
                $merged[$key] = $this->mergeConfigRecursive($merged[$key], $value);
            } else {
                // Override the value
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Scope a query to only include active companies.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

