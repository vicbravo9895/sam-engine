<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DriverController extends Controller
{
    /**
     * Display a listing of the drivers.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        
        $query = Driver::query();
        
        // Filter by company_id (multi-tenant isolation)
        if ($user->company_id) {
            $query->forCompany($user->company_id);
        }

        // Search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%")
                  ->orWhere('license_number', 'like', "%{$search}%");
            });
        }

        // Status filter
        if ($request->has('status') && $request->status) {
            if ($request->status === 'active') {
                $query->active();
            } elseif ($request->status === 'inactive') {
                $query->where('is_deactivated', true);
            }
        }

        // Phone filter (with/without phone)
        if ($request->has('has_phone') && $request->has_phone !== '') {
            if ($request->has_phone === '1') {
                $query->whereNotNull('phone')->where('phone', '!=', '');
            } else {
                $query->where(function ($q) {
                    $q->whereNull('phone')->orWhere('phone', '');
                });
            }
        }

        $drivers = $query->orderBy('name')->paginate(20)->through(function ($driver) {
            return [
                'id' => $driver->id,
                'samsara_id' => $driver->samsara_id,
                'name' => $driver->name,
                'phone' => $driver->phone,
                'country_code' => $driver->country_code,
                'formatted_phone' => $driver->formatted_phone,
                'formatted_whatsapp' => $driver->formatted_whatsapp,
                'username' => $driver->username,
                'license_number' => $driver->license_number,
                'license_state' => $driver->license_state,
                'driver_activation_status' => $driver->driver_activation_status,
                'is_deactivated' => $driver->is_deactivated,
                'assigned_vehicle_name' => $driver->assigned_vehicle_name,
                'profile_image_url' => $driver->profile_image_url,
                'timezone' => $driver->timezone,
                'updated_at' => $driver->updated_at?->toISOString(),
            ];
        });

        return Inertia::render('drivers/index', [
            'drivers' => $drivers,
            'filters' => $request->only(['search', 'status', 'has_phone']),
            'countryCodes' => Driver::getAvailableCountryCodes(),
        ]);
    }

    /**
     * Show the form for editing the specified driver.
     */
    public function edit(Request $request, Driver $driver): Response
    {
        $user = $request->user();
        
        // Ensure driver belongs to user's company (multi-tenant isolation)
        if ($user->company_id && $driver->company_id !== $user->company_id) {
            abort(404);
        }

        return Inertia::render('drivers/edit', [
            'driver' => [
                'id' => $driver->id,
                'samsara_id' => $driver->samsara_id,
                'name' => $driver->name,
                'phone' => $driver->phone,
                'country_code' => $driver->country_code,
                'formatted_phone' => $driver->formatted_phone,
                'formatted_whatsapp' => $driver->formatted_whatsapp,
                'username' => $driver->username,
                'license_number' => $driver->license_number,
                'license_state' => $driver->license_state,
                'driver_activation_status' => $driver->driver_activation_status,
                'is_deactivated' => $driver->is_deactivated,
                'assigned_vehicle_name' => $driver->assigned_vehicle_name,
                'profile_image_url' => $driver->profile_image_url,
                'timezone' => $driver->timezone,
                'notes' => $driver->notes,
                'updated_at' => $driver->updated_at?->toISOString(),
            ],
            'countryCodes' => Driver::getAvailableCountryCodes(),
        ]);
    }

    /**
     * Update the specified driver in storage.
     * Solo permite actualizar campos editables (phone, country_code).
     * El resto de datos vienen de Samsara.
     */
    public function update(Request $request, Driver $driver)
    {
        $user = $request->user();
        
        // Ensure driver belongs to user's company (multi-tenant isolation)
        if ($user->company_id && $driver->company_id !== $user->company_id) {
            abort(404);
        }

        $validated = $request->validate([
            'phone' => 'nullable|string|max:20',
            'country_code' => 'nullable|string|max:5',
        ]);

        // Limpiar el teléfono (solo dígitos y +)
        if (!empty($validated['phone'])) {
            $validated['phone'] = preg_replace('/[^0-9+]/', '', $validated['phone']);
        }

        // Limpiar el country_code (solo dígitos)
        if (!empty($validated['country_code'])) {
            $validated['country_code'] = preg_replace('/[^0-9]/', '', $validated['country_code']);
        }

        $driver->update($validated);

        return redirect()->route('drivers.index')
            ->with('success', "Conductor {$driver->name} actualizado exitosamente.");
    }

    /**
     * Bulk update country codes for multiple drivers.
     */
    public function bulkUpdateCountryCode(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'driver_ids' => 'required|array',
            'driver_ids.*' => 'integer|exists:drivers,id',
            'country_code' => 'required|string|max:5',
        ]);

        // Limpiar el country_code
        $countryCode = preg_replace('/[^0-9]/', '', $validated['country_code']);

        // Query con multi-tenant isolation
        $query = Driver::whereIn('id', $validated['driver_ids']);
        
        if ($user->company_id) {
            $query->forCompany($user->company_id);
        }

        $updated = $query->update(['country_code' => $countryCode]);

        return back()->with('success', "{$updated} conductores actualizados con código de país +{$countryCode}.");
    }
}
