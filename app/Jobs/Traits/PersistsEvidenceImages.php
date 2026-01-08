<?php

namespace App\Jobs\Traits;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Trait para persistir imágenes de evidencia desde URLs de Samsara.
 * 
 * Usado por ProcessSamsaraEventJob y RevalidateSamsaraEventJob.
 */
trait PersistsEvidenceImages
{
    /**
     * Persiste las imágenes de evidencia desde las URLs de Samsara.
     * 
     * Busca URLs en dos lugares:
     * 1. execution.agents[].tools[].media_urls (legacy: cuando el investigador usaba tools)
     * 2. camera_analysis.media_urls (nuevo: análisis pre-cargado automático)
     * 
     * @param array|null $execution Los execution del resultado de AI
     * @param array|null $cameraAnalysis El análisis de cámara pre-cargado
     * @return array Los datos actualizados con URLs locales [execution, camera_analysis]
     */
    private function persistEvidenceImages(?array $execution, ?array $cameraAnalysis = null): array
    {
        $totalMediaUrlsFound = 0;
        $totalDownloaded = 0;
        $eventId = $this->event->id;

        Log::info("persistEvidenceImages: Starting", [
            'event_id' => $eventId,
            'has_camera_analysis' => !empty($cameraAnalysis),
            'camera_analysis_keys' => $cameraAnalysis ? array_keys($cameraAnalysis) : [],
        ]);

        // =========================================================
        // 1. Buscar en camera_analysis (nuevo: análisis pre-cargado)
        // =========================================================
        if ($cameraAnalysis && !empty($cameraAnalysis['media_urls'])) {
            $totalMediaUrlsFound += count($cameraAnalysis['media_urls']);

            Log::info("persistEvidenceImages: Found media_urls in camera_analysis", [
                'event_id' => $eventId,
                'media_urls_count' => count($cameraAnalysis['media_urls']),
                'first_url_preview' => substr($cameraAnalysis['media_urls'][0] ?? '', 0, 100),
            ]);

            $localUrls = [];
            foreach ($cameraAnalysis['media_urls'] as $index => $samsaraUrl) {
                Log::debug("persistEvidenceImages: Downloading image", [
                    'event_id' => $eventId,
                    'index' => $index,
                    'url_preview' => substr($samsaraUrl, 0, 80),
                ]);
                
                $localUrl = $this->downloadAndStoreImage($samsaraUrl);
                if ($localUrl) {
                    $localUrls[] = $localUrl;
                    $totalDownloaded++;
                    Log::info("persistEvidenceImages: Image saved successfully", [
                        'event_id' => $eventId,
                        'index' => $index,
                        'local_url' => $localUrl,
                    ]);
                } else {
                    Log::warning("persistEvidenceImages: Failed to download image", [
                        'event_id' => $eventId,
                        'index' => $index,
                    ]);
                }
            }

            // Reemplazar con URLs locales
            if (!empty($localUrls)) {
                $cameraAnalysis['media_urls'] = $localUrls;
                
                // También actualizar las URLs en cada análisis individual
                foreach ($cameraAnalysis['analyses'] ?? [] as $index => $analysis) {
                    if (isset($localUrls[$index])) {
                        $cameraAnalysis['analyses'][$index]['local_url'] = $localUrls[$index];
                    }
                }
                
                Log::info("persistEvidenceImages: Updated camera_analysis with local URLs", [
                    'event_id' => $eventId,
                    'local_urls_count' => count($localUrls),
                ]);
            } else {
                Log::warning("persistEvidenceImages: No images were downloaded successfully", [
                    'event_id' => $eventId,
                    'total_urls_attempted' => count($cameraAnalysis['media_urls']),
                ]);
            }
        } else {
            Log::debug("persistEvidenceImages: No camera_analysis.media_urls found", [
                'event_id' => $eventId,
                'has_camera_analysis' => !empty($cameraAnalysis),
                'has_media_urls' => isset($cameraAnalysis['media_urls']),
            ]);
        }

        // =========================================================
        // 2. Buscar en execution.agents[].tools[].media_urls (legacy)
        // =========================================================
        if ($execution) {
            foreach ($execution['agents'] ?? [] as $agentIndex => $agent) {
                $agentName = $agent['name'] ?? "agent_{$agentIndex}";

                foreach ($agent['tools'] ?? [] as $toolIndex => $tool) {
                    $toolName = $tool['name'] ?? "tool_{$toolIndex}";

                    if (!empty($tool['media_urls'])) {
                        $totalMediaUrlsFound += count($tool['media_urls']);

                        Log::debug("persistEvidenceImages: Found media_urls in tool", [
                            'event_id' => $eventId,
                            'agent_name' => $agentName,
                            'tool_name' => $toolName,
                            'media_urls_count' => count($tool['media_urls']),
                        ]);

                        $localUrls = [];
                        foreach ($tool['media_urls'] as $samsaraUrl) {
                            $localUrl = $this->downloadAndStoreImage($samsaraUrl);
                            if ($localUrl) {
                                $localUrls[] = $localUrl;
                                $totalDownloaded++;
                            }
                        }
                        // Reemplazar con URLs locales
                        if (!empty($localUrls)) {
                            $execution['agents'][$agentIndex]['tools'][$toolIndex]['media_urls'] = $localUrls;
                        }
                    }
                }
            }
        }

        Log::info("persistEvidenceImages: Completed", [
            'event_id' => $eventId,
            'total_media_urls_found' => $totalMediaUrlsFound,
            'total_downloaded' => $totalDownloaded,
        ]);

        return [$execution, $cameraAnalysis];
    }

    /**
     * Descarga una imagen de una URL y la guarda localmente
     * 
     * @param string $url URL de la imagen (S3 de Samsara)
     * @return string|null URL local de la imagen guardada
     */
    private function downloadAndStoreImage(string $url): ?string
    {
        try {
            // Descargar imagen
            $response = Http::timeout(30)->get($url);

            if (!$response->successful()) {
                Log::warning("Failed to download image", ['url' => substr($url, 0, 100)]);
                return null;
            }

            // Generar nombre único
            $filename = Str::uuid() . '.jpg';
            $path = "evidence/{$filename}";

            // Guardar usando Storage (public disk)
            Storage::disk('public')->put($path, $response->body());

            Log::debug("Evidence image saved", [
                'event_id' => $this->event->id,
                'path' => $path,
            ]);

            // Retornar URL pública
            return "/storage/{$path}";
        } catch (\Exception $e) {
            Log::warning("Error storing image", [
                'url' => substr($url, 0, 100),
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
