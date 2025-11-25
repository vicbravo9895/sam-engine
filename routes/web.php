<?php

use App\Http\Controllers\SamsaraEventController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::prefix('samsara/alerts')->name('samsara.alerts.')->group(function () {
        Route::get('/', [SamsaraEventController::class, 'index'])->name('index');
        Route::get('/{samsaraEvent}', [SamsaraEventController::class, 'show'])->name('show');
    });
});

require __DIR__.'/settings.php';
