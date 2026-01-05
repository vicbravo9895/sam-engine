<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'samsara_id',
        'name',
        'vin',
        'serial',
        'esn',
        'license_plate',
        'make',
        'model',
        'year',
        'notes',
        'aux_input_type_1',
        'aux_input_type_2',
        'aux_input_type_3',
        'aux_input_type_4',
        'aux_input_type_5',
        'aux_input_type_6',
        'aux_input_type_7',
        'aux_input_type_8',
        'aux_input_type_9',
        'aux_input_type_10',
        'aux_input_type_11',
        'aux_input_type_12',
        'aux_input_type_13',
        'camera_serial',
        'gateway',
        'harsh_acceleration_setting_type',
        'is_remote_privacy_button_enabled',
        'vehicle_regulation_mode',
        'vehicle_type',
        'vehicle_weight',
        'vehicle_weight_in_kilograms',
        'vehicle_weight_in_pounds',
        'attributes',
        'external_ids',
        'sensor_configuration',
        'static_assigned_driver',
        'tags',
        'samsara_created_at',
        'samsara_updated_at',
        'data_hash',
    ];

    protected function casts(): array
    {
        return [
            'gateway' => 'array',
            'attributes' => 'array',
            'external_ids' => 'array',
            'sensor_configuration' => 'array',
            'static_assigned_driver' => 'array',
            'tags' => 'array',
            'is_remote_privacy_button_enabled' => 'boolean',
            'samsara_created_at' => 'datetime',
            'samsara_updated_at' => 'datetime',
        ];
    }

    /**
     * Get the company that owns this vehicle.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Scope a query to only include vehicles for a specific company.
     */
    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Generate a hash from the vehicle data for change detection.
     */
    public static function generateDataHash(array $data): string
    {
        // Sort the array to ensure consistent hashing
        ksort($data);
        return md5(json_encode($data));
    }

    /**
     * Check if the vehicle data has changed based on hash comparison.
     */
    public function hasDataChanged(array $newData): bool
    {
        $newHash = self::generateDataHash($newData);
        return $this->data_hash !== $newHash;
    }

    /**
     * Create or update a vehicle from Samsara API data.
     * 
     * @param array $samsaraData The vehicle data from Samsara API
     * @param int|null $companyId The company ID to associate with this vehicle
     */
    public static function syncFromSamsara(array $samsaraData, ?int $companyId = null): self
    {
        $dataHash = self::generateDataHash($samsaraData);
        
        // When syncing with company, we need to match both samsara_id and company_id
        $query = self::where('samsara_id', $samsaraData['id']);
        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }
        $vehicle = $query->first();
        
        // If vehicle exists and hash hasn't changed, skip update
        if ($vehicle && $vehicle->data_hash === $dataHash) {
            return $vehicle;
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
            'name' => $data['name'] ?? null,
            'vin' => $data['vin'] ?? null,
            'serial' => $data['serial'] ?? null,
            'esn' => $data['esn'] ?? null,
            'license_plate' => $data['licensePlate'] ?? null,
            'make' => $data['make'] ?? null,
            'model' => $data['model'] ?? null,
            'year' => $data['year'] ?? null,
            'notes' => $data['notes'] ?? null,
            'aux_input_type_1' => $data['auxInputType1'] ?? null,
            'aux_input_type_2' => $data['auxInputType2'] ?? null,
            'aux_input_type_3' => $data['auxInputType3'] ?? null,
            'aux_input_type_4' => $data['auxInputType4'] ?? null,
            'aux_input_type_5' => $data['auxInputType5'] ?? null,
            'aux_input_type_6' => $data['auxInputType6'] ?? null,
            'aux_input_type_7' => $data['auxInputType7'] ?? null,
            'aux_input_type_8' => $data['auxInputType8'] ?? null,
            'aux_input_type_9' => $data['auxInputType9'] ?? null,
            'aux_input_type_10' => $data['auxInputType10'] ?? null,
            'aux_input_type_11' => $data['auxInputType11'] ?? null,
            'aux_input_type_12' => $data['auxInputType12'] ?? null,
            'aux_input_type_13' => $data['auxInputType13'] ?? null,
            'camera_serial' => $data['cameraSerial'] ?? null,
            'gateway' => $data['gateway'] ?? null,
            'harsh_acceleration_setting_type' => $data['harshAccelerationSettingType'] ?? null,
            'is_remote_privacy_button_enabled' => $data['isRemotePrivacyButtonEnabled'] ?? false,
            'vehicle_regulation_mode' => $data['vehicleRegulationMode'] ?? null,
            'vehicle_type' => $data['vehicleType'] ?? null,
            'vehicle_weight' => $data['vehicleWeight'] ?? null,
            'vehicle_weight_in_kilograms' => $data['vehicleWeightInKilograms'] ?? null,
            'vehicle_weight_in_pounds' => $data['vehicleWeightInPounds'] ?? null,
            'attributes' => $data['attributes'] ?? null,
            'external_ids' => $data['externalIds'] ?? null,
            'sensor_configuration' => $data['sensorConfiguration'] ?? null,
            'static_assigned_driver' => $data['staticAssignedDriver'] ?? null,
            'tags' => $data['tags'] ?? null,
            'samsara_created_at' => isset($data['createdAtTime']) ? \Carbon\Carbon::parse($data['createdAtTime']) : null,
            'samsara_updated_at' => isset($data['updatedAtTime']) ? \Carbon\Carbon::parse($data['updatedAtTime']) : null,
        ];
    }
}

