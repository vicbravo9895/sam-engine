<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StorageController extends Controller
{
    /**
     * Serve storage files with CORS headers (local disk) or redirect to S3/MinIO (bucket is public).
     */
    public function serve(Request $request, string $path)
    {
        $allowedPrefixes = ['dashcam-media', 'evidence', 'signal-media', 'company-logos'];
        $pathParts = explode('/', $path, 2);

        if (!in_array($pathParts[0], $allowedPrefixes)) {
            abort(403, 'Access denied');
        }

        $disk = Storage::disk(config('filesystems.media'));

        if (!$disk->exists($path)) {
            abort(404, 'File not found');
        }

        if (config('filesystems.media') !== 'public') {
            return redirect()->to($disk->url($path));
        }

        $filePath = $disk->path($path);
        $mimeType = $disk->mimeType($path) ?? 'application/octet-stream';

        return response()->file($filePath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=3600',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => '*',
        ]);
    }
}

