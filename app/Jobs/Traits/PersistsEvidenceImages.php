<?php

namespace App\Jobs\Traits;

use App\Jobs\PersistMediaAssetJob;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Trait para despachar la persistencia de imágenes de evidencia de forma asíncrona.
 *
 * Crea registros MediaAsset y despacha PersistMediaAssetJob para cada imagen.
 * Las URLs en los arrays se mantienen como están (source URLs) hasta que
 * el job las reemplace con URLs locales en los campos JSON del evento.
 *
 * Usado por ProcessSamsaraEventJob y RevalidateSamsaraEventJob.
 */
trait PersistsEvidenceImages
{
    /**
     * Despacha jobs asíncronos para persistir las imágenes de evidencia.
     *
     * Busca URLs en dos lugares:
     * 1. camera_analysis.media_urls (análisis pre-cargado automático)
     * 2. execution.agents[].tools[].media_urls (legacy)
     *
     * @return array Los arrays originales sin modificar [execution, cameraAnalysis]
     */
    private function persistEvidenceImages(?array $execution, ?array $cameraAnalysis = null): array
    {
        $eventId = $this->event->id;
        $companyId = $this->event->company_id;
        $disk = config('filesystems.media');
        $dispatched = 0;

        Log::info('media_asset.dispatching_evidence', [
            'event_id' => $eventId,
            'has_camera_analysis' => !empty($cameraAnalysis),
        ]);

        // =========================================================
        // 1. camera_analysis.media_urls (análisis pre-cargado)
        // =========================================================
        if ($cameraAnalysis && !empty($cameraAnalysis['media_urls'])) {
            foreach ($cameraAnalysis['media_urls'] as $index => $samsaraUrl) {
                if (!$this->isRemoteUrl($samsaraUrl)) {
                    continue;
                }

                $storagePath = 'evidence/' . Str::uuid() . '.jpg';

                $asset = MediaAsset::create([
                    'company_id' => $companyId,
                    'assetable_type' => \App\Models\SamsaraEvent::class,
                    'assetable_id' => $eventId,
                    'category' => MediaAsset::CATEGORY_EVIDENCE,
                    'disk' => $disk,
                    'source_url' => $samsaraUrl,
                    'storage_path' => $storagePath,
                    'status' => MediaAsset::STATUS_PENDING,
                    'metadata' => [
                        'origin' => 'camera_analysis',
                        'index' => $index,
                    ],
                ]);

                PersistMediaAssetJob::dispatch($asset);
                $dispatched++;
            }
        }

        // =========================================================
        // 2. execution.agents[].tools[].media_urls (legacy)
        // =========================================================
        if ($execution) {
            foreach ($execution['agents'] ?? [] as $agentIndex => $agent) {
                $agentName = $agent['name'] ?? "agent_{$agentIndex}";

                foreach ($agent['tools'] ?? [] as $toolIndex => $tool) {
                    if (empty($tool['media_urls'])) {
                        continue;
                    }

                    foreach ($tool['media_urls'] as $urlIndex => $samsaraUrl) {
                        if (!$this->isRemoteUrl($samsaraUrl)) {
                            continue;
                        }

                        $storagePath = 'evidence/' . Str::uuid() . '.jpg';

                        $asset = MediaAsset::create([
                            'company_id' => $companyId,
                            'assetable_type' => \App\Models\SamsaraEvent::class,
                            'assetable_id' => $eventId,
                            'category' => MediaAsset::CATEGORY_EVIDENCE,
                            'disk' => $disk,
                            'source_url' => $samsaraUrl,
                            'storage_path' => $storagePath,
                            'status' => MediaAsset::STATUS_PENDING,
                            'metadata' => [
                                'origin' => 'execution_tool',
                                'agent' => $agentName,
                                'tool_index' => $toolIndex,
                                'url_index' => $urlIndex,
                            ],
                        ]);

                        PersistMediaAssetJob::dispatch($asset);
                        $dispatched++;
                    }
                }
            }
        }

        Log::info('media_asset.evidence_dispatched', [
            'event_id' => $eventId,
            'jobs_dispatched' => $dispatched,
        ]);

        return [$execution, $cameraAnalysis];
    }

    /**
     * Verifica si la URL es una URL remota (no local).
     * Evita re-despachar para URLs que ya fueron persistidas.
     */
    private function isRemoteUrl(string $url): bool
    {
        $appUrl = config('app.url', 'http://localhost');

        if (str_starts_with($url, $appUrl)) {
            return false;
        }

        if (str_starts_with($url, '/storage/')) {
            return false;
        }

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }
}
