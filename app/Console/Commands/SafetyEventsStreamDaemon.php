<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\SafetySignal;
use App\Samsara\Client\SamsaraClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Safety Events Stream Daemon.
 * 
 * Este daemon hace polling del stream de safety events de Samsara
 * y persiste los datos normalizados en la tabla safety_signals.
 * 
 * Los eventos NO se procesan con IA, solo se almacenan para:
 * - Referencia histórica
 * - Consultas y reportes
 * - Análisis posterior
 * 
 * El daemon debe correr bajo supervisord para restart automático.
 */
class SafetyEventsStreamDaemon extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'samsara:safety-stream-daemon 
                            {--interval=30 : Polling interval in seconds (minimum 10)}
                            {--once : Run once and exit (for testing)}
                            {--company= : Sync only a specific company ID}
                            {--download-media : Download media files immediately (recommended)}';

    /**
     * The console command description.
     */
    protected $description = 'Daemon that polls Samsara safety events stream and persists them to database';

    private bool $running = true;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $interval = max(10, (int) $this->option('interval'));
        $runOnce = $this->option('once');
        $specificCompanyId = $this->option('company');

        $this->info("Safety Events Stream Daemon started");
        $this->info("Polling interval: {$interval} seconds");
        
        Log::info('SafetyEventsStreamDaemon: Starting daemon', [
            'interval_seconds' => $interval,
            'run_once' => $runOnce,
            'specific_company_id' => $specificCompanyId,
            'pid' => getmypid(),
        ]);

        // Register shutdown handlers for graceful termination
        $this->registerSignalHandlers();

        $iteration = 0;
        
        while ($this->running) {
            $iteration++;
            $cycleStart = microtime(true);
            
            try {
                $stats = $this->syncAllCompanies($specificCompanyId ? (int) $specificCompanyId : null);
                
                $cycleDuration = round((microtime(true) - $cycleStart) * 1000);
                
                if ($stats['total_events'] > 0 || $iteration % 10 === 0) {
                    // Format startTime for display (show only time if today, otherwise date+time)
                    $startTimeDisplay = $stats['start_time'] 
                        ? \Carbon\Carbon::parse($stats['start_time'])->format('Y-m-d H:i')
                        : 'N/A';
                    $cursorStatus = $stats['has_cursor'] ? 'cursor' : 'no-cursor';
                    
                    $this->line(sprintf(
                        '[%s] Cycle %d: %d companies, %d new, %d updated | startTime: %s (%s) | %dms',
                        now()->format('H:i:s'),
                        $iteration,
                        $stats['companies_synced'],
                        $stats['new_events'],
                        $stats['updated_events'],
                        $startTimeDisplay,
                        $cursorStatus,
                        $cycleDuration
                    ));
                }
                
            } catch (\Exception $e) {
                $this->error("Error in sync cycle: {$e->getMessage()}");
                Log::error('SafetyEventsStreamDaemon: Sync cycle failed', [
                    'error' => $e->getMessage(),
                    'iteration' => $iteration,
                ]);
            }

            // Exit if running in once mode
            if ($runOnce) {
                $this->info('Running in --once mode, exiting.');
                break;
            }

            // Sleep before next iteration
            sleep($interval);
        }

        $this->info('Daemon stopped.');
        Log::info('SafetyEventsStreamDaemon: Daemon stopped', [
            'total_iterations' => $iteration,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Register signal handlers for graceful shutdown.
     */
    private function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        
        pcntl_signal(SIGTERM, function () {
            $this->warn('Received SIGTERM, shutting down gracefully...');
            Log::info('SafetyEventsStreamDaemon: Received SIGTERM, shutting down');
            $this->running = false;
        });
        
        pcntl_signal(SIGINT, function () {
            $this->warn('Received SIGINT, shutting down gracefully...');
            Log::info('SafetyEventsStreamDaemon: Received SIGINT, shutting down');
            $this->running = false;
        });
    }

    /**
     * Sync safety events for all eligible companies.
     */
    private function syncAllCompanies(?int $specificCompanyId = null): array
    {
        $stats = [
            'companies_synced' => 0,
            'total_events' => 0,
            'new_events' => 0,
            'updated_events' => 0,
            'start_time' => null,
            'has_cursor' => false,
        ];

        // Get companies to sync
        $query = Company::query()
            ->where('is_active', true)
            ->whereNotNull('samsara_api_key');

        if ($specificCompanyId) {
            $query->where('id', $specificCompanyId);
        }

        $companies = $query->get();

        foreach ($companies as $company) {
            if (!$this->running) {
                break;
            }

            try {
                $companyStats = $this->syncCompany($company);
                
                $stats['companies_synced']++;
                $stats['total_events'] += $companyStats['total_events'];
                $stats['new_events'] += $companyStats['new_events'];
                $stats['updated_events'] += $companyStats['updated_events'];
                // Keep last company's time info for logging
                $stats['start_time'] = $companyStats['start_time'];
                $stats['has_cursor'] = $companyStats['has_cursor'];
                
            } catch (\Exception $e) {
                Log::warning('SafetyEventsStreamDaemon: Failed to sync company', [
                    'company_id' => $company->id,
                    'company_name' => $company->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Sync safety events for a specific company.
     */
    private function syncCompany(Company $company): array
    {
        $stats = [
            'total_events' => 0,
            'new_events' => 0,
            'updated_events' => 0,
            'start_time' => null,
            'has_cursor' => false,
        ];

        // Create Samsara client with company's API key
        $client = new SamsaraClient($company->getSamsaraApiKey());

        // Get cursor for pagination (null = start fresh)
        $cursor = $company->safety_stream_cursor;
        $stats['has_cursor'] = $cursor !== null;

        // Samsara API always requires startTime
        // When using cursor, we still need to provide startTime (API requirement)
        // Default to 24 hours ago if no cursor, or 1 hour ago with cursor
        $startTime = $cursor 
            ? now()->subHour()->toIso8601String() 
            : now()->subDay()->toIso8601String();
        
        $stats['start_time'] = $startTime;

        try {
            $response = $client->getSafetyEventsStream(
                startTime: $startTime,
                endTime: null, // Real-time mode
                vehicleIds: [],
                eventStates: [],
                after: $cursor,
                limit: 100
            );

            $events = $response['data'] ?? [];
            $newCursor = $response['pagination']['endCursor'] ?? null;
            $hasNextPage = $response['pagination']['hasNextPage'] ?? false;

            $stats['total_events'] = count($events);

            // Process each event
            $downloadMedia = $this->option('download-media');
            
            foreach ($events as $eventData) {
                if (!$this->running) {
                    break;
                }

                $result = $this->processEvent($company->id, $eventData, $downloadMedia);
                
                if ($result['is_new']) {
                    $stats['new_events']++;
                } else {
                    $stats['updated_events']++;
                }
            }

            // Update cursor and last sync time
            $company->safety_stream_cursor = $newCursor;
            $company->safety_stream_last_sync = now();
            $company->save();

            // If there are more pages, we'll get them in the next cycle
            if ($hasNextPage && $stats['total_events'] > 0) {
                Log::debug('SafetyEventsStreamDaemon: More pages available', [
                    'company_id' => $company->id,
                    'events_this_page' => $stats['total_events'],
                ]);
            }

        } catch (\Exception $e) {
            // If cursor is invalid or parameters issue, reset and retry
            $shouldRetry = str_contains($e->getMessage(), 'Parameters differ') || 
                str_contains($e->getMessage(), 'Invalid cursor') ||
                str_contains($e->getMessage(), 'startTime');
            
            if ($shouldRetry && $cursor !== null) {
                Log::warning('SafetyEventsStreamDaemon: Cursor issue, resetting and retrying', [
                    'company_id' => $company->id,
                    'error' => $e->getMessage(),
                ]);
                
                $company->safety_stream_cursor = null;
                $company->save();
                
                // Retry without cursor
                return $this->syncCompany($company);
            }

            throw $e;
        }

        return $stats;
    }

    /**
     * Process a single safety event.
     */
    private function processEvent(int $companyId, array $eventData, bool $downloadMedia = false): array
    {
        $samsaraEventId = $eventData['id'] ?? null;
        
        if (!$samsaraEventId) {
            return ['is_new' => false];
        }

        // Check if event already exists
        $existing = SafetySignal::where('company_id', $companyId)
            ->where('samsara_event_id', $samsaraEventId)
            ->first();

        if ($existing) {
            // Update existing event
            $existing->updateFromStreamEvent($eventData);
            return ['is_new' => false];
        }

        // Create new event
        $signal = SafetySignal::createFromStreamEvent($companyId, $eventData);
        
        // Download media immediately if enabled
        if ($downloadMedia && $signal && !empty($signal->media_urls)) {
            $this->downloadSignalMedia($signal);
        }
        
        return ['is_new' => true];
    }

    /**
     * Download and store media files for a safety signal.
     * 
     * This downloads media immediately while URLs are fresh (they expire in ~8 hours).
     */
    private function downloadSignalMedia(SafetySignal $signal): void
    {
        $mediaUrls = $signal->media_urls;
        
        if (!is_array($mediaUrls) || empty($mediaUrls)) {
            return;
        }

        $updatedMediaUrls = [];
        $downloadedCount = 0;

        foreach ($mediaUrls as $media) {
            $originalUrl = $media['url'] ?? $media['mediaUrl'] ?? null;
            
            if (!$originalUrl) {
                $updatedMediaUrls[] = $media;
                continue;
            }

            // Skip if already a local URL
            if (str_starts_with($originalUrl, '/storage/')) {
                $updatedMediaUrls[] = $media;
                continue;
            }

            // Download and store
            $localUrl = $this->downloadMedia($originalUrl, $signal->id);
            
            if ($localUrl) {
                $media['url'] = $localUrl;
                $media['original_url'] = $originalUrl;
                $downloadedCount++;
            }
            
            $updatedMediaUrls[] = $media;
        }

        // Update signal with local URLs
        if ($downloadedCount > 0) {
            $signal->update(['media_urls' => $updatedMediaUrls]);
            
            Log::debug('SafetyEventsStreamDaemon: Downloaded media for signal', [
                'signal_id' => $signal->id,
                'downloaded_count' => $downloadedCount,
            ]);
        }
    }

    /**
     * Download a single media file and store locally.
     */
    private function downloadMedia(string $url, int $signalId): ?string
    {
        try {
            // Determine file extension from URL
            $extension = $this->getExtensionFromUrl($url);
            
            // Download with timeout
            $response = Http::timeout(60)->get($url);

            if (!$response->successful()) {
                Log::warning("SafetyEventsStreamDaemon: Failed to download media", [
                    'signal_id' => $signalId,
                    'status' => $response->status(),
                ]);
                return null;
            }

            // Generate unique filename
            $filename = Str::uuid() . '.' . $extension;
            $path = "signal-media/{$filename}";

            // Store using public disk
            Storage::disk('public')->put($path, $response->body());

            return "/storage/{$path}";
        } catch (\Exception $e) {
            Log::warning("SafetyEventsStreamDaemon: Error downloading media", [
                'signal_id' => $signalId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extract file extension from URL.
     */
    private function getExtensionFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        
        if (str_contains($path, '.mp4')) return 'mp4';
        if (str_contains($path, '.webm')) return 'webm';
        if (str_contains($path, '.mov')) return 'mov';
        if (str_contains($path, '.jpg') || str_contains($path, '.jpeg')) return 'jpg';
        if (str_contains($path, '.png')) return 'png';

        // Default to mp4 for Samsara dashcam media
        return 'mp4';
    }
}
