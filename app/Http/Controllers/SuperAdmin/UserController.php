<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class UserController extends Controller
{
    /**
     * Display a listing of all users.
     */
    public function index(Request $request)
    {
        $query = User::query()
            ->with('company:id,name')
            ->where('role', '!=', User::ROLE_SUPER_ADMIN); // Don't show super admins in this list

        // Search filter
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Company filter
        if ($companyId = $request->input('company_id')) {
            $query->where('company_id', $companyId);
        }

        // Role filter
        if ($role = $request->input('role')) {
            $query->where('role', $role);
        }

        // Status filter
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $users = $query->orderBy('name')->paginate(15)->withQueryString();
        
        $companies = Company::orderBy('name')->get(['id', 'name']);

        return Inertia::render('super-admin/users/index', [
            'users' => $users,
            'companies' => $companies,
            'filters' => $request->only(['search', 'company_id', 'role', 'is_active']),
            'roles' => [
                User::ROLE_ADMIN => 'Administrador',
                User::ROLE_MANAGER => 'Manager',
                User::ROLE_USER => 'Usuario',
            ],
        ]);
    }

    /**
     * Show the form for creating a new user.
     */
    public function create(Request $request)
    {
        $companies = Company::orderBy('name')->get(['id', 'name']);

        return Inertia::render('super-admin/users/create', [
            'companies' => $companies,
            'selectedCompanyId' => $request->input('company_id'),
            'roles' => [
                User::ROLE_ADMIN => 'Administrador',
                User::ROLE_MANAGER => 'Manager',
                User::ROLE_USER => 'Usuario',
            ],
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_USER])],
            'is_active' => ['boolean'],
        ], [
            'company_id.required' => 'Debes seleccionar una empresa.',
            'company_id.exists' => 'La empresa seleccionada no existe.',
            'name.required' => 'El nombre es requerido.',
            'email.required' => 'El correo electrónico es requerido.',
            'email.unique' => 'Este correo ya está registrado.',
            'password.required' => 'La contraseña es requerida.',
            'role.required' => 'El rol es requerido.',
        ]);

        User::create([
            'company_id' => $validated['company_id'],
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'is_active' => $validated['is_active'] ?? true,
            'email_verified_at' => now(),
        ]);

        return redirect()->route('super-admin.users.index')
            ->with('success', 'Usuario creado exitosamente.');
    }

    /**
     * Show the form for editing a user.
     */
    public function edit(User $user)
    {
        // Don't allow editing super admins through this interface
        if ($user->isSuperAdmin()) {
            abort(403, 'No puedes editar usuarios super admin desde aquí.');
        }

        $companies = Company::orderBy('name')->get(['id', 'name']);

        return Inertia::render('super-admin/users/edit', [
            'user' => [
                'id' => $user->id,
                'company_id' => $user->company_id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_active' => $user->is_active,
                'created_at' => $user->created_at,
                'company' => $user->company ? [
                    'id' => $user->company->id,
                    'name' => $user->company->name,
                ] : null,
            ],
            'companies' => $companies,
            'roles' => [
                User::ROLE_ADMIN => 'Administrador',
                User::ROLE_MANAGER => 'Manager',
                User::ROLE_USER => 'Usuario',
            ],
        ]);
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, User $user)
    {
        if ($user->isSuperAdmin()) {
            abort(403, 'No puedes editar usuarios super admin desde aquí.');
        }

        $validated = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => ['nullable', Password::defaults()],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_USER])],
            'is_active' => ['boolean'],
        ], [
            'company_id.required' => 'Debes seleccionar una empresa.',
            'name.required' => 'El nombre es requerido.',
            'email.required' => 'El correo electrónico es requerido.',
            'email.unique' => 'Este correo ya está registrado.',
            'role.required' => 'El rol es requerido.',
        ]);

        $user->update([
            'company_id' => $validated['company_id'],
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        if (!empty($validated['password'])) {
            $user->update([
                'password' => Hash::make($validated['password']),
            ]);
        }

        return back()->with('success', 'Usuario actualizado exitosamente.');
    }

    /**
     * Toggle user active status.
     */
    public function toggleStatus(User $user)
    {
        if ($user->isSuperAdmin()) {
            abort(403, 'No puedes modificar usuarios super admin.');
        }

        $user->update([
            'is_active' => !$user->is_active,
        ]);

        $status = $user->is_active ? 'activado' : 'desactivado';
        return back()->with('success', "Usuario {$status} exitosamente.");
    }

    /**
     * Remove the specified user.
     */
    public function destroy(User $user)
    {
        if ($user->isSuperAdmin()) {
            abort(403, 'No puedes eliminar usuarios super admin.');
        }

        $userName = $user->name;
        $user->delete();

        return redirect()->route('super-admin.users.index')
            ->with('success', "Usuario '{$userName}' eliminado exitosamente.");
    }
}

