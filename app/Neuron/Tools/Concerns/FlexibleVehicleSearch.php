<?php

declare(strict_types=1);

namespace App\Neuron\Tools\Concerns;

use App\Models\Vehicle;
use App\Neuron\CompanyContext;

/**
 * Trait for flexible vehicle searching.
 * 
 * Provides smart search capabilities that can find vehicles by:
 * - Exact name match
 * - Partial name match (LIKE)
 * - Numeric portions (e.g., "606" finds "T-606", "TR-606")
 * 
 * Also provides suggestion functionality when multiple matches are found.
 * 
 * IMPORTANT: All searches are filtered by the current company context
 * to ensure data isolation between companies.
 */
trait FlexibleVehicleSearch
{
    /**
     * Get the base query filtered by company.
     */
    protected function getVehicleQuery()
    {
        $context = CompanyContext::current();
        
        if ($context) {
            return Vehicle::forCompany($context->getCompanyId());
        }
        
        return Vehicle::query();
    }

    /**
     * Find a vehicle using flexible matching.
     * Returns exact match if found, or suggestions if multiple matches.
     * 
     * IMPROVED: Better prioritization for number-based searches.
     * When searching for "796", it will prioritize "T-796" over vehicles
     * that just happen to contain "796" somewhere in other fields.
     * 
     * @param string $searchTerm The search term (name or partial number)
     * @return array{exact: bool, vehicle: ?Vehicle, suggestions: array}
     */
    protected function findVehicleFlexible(string $searchTerm): array
    {
        // Normalize search term
        $searchTerm = trim($searchTerm);
        
        // First try exact match - FILTERED BY COMPANY
        $exactMatch = $this->getVehicleQuery()->where('name', $searchTerm)->first();
        if ($exactMatch) {
            return ['exact' => true, 'vehicle' => $exactMatch, 'suggestions' => []];
        }

        // Extract numbers from search term for smart matching
        preg_match_all('/\d+/', $searchTerm, $matches);
        $numbers = $matches[0] ?? [];
        
        // Try LIKE match on name - FILTERED BY COMPANY
        $likeMatches = $this->getVehicleQuery()
            ->where('name', 'like', '%' . $searchTerm . '%')
            ->orderBy('name')
            ->limit(10)
            ->get();
        
        if ($likeMatches->count() === 1) {
            return ['exact' => true, 'vehicle' => $likeMatches->first(), 'suggestions' => []];
        }

        if ($likeMatches->count() > 1) {
            // If we have multiple matches, try to find the best one
            // Prioritize matches where the number appears after common prefixes (T-, TR-, etc.)
            if (!empty($numbers)) {
                $bestMatch = $this->findBestNumberMatch($likeMatches, $numbers[0]);
                if ($bestMatch) {
                    return ['exact' => true, 'vehicle' => $bestMatch, 'suggestions' => []];
                }
            }
            
            return [
                'exact' => false, 
                'vehicle' => null, 
                'suggestions' => $likeMatches->map(fn($v) => [
                    'id' => $v->samsara_id,
                    'name' => $v->name,
                ])->toArray(),
            ];
        }

        // If no direct LIKE match, search by extracted numbers - FILTERED BY COMPANY
        if (!empty($numbers)) {
            foreach ($numbers as $number) {
                $numberMatches = $this->getVehicleQuery()
                    ->where('name', 'like', '%' . $number . '%')
                    ->orderBy('name')
                    ->limit(10)
                    ->get();
                
                if ($numberMatches->count() === 1) {
                    return ['exact' => true, 'vehicle' => $numberMatches->first(), 'suggestions' => []];
                }
                
                if ($numberMatches->count() > 1) {
                    // Try to find the best match based on common naming patterns
                    $bestMatch = $this->findBestNumberMatch($numberMatches, $number);
                    if ($bestMatch) {
                        return ['exact' => true, 'vehicle' => $bestMatch, 'suggestions' => []];
                    }
                    
                    return [
                        'exact' => false, 
                        'vehicle' => null, 
                        'suggestions' => $numberMatches->map(fn($v) => [
                            'id' => $v->samsara_id,
                            'name' => $v->name,
                        ])->toArray(),
                    ];
                }
            }
        }

        return ['exact' => false, 'vehicle' => null, 'suggestions' => []];
    }
    
    /**
     * Find the best matching vehicle from a collection based on number patterns.
     * 
     * Prioritizes vehicles where the number appears:
     * 1. Right after a prefix like "T-", "TR-", "Unidad-" (e.g., "T-796" for search "796")
     * 2. At the start of the name
     * 3. As a distinct segment (surrounded by non-digits)
     * 
     * @param \Illuminate\Support\Collection $vehicles Collection of vehicles to search
     * @param string $number The number to match
     * @return Vehicle|null The best matching vehicle, or null if no clear winner
     */
    protected function findBestNumberMatch($vehicles, string $number): ?Vehicle
    {
        // Common vehicle name prefixes
        $prefixes = ['T-', 'TR-', 'Unidad-', 'Unidad ', 'Camion-', 'Camion ', 'Camión-', 'Camión ', 'V-'];
        
        foreach ($vehicles as $vehicle) {
            $name = $vehicle->name;
            
            // Check if name matches pattern: PREFIX + NUMBER (e.g., "T-796 ...")
            foreach ($prefixes as $prefix) {
                // Pattern: prefix followed by the exact number, then non-digit or end
                if (preg_match('/^' . preg_quote($prefix, '/') . $number . '(?:\D|$)/i', $name)) {
                    return $vehicle;
                }
            }
            
            // Check if name starts with the number followed by non-digit
            if (preg_match('/^' . $number . '(?:\D|$)/', $name)) {
                return $vehicle;
            }
        }
        
        return null;
    }

    /**
     * Resolve vehicle IDs from names using flexible search.
     * Returns resolved IDs and any suggestions for ambiguous searches.
     * 
     * @param string $vehicleNames Comma-separated vehicle names
     * @return array{vehicleIds: array, vehicleNamesMap: array, suggestions: array}
     */
    protected function resolveVehicleNamesFlexible(string $vehicleNames): array
    {
        $vehicleIds = [];
        $vehicleNamesMap = [];
        $suggestions = [];

        $names = array_map('trim', explode(',', $vehicleNames));
        
        foreach ($names as $name) {
            $matchResult = $this->findVehicleFlexible($name);
            
            if ($matchResult['exact'] && $matchResult['vehicle']) {
                $vehicleIds[] = $matchResult['vehicle']->samsara_id;
                $vehicleNamesMap[$matchResult['vehicle']->samsara_id] = $matchResult['vehicle']->name;
            } elseif (!empty($matchResult['suggestions'])) {
                $suggestions[$name] = $matchResult['suggestions'];
            }
        }

        return [
            'vehicleIds' => $vehicleIds,
            'vehicleNamesMap' => $vehicleNamesMap,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Generate a clarification response when suggestions are available.
     * 
     * @param array $suggestions Map of search term => suggestions
     * @return string JSON response asking for clarification
     */
    protected function generateClarificationResponse(array $suggestions): string
    {
        return json_encode([
            'error' => false,
            'needs_clarification' => true,
            'message' => 'Se encontraron varios vehículos que coinciden con tu búsqueda. Por favor especifica cuál deseas:',
            'suggestions' => $suggestions,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

