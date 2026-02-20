<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SamsaraWebhookController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AlertReviewController;
use App\Http\Controllers\SafetySignalController;
use App\Http\Controllers\TwilioCallbackController;
use App\Http\Middleware\ValidateDealsToken;
use App\Http\Middleware\VerifyTwilioSignature;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Webhook de Samsara (recibe alertas)
Route::post('/webhooks/samsara', [SamsaraWebhookController::class, 'handle']);

// Twilio callbacks (signature-verified)
Route::prefix('webhooks/twilio')->middleware(VerifyTwilioSignature::class)->group(function () {
    Route::post('/voice-callback', [TwilioCallbackController::class, 'voiceCallback']);
    Route::post('/voice-status', [TwilioCallbackController::class, 'voiceStatus']);
    Route::post('/message-status', [TwilioCallbackController::class, 'messageStatus']);
    Route::post('/message-inbound', [TwilioCallbackController::class, 'messageInbound']);
});

// Analytics
Route::get('/events/analytics', [AlertController::class, 'analytics'])
    ->middleware(['web', 'auth']);

// V2: Alert-based review API (primary)
Route::prefix('alerts/{alert}')->middleware(['web', 'auth'])->group(function () {
    Route::patch('/status', [AlertReviewController::class, 'updateStatus']);
    Route::get('/review', [AlertReviewController::class, 'getReviewSummary']);
    Route::get('/comments', [AlertReviewController::class, 'getComments']);
    Route::post('/comments', [AlertReviewController::class, 'addComment']);
    Route::get('/activities', [AlertReviewController::class, 'getActivities']);
    Route::post('/ack', [AlertReviewController::class, 'acknowledge']);
    Route::post('/assign', [AlertReviewController::class, 'assign']);
    Route::post('/close-attention', [AlertReviewController::class, 'closeAttention']);
    Route::post('/reprocess', [AlertReviewController::class, 'reprocess']);
});

// Notifications API
Route::prefix('notifications')->middleware(['web', 'auth'])->group(function () {
    Route::get('/stats', [NotificationController::class, 'stats']);
});

// Safety Signals API
Route::prefix('safety-signals')->middleware(['web', 'auth'])->group(function () {
    Route::get('/analytics', [SafetySignalController::class, 'analytics']);
    Route::get('/analytics/advanced', [SafetySignalController::class, 'advancedAnalytics']);
});

// Deals API (external, pre-shared token auth)
Route::post('/deals', [DealController::class, 'store'])
    ->middleware(ValidateDealsToken::class);
