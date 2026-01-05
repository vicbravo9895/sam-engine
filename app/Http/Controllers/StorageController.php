<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StorageController extends Controller
{
    /**
     * Serve storage files with CORS headers.
     * This is needed because static files from the symlink don't go through Laravel middleware.
     */
    public function serve(Request $request, string $path)
    {
        // Only allow access to dashcam-media
        $allowedPaths = ['dashcam-media'];
        $pathParts = explode('/', $path, 2);
        
        if (!in_array($pathParts[0], $allowedPaths)) {
            abort(403, 'Access denied');
        }

        // Check if file exists
        if (!Storage::disk('public')->exists($path)) {
            abort(404, 'File not found');
        }

        // Get file path and mime type
        $filePath = Storage::disk('public')->path($path);
        $mimeType = Storage::disk('public')->mimeType($path) ?? 'application/octet-stream';

        // Return file with CORS headers
        return response()->file($filePath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=3600',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => '*',
        ]);
    }
}

