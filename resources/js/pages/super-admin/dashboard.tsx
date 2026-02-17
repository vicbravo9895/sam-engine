import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    Building2,
    Users,
    Truck,
    MessageSquare,
    ChevronRight,
    Key,
    UserCheck,
    Activity,
    Gauge,
    Bot,
    Eye,
    AlertTriangle,
    Clock,
} from 'lucide-react';

interface Stats {
    companies: {
        total: number;
        active: number;
        with_samsara: number;
    };
    users: {
        total: number;
        active: number;
        admins: number;
    };
    vehicles: {
        total: number;
    };
    conversations: {
        total: number;
        today: number;
    };
}

interface CompanyData {
    id: number;
    name: string;
    is_active: boolean;
    created_at: string;
    users_count: number;
    vehicles_count: number;
}

interface UserData {
    id: number;
    name: string;
    email: string;
    role: string;
    company_id: number;
    created_at: string;
    company?: {
        id: number;
        name: string;
    };
}

interface AdoptionMetrics {
    pipeline: {
        p50_latency_ms: number | null;
        p95_latency_ms: number | null;
        events_today: number;
        failed_last_7_days: number;
        pending_webhooks: number;
    };
    human_review: {
        total_completed_30d: number;
        human_reviewed_30d: number;
        review_rate_pct: number | null;
        human_override_30d: number;
        override_rate_pct: number | null;
    };
    copilot: {
        sessions_this_month: number;
        messages_this_month: number;
        active_users_this_month: number;
        avg_messages_per_session: number | null;
    };
    onboarding: {
        avg_days_to_first_alert: number | null;
        companies_with_alerts: number;
    };
}

interface Props {
    stats: Stats;
    recentCompanies: CompanyData[];
    recentUsers: UserData[];
    adoptionMetrics: AdoptionMetrics;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Super Admin', href: '/super-admin' },
];

const roleLabels: Record<string, string> = {
    admin: 'Admin',
    manager: 'Manager',
    user: 'Usuario',
};

