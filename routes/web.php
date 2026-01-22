<?php

use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CopilotController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\FleetReportController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\SafetySignalController;
use App\Http\Controllers\SamsaraEventController;
use App\Http\Controllers\StorageController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SuperAdmin\DashboardController as SuperAdminDashboardController;
use App\Http\Controllers\SuperAdmin\CompanyController as SuperAdminCompanyController;
use App\Http\Controllers\SuperAdmin\UserController as SuperAdminUserController;
use App\Http\Middleware\EnsureSuperAdmin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

// Redirigir raíz: si autenticado → dashboard, si no → login
Route::get('/', function () {
    if (Auth::check()) {
        return redirect()->route('dashboard');
    }
    return redirect()->route('login');
})->name('home');

// Public storage route for dashcam media (no auth required, includes CORS headers)
Route::get('storage/{path}', [StorageController::class, 'serve'])
    ->where('path', '.*')
    ->name('storage.serve');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    
    // Copilot routes
    Route::get('copilot', [CopilotController::class, 'index'])->name('copilot.index');
    Route::post('copilot/send', [CopilotController::class, 'send'])->name('copilot.send');
    // SSE endpoints para streaming en tiempo real (ANTES de la ruta con wildcard)
    Route::get('copilot/sse/stream/{threadId}', [CopilotController::class, 'stream'])->name('copilot.sse.stream');
    Route::get('copilot/sse/resume/{threadId}', [CopilotController::class, 'resume'])->name('copilot.sse.resume');
    // Fallback polling endpoint
    Route::get('copilot/stream/{threadId}', [CopilotController::class, 'streamProgress'])->name('copilot.stream.progress');
    // Esta ruta debe ir AL FINAL porque {threadId} es un wildcard
    Route::get('copilot/{threadId}', [CopilotController::class, 'show'])->name('copilot.show');
    Route::delete('copilot/{threadId}', [CopilotController::class, 'destroy'])->name('copilot.destroy');

    // Samsara alerts routes
    Route::prefix('samsara/alerts')->name('samsara.alerts.')->group(function () {
        Route::get('/', [SamsaraEventController::class, 'index'])->name('index');
        Route::get('/{samsaraEvent}', [SamsaraEventController::class, 'show'])->name('show');
    });

    // Safety Signals routes
    Route::prefix('safety-signals')->name('safety-signals.')->group(function () {
        Route::get('/', [SafetySignalController::class, 'index'])->name('index');
        Route::get('/{safetySignal}', [SafetySignalController::class, 'show'])->name('show');
    });

    // Incidents routes
    Route::prefix('incidents')->name('incidents.')->group(function () {
        Route::get('/', [IncidentController::class, 'index'])->name('index');
        Route::get('/{incident}', [IncidentController::class, 'show'])->name('show');
        Route::patch('/{incident}/status', [IncidentController::class, 'updateStatus'])->name('update-status');
    });

    // Fleet Report
    Route::get('fleet-report', [FleetReportController::class, 'index'])->name('fleet-report.index');

    // Contacts Management
    Route::resource('contacts', ContactController::class);
    Route::post('contacts/{contact}/toggle-active', [ContactController::class, 'toggleActive'])->name('contacts.toggle-active');
    Route::post('contacts/{contact}/set-default', [ContactController::class, 'setDefault'])->name('contacts.set-default');

    // Drivers Management (phone numbers and country codes)
    Route::get('drivers', [DriverController::class, 'index'])->name('drivers.index');
    Route::get('drivers/{driver}/edit', [DriverController::class, 'edit'])->name('drivers.edit');
    Route::put('drivers/{driver}', [DriverController::class, 'update'])->name('drivers.update');
    Route::post('drivers/bulk-country-code', [DriverController::class, 'bulkUpdateCountryCode'])->name('drivers.bulk-country-code');

    // User management routes (admin/manager only)
    Route::resource('users', UserController::class)->except(['show']);

    // Company settings routes (admin only)
    Route::get('company', [CompanyController::class, 'edit'])->name('company.edit');
    Route::put('company', [CompanyController::class, 'update'])->name('company.update');
    Route::put('company/samsara-key', [CompanyController::class, 'updateSamsaraKey'])->name('company.samsara-key.update');
    Route::delete('company/samsara-key', [CompanyController::class, 'removeSamsaraKey'])->name('company.samsara-key.destroy');
    
    // AI Configuration routes (admin only)
    Route::get('company/ai-settings', [CompanyController::class, 'editAiSettings'])->name('company.ai-settings.edit');
    Route::put('company/ai-settings', [CompanyController::class, 'updateAiSettings'])->name('company.ai-settings.update');
    Route::post('company/ai-settings/reset', [CompanyController::class, 'resetAiSettings'])->name('company.ai-settings.reset');
});

// Super Admin routes
Route::middleware(['auth', 'verified', EnsureSuperAdmin::class])
    ->prefix('super-admin')
    ->name('super-admin.')
    ->group(function () {
        // Dashboard
        Route::get('/', [SuperAdminDashboardController::class, 'index'])->name('dashboard');
        
        // Companies management
        Route::resource('companies', SuperAdminCompanyController::class);
        Route::put('companies/{company}/samsara-key', [SuperAdminCompanyController::class, 'updateSamsaraKey'])
            ->name('companies.samsara-key.update');
        Route::delete('companies/{company}/samsara-key', [SuperAdminCompanyController::class, 'removeSamsaraKey'])
            ->name('companies.samsara-key.destroy');
        Route::post('companies/{company}/toggle-status', [SuperAdminCompanyController::class, 'toggleStatus'])
            ->name('companies.toggle-status');
        
        // Users management
        Route::resource('users', SuperAdminUserController::class);
        Route::post('users/{user}/toggle-status', [SuperAdminUserController::class, 'toggleStatus'])
            ->name('users.toggle-status');
    });

require __DIR__.'/settings.php';
