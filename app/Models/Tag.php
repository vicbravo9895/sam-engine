<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tag extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'samsara_id',
        'name',
        'parent_tag_id',
        'addresses',
        'assets',
        'drivers',
        'machines',
        'sensors',
        'vehicles',
        'external_ids',
        'data_hash',
    ];

    protected function casts(): array
    {
        return [
            'addresses' => 'array',
            'assets' => 'array',
            'drivers' => 'array',
            'machines' => 'array',
            'sensors' => 'array',
            'vehicles' => 'array',
            'external_ids' => 'array',
        ];
    }

    /**
     * Get the company that owns this tag.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the parent tag.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'parent_tag_id', 'samsara_id');
    }

    /**
     * Scope a query to only include tags for a specific company.
     */
    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Get the child tags.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Tag::class, 'parent_tag_id', 'samsara_id');
    }

    /**
     * Generate a hash from the tag data for change detection.
     */
    public static function generateDataHash(array $data): string
    {
        // Sort the array to ensure consistent hashing
        ksort($data);
        return md5(json_encode($data));
    }

    /**
     * Check if the tag data has changed based on hash comparison.
     */
    public function hasDataChanged(array $newData): bool
    {
        $newHash = self::generateDataHash($newData);
        return $this->data_hash !== $newHash;
    }

    /**
     * Create or update a tag from Samsara API data.
     * 
     * @param array $samsaraData The tag data from Samsara API
     * @param int|null $companyId The company ID to associate with this tag
     */
    public static function syncFromSamsara(array $samsaraData, ?int $companyId = null): self
    {
        $dataHash = self::generateDataHash($samsaraData);
        
        // When syncing with company, we need to match both samsara_id and company_id
        $query = self::where('samsara_id', $samsaraData['id']);
        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }
        $tag = $query->first();
        
        // If tag exists and hash hasn't changed, skip update
        if ($tag && $tag->data_hash === $dataHash) {
            return $tag;
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
            'parent_tag_id' => $data['parentTagId'] ?? null,
            'addresses' => $data['addresses'] ?? null,
            'assets' => $data['assets'] ?? null,
            'drivers' => $data['drivers'] ?? null,
            'machines' => $data['machines'] ?? null,
            'sensors' => $data['sensors'] ?? null,
            'vehicles' => $data['vehicles'] ?? null,
            'external_ids' => $data['externalIds'] ?? null,
        ];
    }

    /**
     * Get the count of associated vehicles.
     */
    public function getVehicleCountAttribute(): int
    {
        return is_array($this->vehicles) ? count($this->vehicles) : 0;
    }

    /**
     * Get the count of associated drivers.
     */
    public function getDriverCountAttribute(): int
    {
        return is_array($this->drivers) ? count($this->drivers) : 0;
    }

    /**
     * Get the count of associated assets.
     */
    public function getAssetCountAttribute(): int
    {
        return is_array($this->assets) ? count($this->assets) : 0;
    }

    /**
     * Check if this is a root tag (no parent).
     */
    public function isRoot(): bool
    {
        return empty($this->parent_tag_id);
    }

    /**
     * Get the full hierarchy path of this tag.
     */
    public function getHierarchyPath(): array
    {
        $path = [$this->name];
        $current = $this;
        
        while ($current->parent) {
            $current = $current->parent;
            array_unshift($path, $current->name);
        }
        
        return $path;
    }
}

