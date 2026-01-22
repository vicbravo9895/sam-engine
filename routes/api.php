<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SamsaraWebhookController;
use App\Http\Controllers\SamsaraEventController;
use App\Http\Controllers\SamsaraEventReviewController;
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

// API para revisión humana (requiere autenticación de sesión web)
Route::prefix('events/{event}')->middleware(['web', 'auth'])->group(function () {
    // Human review
    Route::patch('/status', [SamsaraEventReviewController::class, 'updateStatus']);
    Route::get('/review', [SamsaraEventReviewController::class, 'getReviewSummary']);
    
    // Comentarios
    Route::get('/comments', [SamsaraEventReviewController::class, 'getComments']);
    Route::post('/comments', [SamsaraEventReviewController::class, 'addComment']);
    
    // Timeline de actividades
    Route::get('/activities', [SamsaraEventReviewController::class, 'getActivities']);
    
    // Reprocesar alerta (solo super_admin)
    Route::post('/reprocess', [SamsaraEventReviewController::class, 'reprocess']);
});
