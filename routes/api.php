<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SamsaraWebhookController;
use App\Http\Controllers\SamsaraEventController;
use App\Http\Controllers\TwilioCallbackController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Webhook de Samsara (recibe alertas)
Route::post('/webhooks/samsara', [SamsaraWebhookController::class, 'handle']);

// Twilio voice callbacks (no auth - Twilio validates via signature)
Route::prefix('webhooks/twilio')->group(function () {
    Route::post('/voice-callback', [TwilioCallbackController::class, 'voiceCallback']);
    Route::post('/voice-status', [TwilioCallbackController::class, 'voiceStatus']);
});

// API para el frontend (consultar eventos)
Route::prefix('events')->group(function () {
    Route::get('/', [SamsaraEventController::class, 'index']);
    Route::get('/{id}', [SamsaraEventController::class, 'show']);
    Route::get('/{id}/stream', [SamsaraEventController::class, 'stream']);
    Route::get('/{id}/status', [SamsaraEventController::class, 'status']);
});
