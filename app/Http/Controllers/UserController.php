<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DomainEventEmitter;
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

        $this->authorize('viewAny', User::class);

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
        $this->authorize('create', User::class);

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
        $this->authorize('create', User::class);

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
            'email.required' => 'El correo electr?nico es requerido.',
            'email.email' => 'El correo electr?nico no es v?lido.',
            'email.unique' => 'Este correo electr?nico ya est? registrado.',
            'password.required' => 'La contrase?a es requerida.',
            'role.required' => 'El rol es requerido.',
            'role.in' => 'El rol seleccionado no es v?lido.',
        ]);

        $user = User::create([
            'company_id' => $currentUser->company_id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        DomainEventEmitter::emit(
            companyId: $currentUser->company_id,
            entityType: 'user',
            entityId: (string) $user->id,
            eventType: 'user.created',
            payload: [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            actorType: 'user',
            actorId: (string) $currentUser->id,
        );

        return redirect()->route('users.index')
            ->with('success', "Usuario {$user->name} creado exitosamente.");
    }

    /**
     * Show the form for editing a user.
     */
    public function edit(Request $request, User $user)
    {
        $currentUser = $request->user();
        $this->authorize('update', $user);

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
        $this->authorize('update', $user);

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
            'email.required' => 'El correo electr?nico es requerido.',
            'email.email' => 'El correo electr?nico no es v?lido.',
            'email.unique' => 'Este correo electr?nico ya est? registrado.',
        ]);

        $updateData = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'is_active' => $validated['is_active'] ?? $user->is_active,
        ];

        $previousRole = $user->role;

        if (isset($validated['role'])) {
            $updateData['role'] = $validated['role'];
        }

        if (isset($validated['password'])) {
            $updateData['password'] = Hash::make($validated['password']);
        }

        $user->update($updateData);

        DomainEventEmitter::emit(
            companyId: $user->company_id,
            entityType: 'user',
            entityId: (string) $user->id,
            eventType: 'user.updated',
            payload: array_diff_key($updateData, ['password' => true]),
            actorType: 'user',
            actorId: (string) $currentUser->id,
        );

        if (isset($validated['role']) && $previousRole !== $validated['role']) {
            DomainEventEmitter::emit(
                companyId: $user->company_id,
                entityType: 'user',
                entityId: (string) $user->id,
                eventType: 'user.role_changed',
                payload: [
                    'previous_role' => $previousRole,
                    'new_role' => $validated['role'],
                ],
                actorType: 'user',
                actorId: (string) $currentUser->id,
            );
        }

        return redirect()->route('users.index')
            ->with('success', "Usuario {$user->name} actualizado exitosamente.");
    }

    /**
     * Remove the specified user.
     */
    public function destroy(Request $request, User $user)
    {
        $currentUser = $request->user();
        $this->authorize('delete', $user);

        $userName = $user->name;
        $userEmail = $user->email;
        $userCompanyId = $user->company_id;
        $userId = $user->id;
        $user->delete();

        DomainEventEmitter::emit(
            companyId: $userCompanyId,
            entityType: 'user',
            entityId: (string) $userId,
            eventType: 'user.deleted',
            payload: [
                'name' => $userName,
                'email' => $userEmail,
            ],
            actorType: 'user',
            actorId: (string) $currentUser->id,
        );

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

