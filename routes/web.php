<?php

use App\Http\Controllers\ContactController;
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

    // Contacts Management
    Route::resource('contacts', ContactController::class);
    Route::post('contacts/{contact}/toggle-active', [ContactController::class, 'toggleActive'])->name('contacts.toggle-active');
    Route::post('contacts/{contact}/set-default', [ContactController::class, 'setDefault'])->name('contacts.set-default');
});

require __DIR__.'/settings.php';
