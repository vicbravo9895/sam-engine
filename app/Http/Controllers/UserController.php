<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class UserController extends Controller
{
    /**
     * Display a listing of users.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Only admins and managers can access
        if (!$user->canManageUsers()) {
            abort(403, 'No tienes permisos para acceder a esta sección.');
        }

        $query = User::forCompany($user->company_id)
            ->with('company:id,name')
            ->orderBy('name');

        // Search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Role filter
        if ($request->has('role') && $request->role) {
            $query->where('role', $request->role);
        }

        // Status filter
        if ($request->has('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        $users = $query->paginate(15)->withQueryString();

        return Inertia::render('users/index', [
            'users' => $users,
            'filters' => $request->only(['search', 'role', 'status']),
            'roles' => [
                User::ROLE_ADMIN => 'Administrador',
                User::ROLE_MANAGER => 'Gerente',
                User::ROLE_USER => 'Usuario',
            ],
        ]);
    }

    /**
     * Show the form for creating a new user.
     */
    public function create(Request $request)
    {
        $user = $request->user();

        if (!$user->canManageUsers()) {
            abort(403, 'No tienes permisos para crear usuarios.');
        }

        return Inertia::render('users/create', [
            'roles' => $this->getAvailableRoles($user),
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request)
    {
        $currentUser = $request->user();

        if (!$currentUser->canManageUsers()) {
            abort(403, 'No tienes permisos para crear usuarios.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users'),
            ],
            'password' => ['required', Password::defaults()],
            'role' => ['required', 'string', Rule::in($this->getAllowedRoles($currentUser))],
            'is_active' => ['boolean'],
        ], [
            'name.required' => 'El nombre es requerido.',
            'email.required' => 'El correo electrónico es requerido.',
            'email.email' => 'El correo electrónico no es válido.',
            'email.unique' => 'Este correo electrónico ya está registrado.',
            'password.required' => 'La contraseña es requerida.',
            'role.required' => 'El rol es requerido.',
            'role.in' => 'El rol seleccionado no es válido.',
        ]);

        $user = User::create([
            'company_id' => $currentUser->company_id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return redirect()->route('users.index')
            ->with('success', "Usuario {$user->name} creado exitosamente.");
    }

    /**
     * Show the form for editing a user.
     */
    public function edit(Request $request, User $user)
    {
        $currentUser = $request->user();

        if (!$currentUser->canManageUsers()) {
            abort(403, 'No tienes permisos para editar usuarios.');
        }

        // Ensure user belongs to same company
        if ($user->company_id !== $currentUser->company_id) {
            abort(404);
        }

        return Inertia::render('users/edit', [
            'user' => $user->only(['id', 'name', 'email', 'role', 'is_active', 'created_at']),
            'roles' => $this->getAvailableRoles($currentUser),
            'canChangeRole' => $this->canChangeUserRole($currentUser, $user),
        ]);
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, User $user)
    {
        $currentUser = $request->user();

        if (!$currentUser->canManageUsers()) {
            abort(403, 'No tienes permisos para editar usuarios.');
        }

        // Ensure user belongs to same company
        if ($user->company_id !== $currentUser->company_id) {
            abort(404);
        }

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
            'is_active' => ['boolean'],
        ];

        // Only allow role change if permitted
        if ($this->canChangeUserRole($currentUser, $user)) {
            $rules['role'] = ['required', 'string', Rule::in($this->getAllowedRoles($currentUser))];
        }

        // Password is optional on update
        if ($request->filled('password')) {
            $rules['password'] = [Password::defaults()];
        }

        $validated = $request->validate($rules, [
            'name.required' => 'El nombre es requerido.',
            'email.required' => 'El correo electrónico es requerido.',
            'email.email' => 'El correo electrónico no es válido.',
            'email.unique' => 'Este correo electrónico ya está registrado.',
        ]);

        $updateData = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'is_active' => $validated['is_active'] ?? $user->is_active,
        ];

        if (isset($validated['role'])) {
            $updateData['role'] = $validated['role'];
        }

        if (isset($validated['password'])) {
            $updateData['password'] = Hash::make($validated['password']);
        }

        $user->update($updateData);

        return redirect()->route('users.index')
            ->with('success', "Usuario {$user->name} actualizado exitosamente.");
    }

    /**
     * Remove the specified user.
     */
    public function destroy(Request $request, User $user)
    {
        $currentUser = $request->user();

        if (!$currentUser->canManageUsers()) {
            abort(403, 'No tienes permisos para eliminar usuarios.');
        }

        // Ensure user belongs to same company
        if ($user->company_id !== $currentUser->company_id) {
            abort(404);
        }

        // Cannot delete yourself
        if ($user->id === $currentUser->id) {
            return back()->with('error', 'No puedes eliminar tu propia cuenta.');
        }

        // Cannot delete admins if you're a manager
        if ($user->isAdmin() && !$currentUser->isAdmin()) {
            return back()->with('error', 'No tienes permisos para eliminar administradores.');
        }

        $userName = $user->name;
        $user->delete();

        return redirect()->route('users.index')
            ->with('success', "Usuario {$userName} eliminado exitosamente.");
    }

    /**
     * Get available roles based on current user's permissions.
     */
    private function getAvailableRoles(User $currentUser): array
    {
        $roles = [];

        if ($currentUser->isAdmin()) {
            $roles[User::ROLE_ADMIN] = 'Administrador';
        }

        $roles[User::ROLE_MANAGER] = 'Gerente';
        $roles[User::ROLE_USER] = 'Usuario';

        return $roles;
    }

    /**
     * Get allowed role values for validation.
     */
    private function getAllowedRoles(User $currentUser): array
    {
        $roles = [User::ROLE_MANAGER, User::ROLE_USER];

        if ($currentUser->isAdmin()) {
            $roles[] = User::ROLE_ADMIN;
        }

        return $roles;
    }

    /**
     * Check if current user can change target user's role.
     */
    private function canChangeUserRole(User $currentUser, User $targetUser): bool
    {
        // Cannot change own role
        if ($currentUser->id === $targetUser->id) {
            return false;
        }

        // Admins can change anyone's role
        if ($currentUser->isAdmin()) {
            return true;
        }

        // Managers cannot change admin roles
        if ($targetUser->isAdmin()) {
            return false;
        }

        return true;
    }
}

