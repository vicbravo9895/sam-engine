<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleStat extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'vehicle_id',
        'samsara_vehicle_id',
        'vehicle_name',
        'latitude',
        'longitude',
        'speed_kmh',
        'heading_degrees',
        'location_name',
        'is_geofence',
        'address_id',
        'address_name',
        'gps_time',
        'engine_state',
        'engine_time',
        'odometer_meters',
        'odometer_time',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'speed_kmh' => 'decimal:2',
            'is_geofence' => 'boolean',
            'odometer_meters' => 'integer',
            'gps_time' => 'datetime',
            'engine_time' => 'datetime',
            'odometer_time' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * Get the company that owns this vehicle stat.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the vehicle associated with this stat.
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Scope a query to only include stats for a specific company.
     */
    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Scope a query to only include active vehicles (engine on or moving).
     */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->where('engine_state', '!=', 'off')
              ->orWhere('speed_kmh', '>', 0);
        });
    }

    /**
     * Scope a query to only include inactive vehicles (engine off and not moving).
     */
    public function scopeInactive($query)
    {
        return $query->where(function ($q) {
            $q->where('engine_state', 'off')
              ->where(function ($subQ) {
                  $subQ->whereNull('speed_kmh')
                       ->orWhere('speed_kmh', '<=', 0);
              });
        });
    }

    /**
     * Check if the vehicle is currently moving.
     */
    public function isMoving(): bool
    {
        return $this->speed_kmh !== null && $this->speed_kmh > 0;
    }

    /**
     * Check if the vehicle is currently active (engine on or moving).
     */
    public function isActive(): bool
    {
        return $this->engine_state !== 'off' || $this->isMoving();
    }

    /**
     * Get the formatted location string.
     */
    public function getFormattedLocation(): string
    {
        // Prefer geofence/address name if available
        if ($this->is_geofence && !empty($this->address_name)) {
            return $this->address_name;
        }

        if (!empty($this->location_name)) {
            return $this->location_name;
        }

        // Fallback to coordinates
        if ($this->latitude && $this->longitude) {
            return "{$this->latitude}, {$this->longitude}";
        }

        return 'Ubicación desconocida';
    }

    /**
     * Get the human-readable engine state.
     */
    public function getEngineStateLabel(): string
    {
        return match ($this->engine_state) {
            'on' => 'Encendido',
            'off' => 'Apagado',
            'idle' => 'Ralentí',
            default => 'Desconocido',
        };
    }

    /**
     * Get the odometer in kilometers.
     */
    public function getOdometerKm(): ?float
    {
        if ($this->odometer_meters === null) {
            return null;
        }

        return round($this->odometer_meters / 1000, 1);
    }

    /**
     * Get Google Maps link for the current location.
     */
    public function getMapsLink(): ?string
    {
        if (!$this->latitude || !$this->longitude) {
            return null;
        }

        return "https://www.google.com/maps?q={$this->latitude},{$this->longitude}";
    }

    /**
     * Sync vehicle stats from Samsara API data.
     * 
     * @param array $samsaraData The vehicle stats data from Samsara API
     * @param int $companyId The company ID to associate with this stat
     * @return self The updated or created VehicleStat instance
     */
    public static function syncFromSamsara(array $samsaraData, int $companyId): self
    {
        $samsaraVehicleId = $samsaraData['id'] ?? null;
        
        if (!$samsaraVehicleId) {
            throw new \InvalidArgumentException('Samsara vehicle ID is required');
        }

        // Find the local vehicle record to get vehicle_id
        $vehicle = Vehicle::forCompany($companyId)
            ->where('samsara_id', $samsaraVehicleId)
            ->first();

        // Map Samsara data to our model
        $mappedData = self::mapSamsaraData($samsaraData);
        $mappedData['company_id'] = $companyId;
        $mappedData['vehicle_id'] = $vehicle?->id;
        $mappedData['synced_at'] = now();

        return self::updateOrCreate(
            [
                'company_id' => $companyId,
                'samsara_vehicle_id' => $samsaraVehicleId,
            ],
            $mappedData
        );
    }

    /**
     * Map Samsara API data to model attributes.
     */
    protected static function mapSamsaraData(array $data): array
    {
        $mapped = [
            'samsara_vehicle_id' => $data['id'] ?? null,
            'vehicle_name' => $data['name'] ?? null,
        ];

        // Map GPS data
        $gps = self::extractStatData($data, 'gps');
        if ($gps) {
            $mapped['latitude'] = $gps['latitude'] ?? null;
            $mapped['longitude'] = $gps['longitude'] ?? null;
            
            // Convert speed from mph to km/h
            if (isset($gps['speedMilesPerHour'])) {
                $mapped['speed_kmh'] = round($gps['speedMilesPerHour'] * 1.60934, 2);
            }
            
            $mapped['heading_degrees'] = $gps['headingDegrees'] ?? null;
            $mapped['gps_time'] = isset($gps['time']) ? \Carbon\Carbon::parse($gps['time']) : null;
            
            // Location name from reverseGeo
            if (isset($gps['reverseGeo']['formattedLocation'])) {
                $mapped['location_name'] = $gps['reverseGeo']['formattedLocation'];
            }
            
            // Geofence/address info
            if (isset($gps['address'])) {
                $mapped['is_geofence'] = true;
                $mapped['address_id'] = $gps['address']['id'] ?? null;
                $mapped['address_name'] = $gps['address']['name'] ?? null;
            } else {
                $mapped['is_geofence'] = false;
            }
        }

        // Map engine state
        $engineState = self::extractStatData($data, 'engineStates') 
            ?? self::extractStatData($data, 'engineState');
        if ($engineState) {
            $stateValue = strtolower($engineState['value'] ?? '');
            $mapped['engine_state'] = match ($stateValue) {
                'on' => 'on',
                'off' => 'off',
                'idle' => 'idle',
                default => null,
            };
            $mapped['engine_time'] = isset($engineState['time']) 
                ? \Carbon\Carbon::parse($engineState['time']) 
                : null;
        }

        // Map odometer
        $odometer = self::extractStatData($data, 'obdOdometerMeters') 
            ?? self::extractStatData($data, 'obdOdometer');
        if ($odometer) {
            $mapped['odometer_meters'] = $odometer['value'] ?? null;
            $mapped['odometer_time'] = isset($odometer['time']) 
                ? \Carbon\Carbon::parse($odometer['time']) 
                : null;
        }

        return $mapped;
    }

    /**
     * Extract stat data from Samsara response, handling both array and object formats.
     */
    protected static function extractStatData(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;
        
        if ($value === null) {
            return null;
        }

        // If it's an array of items, get the first one
        if (is_array($value) && isset($value[0]) && is_array($value[0])) {
            return $value[0];
        }

        // If it's a direct object (associative array), return as-is
        if (is_array($value)) {
            return $value;
        }

        return null;
    }
}
