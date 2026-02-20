import { AnimatedCounter, StaggerContainer, StaggerItem } from '@/components/motion';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import copilot from '@/routes/copilot';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Bell,
    Clock,
    MessageSquare,
    ShieldCheck,
    TrendingUp,
} from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard().url },
];

interface OperationalStatus {
    alerts_open: number;
    sla_breaches: number;
    needs_attention: number;
    avg_ack_seconds: number | null;
    deliverability_rate: number | null;
    alerts_today: number;
}

interface AttentionQueueItem {
    id: number;
    vehicle_name: string | null;
    event_type: string | null;
    severity: string;
    created_at: string;
    owner_name: string | null;
    ack_due_at: string | null;
    ack_sla_remaining_seconds: number | null;
    ack_status: string;
    ai_status: string;
    attention_state: string | null;
}

interface TrendDay {
    date: string;
    count?: number;
    total?: number;
    delivered?: number;
}

interface Props {
    isSuperAdmin: boolean;
    companyName: string | null;
    operationalStatus: OperationalStatus;
    attentionQueue: AttentionQueueItem[];
    trends: {
        alerts_per_day: TrendDay[];
        notifications_per_day: TrendDay[];
    };
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
    critical: 'text-red-600 bg-red-50 dark:bg-red-950/30 dark:text-red-400',
    warning: 'text-amber-600 bg-amber-50 dark:bg-amber-950/30 dark:text-amber-400',
    info: 'text-blue-600 bg-blue-50 dark:bg-blue-950/30 dark:text-blue-400',
};

const severityLabels: Record<string, string> = {
    critical: 'Critico',
    warning: 'Advertencia',
    info: 'Informativo',
};

const severityBorderColors: Record<string, string> = {
    critical: 'border-l-red-500',
    warning: 'border-l-amber-500',
    info: 'border-l-blue-500',
};

const aiStatusLabels: Record<string, string> = {
    pending: 'Pendiente',
    processing: 'Procesando',
    investigating: 'Investigando',
    completed: 'Completado',
    failed: 'Fallido',
};

function formatAvgAck(seconds: number | null): string {
    if (seconds === null) return '\u2014';
    if (seconds < 60) return `${seconds}s`;
    if (seconds < 3600) return `${Math.round(seconds / 60)} min`;
    return `${(seconds / 3600).toFixed(1)} h`;
}

function formatRelativeSla(remainingSeconds: number | null): string {
    if (remainingSeconds === null) return '\u2014';
    if (remainingSeconds < 0) return 'Vencido';
    if (remainingSeconds < 60) return `${Math.round(remainingSeconds)}s`;
    if (remainingSeconds < 3600) return `${Math.round(remainingSeconds / 60)} min`;
    return `${Math.round(remainingSeconds / 3600)} h`;
}

function getSlaColor(remainingSeconds: number | null): string {
    if (remainingSeconds === null) return 'text-muted-foreground';
    if (remainingSeconds < 0) return 'text-red-600 dark:text-red-400 font-semibold';
    if (remainingSeconds < 1800) return 'text-amber-600 dark:text-amber-400';
    return 'text-green-600 dark:text-green-400';
}

