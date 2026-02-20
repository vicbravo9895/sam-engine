<?php

namespace App\Jobs;

use App\Jobs\Traits\LogsWithTenantContext;
use App\Models\Alert;
use App\Models\MediaAsset;
use App\Models\SafetySignal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Job genérico para descargar y persistir un asset (imagen/video) de forma asíncrona.
 *
 * Flujo:
 * 1. Descarga el archivo desde source_url
 * 2. Lo almacena en el disco configurado (public/s3)
 * 3. Actualiza el MediaAsset con local_url, mime_type, file_size
 * 4. Actualiza el modelo padre si es necesario (ej: reemplaza URL en JSON)
 *
 * Logs estructurados para Grafana con prefijo "media_asset."
 */
class PersistMediaAssetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, LogsWithTenantContext;

    public $tries = 3;

    public $backoff = [15, 60, 300];

    public $timeout = 120;

    public function __construct(
        public MediaAsset $mediaAsset
    ) {
        $this->onQueue('media-assets');
    }

    public function handle(): void
    {
        $asset = $this->mediaAsset;
        $asset->refresh();

        $this->setLogContext(companyId: $asset->company_id);

        $startTime = microtime(true);

        if ($asset->status === MediaAsset::STATUS_COMPLETED) {
            Log::info('media_asset.skipped', [
                'asset_id' => $asset->id,
                'reason' => 'already_completed',
                'category' => $asset->category,
            ]);
            return;
        }

        Log::info('media_asset.processing', [
            'asset_id' => $asset->id,
            'company_id' => $asset->company_id,
            'category' => $asset->category,
            'assetable_type' => $asset->assetable_type,
            'assetable_id' => $asset->assetable_id,
            'storage_path' => $asset->storage_path,
            'attempt' => $this->attempts(),
            'max_attempts' => $this->tries,
        ]);

        $asset->markAsProcessing();

        try {
            $disk = Storage::disk($asset->disk);

            // Si el archivo ya existe en disco, solo actualizar URL
            if ($disk->exists($asset->storage_path)) {
                $localUrl = $disk->url($asset->storage_path);
                $fileSize = $disk->size($asset->storage_path);
                $mimeType = $disk->mimeType($asset->storage_path) ?: null;

                $asset->markAsCompleted($localUrl, $mimeType, $fileSize);

                Log::info('media_asset.completed', [
                    'asset_id' => $asset->id,
                    'company_id' => $asset->company_id,
                    'category' => $asset->category,
                    'local_url' => $localUrl,
                    'file_size' => $fileSize,
                    'source' => 'disk_exists',
                    'duration_ms' => $this->elapsed($startTime),
                ]);

                $this->updateParentModel($asset);
                return;
            }

            // Descargar desde source_url
            $response = Http::timeout(60)->get($asset->source_url);

            if (!$response->successful()) {
                throw new RuntimeException(
                    "HTTP {$response->status()} al descargar asset desde source_url"
                );
            }

            $body = $response->body();

            // Crear directorio si no existe
            $directory = dirname($asset->storage_path);
            if ($directory !== '.' && !$disk->exists($directory)) {
                $disk->makeDirectory($directory);
            }

            $disk->put($asset->storage_path, $body);

            $localUrl = $disk->url($asset->storage_path);
            $fileSize = strlen($body);
            $mimeType = $this->detectMimeType($asset->storage_path);

            $asset->markAsCompleted($localUrl, $mimeType, $fileSize);

            $durationMs = $this->elapsed($startTime);

            Log::info('media_asset.completed', [
                'asset_id' => $asset->id,
                'company_id' => $asset->company_id,
                'category' => $asset->category,
                'assetable_type' => $asset->assetable_type,
                'assetable_id' => $asset->assetable_id,
                'local_url' => $localUrl,
                'file_size' => $fileSize,
                'mime_type' => $mimeType,
                'duration_ms' => $durationMs,
            ]);

            $this->updateParentModel($asset);

        } catch (\Throwable $e) {
            $durationMs = $this->elapsed($startTime);

            Log::error('media_asset.failed', [
                'asset_id' => $asset->id,
                'company_id' => $asset->company_id,
                'category' => $asset->category,
                'assetable_type' => $asset->assetable_type,
                'assetable_id' => $asset->assetable_id,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries,
                'will_retry' => $this->attempts() < $this->tries,
                'duration_ms' => $durationMs,
            ]);

            if ($this->attempts() >= $this->tries) {
                $asset->markAsFailed($e->getMessage());
            }

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->mediaAsset->refresh();

        if ($this->mediaAsset->status !== MediaAsset::STATUS_FAILED) {
            $this->mediaAsset->markAsFailed($exception->getMessage());
        }

        Log::error('media_asset.permanently_failed', [
            'asset_id' => $this->mediaAsset->id,
            'company_id' => $this->mediaAsset->company_id,
            'category' => $this->mediaAsset->category,
            'assetable_type' => $this->mediaAsset->assetable_type,
            'assetable_id' => $this->mediaAsset->assetable_id,
            'storage_path' => $this->mediaAsset->storage_path,
            'error' => $exception->getMessage(),
            'total_attempts' => $this->mediaAsset->attempts,
        ]);
    }

    // ─── Parent model update strategies ──────────────────────────

    private function updateParentModel(MediaAsset $asset): void
    {
        if (!$asset->assetable_type || !$asset->assetable_id) {
            return;
        }

        try {
            match ($asset->category) {
                MediaAsset::CATEGORY_EVIDENCE => $this->replaceUrlInAlertJson($asset),
                MediaAsset::CATEGORY_SIGNAL => $this->replaceUrlInSignalMedia($asset),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::warning('media_asset.parent_update_failed', [
                'asset_id' => $asset->id,
                'assetable_type' => $asset->assetable_type,
                'assetable_id' => $asset->assetable_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reemplaza la URL original con la URL local en los campos JSON de AlertAi.
     * Usa lock para evitar race conditions con otros jobs concurrentes.
     */
    private function replaceUrlInAlertJson(MediaAsset $asset): void
    {
        DB::transaction(function () use ($asset) {
            $alert = Alert::lockForUpdate()->find($asset->assetable_id);
            if (!$alert) {
                return;
            }

            $alertAi = $alert->ai;
            if (!$alertAi) {
                return;
            }

            $sourceUrl = $asset->source_url;
            $localUrl = $asset->local_url;
            $updated = false;

            foreach (['ai_actions', 'supporting_evidence', 'raw_ai_output', 'alert_context', 'ai_assessment'] as $field) {
                $data = $alertAi->{$field};
                if (!$data) {
                    continue;
                }

                $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $replaced = str_replace($sourceUrl, $localUrl, $json);

                if ($json !== $replaced) {
                    $alertAi->{$field} = json_decode($replaced, true);
                    $updated = true;
                }
            }

            if ($updated) {
                $alertAi->save();

                Log::debug('media_asset.parent_url_replaced', [
                    'asset_id' => $asset->id,
                    'alert_id' => $alert->id,
                    'category' => $asset->category,
                ]);
            }
        });
    }

    /**
     * Reemplaza la URL original con la URL local en media_urls del SafetySignal.
     */
    private function replaceUrlInSignalMedia(MediaAsset $asset): void
    {
        DB::transaction(function () use ($asset) {
            $signal = SafetySignal::lockForUpdate()->find($asset->assetable_id);
            if (!$signal) {
                return;
            }

            $mediaUrls = $signal->media_urls;
            if (!is_array($mediaUrls)) {
                return;
            }

            $changed = false;
            foreach ($mediaUrls as $index => $media) {
                $url = $media['url'] ?? $media['mediaUrl'] ?? null;
                if ($url === $asset->source_url) {
                    $mediaUrls[$index]['url'] = $asset->local_url;
                    $mediaUrls[$index]['original_url'] = $asset->source_url;
                    $changed = true;
                }
            }

            if ($changed) {
                $signal->update(['media_urls' => $mediaUrls]);

                Log::debug('media_asset.parent_url_replaced', [
                    'asset_id' => $asset->id,
                    'signal_id' => $signal->id,
                    'category' => $asset->category,
                ]);
            }
        });
    }

    // ─── Helpers ─────────────────────────────────────────────────

    private function detectMimeType(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            default => null,
        };
    }

    private function elapsed(float $start): float
    {
        return round((microtime(true) - $start) * 1000, 2);
    }
}
