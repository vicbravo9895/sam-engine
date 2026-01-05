<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * Display the super admin dashboard.
     */
    public function index()
    {
        $stats = [
            'companies' => [
                'total' => Company::count(),
                'active' => Company::where('is_active', true)->count(),
                'with_samsara' => Company::whereNotNull('samsara_api_key')->count(),
            ],
            'users' => [
                'total' => User::where('role', '!=', User::ROLE_SUPER_ADMIN)->count(),
                'active' => User::where('role', '!=', User::ROLE_SUPER_ADMIN)->where('is_active', true)->count(),
                'admins' => User::where('role', User::ROLE_ADMIN)->count(),
            ],
            'vehicles' => [
                'total' => Vehicle::count(),
            ],
            'conversations' => [
                'total' => Conversation::count(),
                'today' => Conversation::whereDate('created_at', today())->count(),
            ],
        ];

        $recentCompanies = Company::withCount(['users', 'vehicles'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get(['id', 'name', 'is_active', 'created_at']);

        $recentUsers = User::with('company:id,name')
            ->where('role', '!=', User::ROLE_SUPER_ADMIN)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get(['id', 'name', 'email', 'role', 'company_id', 'created_at']);

        return Inertia::render('super-admin/dashboard', [
            'stats' => $stats,
            'recentCompanies' => $recentCompanies,
            'recentUsers' => $recentUsers,
        ]);
    }
}

