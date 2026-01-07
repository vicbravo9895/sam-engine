<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Driver extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'samsara_id',
        'username',
        'current_id_card_code',
        'name',
        'phone',
        'profile_image_url',
        'notes',
        'license_number',
        'license_state',
        'driver_activation_status',
        'is_deactivated',
        'timezone',
        'locale',
        'eld_exempt',
        'eld_exempt_reason',
        'eld_adverse_weather_exemption_enabled',
        'eld_big_day_exemption_enabled',
        'eld_day_start_hour',
        'eld_pc_enabled',
        'eld_ym_enabled',
        'tachograph_card_number',
        'has_driving_features_hidden',
        'has_vehicle_unpinning_enabled',
        'waiting_time_duty_status_enabled',
        'attributes',
        'carrier_settings',
        'eld_settings',
        'external_ids',
        'static_assigned_vehicle',
        'assigned_vehicle_samsara_id',
        'tags',
        'peer_group_tag',
        'vehicle_group_tag',
        'us_driver_ruleset_override',
        'samsara_created_at',
        'samsara_updated_at',
        'data_hash',
    ];

    protected function casts(): array
    {
        return [
            'is_deactivated' => 'boolean',
            'eld_exempt' => 'boolean',
            'eld_adverse_weather_exemption_enabled' => 'boolean',
            'eld_big_day_exemption_enabled' => 'boolean',
            'eld_pc_enabled' => 'boolean',
            'eld_ym_enabled' => 'boolean',
            'has_driving_features_hidden' => 'boolean',
            'has_vehicle_unpinning_enabled' => 'boolean',
            'waiting_time_duty_status_enabled' => 'boolean',
            'attributes' => 'array',
            'carrier_settings' => 'array',
            'eld_settings' => 'array',
            'external_ids' => 'array',
            'static_assigned_vehicle' => 'array',
            'tags' => 'array',
            'peer_group_tag' => 'array',
            'vehicle_group_tag' => 'array',
            'us_driver_ruleset_override' => 'array',
            'samsara_created_at' => 'datetime',
            'samsara_updated_at' => 'datetime',
        ];
    }

    /**
     * Get the company that owns this driver.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the statically assigned vehicle for this driver.
     * 
     * Cross-references the vehicles table using the Samsara ID.
     */
    public function assignedVehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class, 'samsara_id', 'assigned_vehicle_samsara_id');
    }

    /**
     * Scope a query to only include drivers for a specific company.
     */
    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Scope a query to only include active drivers.
     */
    public function scopeActive($query)
    {
        return $query->where('is_deactivated', false)
            ->where('driver_activation_status', 'active');
    }

    /**
     * Generate a hash from the driver data for change detection.
     */
    public static function generateDataHash(array $data): string
    {
        // Sort the array to ensure consistent hashing
        ksort($data);
        return md5(json_encode($data));
    }

    /**
     * Check if the driver data has changed based on hash comparison.
     */
    public function hasDataChanged(array $newData): bool
    {
        $newHash = self::generateDataHash($newData);
        return $this->data_hash !== $newHash;
    }

    /**
     * Create or update a driver from Samsara API data.
     * 
     * @param array $samsaraData The driver data from Samsara API
     * @param int|null $companyId The company ID to associate with this driver
     */
    public static function syncFromSamsara(array $samsaraData, ?int $companyId = null): self
    {
        $dataHash = self::generateDataHash($samsaraData);
        
        // When syncing with company, we need to match both samsara_id and company_id
        $query = self::where('samsara_id', $samsaraData['id']);
        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }
        $driver = $query->first();
        
        // If driver exists and hash hasn't changed, skip update
        if ($driver && $driver->data_hash === $dataHash) {
            return $driver;
        }
        
        $mappedData = self::mapSamsaraData($samsaraData);
        $mappedData['data_hash'] = $dataHash;
        
        if ($companyId !== null) {
            $mappedData['company_id'] = $companyId;
        }
        
        // Use both samsara_id and company_id for uniqueness
        $uniqueAttributes = ['samsara_id' => $samsaraData['id']];
        if ($companyId !== null) {
            $uniqueAttributes['company_id'] = $companyId;
        }
        
        return self::updateOrCreate($uniqueAttributes, $mappedData);
    }

    /**
     * Map Samsara API data to model attributes.
     */
    protected static function mapSamsaraData(array $data): array
    {
        return [
            'samsara_id' => $data['id'] ?? null,
            'username' => $data['username'] ?? null,
            'current_id_card_code' => $data['currentIdCardCode'] ?? null,
            'name' => $data['name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'profile_image_url' => $data['profileImageUrl'] ?? null,
            'notes' => $data['notes'] ?? null,
            'license_number' => $data['licenseNumber'] ?? null,
            'license_state' => $data['licenseState'] ?? null,
            'driver_activation_status' => $data['driverActivationStatus'] ?? 'active',
            'is_deactivated' => $data['isDeactivated'] ?? false,
            'timezone' => $data['timezone'] ?? null,
            'locale' => $data['locale'] ?? null,
            'eld_exempt' => $data['eldExempt'] ?? false,
            'eld_exempt_reason' => $data['eldExemptReason'] ?? null,
            'eld_adverse_weather_exemption_enabled' => $data['eldAdverseWeatherExemptionEnabled'] ?? false,
            'eld_big_day_exemption_enabled' => $data['eldBigDayExemptionEnabled'] ?? false,
            'eld_day_start_hour' => $data['eldDayStartHour'] ?? 0,
            'eld_pc_enabled' => $data['eldPcEnabled'] ?? false,
            'eld_ym_enabled' => $data['eldYmEnabled'] ?? false,
            'tachograph_card_number' => $data['tachographCardNumber'] ?? null,
            'has_driving_features_hidden' => $data['hasDrivingFeaturesHidden'] ?? false,
            'has_vehicle_unpinning_enabled' => $data['hasVehicleUnpinningEnabled'] ?? false,
            'waiting_time_duty_status_enabled' => $data['waitingTimeDutyStatusEnabled'] ?? false,
            'attributes' => $data['attributes'] ?? null,
            'carrier_settings' => $data['carrierSettings'] ?? null,
            'eld_settings' => $data['eldSettings'] ?? null,
            'external_ids' => $data['externalIds'] ?? null,
            'static_assigned_vehicle' => $data['staticAssignedVehicle'] ?? null,
            'assigned_vehicle_samsara_id' => $data['staticAssignedVehicle']['id'] ?? null,
            'tags' => $data['tags'] ?? null,
            'peer_group_tag' => $data['peerGroupTag'] ?? null,
            'vehicle_group_tag' => $data['vehicleGroupTag'] ?? null,
            'us_driver_ruleset_override' => $data['usDriverRulesetOverride'] ?? null,
            'samsara_created_at' => isset($data['createdAtTime']) ? \Carbon\Carbon::parse($data['createdAtTime']) : null,
            'samsara_updated_at' => isset($data['updatedAtTime']) ? \Carbon\Carbon::parse($data['updatedAtTime']) : null,
        ];
    }

    /**
     * Get the driver's full name with license info if available.
     */
    public function getDisplayNameAttribute(): string
    {
        $name = $this->name;
        
        if ($this->license_number && $this->license_state) {
            $name .= " ({$this->license_state}: {$this->license_number})";
        }
        
        return $name;
    }

    /**
     * Get carrier name from carrier settings.
     */
    public function getCarrierNameAttribute(): ?string
    {
        return $this->carrier_settings['carrierName'] ?? null;
    }

    /**
     * Get the assigned vehicle info.
     */
    public function getAssignedVehicleNameAttribute(): ?string
    {
        return $this->static_assigned_vehicle['name'] ?? null;
    }

    /**
     * Get the assigned vehicle Samsara ID.
     */
    public function getAssignedVehicleIdAttribute(): ?string
    {
        return $this->static_assigned_vehicle['id'] ?? null;
    }
}

