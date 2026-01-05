<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class CompanyController extends Controller
{
    /**
     * Display a listing of companies.
     */
    public function index(Request $request)
    {
        $query = Company::query()
            ->withCount('users')
            ->withCount('vehicles');

        // Search filter
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('legal_name', 'like', "%{$search}%");
            });
        }

        // Status filter
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $companies = $query->orderBy('name')->paginate(15)->withQueryString();

        return Inertia::render('super-admin/companies/index', [
            'companies' => $companies,
            'filters' => $request->only(['search', 'is_active']),
        ]);
    }

    /**
     * Show the form for creating a new company.
     */
    public function create()
    {
        return Inertia::render('super-admin/companies/create');
    }

    /**
     * Store a newly created company.
     */
    public function store(Request $request)
    {
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
            'samsara_api_key' => ['nullable', 'string', 'min:20'],
            'is_active' => ['boolean'],
            // Admin user fields
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'admin_password' => ['required', Password::defaults()],
        ], [
            'name.required' => 'El nombre de la empresa es requerido.',
            'admin_name.required' => 'El nombre del administrador es requerido.',
            'admin_email.required' => 'El correo del administrador es requerido.',
            'admin_email.unique' => 'Este correo ya está registrado.',
            'admin_password.required' => 'La contraseña del administrador es requerida.',
        ]);

        // Create company
        $company = Company::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'legal_name' => $validated['legal_name'] ?? null,
            'tax_id' => $validated['tax_id'] ?? null,
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'city' => $validated['city'] ?? null,
            'state' => $validated['state'] ?? null,
            'country' => $validated['country'] ?? 'MX',
            'postal_code' => $validated['postal_code'] ?? null,
            'samsara_api_key' => $validated['samsara_api_key'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        // Create admin user for the company
        User::create([
            'company_id' => $company->id,
            'name' => $validated['admin_name'],
            'email' => $validated['admin_email'],
            'password' => Hash::make($validated['admin_password']),
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        return redirect()->route('super-admin.companies.index')
            ->with('success', "Empresa '{$company->name}' creada exitosamente.");
    }

    /**
     * Show the form for editing a company.
     */
    public function edit(Company $company)
    {
        $company->loadCount(['users', 'vehicles']);
        
        return Inertia::render('super-admin/companies/edit', [
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
                'users_count' => $company->users_count,
                'vehicles_count' => $company->vehicles_count,
                'created_at' => $company->created_at,
            ],
            'users' => $company->users()
                ->select(['id', 'name', 'email', 'role', 'is_active', 'created_at'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * Update the specified company.
     */
    public function update(Request $request, Company $company)
    {
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
            'is_active' => ['boolean'],
        ], [
            'name.required' => 'El nombre de la empresa es requerido.',
        ]);

        // Update slug if name changed
        if ($validated['name'] !== $company->name) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $company->update($validated);

        return back()->with('success', 'Empresa actualizada exitosamente.');
    }

    /**
     * Update the Samsara API key for a company.
     */
    public function updateSamsaraKey(Request $request, Company $company)
    {
        $validated = $request->validate([
            'samsara_api_key' => ['required', 'string', 'min:20'],
        ], [
            'samsara_api_key.required' => 'La API key de Samsara es requerida.',
            'samsara_api_key.min' => 'La API key debe tener al menos 20 caracteres.',
        ]);

        $company->update([
            'samsara_api_key' => $validated['samsara_api_key'],
        ]);

        return back()->with('success', 'API key de Samsara actualizada exitosamente.');
    }

    /**
     * Remove the Samsara API key from a company.
     */
    public function removeSamsaraKey(Company $company)
    {
        $company->update([
            'samsara_api_key' => null,
        ]);

        return back()->with('success', 'API key de Samsara eliminada.');
    }

    /**
     * Toggle company active status.
     */
    public function toggleStatus(Company $company)
    {
        $company->update([
            'is_active' => !$company->is_active,
        ]);

        $status = $company->is_active ? 'activada' : 'desactivada';
        return back()->with('success', "Empresa {$status} exitosamente.");
    }

    /**
     * Remove the specified company.
     */
    public function destroy(Company $company)
    {
        $companyName = $company->name;
        
        // Soft delete the company
        $company->delete();

        return redirect()->route('super-admin.companies.index')
            ->with('success', "Empresa '{$companyName}' eliminada exitosamente.");
    }
}

