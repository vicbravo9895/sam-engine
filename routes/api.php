<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SamsaraWebhookController;
use App\Http\Controllers\SamsaraEventController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Webhook de Samsara (recibe alertas)
Route::post('/webhooks/samsara', [SamsaraWebhookController::class, 'handle']);

// API para el frontend (consultar eventos)
Route::prefix('events')->group(function () {
    Route::get('/', [SamsaraEventController::class, 'index']);
    Route::get('/{id}', [SamsaraEventController::class, 'show']);
    Route::get('/{id}/stream', [SamsaraEventController::class, 'stream']);
    Route::get('/{id}/status', [SamsaraEventController::class, 'status']);
});
