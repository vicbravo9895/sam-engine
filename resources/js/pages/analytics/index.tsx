import { EventAnalyticsDashboard } from '@/components/samsara/event-analytics-dashboard';
import { SignalAnalyticsDashboard } from '@/components/samsara/signal-analytics-dashboard';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    BarChart3,
    Bell,
    Brain,
    Calendar,
    CheckCircle2,
    Download,
    ExternalLink,
    Loader2,
    Radio,
    RefreshCcw,
    Shield,
    ShieldAlert,
    Target,
    TrendingDown,
    TrendingUp,
    Truck,
    User,
    Users,
    XCircle,
    Zap,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

// ============================================================================
// TYPE DEFINITIONS
// ============================================================================

interface ExecutiveSummary {
    alerts: {
        total: number;
        critical: number;
        real_alerts: number;
        false_positive_rate: number;
        avg_daily: number;
        trend: 'up' | 'down' | 'stable';
    };
    signals: {
        total: number;
        critical: number;
        coached_rate: number;
        unique_drivers: number;
        unique_vehicles: number;
        avg_daily: number;
        trend: 'up' | 'down' | 'stable';
    };
    period_days: number;
}

type TabId = 'overview' | 'alerts' | 'signals';

interface TabConfig {
    id: TabId;
    label: string;
    icon: React.ElementType;
    description: string;
}

// ============================================================================
// CONSTANTS
// ============================================================================

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Centro de Analytics', href: '/analytics' },
];

const TABS: TabConfig[] = [
    {
        id: 'overview',
        label: 'Resumen Ejecutivo',
        icon: BarChart3,
        description: 'Vista consolidada de métricas clave',
    },
    {
        id: 'alerts',
        label: 'Alertas AI',
        icon: Bell,
        description: 'Eventos de pánico y alertas procesadas por AI',
    },
    {
        id: 'signals',
        label: 'Señales de Seguridad',
        icon: Radio,
        description: 'Comportamientos de conducción capturados',
    },
];

// ============================================================================
// MAIN COMPONENT
// ============================================================================