export default function SuperAdminDashboard() {
    const { stats, recentCompanies, recentUsers, adoptionMetrics } = usePage<{ props: Props }>().props as unknown as Props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Super Admin" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
                {/* Header */}
                <div>
                    <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">
                        Panel de Super Administrador
                    </h1>
                    <p className="text-muted-foreground">
                        Gestiona empresas, usuarios y configuración del sistema
                    </p>
                </div>

                {/* Stats Grid */}
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">Empresas</CardTitle>
                            <Building2 className="text-muted-foreground size-5" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold">{stats.companies.total}</div>
                            <div className="text-muted-foreground mt-1 flex items-center gap-2 text-xs">
                                <span className="text-success">{stats.companies.active} activas</span>
                                <span>•</span>
                                <span className="flex items-center gap-1">
                                    <Key className="size-3" />
                                    {stats.companies.with_samsara} con Samsara
                                </span>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">Usuarios</CardTitle>
                            <Users className="text-muted-foreground size-5" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold">{stats.users.total}</div>
                            <div className="text-muted-foreground mt-1 flex items-center gap-2 text-xs">
                                <span className="flex items-center gap-1 text-success">
                                    <UserCheck className="size-3" />
                                    {stats.users.active} activos
                                </span>
                                <span>•</span>
                                <span>{stats.users.admins} admins</span>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">Vehículos</CardTitle>
                            <Truck className="text-muted-foreground size-5" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold">{stats.vehicles.total}</div>
                            <p className="text-muted-foreground mt-1 text-xs">
                                Total sincronizados
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">Conversaciones</CardTitle>
                            <MessageSquare className="text-muted-foreground size-5" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold">{stats.conversations.total}</div>
                            <div className="text-muted-foreground mt-1 flex items-center gap-2 text-xs">
                                <span className="flex items-center gap-1 text-info">
                                    <Activity className="size-3" />
                                    {stats.conversations.today} hoy
                                </span>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Quick Actions */}
                <div className="grid gap-4 md:grid-cols-2">
                    <Link href="/super-admin/companies/create">
                        <Card className="hover:bg-muted/50 cursor-pointer transition-colors">
                            <CardContent className="flex items-center gap-4 p-6">
                                <div className="flex size-12 items-center justify-center rounded-full bg-success/10">
                                    <Building2 className="size-6 text-success" />
                                </div>
                                <div className="flex-1">
                                    <h3 className="font-semibold">Nueva Empresa</h3>
                                    <p className="text-muted-foreground text-sm">
                                        Crear una nueva empresa con su administrador
                                    </p>
                                </div>
                                <ChevronRight className="text-muted-foreground size-5" />
                            </CardContent>
                        </Card>
                    </Link>

                    <Link href="/super-admin/users/create">
                        <Card className="hover:bg-muted/50 cursor-pointer transition-colors">
                            <CardContent className="flex items-center gap-4 p-6">
                                <div className="flex size-12 items-center justify-center rounded-full bg-info/10">
                                    <Users className="size-6 text-info" />
                                </div>
                                <div className="flex-1">
                                    <h3 className="font-semibold">Nuevo Usuario</h3>
                                    <p className="text-muted-foreground text-sm">
                                        Agregar un usuario a cualquier empresa
                                    </p>
                                </div>
                                <ChevronRight className="text-muted-foreground size-5" />
                            </CardContent>
                        </Card>
                    </Link>
                </div>

                {/* Recent Activity */}
                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Recent Companies */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <div>
                                <CardTitle>Empresas Recientes</CardTitle>
                                <CardDescription>Últimas empresas creadas</CardDescription>
                            </div>
                            <Link
                                href="/super-admin/companies"
                                className="text-primary text-sm hover:underline"
                            >
                                Ver todas
                            </Link>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-4">
                                {recentCompanies.length === 0 ? (
                                    <p className="text-muted-foreground py-4 text-center text-sm">
                                        No hay empresas registradas
                                    </p>
                                ) : (
                                    recentCompanies.map((company) => (
                                        <Link
                                            key={company.id}
                                            href={`/super-admin/companies/${company.id}/edit`}
                                            className="hover:bg-muted/50 flex items-center justify-between rounded-lg p-2 transition-colors"
                                        >
                                            <div className="flex items-center gap-3">
                                                <div className="bg-primary/10 flex size-10 items-center justify-center rounded-full">
                                                    <Building2 className="text-primary size-5" />
                                                </div>
                                                <div>
                                                    <p className="font-medium">{company.name}</p>
                                                    <p className="text-muted-foreground text-xs">
                                                        {company.users_count} usuarios • {company.vehicles_count} vehículos
                                                    </p>
                                                </div>
                                            </div>
                                            <Badge variant={company.is_active ? 'default' : 'secondary'}>
                                                {company.is_active ? 'Activa' : 'Inactiva'}
                                            </Badge>
                                        </Link>
                                    ))
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Recent Users */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <div>
                                <CardTitle>Usuarios Recientes</CardTitle>
                                <CardDescription>Últimos usuarios registrados</CardDescription>
                            </div>
                            <Link
                                href="/super-admin/users"
                                className="text-primary text-sm hover:underline"
                            >
                                Ver todos
                            </Link>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-4">
                                {recentUsers.length === 0 ? (
                                    <p className="text-muted-foreground py-4 text-center text-sm">
                                        No hay usuarios registrados
                                    </p>
                                ) : (
                                    recentUsers.map((user) => (
                                        <Link
                                            key={user.id}
                                            href={`/super-admin/users/${user.id}/edit`}
                                            className="hover:bg-muted/50 flex items-center justify-between rounded-lg p-2 transition-colors"
                                        >
                                            <div className="flex items-center gap-3">
                                                <div className="bg-primary/10 flex size-10 items-center justify-center rounded-full">
                                                    <Users className="text-primary size-5" />
                                                </div>
                                                <div>
                                                    <p className="font-medium">{user.name}</p>
                                                    <p className="text-muted-foreground text-xs">
                                                        {user.company?.name || 'Sin empresa'}
                                                    </p>
                                                </div>
                                            </div>
                                            <Badge variant="outline">
                                                {roleLabels[user.role] || user.role}
                                            </Badge>
                                        </Link>
                                    ))
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>
                {/* Adoption Metrics */}
                <div>
                    <h2 className="mb-4 text-lg font-semibold">Métricas de Adopción</h2>
                    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                        {/* Pipeline Performance */}
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between pb-2">
                                <CardTitle className="text-sm font-medium">Pipeline AI</CardTitle>
                                <Gauge className="text-muted-foreground size-5" />
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-1.5 text-sm">
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">P50 latencia</span>
                                        <span className="font-medium">
                                            {adoptionMetrics.pipeline.p50_latency_ms != null
                                                ? `${(adoptionMetrics.pipeline.p50_latency_ms / 1000).toFixed(1)}s`
                                                : '—'}
                                        </span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">P95 latencia</span>
                                        <span className="font-medium">
                                            {adoptionMetrics.pipeline.p95_latency_ms != null
                                                ? `${(adoptionMetrics.pipeline.p95_latency_ms / 1000).toFixed(1)}s`
                                                : '—'}
                                        </span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Eventos hoy</span>
                                        <span className="font-medium">{adoptionMetrics.pipeline.events_today}</span>
                                    </div>
                                    {adoptionMetrics.pipeline.failed_last_7_days > 0 && (
                                        <div className="flex justify-between text-destructive">
                                            <span>Fallidos (7d)</span>
                                            <span className="font-medium">{adoptionMetrics.pipeline.failed_last_7_days}</span>
                                        </div>
                                    )}
                                    {adoptionMetrics.pipeline.pending_webhooks > 0 && (
                                        <div className="flex justify-between text-warning">
                                            <span>Webhooks pendientes</span>
                                            <span className="font-medium">{adoptionMetrics.pipeline.pending_webhooks}</span>
                                        </div>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Human Review */}
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between pb-2">
                                <CardTitle className="text-sm font-medium">Revisión Humana</CardTitle>
                                <Eye className="text-muted-foreground size-5" />
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-1.5 text-sm">
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Tasa de revisión</span>
                                        <span className="font-medium">
                                            {adoptionMetrics.human_review.review_rate_pct != null
                                                ? `${adoptionMetrics.human_review.review_rate_pct}%`
                                                : '—'}
                                        </span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Revisadas (30d)</span>
                                        <span className="font-medium">
                                            {adoptionMetrics.human_review.human_reviewed_30d}/{adoptionMetrics.human_review.total_completed_30d}
                                        </span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Override rate</span>
                                        <span className="font-medium">
                                            {adoptionMetrics.human_review.override_rate_pct != null
                                                ? `${adoptionMetrics.human_review.override_rate_pct}%`
                                                : '—'}
                                        </span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Falsos positivos</span>
                                        <span className="font-medium">{adoptionMetrics.human_review.human_override_30d}</span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Copilot Usage */}
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between pb-2">
                                <CardTitle className="text-sm font-medium">Copilot</CardTitle>
                                <Bot className="text-muted-foreground size-5" />
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-1.5 text-sm">
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Sesiones (mes)</span>
                                        <span className="font-medium">{adoptionMetrics.copilot.sessions_this_month}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Mensajes (mes)</span>
                                        <span className="font-medium">{adoptionMetrics.copilot.messages_this_month}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Usuarios activos</span>
                                        <span className="font-medium">{adoptionMetrics.copilot.active_users_this_month}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Msgs/sesión</span>
                                        <span className="font-medium">
                                            {adoptionMetrics.copilot.avg_messages_per_session ?? '—'}
                                        </span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Onboarding */}
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between pb-2">
                                <CardTitle className="text-sm font-medium">Onboarding</CardTitle>
                                <Clock className="text-muted-foreground size-5" />
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-1.5 text-sm">
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Días a 1ra alerta</span>
                                        <span className="font-medium">
                                            {adoptionMetrics.onboarding.avg_days_to_first_alert != null
                                                ? `${adoptionMetrics.onboarding.avg_days_to_first_alert}d`
                                                : '—'}
                                        </span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Empresas con alertas</span>
                                        <span className="font-medium">{adoptionMetrics.onboarding.companies_with_alerts}</span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

