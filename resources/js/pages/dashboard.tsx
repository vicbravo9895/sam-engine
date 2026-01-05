import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import samsara from '@/routes/samsara';
import copilot from '@/routes/copilot';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Activity,
    CheckCircle2,
    Clock,
    FileWarning,
    Loader2,
    Truck,
    Users,
    Phone,
    MessageSquare,
    XCircle,
    Eye,
    TrendingUp,
    AlertCircle,
} from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

interface SamsaraEvent {
    id: number;
    event_type: string;
    event_description: string;
    vehicle_name: string | null;
    driver_name: string | null;
    severity: string;
    ai_status: string;
    occurred_at: string;
    risk_escalation?: string | null;
}

interface Props {
    isSuperAdmin: boolean;
    companyName: string | null;
    samsaraStats: {
        total: number;
        today: number;
        thisWeek: number;
        critical: number;
        pending: number;
        processing: number;
        investigating: number;
        completed: number;
        failed: number;
        needsHumanAttention: number;
    };
    vehiclesStats: {
        total: number;
    };
    contactsStats: {
        total: number;
        active: number;
        default: number;
    };
    usersStats: {
        total: number;
        active: number;
        admins?: number;
    };
    conversationsStats: {
        total: number;
        today: number;
        thisWeek: number;
    };
    eventsBySeverity: Record<string, number>;
    eventsByAiStatus: Record<string, number>;
    eventsByDay: Array<{ day: string; count: number }>;
    eventsByType: Array<{ type: string; count: number }>;
    recentEvents: SamsaraEvent[];
    criticalEvents: SamsaraEvent[];
    eventsNeedingAttention: SamsaraEvent[];
    recentConversations: Array<{
        id: number;
        thread_id: string;
        title: string;
        user_name: string;
        message_count: number;
        last_message_preview: string | null;
        updated_at: string;
    }>;
}

const severityColors: Record<string, string> = {
    critical: 'bg-red-500',
    warning: 'bg-yellow-500',
    info: 'bg-blue-500',
};

const severityLabels: Record<string, string> = {
    critical: 'Crítico',
    warning: 'Advertencia',
    info: 'Informativo',
};

const aiStatusColors: Record<string, string> = {
    pending: 'bg-gray-500',
    processing: 'bg-blue-500',
    investigating: 'bg-yellow-500',
    completed: 'bg-green-500',
    failed: 'bg-red-500',
};

const aiStatusLabels: Record<string, string> = {
    pending: 'Pendiente',
    processing: 'Procesando',
    investigating: 'Investigando',
    completed: 'Completado',
    failed: 'Fallido',
};

const riskEscalationLabels: Record<string, string> = {
    monitor: 'Monitorear',
    warn: 'Advertir',
    call: 'Llamar',
    emergency: 'Emergencia',
};

function formatEventTime(dateString: string): string {
    try {
        const date = new Date(dateString);
        const now = new Date();
        const diffInSeconds = Math.floor((now.getTime() - date.getTime()) / 1000);

        if (diffInSeconds < 60) {
            return 'hace unos segundos';
        } else if (diffInSeconds < 3600) {
            const minutes = Math.floor(diffInSeconds / 60);
            return `hace ${minutes} ${minutes === 1 ? 'minuto' : 'minutos'}`;
        } else if (diffInSeconds < 86400) {
            const hours = Math.floor(diffInSeconds / 3600);
            return `hace ${hours} ${hours === 1 ? 'hora' : 'horas'}`;
        } else if (diffInSeconds < 604800) {
            const days = Math.floor(diffInSeconds / 86400);
            return `hace ${days} ${days === 1 ? 'día' : 'días'}`;
        } else {
            return date.toLocaleDateString('es-MX', {
                day: 'numeric',
                month: 'short',
                year: date.getFullYear() !== now.getFullYear() ? 'numeric' : undefined,
            });
        }
    } catch {
        return 'Fecha inválida';
    }
}

