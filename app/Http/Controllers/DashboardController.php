<?php

namespace App\Http\Controllers;

use App\Models\SamsaraEvent;
use App\Models\Vehicle;
use App\Models\Contact;
use App\Models\User;
use App\Models\Conversation;
use App\Models\ChatMessage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $isSuperAdmin = $user->isSuperAdmin();
        $today = Carbon::today();
        $lastWeek = Carbon::now()->subDays(7);
        $lastMonth = Carbon::now()->subDays(30);

        // Determinar el filtro de company_id
        $companyIdFilter = $isSuperAdmin ? null : $user->company_id;

        // ========================================
        // ESTADÍSTICAS DE EVENTOS DE SAMSARA
        // ========================================
        $samsaraEventsQuery = SamsaraEvent::query();
        if ($companyIdFilter !== null) {
            $samsaraEventsQuery->forCompany($companyIdFilter);
        }

        $samsaraStats = [
            'total' => (clone $samsaraEventsQuery)->count(),
            'today' => (clone $samsaraEventsQuery)->whereDate('created_at', $today)->count(),
            'thisWeek' => (clone $samsaraEventsQuery)->where('created_at', '>=', $lastWeek)->count(),
            'critical' => (clone $samsaraEventsQuery)->critical()->count(),
            'pending' => (clone $samsaraEventsQuery)->pending()->count(),
            'processing' => (clone $samsaraEventsQuery)->processing()->count(),
            'investigating' => (clone $samsaraEventsQuery)->investigating()->count(),
            'completed' => (clone $samsaraEventsQuery)->completed()->count(),
            'failed' => (clone $samsaraEventsQuery)->failed()->count(),
            'needsHumanAttention' => (clone $samsaraEventsQuery)->needsHumanAttention()->count(),
        ];

        // Eventos por severidad
        $eventsBySeverity = (clone $samsaraEventsQuery)
            ->select('severity', DB::raw('COUNT(*) as count'))
            ->groupBy('severity')
            ->get()
            ->pluck('count', 'severity')
            ->toArray();

        // Eventos por estado AI
        $eventsByAiStatus = (clone $samsaraEventsQuery)
            ->select('ai_status', DB::raw('COUNT(*) as count'))
            ->groupBy('ai_status')
            ->get()
            ->pluck('count', 'ai_status')
            ->toArray();

        // Actividad de eventos por día (últimos 7 días)
        $eventsActivity = (clone $samsaraEventsQuery)
            ->where('created_at', '>=', $lastWeek)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $eventsByDay = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            $dayName = Carbon::now()->subDays($i)->locale('es')->isoFormat('ddd');
            $eventsByDay[] = [
                'day' => ucfirst($dayName),
                'count' => (int) ($eventsActivity->get($date)?->count ?? 0),
            ];
        }

        // ========================================
        // ESTADÍSTICAS DE VEHÍCULOS
        // ========================================
        $vehiclesQuery = Vehicle::query();
        if ($companyIdFilter !== null) {
            $vehiclesQuery->forCompany($companyIdFilter);
        }

        $vehiclesStats = [
            'total' => (clone $vehiclesQuery)->count(),
        ];

        // ========================================
        // ESTADÍSTICAS DE CONTACTOS
        // ========================================
        $contactsQuery = Contact::query();
        if ($companyIdFilter !== null) {
            $contactsQuery->forCompany($companyIdFilter);
        }

        $contactsStats = [
            'total' => (clone $contactsQuery)->count(),
            'active' => (clone $contactsQuery)->active()->count(),
            'default' => (clone $contactsQuery)->default()->count(),
        ];

        // ========================================
        // ESTADÍSTICAS DE USUARIOS (solo si es super admin)
        // ========================================
        $usersStats = null;
        if ($isSuperAdmin) {
            $usersStats = [
                'total' => User::where('role', '!=', User::ROLE_SUPER_ADMIN)->count(),
                'active' => User::where('role', '!=', User::ROLE_SUPER_ADMIN)->where('is_active', true)->count(),
                'admins' => User::where('role', User::ROLE_ADMIN)->count(),
            ];
        } else {
            // Para usuarios normales, mostrar usuarios de su compañía
            $usersQuery = User::query()->forCompany($user->company_id);
            $usersStats = [
                'total' => (clone $usersQuery)->count(),
                'active' => (clone $usersQuery)->active()->count(),
            ];
        }

        // ========================================
        // EVENTOS RECIENTES Y CRÍTICOS
        // ========================================
        $recentEvents = (clone $samsaraEventsQuery)
            ->orderBy('occurred_at', 'desc')
            ->limit(10)
            ->get(['id', 'event_type', 'event_description', 'vehicle_name', 'driver_name', 'severity', 'ai_status', 'occurred_at', 'risk_escalation']);

        $criticalEvents = (clone $samsaraEventsQuery)
            ->critical()
            ->orderBy('occurred_at', 'desc')
            ->limit(5)
            ->get(['id', 'event_type', 'event_description', 'vehicle_name', 'driver_name', 'ai_status', 'occurred_at', 'risk_escalation']);

        $eventsNeedingAttention = (clone $samsaraEventsQuery)
            ->needsHumanAttention()
            ->orderBy('occurred_at', 'desc')
            ->limit(5)
            ->get(['id', 'event_type', 'event_description', 'vehicle_name', 'driver_name', 'severity', 'ai_status', 'occurred_at', 'risk_escalation']);

        // ========================================
        // EVENTOS POR TIPO (últimos 30 días)
        // ========================================
        $eventsByType = (clone $samsaraEventsQuery)
            ->where('created_at', '>=', $lastMonth)
            ->select('event_type', DB::raw('COUNT(*) as count'))
            ->groupBy('event_type')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'type' => $item->event_type,
                    'count' => $item->count,
                ];
            });

        // ========================================
        // ESTADÍSTICAS DE CONVERSACIONES
        // ========================================
        $conversationsQuery = Conversation::query();
        if ($companyIdFilter !== null) {
            $conversationsQuery->forCompany($companyIdFilter);
        }

        $conversationsStats = [
            'total' => (clone $conversationsQuery)->count(),
            'today' => (clone $conversationsQuery)->whereDate('created_at', $today)->count(),
            'thisWeek' => (clone $conversationsQuery)->where('created_at', '>=', $lastWeek)->count(),
        ];

        // Conversaciones recientes
        $recentConversations = (clone $conversationsQuery)
            ->with('user:id,name')
            ->orderBy('updated_at', 'desc')
            ->limit(5)
            ->get(['id', 'thread_id', 'title', 'user_id', 'updated_at', 'created_at'])
            ->map(function ($conv) {
                $messageCount = ChatMessage::where('thread_id', $conv->thread_id)->count();
                $lastMessage = ChatMessage::where('thread_id', $conv->thread_id)
                    ->orderBy('created_at', 'desc')
                    ->first();
                
                return [
                    'id' => $conv->id,
                    'thread_id' => $conv->thread_id,
                    'title' => $conv->title,
                    'user_name' => $conv->user?->name ?? 'Usuario',
                    'message_count' => $messageCount,
                    'last_message_preview' => $lastMessage 
                        ? (is_array($lastMessage->content) 
                            ? \Illuminate\Support\Str::limit($lastMessage->content['text'] ?? '', 80)
                            : \Illuminate\Support\Str::limit($lastMessage->content ?? '', 80))
                        : null,
                    'updated_at' => $conv->updated_at->diffForHumans(),
                ];
            });

        return Inertia::render('dashboard', [
            'isSuperAdmin' => $isSuperAdmin,
            'companyName' => $user->company?->name ?? null,
            'samsaraStats' => $samsaraStats,
            'vehiclesStats' => $vehiclesStats,
            'contactsStats' => $contactsStats,
            'usersStats' => $usersStats,
            'conversationsStats' => $conversationsStats,
            'eventsBySeverity' => $eventsBySeverity,
            'eventsByAiStatus' => $eventsByAiStatus,
            'eventsByDay' => $eventsByDay,
            'eventsByType' => $eventsByType,
            'recentEvents' => $recentEvents,
            'criticalEvents' => $criticalEvents,
            'eventsNeedingAttention' => $eventsNeedingAttention,
            'recentConversations' => $recentConversations,
        ]);
    }
}

