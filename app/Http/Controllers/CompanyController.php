<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CompanyController extends Controller
{
    /**
     * Show the company settings page.
     */
    public function edit(Request $request)
    {
        $user = $request->user();

        // Only admins can edit company settings
        if (!$user->isAdmin()) {
            abort(403, 'Solo los administradores pueden editar la configuración de la empresa.');
        }

        $company = Company::findOrFail($user->company_id);

        if (!$company) {
            abort(404, 'No se encontró la empresa.');
        }

        return Inertia::render('company/edit', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'legal_name' => $company->legal_name,
                'tax_id' => $company->tax_id,
                'email' => $company->email,
                'phone' => $company->phone,
                'address' => $company->address,
                'city' => $company->city,
                'state' => $company->state,
                'country' => $company->country,
                'postal_code' => $company->postal_code,
                'logo_url' => $company->logo_url,
                'is_active' => $company->is_active,
                'has_samsara_key' => $company->hasSamsaraApiKey(),
                'created_at' => $company->created_at,
            ],
        ]);
    }

    /**
     * Update the company settings.
     */
    public function update(Request $request)
    {
        $user = $request->user();

        if (!$user->isAdmin()) {
            abort(403, 'Solo los administradores pueden editar la configuración de la empresa.');
        }

        $company = $user->company;

        if (!$company) {
            abort(404, 'No se encontró la empresa.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:2'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'logo' => ['nullable', 'image', 'max:2048'], // 2MB max
        ], [
            'name.required' => 'El nombre de la empresa es requerido.',
            'email.email' => 'El correo electrónico no es válido.',
            'logo.image' => 'El logo debe ser una imagen.',
            'logo.max' => 'El logo no puede superar 2MB.',
        ]);

        // Handle logo upload
        if ($request->hasFile('logo')) {
            // Delete old logo
            if ($company->logo_path) {
                Storage::disk('public')->delete($company->logo_path);
            }

            $path = $request->file('logo')->store('company-logos', 'public');
            $validated['logo_path'] = $path;
        }

        // Update slug if name changed
        if ($validated['name'] !== $company->name) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        unset($validated['logo']);
        $company->update($validated);

        return back()->with('success', 'Configuración de empresa actualizada exitosamente.');
    }

    /**
     * Update the Samsara API key.
     */
    public function updateSamsaraKey(Request $request)
    {
        $user = $request->user();

        if (!$user->isAdmin()) {
            abort(403, 'Solo los administradores pueden actualizar la API key de Samsara.');
        }

        $company = $user->company;

        if (!$company) {
            abort(404, 'No se encontró la empresa.');
        }

        $validated = $request->validate([
            'samsara_api_key' => ['required', 'string', 'min:20'],
        ], [
            'samsara_api_key.required' => 'La API key de Samsara es requerida.',
            'samsara_api_key.min' => 'La API key debe tener al menos 20 caracteres.',
        ]);

        $company->update([
            'samsara_api_key' => $validated['samsara_api_key'],
        ]);

        return redirect()->route('company.edit')->with('success', 'API key de Samsara actualizada exitosamente.');
    }

    /**
     * Remove the Samsara API key.
     */
    public function removeSamsaraKey(Request $request)
    {
        $user = $request->user();

        if (!$user->isAdmin()) {
            abort(403, 'Solo los administradores pueden eliminar la API key de Samsara.');
        }

        $company = $user->company;

        if (!$company) {
            abort(404, 'No se encontró la empresa.');
        }

        $company->update([
            'samsara_api_key' => null,
        ]);

        return redirect()->route('company.edit')->with('success', 'API key de Samsara eliminada.');
    }
}