export default function AnalyticsIndex() {
    const [activeTab, setActiveTab] = useState<TabId>('overview');
    const [periodDays, setPeriodDays] = useState<string>('30');
    const [summary, setSummary] = useState<ExecutiveSummary | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [lastUpdated, setLastUpdated] = useState<Date | null>(null);

    const fetchExecutiveSummary = useCallback(async () => {
        setLoading(true);
        setError(null);

        try {
            // Fetch both analytics endpoints in parallel
            const [alertsRes, signalsRes] = await Promise.all([
                fetch(`/api/events/analytics?days=${periodDays}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                }),
                fetch(`/api/safety-signals/analytics?days=${periodDays}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                }),
            ]);

            if (!alertsRes.ok || !signalsRes.ok) {
                throw new Error('Error al cargar datos de analytics');
            }

            const [alertsData, signalsData] = await Promise.all([
                alertsRes.json(),
                signalsRes.json(),
            ]);

            // Build executive summary
            const executiveSummary: ExecutiveSummary = {
                alerts: {
                    total: alertsData.summary?.total_events ?? 0,
                    critical:
                        alertsData.events_by_severity?.critical ?? 0,
                    real_alerts: alertsData.summary?.real_alerts ?? 0,
                    false_positive_rate:
                        alertsData.summary?.false_positive_rate ?? 0,
                    avg_daily:
                        alertsData.period_days > 0
                            ? Math.round(
                                  alertsData.summary?.total_events /
                                      alertsData.period_days
                              )
                            : 0,
                    trend: 'stable', // Could be calculated from historical data
                },
                signals: {
                    total: signalsData.summary?.total_signals ?? 0,
                    critical: signalsData.summary?.critical ?? 0,
                    coached_rate: signalsData.summary?.coached_rate ?? 0,
                    unique_drivers: signalsData.summary?.unique_drivers ?? 0,
                    unique_vehicles: signalsData.summary?.unique_vehicles ?? 0,
                    avg_daily: signalsData.summary?.avg_daily ?? 0,
                    trend: 'stable',
                },
                period_days: parseInt(periodDays),
            };

            setSummary(executiveSummary);
            setLastUpdated(new Date());
        } catch (err) {
            setError(
                err instanceof Error
                    ? err.message
                    : 'Error al cargar analytics'
            );
        } finally {
            setLoading(false);
        }
    }, [periodDays]);

    useEffect(() => {
        fetchExecutiveSummary();
    }, [fetchExecutiveSummary]);

    const formatLastUpdated = () => {
        if (!lastUpdated) return '';
        return lastUpdated.toLocaleTimeString('es-MX', {
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Centro de Analytics" />
            <div className="flex flex-1 flex-col gap-6 p-4 lg:p-6">
                {/* ============================================================
                    HEADER
                ============================================================ */}
                <header className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-lg">
                                <BarChart3 className="size-5" />
                            </div>
                            <div>
                                <h1 className="text-2xl font-bold tracking-tight">
                                    Centro de Analytics
                                </h1>
                                <p className="text-sm text-muted-foreground">
                                    Inteligencia operativa para tu flota
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
                        {/* Period Selector */}
                        <Select value={periodDays} onValueChange={setPeriodDays}>
                            <SelectTrigger className="w-[160px]">
                                <Calendar className="mr-2 size-4" />
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="7">Últimos 7 días</SelectItem>
                                <SelectItem value="14">Últimos 14 días</SelectItem>
                                <SelectItem value="30">Últimos 30 días</SelectItem>
                                <SelectItem value="60">Últimos 60 días</SelectItem>
                                <SelectItem value="90">Últimos 90 días</SelectItem>
                            </SelectContent>
                        </Select>

                        {/* Refresh */}
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Button
                                    variant="outline"
                                    size="icon"
                                    onClick={fetchExecutiveSummary}
                                    disabled={loading}
                                >
                                    <RefreshCcw
                                        className={`size-4 ${loading ? 'animate-spin' : ''}`}
                                    />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>
                                Actualizar datos
                                {lastUpdated && (
                                    <span className="ml-1 text-xs text-muted-foreground">
                                        · {formatLastUpdated()}
                                    </span>
                                )}
                            </TooltipContent>
                        </Tooltip>

                        {/* Export (future feature placeholder) */}
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Button variant="outline" size="icon" disabled>
                                    <Download className="size-4" />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>Exportar reporte (próximamente)</TooltipContent>
                        </Tooltip>
                    </div>
                </header>

                {/* ============================================================
                    TABS NAVIGATION
                ============================================================ */}
                <nav className="flex flex-wrap items-center gap-1 rounded-xl border bg-muted/30 p-1">
                    {TABS.map((tab) => {
                        const Icon = tab.icon;
                        const isActive = activeTab === tab.id;
                        return (
                            <button
                                key={tab.id}
                                onClick={() => setActiveTab(tab.id)}
                                className={`flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition-all ${
                                    isActive
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:bg-background/50 hover:text-foreground'
                                }`}
                            >
                                <Icon className="size-4" />
                                <span className="hidden sm:inline">{tab.label}</span>
                            </button>
                        );
                    })}
                </nav>

                {/* ============================================================
                    TAB CONTENT
                ============================================================ */}
                {activeTab === 'overview' && (
                    <OverviewTab
                        summary={summary}
                        loading={loading}
                        error={error}
                        onRetry={fetchExecutiveSummary}
                        onNavigateToTab={setActiveTab}
                    />
                )}

                {activeTab === 'alerts' && (
                    <div className="space-y-4">
                        <div className="flex items-center justify-between">
                            <div>
                                <h2 className="text-lg font-semibold">Alertas AI</h2>
                                <p className="text-sm text-muted-foreground">
                                    Eventos de pánico y alertas procesadas por el pipeline de AI
                                </p>
                            </div>
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/samsara/alerts">
                                    Ver alertas
                                    <ExternalLink className="ml-2 size-4" />
                                </Link>
                            </Button>
                        </div>
                        <EventAnalyticsDashboard />
                    </div>
                )}

                {activeTab === 'signals' && (
                    <div className="space-y-4">
                        <div className="flex items-center justify-between">
                            <div>
                                <h2 className="text-lg font-semibold">Señales de Seguridad</h2>
                                <p className="text-sm text-muted-foreground">
                                    Comportamientos de conducción capturados del stream de Samsara
                                </p>
                            </div>
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/safety-signals">
                                    Ver señales
                                    <ExternalLink className="ml-2 size-4" />
                                </Link>
                            </Button>
                        </div>
                        <SignalAnalyticsDashboard />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

// ============================================================================
// OVERVIEW TAB COMPONENT
// ============================================================================

interface OverviewTabProps {
    summary: ExecutiveSummary | null;
    loading: boolean;
    error: string | null;
    onRetry: () => void;
    onNavigateToTab: (tab: TabId) => void;
}

function OverviewTab({ summary, loading, error, onRetry, onNavigateToTab }: OverviewTabProps) {
    if (loading && !summary) {
        return (
            <div className="flex items-center justify-center p-16">
                <div className="flex flex-col items-center gap-4">
                    <Loader2 className="size-10 animate-spin text-primary" />
                    <p className="text-sm text-muted-foreground">Cargando analytics...</p>
                </div>
            </div>
        );
    }

    if (error && !summary) {
        return (
            <div className="flex flex-col items-center justify-center gap-4 rounded-xl border border-dashed p-16">
                <XCircle className="size-12 text-red-500" />
                <div className="text-center">
                    <p className="font-medium">{error}</p>
                    <p className="text-sm text-muted-foreground">
                        No pudimos cargar los datos de analytics
                    </p>
                </div>
                <Button variant="outline" onClick={onRetry}>
                    <RefreshCcw className="mr-2 size-4" />
                    Reintentar
                </Button>
            </div>
        );
    }

    if (!summary) return null;

    const totalEvents = summary.alerts.total + summary.signals.total;
    const totalCritical = summary.alerts.critical + summary.signals.critical;
    const criticalRate = totalEvents > 0 ? ((totalCritical / totalEvents) * 100).toFixed(1) : 0;

    return (
        <div className="space-y-6">
            {/* Executive KPIs */}
            <section className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <KPICard
                    icon={Activity}
                    title="Total Eventos"
                    value={totalEvents.toLocaleString()}
                    subtitle={`últimos ${summary.period_days} días`}
                    trend={null}
                    accent="from-blue-500/20 to-blue-500/5"
                    iconColor="text-blue-500"
                />
                <KPICard
                    icon={ShieldAlert}
                    title="Eventos Críticos"
                    value={totalCritical.toLocaleString()}
                    subtitle={`${criticalRate}% del total`}
                    trend={totalCritical > 10 ? 'up' : null}
                    accent="from-red-500/20 to-red-500/5"
                    iconColor="text-red-500"
                />
                <KPICard
                    icon={Users}
                    title="Conductores Activos"
                    value={summary.signals.unique_drivers.toLocaleString()}
                    subtitle="con señales registradas"
                    trend={null}
                    accent="from-amber-500/20 to-amber-500/5"
                    iconColor="text-amber-500"
                />
                <KPICard
                    icon={Truck}
                    title="Vehículos Monitoreados"
                    value={summary.signals.unique_vehicles.toLocaleString()}
                    subtitle="en el período"
                    trend={null}
                    accent="from-emerald-500/20 to-emerald-500/5"
                    iconColor="text-emerald-500"
                />
            </section>

            {/* System Comparison Cards */}
            <section className="grid gap-6 lg:grid-cols-2">
                {/* Alerts Summary Card */}
                <Card className="overflow-hidden">
                    <CardHeader className="border-b bg-gradient-to-r from-primary/5 to-transparent pb-4">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-3">
                                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                                    <Bell className="size-5 text-primary" />
                                </div>
                                <div>
                                    <CardTitle className="text-base">Alertas AI</CardTitle>
                                    <CardDescription>Pipeline de procesamiento</CardDescription>
                                </div>
                            </div>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => onNavigateToTab('alerts')}
                            >
                                Ver detalles
                                <ArrowRight className="ml-1 size-4" />
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent className="p-4">
                        <div className="grid grid-cols-2 gap-4">
                            <MetricItem
                                label="Total alertas"
                                value={summary.alerts.total}
                                icon={Bell}
                            />
                            <MetricItem
                                label="Alertas reales"
                                value={summary.alerts.real_alerts}
                                icon={CheckCircle2}
                                accent="text-emerald-500"
                            />
                            <MetricItem
                                label="Críticas"
                                value={summary.alerts.critical}
                                icon={AlertTriangle}
                                accent="text-red-500"
                            />
                            <MetricItem
                                label="Falsos positivos"
                                value={`${summary.alerts.false_positive_rate}%`}
                                icon={Target}
                                accent="text-blue-500"
                            />
                        </div>
                        <div className="mt-4 flex items-center justify-between rounded-lg bg-muted/50 p-3">
                            <span className="text-sm text-muted-foreground">Promedio diario</span>
                            <Badge variant="secondary" className="font-mono">
                                {summary.alerts.avg_daily} alertas/día
                            </Badge>
                        </div>
                    </CardContent>
                </Card>

                {/* Signals Summary Card */}
                <Card className="overflow-hidden">
                    <CardHeader className="border-b bg-gradient-to-r from-amber-500/5 to-transparent pb-4">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-3">
                                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-amber-500/10">
                                    <Radio className="size-5 text-amber-500" />
                                </div>
                                <div>
                                    <CardTitle className="text-base">Señales de Seguridad</CardTitle>
                                    <CardDescription>Stream en tiempo real</CardDescription>
                                </div>
                            </div>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => onNavigateToTab('signals')}
                            >
                                Ver detalles
                                <ArrowRight className="ml-1 size-4" />
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent className="p-4">
                        <div className="grid grid-cols-2 gap-4">
                            <MetricItem
                                label="Total señales"
                                value={summary.signals.total}
                                icon={Activity}
                            />
                            <MetricItem
                                label="Críticas"
                                value={summary.signals.critical}
                                icon={AlertTriangle}
                                accent="text-red-500"
                            />
                            <MetricItem
                                label="Conductores"
                                value={summary.signals.unique_drivers}
                                icon={User}
                            />
                            <MetricItem
                                label="Tasa coaching"
                                value={`${summary.signals.coached_rate}%`}
                                icon={CheckCircle2}
                                accent="text-emerald-500"
                            />
                        </div>
                        <div className="mt-4 flex items-center justify-between rounded-lg bg-muted/50 p-3">
                            <span className="text-sm text-muted-foreground">Promedio diario</span>
                            <Badge variant="secondary" className="font-mono">
                                {summary.signals.avg_daily.toFixed(1)} señales/día
                            </Badge>
                        </div>
                    </CardContent>
                </Card>
            </section>

            {/* Quick Insights */}
            <section>
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <Brain className="size-5 text-primary" />
                            <CardTitle className="text-base">Insights Rápidos</CardTitle>
                        </div>
                        <CardDescription>
                            Observaciones clave basadas en los datos del período
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                            <InsightCard
                                type={summary.alerts.false_positive_rate < 30 ? 'positive' : 'warning'}
                                title="Precisión del AI"
                                description={
                                    summary.alerts.false_positive_rate < 30
                                        ? 'El pipeline de AI mantiene una buena tasa de detección'
                                        : 'La tasa de falsos positivos podría optimizarse'
                                }
                                metric={`${(100 - summary.alerts.false_positive_rate).toFixed(0)}% precisión`}
                            />
                            <InsightCard
                                type={summary.signals.coached_rate > 50 ? 'positive' : 'info'}
                                title="Gestión de Coaching"
                                description={
                                    summary.signals.coached_rate > 50
                                        ? 'Buen progreso en coaching de conductores'
                                        : 'Hay oportunidad de mejorar el coaching'
                                }
                                metric={`${summary.signals.coached_rate}% completado`}
                            />
                            <InsightCard
                                type={totalCritical > 20 ? 'warning' : 'positive'}
                                title="Eventos Críticos"
                                description={
                                    totalCritical > 20
                                        ? 'Alto volumen de eventos críticos requiere atención'
                                        : 'Nivel de eventos críticos dentro de lo esperado'
                                }
                                metric={`${totalCritical} eventos`}
                            />
                        </div>
                    </CardContent>
                </Card>
            </section>

            {/* Quick Links */}
            <section className="grid gap-4 md:grid-cols-3">
                <QuickLinkCard
                    icon={Bell}
                    title="Alertas Activas"
                    description="Revisa y gestiona alertas pendientes"
                    href="/samsara/alerts"
                    accent="from-primary/10 to-primary/5"
                />
                <QuickLinkCard
                    icon={Radio}
                    title="Stream de Señales"
                    description="Monitorea comportamientos en tiempo real"
                    href="/safety-signals"
                    accent="from-amber-500/10 to-amber-500/5"
                />
                <QuickLinkCard
                    icon={Shield}
                    title="Incidentes"
                    description="Casos escalados y en investigación"
                    href="/incidents"
                    accent="from-red-500/10 to-red-500/5"
                />
            </section>
        </div>
    );
}

// ============================================================================
// SUB-COMPONENTS
// ============================================================================

interface KPICardProps {
    icon: React.ElementType;
    title: string;
    value: string;
    subtitle: string;
    trend: 'up' | 'down' | null;
    accent: string;
    iconColor: string;
}

function KPICard({ icon: Icon, title, value, subtitle, trend, accent, iconColor }: KPICardProps) {
    return (
        <Card className="relative overflow-hidden">
            <div className={`absolute inset-0 bg-gradient-to-br ${accent}`} />
            <CardContent className="relative p-4">
                <div className="flex items-start justify-between">
                    <div className={`rounded-lg p-2 ${iconColor} bg-background/80 backdrop-blur`}>
                        <Icon className="size-5" />
                    </div>
                    {trend && (
                        <span
                            className={`flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${
                                trend === 'up'
                                    ? 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300'
                                    : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300'
                            }`}
                        >
                            {trend === 'up' ? (
                                <TrendingUp className="size-3" />
                            ) : (
                                <TrendingDown className="size-3" />
                            )}
                        </span>
                    )}
                </div>
                <div className="mt-3">
                    <p className="text-3xl font-bold">{value}</p>
                    <p className="text-sm text-muted-foreground">{title}</p>
                    <p className="mt-1 text-xs text-muted-foreground/70">{subtitle}</p>
                </div>
            </CardContent>
        </Card>
    );
}

interface MetricItemProps {
    label: string;
    value: string | number;
    icon: React.ElementType;
    accent?: string;
}

function MetricItem({ label, value, icon: Icon, accent }: MetricItemProps) {
    return (
        <div className="flex items-center gap-3 rounded-lg border bg-background p-3">
            <div className="rounded-md bg-muted p-1.5">
                <Icon className={`size-4 ${accent ?? 'text-muted-foreground'}`} />
            </div>
            <div>
                <p className="text-lg font-semibold">{value}</p>
                <p className="text-xs text-muted-foreground">{label}</p>
            </div>
        </div>
    );
}

interface InsightCardProps {
    type: 'positive' | 'warning' | 'info';
    title: string;
    description: string;
    metric: string;
}

function InsightCard({ type, title, description, metric }: InsightCardProps) {
    const styles = {
        positive: {
            border: 'border-l-emerald-500',
            bg: 'bg-emerald-500/5',
            badge: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
            icon: CheckCircle2,
        },
        warning: {
            border: 'border-l-amber-500',
            bg: 'bg-amber-500/5',
            badge: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
            icon: AlertTriangle,
        },
        info: {
            border: 'border-l-blue-500',
            bg: 'bg-blue-500/5',
            badge: 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300',
            icon: Zap,
        },
    };

    const style = styles[type];
    const Icon = style.icon;

    return (
        <div className={`rounded-lg border-l-4 p-4 ${style.border} ${style.bg}`}>
            <div className="mb-2 flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <Icon className="size-4" />
                    <span className="font-medium text-sm">{title}</span>
                </div>
                <Badge className={style.badge}>{metric}</Badge>
            </div>
            <p className="text-sm text-muted-foreground">{description}</p>
        </div>
    );
}

interface QuickLinkCardProps {
    icon: React.ElementType;
    title: string;
    description: string;
    href: string;
    accent: string;
}

function QuickLinkCard({ icon: Icon, title, description, href, accent }: QuickLinkCardProps) {
    return (
        <Card className="group relative overflow-hidden transition-all hover:shadow-md">
            <div className={`absolute inset-0 bg-gradient-to-br ${accent} opacity-50 transition-opacity group-hover:opacity-100`} />
            <CardContent className="relative p-4">
                <Link href={href} className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div className="rounded-lg bg-background p-2 shadow-sm">
                            <Icon className="size-5" />
                        </div>
                        <div>
                            <p className="font-medium">{title}</p>
                            <p className="text-sm text-muted-foreground">{description}</p>
                        </div>
                    </div>
                    <ArrowRight className="size-5 text-muted-foreground transition-transform group-hover:translate-x-1" />
                </Link>
            </CardContent>
        </Card>
    );
}
