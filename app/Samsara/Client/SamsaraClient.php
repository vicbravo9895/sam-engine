<?php

declare(strict_types=1);

namespace App\Samsara\Client;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

class SamsaraClient
{
    private const BASE_URL = 'https://api.samsara.com';

    public function __construct(
        private ?string $apiKey = null,
    ) {
        $this->apiKey = $apiKey ?? config('services.samsara.api_key');
    }

    /**
     * Get the HTTP client with authentication headers.
     */
    protected function client(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
        ])->baseUrl(self::BASE_URL);
    }

    /**
     * Get all vehicles from Samsara API with pagination support.
     * 
     * @param array $params Optional query parameters (limit, tagIds, etc.)
     * @return array All vehicles data combined from all pages
     */
    public function getVehicles(array $params = []): array
    {
        $allVehicles = [];
        $cursor = null;
        $hasNextPage = true;

        while ($hasNextPage) {
            $queryParams = $params;
            
            if ($cursor) {
                $queryParams['after'] = $cursor;
            }

            /** @var Response $response */
            $response = $this->client()->get('/fleet/vehicles', $queryParams);

            if (!$response->successful()) {
                throw new \Exception(
                    'Failed to fetch vehicles from Samsara API: ' . $response->body(),
                    $response->status()
                );
            }

            $data = $response->json();

            // Merge vehicles from this page
            if (isset($data['data']) && is_array($data['data'])) {
                $allVehicles = array_merge($allVehicles, $data['data']);
            }

            // Check for next page
            $hasNextPage = $data['pagination']['hasNextPage'] ?? false;
            $cursor = $data['pagination']['endCursor'] ?? null;
        }

        return $allVehicles;
    }

    /**
     * Get vehicles with pagination info (single page).
     * 
     * @param string|null $cursor Pagination cursor
     * @param int $limit Number of results per page
     * @return array Response with data and pagination info
     */
    public function getVehiclesPage(?string $cursor = null, int $limit = 100): array
    {
        $params = ['limit' => $limit];
        
        if ($cursor) {
            $params['after'] = $cursor;
        }

        /** @var Response $response */
        $response = $this->client()->get('/fleet/vehicles', $params);

        if (!$response->successful()) {
            throw new \Exception(
                'Failed to fetch vehicles from Samsara API: ' . $response->body(),
                $response->status()
            );
        }

        return $response->json();
    }

    /**
     * Get a single vehicle by ID.
     * 
     * @param string $vehicleId The Samsara vehicle ID
     * @return array Vehicle data
     */
    public function getVehicle(string $vehicleId): array
    {
        /** @var Response $response */
        $response = $this->client()->get("/fleet/vehicles/{$vehicleId}");

        if (!$response->successful()) {
            throw new \Exception(
                "Failed to fetch vehicle {$vehicleId} from Samsara API: " . $response->body(),
                $response->status()
            );
        }

        return $response->json()['data'] ?? [];
    }

    /**
     * Available stat types for vehicle stats feed.
     */
    public const STAT_TYPES = [
        'gps',                            // GPS location of the vehicle
        'fuelPercents',                   // Fuel level percentage
        'obdOdometerMeters',              // OBD odometer reading in meters
        'engineStates',                   // Engine state (on, off, idle)
        'engineRpm',                      // Engine revolutions per minute
        'vehicleBatteryVoltage',          // Vehicle battery voltage
        'engineCoolantTemperatureMilliC', // Engine coolant temperature in milli-Celsius
        'engineLoadPercent',              // Engine load percentage
        'ambientAirTemperatureMilliC',    // Ambient air temperature in milli-Celsius
        'faultCodes',                     // Engine fault codes
    ];

    /**
     * Get vehicle stats feed from Samsara API.
     * 
     * Follow a feed of vehicle stats. Returns the most recent stats for each vehicle.
     * 
     * @param array $vehicleIds Array of Samsara vehicle IDs to query
     * @param array $types Array of stat types to retrieve (defaults to all available types)
     * @param string|null $after Pagination cursor for getting updates since last call
     * @return array Stats data with pagination info
     * 
     * @see https://developers.samsara.com/reference/getvehiclestatsfeed
     */
    public function getVehicleStatsFeed(array $vehicleIds = [], array $types = [], ?string $after = null): array
    {
        $params = [];

        // Add vehicle IDs if provided
        if (!empty($vehicleIds)) {
            $params['vehicleIds'] = implode(',', $vehicleIds);
        }

        // Use default types if none provided
        if (empty($types)) {
            $types = self::STAT_TYPES;
        }
        $params['types'] = implode(',', $types);

        // Add cursor for pagination
        if ($after) {
            $params['after'] = $after;
        }

        /** @var Response $response */
        $response = $this->client()->get('/fleet/vehicles/stats', $params);

        if (!$response->successful()) {
            throw new \Exception(
                'Failed to fetch vehicle stats from Samsara API: ' . $response->body(),
                $response->status()
            );
        }

        return $response->json();
    }

    /**
     * Maximum number of stat types per API request.
     * Samsara API limits to 3 types per request.
     */
    public const MAX_TYPES_PER_REQUEST = 3;

    /**
     * Get vehicle stats by making multiple requests if needed (max 3 types per request).
     * 
     * This method automatically handles the 3-type limit by making multiple API calls
     * and merging the results.
     * 
     * @param array $vehicleIds Array of Samsara vehicle IDs to query
     * @param array $types Array of stat types to retrieve (will be chunked into groups of 3)
     * @return array Merged stats data from all requests
     * 
     * @see https://developers.samsara.com/reference/getvehiclestatsfeed
     */
    public function getVehicleStats(array $vehicleIds = [], array $types = []): array
    {
        // Use default types if none provided
        if (empty($types)) {
            $types = array_slice(self::STAT_TYPES, 0, self::MAX_TYPES_PER_REQUEST);
        }

        // Chunk types into groups of 3 (API limit)
        $typeChunks = array_chunk($types, self::MAX_TYPES_PER_REQUEST);
        
        $mergedData = [];
        
        foreach ($typeChunks as $typeChunk) {
            $response = $this->getVehicleStatsFeed($vehicleIds, $typeChunk);
            
            // Merge the data from this request
            if (isset($response['data']) && is_array($response['data'])) {
                foreach ($response['data'] as $vehicleData) {
                    $vehicleId = $vehicleData['id'] ?? null;
                    if (!$vehicleId) continue;
                    
                    if (!isset($mergedData[$vehicleId])) {
                        $mergedData[$vehicleId] = $vehicleData;
                    } else {
                        // Merge stat fields
                        foreach ($typeChunk as $type) {
                            if (isset($vehicleData[$type])) {
                                $mergedData[$vehicleId][$type] = $vehicleData[$type];
                            }
                        }
                    }
                }
            }
        }
        
        return [
            'data' => array_values($mergedData),
        ];
    }

    /**
     * Available media input types for dashcam media.
     */
    public const MEDIA_INPUTS = [
        'dashcamRoadFacing',       // Road-facing camera
        'dashcamDriverFacing',     // Driver-facing camera
    ];

    /**
     * Maximum time range for media queries (in minutes).
     * API allows up to 24 hours, but we'll use a reasonable default.
     */
    public const MAX_MEDIA_QUERY_RANGE_MINUTES = 60;

    /**
     * Get uploaded media (dashcam images/videos) from Samsara API.
     * 
     * @param string $startTime RFC 3339 timestamp for range start
     * @param string $endTime RFC 3339 timestamp for range end
     * @param array $vehicleIds Optional filter by vehicle IDs
     * @param array $inputs Optional filter by input types (dashcamRoadFacing, dashcamDriverFacing)
     * @return array Media data
     * 
     * @see https://developers.samsara.com/reference/listuploadedmedia
     */
    public function getUploadedMedia(
        string $startTime,
        string $endTime,
        array $vehicleIds = [],
        array $inputs = []
    ): array {
        // Build query string manually because inputs needs to be repeated, not comma-separated
        $queryParts = [
            'startTime=' . urlencode($startTime),
            'endTime=' . urlencode($endTime),
        ];

        if (!empty($vehicleIds)) {
            $queryParts[] = 'vehicleIds=' . urlencode(implode(',', $vehicleIds));
        }

        // Each input must be a separate parameter (inputs=X&inputs=Y)
        foreach ($inputs as $input) {
            $queryParts[] = 'inputs=' . urlencode($input);
        }

        $queryString = implode('&', $queryParts);

        /** @var Response $response */
        $response = $this->client()->get('/cameras/media?' . $queryString);

        if (!$response->successful()) {
            throw new \Exception(
                'Failed to fetch uploaded media from Samsara API: ' . $response->body(),
                $response->status()
            );
        }

        return $response->json();
    }

    /**
     * Get safety events from Samsara API.
     * 
     * Fetch safety events for the organization in a given time period.
     * 
     * @param string $startTime RFC 3339 timestamp for range start
     * @param string $endTime RFC 3339 timestamp for range end
     * @param array $vehicleIds Optional filter by vehicle IDs
     * @return array Safety events data
     * 
     * @see https://developers.samsara.com/reference/getsafetyevents
     */
    public function getSafetyEvents(
        string $startTime,
        string $endTime,
        array $vehicleIds = []
    ): array {
        $params = [
            'startTime' => $startTime,
            'endTime' => $endTime,
        ];

        if (!empty($vehicleIds)) {
            $params['vehicleIds'] = implode(',', $vehicleIds);
        }

        /** @var Response $response */
        $response = $this->client()->get('/fleet/safety-events', $params);

        if (!$response->successful()) {
            throw new \Exception(
                'Failed to fetch safety events from Samsara API: ' . $response->body(),
                $response->status()
            );
        }

        return $response->json();
    }

    /**
     * Get safety events with automatic time range around the query time.
     * 
     * This method will search for safety events in a time window
     * around the current time (or specified end time).
     * 
     * @param array $vehicleIds Vehicle IDs to query
     * @param int $minutesBefore Minutes to look back from end time (default: 5)
     * @param string|null $endTime Optional end time (defaults to now)
     * @return array Safety events data with search metadata
     */
    public function getSafetyEventsRecent(
        array $vehicleIds = [],
        int $minutesBefore = 5,
        ?string $endTime = null
    ): array {
        $endDateTime = $endTime ? new \DateTime($endTime) : new \DateTime();
        $startDateTime = clone $endDateTime;
        $startDateTime->modify("-{$minutesBefore} minutes");

        $response = $this->getSafetyEvents(
            $startDateTime->format(\DateTime::RFC3339),
            $endDateTime->format(\DateTime::RFC3339),
            $vehicleIds
        );

        $events = $response['data'] ?? [];

        return [
            'data' => $events,
            'meta' => [
                'searchRangeMinutes' => $minutesBefore,
                'startTime' => $startDateTime->format(\DateTime::RFC3339),
                'endTime' => $endDateTime->format(\DateTime::RFC3339),
                'totalEvents' => count($events),
            ],
        ];
    }

    /**
     * Get dashcam media with automatic retry on empty results.
     * 
     * This method will progressively extend the time range backwards
     * if no media is found, up to a maximum retry count.
     * 
     * @param array $vehicleIds Vehicle IDs to query
     * @param array $inputs Input types (dashcamRoadFacing, dashcamDriverFacing)
     * @param int $maxRetries Maximum number of retries (each extends range by increment)
     * @param int $incrementMinutes Minutes to extend range on each retry
     * @param string|null $endTime Optional end time (defaults to now)
     * @return array Media data with search metadata
     */
    public function getDashcamMediaWithRetry(
        array $vehicleIds = [],
        array $inputs = ['dashcamRoadFacing', 'dashcamDriverFacing'],
        int $maxRetries = 10,
        int $incrementMinutes = 5,
        ?string $endTime = null
    ): array {
        $endDateTime = $endTime ? new \DateTime($endTime) : new \DateTime();
        $startDateTime = clone $endDateTime;
        $startDateTime->modify("-{$incrementMinutes} minutes");

        $attempt = 0;
        $media = [];
        $totalRangeMinutes = $incrementMinutes;

        while ($attempt < $maxRetries) {
            $response = $this->getUploadedMedia(
                $startDateTime->format(\DateTime::RFC3339),
                $endDateTime->format(\DateTime::RFC3339),
                $vehicleIds,
                $inputs
            );

            // API returns data.media array
            $media = $response['data']['media'] ?? [];

            if (!empty($media)) {
                // Found media, return results
                return [
                    'data' => $media,
                    'meta' => [
                        'attempts' => $attempt + 1,
                        'searchRangeMinutes' => $totalRangeMinutes,
                        'startTime' => $startDateTime->format(\DateTime::RFC3339),
                        'endTime' => $endDateTime->format(\DateTime::RFC3339),
                        'found' => true,
                    ],
                ];
            }

            // Extend the range backwards
            $attempt++;
            $startDateTime->modify("-{$incrementMinutes} minutes");
            $totalRangeMinutes += $incrementMinutes;

            // Don't exceed maximum range (24 hours = 1440 minutes)
            if ($totalRangeMinutes > 1440) {
                break;
            }
        }

        // No media found after all retries
        return [
            'data' => [],
            'meta' => [
                'attempts' => $attempt,
                'searchRangeMinutes' => $totalRangeMinutes,
                'startTime' => $startDateTime->format(\DateTime::RFC3339),
                'endTime' => $endDateTime->format(\DateTime::RFC3339),
                'found' => false,
            ],
        ];
    }

    /**
     * Available event states for safety events filtering.
     */
    public const SAFETY_EVENT_STATES = [
        'needsReview',     // Event needs to be reviewed
        'needsCoaching',   // Event needs coaching
        'dismissed',       // Event has been dismissed
        'coached',         // Event has been coached
    ];

    /**
     * Get safety events stream from Samsara API.
     * 
     * This endpoint returns all safety events with rich data including
     * asset info, driver info, behavior labels, context labels, media URLs, and more.
     * Supports real-time polling with pagination cursor.
     * 
     * @param string|null $startTime RFC 3339 timestamp for range start
     * @param string|null $endTime RFC 3339 timestamp for range end (optional for real-time)
     * @param array $vehicleIds Optional filter by vehicle IDs
     * @param array $eventStates Optional filter by event states (needsReview, needsCoaching, dismissed, coached)
     * @param string|null $after Pagination cursor for getting more results
     * @param int $limit Maximum number of results per page (default 100)
     * @return array Safety events data with pagination
     * 
     * @see https://developers.samsara.com/reference/getsafetyeventsv2stream
     */
    public function getSafetyEventsStream(
        ?string $startTime = null,
        ?string $endTime = null,
        array $vehicleIds = [],
        array $eventStates = [],
        ?string $after = null,
        int $limit = 100
    ): array {
        $params = [];

        if ($startTime) {
            $params['startTime'] = $startTime;
        }

        if ($endTime) {
            $params['endTime'] = $endTime;
        }

        if (!empty($vehicleIds)) {
            $params['vehicleIds'] = implode(',', $vehicleIds);
        }

        if (!empty($eventStates)) {
            $params['eventStates'] = implode(',', $eventStates);
        }

        if ($after) {
            $params['after'] = $after;
        }

        $params['limit'] = $limit;

        /** @var Response $response */
        $response = $this->client()->get('/safety-events/stream', $params);

        if (!$response->successful()) {
            throw new \Exception(
                'Failed to fetch safety events stream from Samsara API: ' . $response->body(),
                $response->status()
            );
        }

        return $response->json();
    }

    /**
     * Get recent safety events using the stream endpoint.
     * 
     * This method fetches the latest safety events with all associated data
     * including media, driver info, location details, and coaching status.
     * 
     * @param array $vehicleIds Vehicle IDs to query
     * @param int $minutesBefore Minutes to look back from now (default: 60)
     * @param array $eventStates Optional filter by event states
     * @param int $limit Maximum number of events to return
     * @return array Safety events with search metadata
     */
    public function getRecentSafetyEventsStream(
        array $vehicleIds = [],
        int $minutesBefore = 60,
        array $eventStates = [],
        int $limit = 50
    ): array {
        $endDateTime = new \DateTime();
        $startDateTime = clone $endDateTime;
        $startDateTime->modify("-{$minutesBefore} minutes");

        $allEvents = [];
        $cursor = null;
        $hasNextPage = true;

        // Paginate through all results
        while ($hasNextPage && count($allEvents) < $limit) {
            $response = $this->getSafetyEventsStream(
                $startDateTime->format(\DateTime::RFC3339),
                $endDateTime->format(\DateTime::RFC3339),
                $vehicleIds,
                $eventStates,
                $cursor,
                min($limit - count($allEvents), 100)
            );

            $events = $response['data'] ?? [];
            $allEvents = array_merge($allEvents, $events);

            $hasNextPage = $response['pagination']['hasNextPage'] ?? false;
            $cursor = $response['pagination']['endCursor'] ?? null;
        }

        // Sort by most recent first
        usort($allEvents, function ($a, $b) {
            $timeA = $a['createdAtTime'] ?? $a['startMs'] ?? '';
            $timeB = $b['createdAtTime'] ?? $b['startMs'] ?? '';
            return strcmp($timeB, $timeA);
        });

        // Limit to requested amount
        $allEvents = array_slice($allEvents, 0, $limit);

        return [
            'data' => $allEvents,
            'meta' => [
                'searchRangeMinutes' => $minutesBefore,
                'startTime' => $startDateTime->format(\DateTime::RFC3339),
                'endTime' => $endDateTime->format(\DateTime::RFC3339),
                'totalEvents' => count($allEvents),
            ],
        ];
    }

    /**
     * Get latest safety events with extended time range search.
     * 
     * This method will progressively extend the time range backwards
     * if not enough events are found, up to a maximum range.
     * 
     * @param array $vehicleIds Vehicle IDs to query
     * @param int $minEvents Minimum number of events to find before stopping
     * @param int $maxRangeHours Maximum hours to look back
     * @param array $eventStates Optional filter by event states
     * @return array Safety events with search metadata
     */
    public function getLatestSafetyEvents(
        array $vehicleIds = [],
        int $minEvents = 10,
        int $maxRangeHours = 24,
        array $eventStates = []
    ): array {
        $endDateTime = new \DateTime();
        $startDateTime = clone $endDateTime;
        
        $incrementMinutes = 60; // Start with 1 hour
        $totalMinutes = 0;
        $maxMinutes = $maxRangeHours * 60;
        
        $allEvents = [];

        while (count($allEvents) < $minEvents && $totalMinutes < $maxMinutes) {
            $totalMinutes += $incrementMinutes;
            $startDateTime = clone $endDateTime;
            $startDateTime->modify("-{$totalMinutes} minutes");

            $response = $this->getSafetyEventsStream(
                $startDateTime->format(\DateTime::RFC3339),
                $endDateTime->format(\DateTime::RFC3339),
                $vehicleIds,
                $eventStates,
                null,
                100
            );

            $events = $response['data'] ?? [];
            
            if (!empty($events)) {
                $allEvents = $events;
                
                // Continue paginating if we have more pages and need more events
                $hasNextPage = $response['pagination']['hasNextPage'] ?? false;
                $cursor = $response['pagination']['endCursor'] ?? null;
                
                while ($hasNextPage && count($allEvents) < $minEvents) {
                    $pageResponse = $this->getSafetyEventsStream(
                        $startDateTime->format(\DateTime::RFC3339),
                        $endDateTime->format(\DateTime::RFC3339),
                        $vehicleIds,
                        $eventStates,
                        $cursor,
                        100
                    );
                    
                    $pageEvents = $pageResponse['data'] ?? [];
                    $allEvents = array_merge($allEvents, $pageEvents);
                    
                    $hasNextPage = $pageResponse['pagination']['hasNextPage'] ?? false;
                    $cursor = $pageResponse['pagination']['endCursor'] ?? null;
                }
            }

            // Double the increment for next iteration
            $incrementMinutes = min($incrementMinutes * 2, 240);
        }

        // Sort by most recent first
        usort($allEvents, function ($a, $b) {
            $timeA = $a['createdAtTime'] ?? $a['startMs'] ?? '';
            $timeB = $b['createdAtTime'] ?? $b['startMs'] ?? '';
            return strcmp($timeB, $timeA);
        });

        return [
            'data' => $allEvents,
            'meta' => [
                'searchRangeMinutes' => $totalMinutes,
                'startTime' => $startDateTime->format(\DateTime::RFC3339),
                'endTime' => $endDateTime->format(\DateTime::RFC3339),
                'totalEvents' => count($allEvents),
            ],
        ];
    }

    /**
     * Get all tags from Samsara API with pagination support.
     * 
     * Tags are used to organize and group resources in Samsara
     * (vehicles, drivers, assets, etc.)
     * 
     * @param int $limit Number of results per page (max 512)
     * @return array All tags data combined from all pages
     * 
     * @see https://developers.samsara.com/reference/listtags
     */
    public function getTags(int $limit = 512): array
    {
        $allTags = [];
        $cursor = null;
        $hasNextPage = true;

        while ($hasNextPage) {
            $params = ['limit' => $limit];
            
            if ($cursor) {
                $params['after'] = $cursor;
            }

            /** @var Response $response */
            $response = $this->client()->get('/tags', $params);

            if (!$response->successful()) {
                throw new \Exception(
                    'Failed to fetch tags from Samsara API: ' . $response->body(),
                    $response->status()
                );
            }

            $data = $response->json();

            // Merge tags from this page
            if (isset($data['data']) && is_array($data['data'])) {
                $allTags = array_merge($allTags, $data['data']);
            }

            // Check for next page
            $hasNextPage = $data['pagination']['hasNextPage'] ?? false;
            $cursor = $data['pagination']['endCursor'] ?? null;
        }

        return $allTags;
    }

    /**
     * Get a single tag by ID.
     * 
     * @param string $tagId The Samsara tag ID
     * @return array Tag data
     * 
     * @see https://developers.samsara.com/reference/gettagbyid
     */
    public function getTag(string $tagId): array
    {
        /** @var Response $response */
        $response = $this->client()->get("/tags/{$tagId}");

        if (!$response->successful()) {
            throw new \Exception(
                "Failed to fetch tag {$tagId} from Samsara API: " . $response->body(),
                $response->status()
            );
        }

        return $response->json()['data'] ?? [];
    }

    /**
     * Get trips stream from Samsara API.
     * 
     * This endpoint returns trips that have been collected for your organization
     * based on the time parameters passed in. Results are paginated.
     * 
     * @param string $startTime RFC 3339 timestamp for range start (required)
     * @param array $assetIds Array of asset IDs to query (required, max 50)
     * @param string|null $after Pagination cursor for getting more results
     * @param int $limit Maximum number of results per page (default 100)
     * @return array Trips data with pagination
     * 
     * @see https://developers.samsara.com/reference/gettrips
     */
    public function getTripsStream(
        string $startTime,
        array $assetIds,
        ?string $after = null,
        int $limit = 100
    ): array {
        if (empty($assetIds)) {
            throw new \InvalidArgumentException('At least one asset ID is required');
        }

        if (count($assetIds) > 50) {
            throw new \InvalidArgumentException('Maximum 50 asset IDs allowed per request');
        }

        $params = [
            'startTime' => $startTime,
            'ids' => implode(',', $assetIds),
            'limit' => $limit,
        ];

        if ($after) {
            $params['after'] = $after;
        }

        /** @var Response $response */
        $response = $this->client()->get('/trips/stream', $params);

        if (!$response->successful()) {
            throw new \Exception(
                'Failed to fetch trips stream from Samsara API: ' . $response->body(),
                $response->status()
            );
        }

        return $response->json();
    }

    /**
     * Get recent trips using the stream endpoint.
     * 
     * This method fetches trips with all associated data including
     * asset info, locations, and timing details.
     * 
     * @param array $assetIds Asset IDs to query (required)
     * @param int $hoursBack Hours to look back from now (default: 24)
     * @param int $limit Maximum number of trips to return
     * @return array Trips with search metadata
     */
    public function getRecentTrips(
        array $assetIds,
        int $hoursBack = 24,
        int $limit = 10
    ): array {
        $endDateTime = new \DateTime();
        $startDateTime = clone $endDateTime;
        $startDateTime->modify("-{$hoursBack} hours");

        $allTrips = [];
        $cursor = null;
        $hasNextPage = true;

        // Paginate through all results
        while ($hasNextPage && count($allTrips) < $limit) {
            $response = $this->getTripsStream(
                $startDateTime->format(\DateTime::RFC3339),
                $assetIds,
                $cursor,
                min($limit - count($allTrips), 100)
            );

            $trips = $response['data'] ?? [];
            $allTrips = array_merge($allTrips, $trips);

            $hasNextPage = $response['pagination']['hasNextPage'] ?? false;
            $cursor = $response['pagination']['endCursor'] ?? null;
        }

        // Sort by most recent first (by tripStartTime)
        usort($allTrips, function ($a, $b) {
            $timeA = $a['tripStartTime'] ?? $a['createdAtTime'] ?? '';
            $timeB = $b['tripStartTime'] ?? $b['createdAtTime'] ?? '';
            return strcmp($timeB, $timeA);
        });

        // Limit to requested amount
        $allTrips = array_slice($allTrips, 0, $limit);

        return [
            'data' => $allTrips,
            'meta' => [
                'searchRangeHours' => $hoursBack,
                'startTime' => $startDateTime->format(\DateTime::RFC3339),
                'endTime' => $endDateTime->format(\DateTime::RFC3339),
                'totalTrips' => count($allTrips),
            ],
        ];
    }
}

