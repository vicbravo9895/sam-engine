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
import {
    AlertTriangle,
    BarChart3,
    Calendar,
    CheckCircle2,
    Clock,
    Loader2,
    RefreshCcw,
    ShieldAlert,
    TrendingDown,
    TrendingUp,
    Truck,
    User,
    XCircle,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

// ============================================================================
// TYPE DEFINITIONS
// ============================================================================

interface AnalyticsData {
    period_days: number;
    period_start: string;
    summary: {
        total_events: number;
        false_positives: number;
        false_positive_rate: number;
        real_alerts: number;
        real_alert_rate: number;
    };
    events_by_type: Array<{ label: string; value: number }>;
    top_vehicles: Array<{ id: string; name: string; count: number }>;
    top_drivers: Array<{ id: string; name: string; count: number }>;
    events_by_severity: Record<string, number>;
    events_by_verdict: Array<{ verdict: string; label: string; count: number }>;
    events_by_day: Array<{ date: string; count: number }>;
    events_by_hour: Array<{ hour: number; count: number }>;
}

interface EventAnalyticsDashboardProps {
    className?: string;
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

const severityColors: Record<string, string> = {
    critical: 'bg-red-500',
    warning: 'bg-amber-500',
    info: 'bg-blue-500',
};

const severityLabels: Record<string, string> = {
    critical: 'Crítica',
    warning: 'Advertencia',
    info: 'Informativa',
};

const formatHour = (hour: number): string => {
    const h = hour % 24;
    if (h === 0) return '12 AM';
    if (h < 12) return `${h} AM`;
    if (h === 12) return '12 PM';
    return `${h - 12} PM`;
};

const getMaxValue = (items: Array<{ count?: number; value?: number }>): number => {
    return Math.max(...items.map((i) => i.count ?? i.value ?? 0), 1);
};

// ============================================================================
// SUB-COMPONENTS
// ============================================================================

function StatCard({
    icon: Icon,
    label,
    value,
    subValue,
    trend,
    accent,
}: {
    icon: React.ElementType;
    label: string;
    value: string | number;
    subValue?: string;
    trend?: 'up' | 'down' | 'neutral';
    accent?: string;
}) {
    return (
        <Card>
            <CardContent className="p-4">
                <div className="flex items-center gap-3">
                    <div className={`rounded-lg p-2 ${accent ?? 'bg-muted'}`}>
                        <Icon className="size-5 text-muted-foreground" />
                    </div>
                    <div className="flex-1 min-w-0">
                        <p className="text-sm text-muted-foreground truncate">{label}</p>
                        <div className="flex items-center gap-2">
                            <p className="text-2xl font-bold">{value}</p>
                            {trend && trend !== 'neutral' && (
                                <span className={trend === 'up' ? 'text-red-500' : 'text-emerald-500'}>
                                    {trend === 'up' ? (
                                        <TrendingUp className="size-4" />
                                    ) : (
                                        <TrendingDown className="size-4" />
                                    )}
                                </span>
                            )}
                        </div>
                        {subValue && (
                            <p className="text-xs text-muted-foreground">{subValue}</p>
                        )}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

function HorizontalBarChart({
    items,
    maxValue,
    colorClass = 'bg-primary',
}: {
    items: Array<{ label: string; value: number }>;
    maxValue: number;
    colorClass?: string;
}) {
    if (items.length === 0) {
        return (
            <div className="flex items-center justify-center h-32 text-muted-foreground text-sm">
                Sin datos para mostrar
            </div>
        );
    }

    return (
        <div className="space-y-2">
            {items.map((item, idx) => (
                <div key={idx} className="flex items-center gap-3">
                    <div className="w-32 text-sm text-muted-foreground truncate" title={item.label}>
                        {item.label}
                    </div>
                    <div className="flex-1 h-6 bg-muted rounded-full overflow-hidden">
                        <div
                            className={`h-full ${colorClass} rounded-full transition-all duration-500`}
                            style={{ width: `${(item.value / maxValue) * 100}%` }}
                        />
                    </div>
                    <div className="w-12 text-sm font-medium text-right">{item.value}</div>
                </div>
            ))}
        </div>
    );
}

function HourlyChart({ data }: { data: Array<{ hour: number; count: number }> }) {
    // Fill in missing hours with 0
    const hourlyData = Array.from({ length: 24 }, (_, i) => {
        const found = data.find((d) => d.hour === i);
        return { hour: i, count: found?.count ?? 0 };
    });

    const counts = hourlyData.map((d) => d.count);
    const minCount = Math.min(...counts);
    const maxCount = Math.max(...counts);
    const range = maxCount - minCount;

    // Calcular altura: si hay rango, usar escala relativa (20%-100%)
    // Si todos son iguales, mostrar 50% para valores > 0
    const getBarHeight = (count: number): number => {
        if (maxCount === 0) return 0;
        if (range === 0) return count > 0 ? 50 : 0;
        return ((count - minCount) / range) * 80 + 20;
    };

    return (
        <div className="flex items-end gap-1 h-24">
            {hourlyData.map((item) => (
                <Tooltip key={item.hour}>
                    <TooltipTrigger asChild>
                        <div className="flex-1 flex flex-col items-center">
                            <div
                                className="w-full bg-primary/80 hover:bg-primary rounded-t transition-all"
                                style={{
                                    height: `${getBarHeight(item.count)}%`,
                                    minHeight: item.count > 0 ? '4px' : '0',
                                }}
                            />
                        </div>
                    </TooltipTrigger>
                    <TooltipContent>
                        <p className="font-medium">{formatHour(item.hour)}</p>
                        <p className="text-xs text-muted-foreground">{item.count} eventos</p>
                    </TooltipContent>
                </Tooltip>
            ))}
        </div>
    );
}

function DailyTrendChart({ data }: { data: Array<{ date: string; count: number }> }) {
    if (data.length === 0) {
        return (
            <div className="flex items-center justify-center h-24 text-muted-foreground text-sm">
                Sin datos para mostrar
            </div>
        );
    }

    const counts = data.map((d) => d.count);
    const minCount = Math.min(...counts);
    const maxCount = Math.max(...counts);
    const range = maxCount - minCount;

    // Calcular altura: si hay rango, usar escala relativa (20%-100%)
    // Si todos son iguales, mostrar 50% para valores > 0
    const getBarHeight = (count: number): number => {
        if (maxCount === 0) return 0;
        if (range === 0) return count > 0 ? 50 : 0;
        return ((count - minCount) / range) * 80 + 20;
    };

    return (
        <div className="flex items-end gap-0.5 h-24">
            {data.map((item, idx) => (
                <Tooltip key={idx}>
                    <TooltipTrigger asChild>
                        <div className="flex-1 flex flex-col items-center min-w-[4px]">
                            <div
                                className="w-full bg-primary/80 hover:bg-primary rounded-t transition-all"
                                style={{
                                    height: `${getBarHeight(item.count)}%`,
                                    minHeight: item.count > 0 ? '4px' : '0',
                                }}
                            />
                        </div>
                    </TooltipTrigger>
                    <TooltipContent>
                        <p className="font-medium">{item.date}</p>
                        <p className="text-xs text-muted-foreground">{item.count} eventos</p>
                    </TooltipContent>
                </Tooltip>
            ))}
        </div>
    );
}

function SeverityDistribution({ data }: { data: Record<string, number> }) {
    const total = Object.values(data).reduce((sum, count) => sum + count, 0);
    
    if (total === 0) {
        return (
            <div className="flex items-center justify-center h-16 text-muted-foreground text-sm">
                Sin datos
            </div>
        );
    }

    return (
        <div className="space-y-3">
            <div className="flex h-4 rounded-full overflow-hidden">
                {(['critical', 'warning', 'info'] as const).map((severity) => {
                    const count = data[severity] ?? 0;
                    const percent = (count / total) * 100;
                    if (percent === 0) return null;
                    return (
                        <Tooltip key={severity}>
                            <TooltipTrigger asChild>
                                <div
                                    className={`${severityColors[severity]} transition-all`}
                                    style={{ width: `${percent}%` }}
                                />
                            </TooltipTrigger>
                            <TooltipContent>
                                <p className="font-medium">{severityLabels[severity]}</p>
                                <p className="text-xs">{count} eventos ({percent.toFixed(1)}%)</p>
                            </TooltipContent>
                        </Tooltip>
                    );
                })}
            </div>
            <div className="flex justify-between text-xs text-muted-foreground">
                {(['critical', 'warning', 'info'] as const).map((severity) => (
                    <div key={severity} className="flex items-center gap-1.5">
                        <div className={`size-2 rounded-full ${severityColors[severity]}`} />
                        <span>{severityLabels[severity]}: {data[severity] ?? 0}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function TopList({
    items,
    icon: Icon,
    emptyMessage = 'Sin datos',
}: {
    items: Array<{ name: string; count: number }>;
    icon: React.ElementType;
    emptyMessage?: string;
}) {
    if (items.length === 0) {
        return (
            <div className="flex items-center justify-center h-32 text-muted-foreground text-sm">
                {emptyMessage}
            </div>
        );
    }

    const maxCount = getMaxValue(items);

    return (
        <div className="space-y-2">
            {items.slice(0, 5).map((item, idx) => (
                <div key={idx} className="flex items-center gap-3">
                    <div className="flex items-center justify-center size-6 rounded-full bg-muted text-xs font-medium">
                        {idx + 1}
                    </div>
                    <Icon className="size-4 text-muted-foreground flex-shrink-0" />
                    <div className="flex-1 min-w-0">
                        <p className="text-sm font-medium truncate" title={item.name}>
                            {item.name}
                        </p>
                        <div className="h-1.5 bg-muted rounded-full overflow-hidden mt-1">
                            <div
                                className="h-full bg-primary rounded-full"
                                style={{ width: `${(item.count / maxCount) * 100}%` }}
                            />
                        </div>
                    </div>
                    <Badge variant="secondary" className="flex-shrink-0">
                        {item.count}
                    </Badge>
                </div>
            ))}
        </div>
    );
}

// ============================================================================
// MAIN COMPONENT
// ============================================================================

export function EventAnalyticsDashboard({ className }: EventAnalyticsDashboardProps) {
    const [data, setData] = useState<AnalyticsData | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [periodDays, setPeriodDays] = useState<string>('30');

    const fetchAnalytics = useCallback(async () => {
        setLoading(true);
        setError(null);

        try {
            const response = await fetch(`/api/events/analytics?days=${periodDays}`, {
                headers: {
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`Error ${response.status}: ${response.statusText}`);
            }

            const json = await response.json();
            setData(json);
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Error al cargar analytics');
        } finally {
            setLoading(false);
        }
    }, [periodDays]);

    useEffect(() => {
        fetchAnalytics();
    }, [fetchAnalytics]);

    if (loading && !data) {
        return (
            <div className={`flex items-center justify-center p-12 ${className}`}>
                <Loader2 className="size-8 animate-spin text-muted-foreground" />
            </div>
        );
    }

    if (error && !data) {
        return (
            <div className={`flex flex-col items-center justify-center gap-4 p-12 ${className}`}>
                <XCircle className="size-12 text-red-500" />
                <p className="text-muted-foreground">{error}</p>
                <Button variant="outline" onClick={fetchAnalytics}>
                    <RefreshCcw className="size-4 mr-2" />
                    Reintentar
                </Button>
            </div>
        );
    }

    if (!data) return null;

    return (
        <div className={`space-y-4 ${className}`}>
            {/* Header */}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="text-lg font-semibold flex items-center gap-2">
                        <BarChart3 className="size-5" />
                        Analytics de Eventos
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Análisis de patrones y tendencias
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <Select value={periodDays} onValueChange={setPeriodDays}>
                        <SelectTrigger className="w-[140px]">
                            <Calendar className="size-4 mr-2" />
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
                    <Button
                        variant="outline"
                        size="icon"
                        onClick={fetchAnalytics}
                        disabled={loading}
                    >
                        <RefreshCcw className={`size-4 ${loading ? 'animate-spin' : ''}`} />
                    </Button>
                </div>
            </div>

            {/* Summary Stats */}
            <div className="grid gap-3 grid-cols-2 lg:grid-cols-4">
                <StatCard
                    icon={BarChart3}
                    label="Total Eventos"
                    value={data.summary.total_events}
                    subValue={`últimos ${data.period_days} días`}
                />
                <StatCard
                    icon={ShieldAlert}
                    label="Alertas Reales"
                    value={data.summary.real_alerts}
                    subValue={`${data.summary.real_alert_rate}% del total`}
                    accent="bg-red-500/10"
                />
                <StatCard
                    icon={CheckCircle2}
                    label="Falsos Positivos"
                    value={data.summary.false_positives}
                    subValue={`${data.summary.false_positive_rate}% del total`}
                    accent="bg-emerald-500/10"
                />
                <StatCard
                    icon={AlertTriangle}
                    label="Promedio Diario"
                    value={
                        data.period_days > 0
                            ? Math.round(data.summary.total_events / data.period_days)
                            : 0
                    }
                    subValue="eventos por día"
                />
            </div>

            {/* Charts Grid */}
            <div className="grid gap-4 lg:grid-cols-2">
                {/* Events by Type */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">Eventos por Tipo</CardTitle>
                        <CardDescription>Tipos de eventos más frecuentes</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <HorizontalBarChart
                            items={data.events_by_type.slice(0, 6)}
                            maxValue={getMaxValue(data.events_by_type)}
                            colorClass="bg-primary"
                        />
                    </CardContent>
                </Card>

                {/* Severity Distribution */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">Distribución por Severidad</CardTitle>
                        <CardDescription>Proporción de eventos por nivel de severidad</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <SeverityDistribution data={data.events_by_severity} />
                    </CardContent>
                </Card>

                {/* Top Vehicles */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base flex items-center gap-2">
                            <Truck className="size-4" />
                            Top Vehículos
                        </CardTitle>
                        <CardDescription>Vehículos con más eventos registrados</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <TopList
                            items={data.top_vehicles}
                            icon={Truck}
                            emptyMessage="Sin datos de vehículos"
                        />
                    </CardContent>
                </Card>

                {/* Top Drivers */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base flex items-center gap-2">
                            <User className="size-4" />
                            Top Conductores
                        </CardTitle>
                        <CardDescription>Conductores con más eventos registrados</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <TopList
                            items={data.top_drivers}
                            icon={User}
                            emptyMessage="Sin datos de conductores"
                        />
                    </CardContent>
                </Card>

                {/* Daily Trend */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">Tendencia Diaria</CardTitle>
                        <CardDescription>Evolución de eventos por día</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <DailyTrendChart data={data.events_by_day} />
                        <div className="flex justify-between text-xs text-muted-foreground mt-2">
                            <span>{data.events_by_day[0]?.date ?? ''}</span>
                            <span>{data.events_by_day[data.events_by_day.length - 1]?.date ?? ''}</span>
                        </div>
                    </CardContent>
                </Card>

                {/* Hourly Pattern */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base flex items-center gap-2">
                            <Clock className="size-4" />
                            Patrón por Hora
                        </CardTitle>
                        <CardDescription>Distribución de eventos durante el día</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <HourlyChart data={data.events_by_hour} />
                        <div className="flex justify-between text-xs text-muted-foreground mt-2">
                            <span>12 AM</span>
                            <span>6 AM</span>
                            <span>12 PM</span>
                            <span>6 PM</span>
                            <span>12 AM</span>
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* Verdicts Summary */}
            {data.events_by_verdict.length > 0 && (
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">Resultados del Análisis AI</CardTitle>
                        <CardDescription>Distribución de veredictos del pipeline de AI</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            {data.events_by_verdict.slice(0, 8).map((item, idx) => (
                                <div
                                    key={idx}
                                    className="flex items-center justify-between rounded-lg border p-3"
                                >
                                    <span className="text-sm text-muted-foreground truncate" title={item.label}>
                                        {item.label}
                                    </span>
                                    <Badge variant="secondary">{item.count}</Badge>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            )}
        </div>
    );
}