export default function Dashboard() {
    const {
        isSuperAdmin,
        companyName,
        samsaraStats,
        vehiclesStats,
        contactsStats,
        usersStats,
        conversationsStats,
        eventsBySeverity,
        eventsByAiStatus,
        eventsByDay,
        eventsByType,
        recentEvents,
        criticalEvents,
        eventsNeedingAttention,
        recentConversations,
    } = usePage<{ props: Props }>().props as unknown as Props;

    const maxEventsCount = Math.max(...eventsByDay.map((d) => d.count), 1);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
                {/* Header */}
                <div>
                    <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">
                        Dashboard
                    </h1>
                    <p className="text-muted-foreground">
                        {isSuperAdmin
                            ? 'Vista general del sistema'
                            : companyName
                              ? `Vista general de ${companyName}`
                              : 'Vista general de tu flota'}
                    </p>
                </div>

                {/* Stats Grid - Samsara Events */}
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">Total de Alertas</CardTitle>
                            <AlertTriangle className="text-muted-foreground size-5" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold">{samsaraStats.total}</div>
                            <div className="text-muted-foreground mt-1 flex items-center gap-2 text-xs">
                                <span className="text-sky-600">{samsaraStats.today} hoy</span>
                                <span>•</span>
                                <span>{samsaraStats.thisWeek} esta semana</span>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">Alertas Críticas</CardTitle>
                            <AlertCircle className="text-red-500 size-5" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold text-red-600">{samsaraStats.critical}</div>
                            <div className="text-muted-foreground mt-1 flex items-center gap-2 text-xs">
                                <span className="text-red-600">
                                    {samsaraStats.needsHumanAttention} requieren atención
                                </span>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">En Procesamiento</CardTitle>
                            <Loader2 className="text-blue-500 size-5 animate-spin" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold">
                                {samsaraStats.pending + samsaraStats.processing + samsaraStats.investigating}
                            </div>
                            <div className="text-muted-foreground mt-1 flex items-center gap-2 text-xs">
                                <span>{samsaraStats.pending} pendientes</span>
                                <span>•</span>
                                <span>{samsaraStats.processing} procesando</span>
                                <span>•</span>
                                <span>{samsaraStats.investigating} investigando</span>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">Completadas</CardTitle>
                            <CheckCircle2 className="text-green-500 size-5" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold text-green-600">{samsaraStats.completed}</div>
                            <div className="text-muted-foreground mt-1 flex items-center gap-2 text-xs">
                                {samsaraStats.failed > 0 && (
                                    <>
                                        <span className="text-red-600">{samsaraStats.failed} fallidas</span>
                                    </>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Secondary Stats Grid */}
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">Vehículos</CardTitle>
                            <Truck className="text-muted-foreground size-5" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold">{vehiclesStats.total}</div>
                            <p className="text-muted-foreground mt-1 text-xs">Total en flota</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">Contactos</CardTitle>
                            <Phone className="text-muted-foreground size-5" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold">{contactsStats.total}</div>
                            <div className="text-muted-foreground mt-1 flex items-center gap-2 text-xs">
                                <span className="text-emerald-600">{contactsStats.active} activos</span>
                                {contactsStats.default > 0 && (
                                    <>
                                        <span>•</span>
                                        <span>{contactsStats.default} por defecto</span>
                                    </>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">Usuarios</CardTitle>
                            <Users className="text-muted-foreground size-5" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold">{usersStats.total}</div>
                            <div className="text-muted-foreground mt-1 flex items-center gap-2 text-xs">
                                <span className="text-emerald-600">{usersStats.active} activos</span>
                                {usersStats.admins !== undefined && (
                                    <>
                                        <span>•</span>
                                        <span>{usersStats.admins} admins</span>
                                    </>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">Conversaciones</CardTitle>
                            <MessageSquare className="text-muted-foreground size-5" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold">{conversationsStats.total}</div>
                            <div className="text-muted-foreground mt-1 flex items-center gap-2 text-xs">
                                <span className="text-sky-600">{conversationsStats.today} hoy</span>
                                <span>•</span>
                                <span>{conversationsStats.thisWeek} esta semana</span>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Charts and Activity */}
                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Activity Chart */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Actividad de Alertas (7 días)</CardTitle>
                            <CardDescription>Número de alertas por día</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-2">
                                {eventsByDay.map((day, index) => {
                                    const height = maxEventsCount > 0 ? (day.count / maxEventsCount) * 100 : 0;
                                    return (
                                        <div key={index} className="flex items-center gap-3">
                                            <div className="text-muted-foreground w-12 text-xs">
                                                {day.day}
                                            </div>
                                            <div className="flex-1">
                                                <div className="bg-muted relative h-8 w-full overflow-hidden rounded-full">
                                                    <div
                                                        className="bg-primary h-full transition-all"
                                                        style={{ width: `${height}%` }}
                                                    />
                                                </div>
                                            </div>
                                            <div className="text-muted-foreground w-8 text-right text-xs">
                                                {day.count}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Events by Type */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Alertas por Tipo</CardTitle>
                            <CardDescription>Últimos 30 días</CardDescription>
                        </CardHeader>
                        <CardContent>
                            {eventsByType.length === 0 ? (
                                <p className="text-muted-foreground py-4 text-center text-sm">
                                    No hay datos disponibles
                                </p>
                            ) : (
                                <div className="space-y-3">
                                    {eventsByType.map((item, index) => (
                                        <div key={index} className="flex items-center justify-between">
                                            <div className="flex-1">
                                                <p className="text-sm font-medium">{item.type}</p>
                                            </div>
                                            <Badge variant="outline">{item.count}</Badge>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Events Lists */}
                <div className="grid gap-6 lg:grid-cols-3">
                    {/* Critical Events */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <div>
                                <CardTitle>Alertas Críticas</CardTitle>
                                <CardDescription>Requieren atención inmediata</CardDescription>
                            </div>
                            <Link
                                href={samsara.alerts.index().url}
                                className="text-primary text-sm hover:underline"
                            >
                                Ver todas
                            </Link>
                        </CardHeader>
                        <CardContent>
                            {criticalEvents.length === 0 ? (
                                <p className="text-muted-foreground py-4 text-center text-sm">
                                    No hay alertas críticas
                                </p>
                            ) : (
                                <div className="space-y-3">
                                    {criticalEvents.map((event) => (
                                        <Link
                                            key={event.id}
                                            href={samsara.alerts.show({ samsaraEvent: event.id }).url}
                                            className="hover:bg-muted/50 block rounded-lg border p-3 transition-colors"
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <div className="flex-1 min-w-0">
                                                    <p className="text-sm font-medium truncate">
                                                        {event.event_description || event.event_type}
                                                    </p>
                                                    {event.vehicle_name && (
                                                        <p className="text-muted-foreground text-xs">
                                                            {event.vehicle_name}
                                                        </p>
                                                    )}
                                                    <p className="text-muted-foreground mt-1 text-xs">
                                                        {formatEventTime(event.occurred_at)}
                                                    </p>
                                                </div>
                                                <Badge
                                                    variant="destructive"
                                                    className="shrink-0"
                                                >
                                                    Crítico
                                                </Badge>
                                            </div>
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Events Needing Attention */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <div>
                                <CardTitle>Requieren Atención</CardTitle>
                                <CardDescription>Pendientes de revisión humana</CardDescription>
                            </div>
                            <Link
                                href={samsara.alerts.index().url}
                                className="text-primary text-sm hover:underline"
                            >
                                Ver todas
                            </Link>
                        </CardHeader>
                        <CardContent>
                            {eventsNeedingAttention.length === 0 ? (
                                <p className="text-muted-foreground py-4 text-center text-sm">
                                    No hay eventos pendientes
                                </p>
                            ) : (
                                <div className="space-y-3">
                                    {eventsNeedingAttention.map((event) => (
                                        <Link
                                            key={event.id}
                                            href={samsara.alerts.show({ samsaraEvent: event.id }).url}
                                            className="hover:bg-muted/50 block rounded-lg border p-3 transition-colors"
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <div className="flex-1 min-w-0">
                                                    <p className="text-sm font-medium truncate">
                                                        {event.event_description || event.event_type}
                                                    </p>
                                                    {event.vehicle_name && (
                                                        <p className="text-muted-foreground text-xs">
                                                            {event.vehicle_name}
                                                        </p>
                                                    )}
                                                    <div className="text-muted-foreground mt-1 flex items-center gap-2 text-xs">
                                                        <Badge
                                                            variant="outline"
                                                            className="text-xs"
                                                        >
                                                            {aiStatusLabels[event.ai_status] || event.ai_status}
                                                        </Badge>
                                                    </div>
                                                </div>
                                                <Eye className="text-amber-500 size-4 shrink-0" />
                                            </div>
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Recent Events */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <div>
                                <CardTitle>Eventos Recientes</CardTitle>
                                <CardDescription>Últimas alertas recibidas</CardDescription>
                            </div>
                            <Link
                                href={samsara.alerts.index().url}
                                className="text-primary text-sm hover:underline"
                            >
                                Ver todas
                            </Link>
                        </CardHeader>
                        <CardContent>
                            {recentEvents.length === 0 ? (
                                <p className="text-muted-foreground py-4 text-center text-sm">
                                    No hay eventos recientes
                                </p>
                            ) : (
                                <div className="space-y-3">
                                    {recentEvents.map((event) => (
                                        <Link
                                            key={event.id}
                                            href={samsara.alerts.show({ samsaraEvent: event.id }).url}
                                            className="hover:bg-muted/50 block rounded-lg border p-3 transition-colors"
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <div className="flex-1 min-w-0">
                                                    <p className="text-sm font-medium truncate">
                                                        {event.event_description || event.event_type}
                                                    </p>
                                                    {event.vehicle_name && (
                                                        <p className="text-muted-foreground text-xs">
                                                            {event.vehicle_name}
                                                        </p>
                                                    )}
                                                    <div className="text-muted-foreground mt-1 flex items-center gap-2 text-xs">
                                                        <Badge
                                                            variant="outline"
                                                            className="text-xs"
                                                            style={{
                                                                backgroundColor:
                                                                    severityColors[event.severity] + '20',
                                                                color: severityColors[event.severity],
                                                            }}
                                                        >
                                                            {severityLabels[event.severity] || event.severity}
                                                        </Badge>
                                                        {event.risk_escalation && (
                                                            <Badge variant="outline" className="text-xs">
                                                                {riskEscalationLabels[event.risk_escalation] ||
                                                                    event.risk_escalation}
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    <p className="text-muted-foreground mt-1 text-xs">
                                                        {formatEventTime(event.occurred_at)}
                                                    </p>
                                                </div>
                                                <div
                                                    className="size-2 shrink-0 rounded-full"
                                                    style={{
                                                        backgroundColor:
                                                            severityColors[event.severity] || '#gray',
                                                    }}
                                                />
                                            </div>
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Conversaciones Recientes */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <div>
                            <CardTitle>Conversaciones Recientes</CardTitle>
                            <CardDescription>Últimas conversaciones del Copilot</CardDescription>
                        </div>
                        <Link
                            href={copilot.index().url}
                            className="text-primary text-sm hover:underline"
                        >
                            Ver todas
                        </Link>
                    </CardHeader>
                    <CardContent>
                        {recentConversations.length === 0 ? (
                            <p className="text-muted-foreground py-4 text-center text-sm">
                                No hay conversaciones recientes
                            </p>
                        ) : (
                            <div className="space-y-3">
                                {recentConversations.map((conv) => (
                                    <Link
                                        key={conv.id}
                                        href={copilot.show({ threadId: conv.thread_id }).url}
                                        className="hover:bg-muted/50 block rounded-lg border p-3 transition-colors"
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="flex-1 min-w-0">
                                                <p className="text-sm font-medium truncate">
                                                    {conv.title || 'Sin título'}
                                                </p>
                                                <p className="text-muted-foreground text-xs">
                                                    {conv.user_name}
                                                </p>
                                                {conv.last_message_preview && (
                                                    <p className="text-muted-foreground mt-1 truncate text-xs">
                                                        {conv.last_message_preview}
                                                    </p>
                                                )}
                                                <div className="text-muted-foreground mt-1 flex items-center gap-2 text-xs">
                                                    <span>{conv.message_count} mensajes</span>
                                                    <span>•</span>
                                                    <span>{conv.updated_at}</span>
                                                </div>
                                            </div>
                                            <MessageSquare className="text-muted-foreground size-4 shrink-0" />
                                        </div>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