function formatShortDate(iso: string): string {
    try {
        const d = new Date(iso);
        return d.toLocaleDateString('es-MX', {
            day: '2-digit',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return '\u2014';
    }
}

function MiniSparkline({ data, accessor, color }: { data: TrendDay[]; accessor: (d: TrendDay) => number; color: string }) {
    const values = data.map(accessor);
    const max = Math.max(...values, 1);
    const width = 200;
    const height = 48;
    const padding = 2;
    const stepX = (width - padding * 2) / Math.max(values.length - 1, 1);

    const points = values.map((v, i) => ({
        x: padding + i * stepX,
        y: height - padding - ((v / max) * (height - padding * 2)),
    }));

    const linePath = points.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x},${p.y}`).join(' ');
    const areaPath = `${linePath} L${points[points.length - 1]?.x ?? width},${height} L${points[0]?.x ?? 0},${height} Z`;

    return (
        <svg viewBox={`0 0 ${width} ${height}`} className="w-full h-12" preserveAspectRatio="none">
            <defs>
                <linearGradient id={`grad-${color}`} x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor={color} stopOpacity="0.2" />
                    <stop offset="100%" stopColor={color} stopOpacity="0" />
                </linearGradient>
            </defs>
            <path d={areaPath} fill={`url(#grad-${color})`} />
            <path d={linePath} fill="none" stroke={color} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

interface StatCardProps {
    label: string;
    value: string | number;
    subtitle?: string;
    colorClass: string;
    icon: React.ReactNode;
    glowClass?: string;
}

function StatCard({ label, value, subtitle, colorClass, icon, glowClass }: StatCardProps) {
    return (
        <div className={`relative overflow-hidden rounded-xl border bg-card p-4 transition-shadow duration-200 hover:shadow-md ${glowClass ?? ''}`}>
            <div className="absolute top-3 right-3 text-muted-foreground/15">
                {icon}
            </div>
            <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">{label}</p>
            <p className={`mt-2 font-display text-2xl font-bold tracking-tight ${colorClass}`}>
                {typeof value === 'number' ? (
                    <AnimatedCounter value={value} />
                ) : (
                    value
                )}
            </p>
            {subtitle ? (
                <p className="mt-1 text-xs text-muted-foreground">{subtitle}</p>
            ) : null}
        </div>
    );
}

export default function Dashboard() {
    const {
        isSuperAdmin,
        companyName,
        operationalStatus,
        attentionQueue,
        trends,
        recentConversations,
    } = usePage<{ props: Props }>().props as unknown as Props;

    const openColor =
        operationalStatus.alerts_open === 0
            ? 'text-green-600 dark:text-green-400'
            : operationalStatus.sla_breaches > 0
              ? 'text-red-600 dark:text-red-400'
              : 'text-amber-600 dark:text-amber-400';

    const attentionColor =
        operationalStatus.needs_attention === 0
            ? 'text-green-600 dark:text-green-400'
            : 'text-amber-600 dark:text-amber-400';

    const deliverColor =
        operationalStatus.deliverability_rate === null
            ? 'text-muted-foreground'
            : operationalStatus.deliverability_rate >= 90
              ? 'text-green-600 dark:text-green-400'
              : operationalStatus.deliverability_rate >= 70
                ? 'text-amber-600 dark:text-amber-400'
                : 'text-red-600 dark:text-red-400';

    const todayColor =
        operationalStatus.alerts_today === 0
            ? 'text-green-600 dark:text-green-400'
            : operationalStatus.alerts_today > 10
              ? 'text-amber-600 dark:text-amber-400'
              : 'text-foreground';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
                <div>
                    <h1 className="font-display text-2xl font-bold tracking-tight sm:text-3xl">
                        Centro de Comando
                    </h1>
                    <p className="text-muted-foreground">
                        {isSuperAdmin
                            ? 'Vista general del sistema'
                            : companyName
                              ? companyName
                              : 'Vista general de tu flota'}
                    </p>
                </div>

                {/* Stat cards */}
                <StaggerContainer className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-5">
                    <StaggerItem>
                        <StatCard
                            label="Alertas Abiertas"
                            value={operationalStatus.alerts_open}
                            subtitle={operationalStatus.sla_breaches > 0 ? `${operationalStatus.sla_breaches} SLA vencidos` : undefined}
                            colorClass={openColor}
                            icon={<AlertTriangle className="size-8" />}
                            glowClass={operationalStatus.sla_breaches > 0 ? 'glow-red' : undefined}
                        />
                    </StaggerItem>
                    <StaggerItem>
                        <StatCard
                            label="Requieren Atencion"
                            value={operationalStatus.needs_attention}
                            colorClass={attentionColor}
                            icon={<Activity className="size-8" />}
                        />
                    </StaggerItem>
                    <StaggerItem>
                        <StatCard
                            label="Tiempo Promedio ACK"
                            value={formatAvgAck(operationalStatus.avg_ack_seconds)}
                            subtitle="ultimos 7 dias"
                            colorClass="text-foreground"
                            icon={<Clock className="size-8" />}
                        />
                    </StaggerItem>
                    <StaggerItem>
                        <StatCard
                            label="Tasa de Entrega"
                            value={operationalStatus.deliverability_rate !== null ? `${operationalStatus.deliverability_rate}%` : '\u2014'}
                            subtitle="notificaciones 7 dias"
                            colorClass={deliverColor}
                            icon={<Bell className="size-8" />}
                        />
                    </StaggerItem>
                    <StaggerItem>
                        <StatCard
                            label="Alertas Hoy"
                            value={operationalStatus.alerts_today}
                            colorClass={todayColor}
                            icon={<ShieldCheck className="size-8" />}
                        />
                    </StaggerItem>
                </StaggerContainer>

                {/* Attention Queue */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between pb-2">
                        <CardTitle className="flex items-center gap-2 text-lg">
                            <AlertTriangle className="size-5 text-amber-500" />
                            Cola de Atencion
                        </CardTitle>
                        <Link
                            href="/samsara/alerts"
                            className="text-sm font-medium text-primary transition-colors hover:text-primary/80"
                        >
                            Ver todas
                        </Link>
                    </CardHeader>
                    <CardContent>
                        {attentionQueue.length === 0 ? (
                            <div className="flex flex-col items-center gap-2 py-10 text-center">
                                <ShieldCheck className="size-10 text-green-500/40" />
                                <p className="text-sm font-medium text-muted-foreground">
                                    No hay alertas que requieran atencion
                                </p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">ID</th>
                                            <th className="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Vehiculo</th>
                                            <th className="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Tipo</th>
                                            <th className="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Severidad</th>
                                            <th className="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Creada</th>
                                            <th className="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Propietario</th>
                                            <th className="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">SLA</th>
                                            <th className="pb-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {attentionQueue.map((alert) => (
                                            <tr
                                                key={alert.id}
                                                className={`cursor-pointer border-b border-l-2 transition-colors duration-150 hover:bg-muted/50 ${severityBorderColors[alert.severity] ?? 'border-l-transparent'}`}
                                                onClick={() => router.visit(`/samsara/alerts/${alert.id}`)}
                                            >
                                                <td className="py-3 pr-4 font-mono text-xs font-medium">
                                                    {alert.id}
                                                </td>
                                                <td className="py-3 pr-4">
                                                    {alert.vehicle_name ?? '\u2014'}
                                                </td>
                                                <td className="py-3 pr-4 max-w-[160px] truncate">
                                                    {alert.event_type ?? '\u2014'}
                                                </td>
                                                <td className="py-3 pr-4">
                                                    <span className={`inline-flex rounded-md px-2 py-0.5 text-xs font-medium ${severityColors[alert.severity] ?? 'bg-muted text-muted-foreground'}`}>
                                                        {severityLabels[alert.severity] ?? alert.severity}
                                                    </span>
                                                </td>
                                                <td className="py-3 pr-4 text-xs text-muted-foreground">
                                                    {formatShortDate(alert.created_at)}
                                                </td>
                                                <td className="py-3 pr-4">
                                                    {alert.owner_name ?? '\u2014'}
                                                </td>
                                                <td className={`py-3 pr-4 font-mono text-xs font-medium ${getSlaColor(alert.ack_sla_remaining_seconds)}`}>
                                                    {formatRelativeSla(alert.ack_sla_remaining_seconds)}
                                                </td>
                                                <td className="py-3">
                                                    <span className="text-xs text-muted-foreground">
                                                        {aiStatusLabels[alert.ai_status] ?? alert.ai_status}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Trend sparklines */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm">
                                <TrendingUp className="size-4 text-muted-foreground" />
                                Alertas por dia (14 dias)
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <MiniSparkline
                                data={trends.alerts_per_day}
                                accessor={(d) => d.count ?? 0}
                                color="var(--sam-accent-teal)"
                            />
                            <p className="mt-2 font-mono text-xs text-muted-foreground">
                                {trends.alerts_per_day.reduce((s, d) => s + (d.count ?? 0), 0)} total
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm">
                                <Bell className="size-4 text-muted-foreground" />
                                Notificaciones por dia (14 dias)
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <MiniSparkline
                                data={trends.notifications_per_day}
                                accessor={(d) => d.total ?? 0}
                                color="var(--sam-accent-blue)"
                            />
                            <p className="mt-2 font-mono text-xs text-muted-foreground">
                                {trends.notifications_per_day.reduce((s, d) => s + (d.total ?? 0), 0)} total
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Recent Conversations */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <div>
                            <CardTitle>Conversaciones Recientes</CardTitle>
                            <p className="text-sm text-muted-foreground">
                                Ultimas conversaciones del Copilot
                            </p>
                        </div>
                        <Link
                            href={copilot.index().url}
                            className="text-sm font-medium text-primary transition-colors hover:text-primary/80"
                        >
                            Ver todas
                        </Link>
                    </CardHeader>
                    <CardContent>
                        {recentConversations.length === 0 ? (
                            <div className="flex flex-col items-center gap-2 py-8 text-center">
                                <MessageSquare className="size-10 text-muted-foreground/30" />
                                <p className="text-sm text-muted-foreground">
                                    No hay conversaciones recientes
                                </p>
                            </div>
                        ) : (
                            <StaggerContainer className="space-y-2">
                                {recentConversations.map((conv) => (
                                    <StaggerItem key={conv.id}>
                                        <Link
                                            href={copilot.show({ threadId: conv.thread_id }).url}
                                            className="group block rounded-lg border border-transparent p-3 transition-all duration-150 hover:border-border hover:bg-muted/50 hover:shadow-sm"
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <div className="flex-1 min-w-0">
                                                    <p className="text-sm font-medium truncate group-hover:text-primary transition-colors">
                                                        {conv.title || 'Sin titulo'}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {conv.user_name}
                                                    </p>
                                                    {conv.last_message_preview ? (
                                                        <p className="mt-1 truncate text-xs text-muted-foreground/70">
                                                            {conv.last_message_preview}
                                                        </p>
                                                    ) : null}
                                                    <div className="mt-1.5 flex items-center gap-2 font-mono text-[11px] text-muted-foreground/60">
                                                        <span>{conv.message_count} msgs</span>
                                                        <span className="text-muted-foreground/30">/</span>
                                                        <span>{conv.updated_at}</span>
                                                    </div>
                                                </div>
                                                <MessageSquare className="size-4 shrink-0 text-muted-foreground/30 transition-colors group-hover:text-primary/50" />
                                            </div>
                                        </Link>
                                    </StaggerItem>
                                ))}
                            </StaggerContainer>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
