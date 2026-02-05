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
    Activity,
    AlertTriangle,
    BarChart3,
    Brain,
    Calendar,
    Car,
    CheckCircle2,
    Clock,
    Lightbulb,
    Loader2,
    RefreshCcw,
    Shield,
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

interface BasicAnalyticsData {
    period_days: number;
    period_start: string;
    summary: {
        total_signals: number;
        critical: number;
        critical_rate: number;
        needs_review: number;
        coached: number;
        coached_rate: number;
        dismissed: number;
        linked_to_incidents: number;
        unique_drivers: number;
        unique_vehicles: number;
        avg_daily: number;
    };
    signals_by_behavior: Array<{ label: string; label_translated: string; value: number }>;
    top_vehicles: Array<{ id: string; name: string; count: number }>;
    top_drivers: Array<{ id: string; name: string; count: number }>;
    signals_by_severity: Record<string, number>;
    signals_by_state: Array<{ state: string; label: string; count: number }>;
    signals_by_day: Array<{ date: string; count: number }>;
    signals_by_hour: Array<{ hour: number; count: number }>;
    signals_by_day_of_week: Array<{ day: number; day_name: string; count: number }>;
    avg_acceleration_by_behavior: Array<{
        behavior: string;
        behavior_translated: string;
        avg_g: number;
        max_g: number;
        count: number;
    }>;
}

interface AdvancedAnalyticsData {
    patterns?: {
        behavior_correlations: Array<{
            behavior_a: string;
            behavior_b: string;
            correlation: number;
            co_occurrence_count: number;
            description: string;
        }>;
        temporal_hotspots: Array<{
            type: string;
            value: string;
            signal_count: number;
            severity_breakdown: Record<string, number>;
            risk_level: string;
            description: string;
        }>;
        escalation_patterns: Array<{
            driver_id: string;
            driver_name?: string;
            warning_count: number;
            critical_count: number;
            escalation_rate: number;
            trend: string;
            description: string;
        }>;
    };
    driver_risk?: {
        drivers: Array<{
            driver_id: string;
            driver_name?: string;
            risk_score: number;
            risk_level: string;
            signal_count: number;
            critical_count: number;
            warning_count: number;
            top_behaviors: string[];
            trend: string;
            trend_delta: number;
        }>;
        fleet_avg_score: number;
        high_risk_count: number;
    };
    predictions?: {
        at_risk_drivers: Array<{
            driver_id: string;
            driver_name?: string;
            current_risk_score: number;
            predicted_risk_7d: number;
            incident_probability: number;
            confidence: number;
            warning_signals: string[];
            recommendation: string;
        }>;
        volume_forecast: {
            current_avg_daily: number;
            predicted_avg_daily: number;
            trend: string;
            confidence: number;
        };
        alerts: string[];
    };
    insights?: {
        insights: Array<{
            id: string;
            category: string;
            priority: string;
            title: string;
            description: string;
            data_points: string[];
            action_items: string[];
        }>;
    };
    processing_time_ms?: number;
}

interface SignalAnalyticsDashboardProps {
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
    info: 'Información',
};

const riskLevelColors: Record<string, string> = {
    critical: 'text-red-600 bg-red-100 dark:bg-red-500/20 dark:text-red-300',
    high: 'text-orange-600 bg-orange-100 dark:bg-orange-500/20 dark:text-orange-300',
    medium: 'text-amber-600 bg-amber-100 dark:bg-amber-500/20 dark:text-amber-300',
    low: 'text-emerald-600 bg-emerald-100 dark:bg-emerald-500/20 dark:text-emerald-300',
};

const priorityColors: Record<string, string> = {
    high: 'border-l-red-500 bg-red-500/5',
    medium: 'border-l-amber-500 bg-amber-500/5',
    low: 'border-l-blue-500 bg-blue-500/5',
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
                        <p className="text-xs text-muted-foreground">{item.count} señales</p>
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
                        <p className="text-xs text-muted-foreground">{item.count} señales</p>
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
                                <p className="text-xs">{count} señales ({percent.toFixed(1)}%)</p>
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

function DriverRiskSection({ data }: { data: NonNullable<AdvancedAnalyticsData['driver_risk']> }) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-base flex items-center gap-2">
                    <Target className="size-4" />
                    Conductores de Alto Riesgo
                </CardTitle>
                <CardDescription>
                    Score 0-100 basado en frecuencia, severidad y tendencia
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div className="mb-4 flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">Promedio de flota:</span>
                    <Badge variant="outline">{data.fleet_avg_score.toFixed(0)}</Badge>
                </div>
                <div className="space-y-3">
                    {data.drivers.slice(0, 5).map((driver, idx) => (
                        <div key={driver.driver_id} className="flex items-center gap-3">
                            <div className="flex items-center justify-center size-6 rounded-full bg-muted text-xs font-medium">
                                {idx + 1}
                            </div>
                            <div className="flex-1 min-w-0">
                                <div className="flex items-center justify-between">
                                    <p className="text-sm font-medium truncate">
                                        {driver.driver_name || driver.driver_id}
                                    </p>
                                    <Badge className={riskLevelColors[driver.risk_level]}>
                                        {driver.risk_score.toFixed(0)}
                                    </Badge>
                                </div>
                                <div className="flex items-center gap-2 mt-1 text-xs text-muted-foreground">
                                    <span>{driver.signal_count} señales</span>
                                    <span>•</span>
                                    <span className={driver.critical_count > 0 ? 'text-red-500' : ''}>
                                        {driver.critical_count} críticos
                                    </span>
                                    <span>•</span>
                                    <span className={
                                        driver.trend === 'worsening' ? 'text-red-500' :
                                        driver.trend === 'improving' ? 'text-emerald-500' : ''
                                    }>
                                        {driver.trend === 'worsening' ? 'Empeorando' :
                                         driver.trend === 'improving' ? 'Mejorando' : 'Estable'}
                                    </span>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}

function PatternsSection({ data }: { data: NonNullable<AdvancedAnalyticsData['patterns']> }) {
    const hasData = data.behavior_correlations.length > 0 || 
                    data.temporal_hotspots.length > 0 || 
                    data.escalation_patterns.length > 0;

    if (!hasData) {
        return null;
    }

    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-base flex items-center gap-2">
                    <Zap className="size-4" />
                    Patrones Detectados
                </CardTitle>
                <CardDescription>
                    Correlaciones, hotspots y escalaciones identificadas
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {/* Temporal Hotspots */}
                {data.temporal_hotspots.length > 0 && (
                    <div>
                        <h4 className="text-sm font-medium mb-2">Hotspots Temporales</h4>
                        <div className="space-y-2">
                            {data.temporal_hotspots.slice(0, 3).map((hotspot, idx) => (
                                <div key={idx} className="flex items-center justify-between p-2 rounded-lg bg-muted/50">
                                    <div className="flex items-center gap-2">
                                        <Clock className="size-4 text-muted-foreground" />
                                        <span className="text-sm font-medium">{hotspot.value}</span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm text-muted-foreground">{hotspot.signal_count} señales</span>
                                        <Badge variant="outline" className={
                                            hotspot.risk_level === 'high' ? 'border-red-500 text-red-500' :
                                            hotspot.risk_level === 'medium' ? 'border-amber-500 text-amber-500' :
                                            'border-emerald-500 text-emerald-500'
                                        }>
                                            {hotspot.risk_level === 'high' ? 'Alto' :
                                             hotspot.risk_level === 'medium' ? 'Medio' : 'Bajo'}
                                        </Badge>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Behavior Correlations */}
                {data.behavior_correlations.length > 0 && (
                    <div>
                        <h4 className="text-sm font-medium mb-2">Correlaciones de Comportamiento</h4>
                        <div className="space-y-2">
                            {data.behavior_correlations.slice(0, 3).map((corr, idx) => (
                                <div key={idx} className="p-2 rounded-lg bg-muted/50">
                                    <div className="flex items-center gap-2 text-sm">
                                        <Badge variant="secondary">{corr.behavior_a}</Badge>
                                        <span className="text-muted-foreground">↔</span>
                                        <Badge variant="secondary">{corr.behavior_b}</Badge>
                                        <span className="ml-auto text-muted-foreground">
                                            r={corr.correlation.toFixed(2)}
                                        </span>
                                    </div>
                                    <p className="text-xs text-muted-foreground mt-1">{corr.description}</p>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Escalation Patterns */}
                {data.escalation_patterns.length > 0 && (
                    <div>
                        <h4 className="text-sm font-medium mb-2">Patrones de Escalación</h4>
                        <div className="space-y-2">
                            {data.escalation_patterns.slice(0, 3).map((pattern, idx) => (
                                <div key={idx} className="p-2 rounded-lg bg-muted/50">
                                    <div className="flex items-center justify-between">
                                        <span className="text-sm font-medium">
                                            {pattern.driver_name || pattern.driver_id}
                                        </span>
                                        <Badge variant="outline" className={
                                            pattern.trend === 'worsening' ? 'border-red-500 text-red-500' :
                                            pattern.trend === 'improving' ? 'border-emerald-500 text-emerald-500' : ''
                                        }>
                                            {(pattern.escalation_rate * 100).toFixed(0)}% críticos
                                        </Badge>
                                    </div>
                                    <p className="text-xs text-muted-foreground mt-1">{pattern.description}</p>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function PredictionsSection({ data }: { data: NonNullable<AdvancedAnalyticsData['predictions']> }) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-base flex items-center gap-2">
                    <Brain className="size-4" />
                    Predicciones
                </CardTitle>
                <CardDescription>
                    Pronóstico de riesgo basado en tendencias históricas
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {/* Volume Forecast */}
                <div className="p-3 rounded-lg bg-muted/50">
                    <div className="flex items-center justify-between mb-2">
                        <span className="text-sm font-medium">Pronóstico de Volumen</span>
                        <Badge variant="outline">
                            {(data.volume_forecast.confidence * 100).toFixed(0)}% confianza
                        </Badge>
                    </div>
                    <div className="flex items-center gap-4">
                        <div className="text-center">
                            <p className="text-2xl font-bold">{data.volume_forecast.current_avg_daily.toFixed(1)}</p>
                            <p className="text-xs text-muted-foreground">Actual/día</p>
                        </div>
                        <div className={`text-xl ${
                            data.volume_forecast.trend === 'increasing' ? 'text-red-500' :
                            data.volume_forecast.trend === 'decreasing' ? 'text-emerald-500' : 'text-muted-foreground'
                        }`}>
                            →
                        </div>
                        <div className="text-center">
                            <p className="text-2xl font-bold">{data.volume_forecast.predicted_avg_daily.toFixed(1)}</p>
                            <p className="text-xs text-muted-foreground">Predicho/día</p>
                        </div>
                    </div>
                </div>

                {/* At Risk Drivers */}
                {data.at_risk_drivers.length > 0 && (
                    <div>
                        <h4 className="text-sm font-medium mb-2">Conductores en Riesgo de Incidente</h4>
                        <div className="space-y-2">
                            {data.at_risk_drivers.slice(0, 3).map((driver, idx) => (
                                <div key={idx} className="p-2 rounded-lg border border-red-200 bg-red-50 dark:bg-red-950/20 dark:border-red-900">
                                    <div className="flex items-center justify-between">
                                        <span className="text-sm font-medium">
                                            {driver.driver_name || driver.driver_id}
                                        </span>
                                        <Badge className="bg-red-500">
                                            {(driver.incident_probability * 100).toFixed(0)}% prob.
                                        </Badge>
                                    </div>
                                    <p className="text-xs text-muted-foreground mt-1">{driver.recommendation}</p>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* System Alerts */}
                {data.alerts.length > 0 && (
                    <div className="space-y-2">
                        {data.alerts.map((alert, idx) => (
                            <div key={idx} className="flex items-start gap-2 p-2 rounded-lg bg-amber-50 dark:bg-amber-950/20">
                                <AlertTriangle className="size-4 text-amber-500 mt-0.5 shrink-0" />
                                <span className="text-sm">{alert}</span>
                            </div>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function AIInsightsSection({ data }: { data: NonNullable<AdvancedAnalyticsData['insights']> }) {
    if (!data.insights || data.insights.length === 0) {
        return null;
    }

    return (
        <Card className="lg:col-span-2">
            <CardHeader className="pb-2">
                <CardTitle className="text-base flex items-center gap-2">
                    <Lightbulb className="size-4" />
                    Insights de AI
                </CardTitle>
                <CardDescription>
                    Observaciones y recomendaciones generadas automáticamente
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div className="grid gap-3 md:grid-cols-2">
                    {data.insights.map((insight) => (
                        <div
                            key={insight.id}
                            className={`p-4 rounded-lg border-l-4 ${priorityColors[insight.priority]}`}
                        >
                            <div className="flex items-start justify-between gap-2 mb-2">
                                <h4 className="font-medium text-sm">{insight.title}</h4>
                                <Badge variant="outline" className="shrink-0 text-xs">
                                    {insight.category === 'pattern' ? 'Patrón' :
                                     insight.category === 'risk' ? 'Riesgo' :
                                     insight.category === 'prediction' ? 'Predicción' : 'Recomendación'}
                                </Badge>
                            </div>
                            <p className="text-sm text-muted-foreground mb-2">{insight.description}</p>
                            {insight.data_points.length > 0 && (
                                <div className="flex flex-wrap gap-1 mb-2">
                                    {insight.data_points.map((point, idx) => (
                                        <Badge key={idx} variant="secondary" className="text-xs">
                                            {point}
                                        </Badge>
                                    ))}
                                </div>
                            )}
                            {insight.action_items.length > 0 && (
                                <ul className="text-xs text-muted-foreground space-y-1">
                                    {insight.action_items.map((action, idx) => (
                                        <li key={idx} className="flex items-center gap-1">
                                            <CheckCircle2 className="size-3 text-emerald-500" />
                                            {action}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}

// ============================================================================
// MAIN COMPONENT
// ============================================================================

export function SignalAnalyticsDashboard({ className }: SignalAnalyticsDashboardProps) {
    const [basicData, setBasicData] = useState<BasicAnalyticsData | null>(null);
    const [advancedData, setAdvancedData] = useState<AdvancedAnalyticsData | null>(null);
    const [loading, setLoading] = useState(true);
    const [advancedLoading, setAdvancedLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [periodDays, setPeriodDays] = useState<string>('30');

    const fetchBasicAnalytics = useCallback(async () => {
        setLoading(true);
        setError(null);

        try {
            const response = await fetch(`/api/safety-signals/analytics?days=${periodDays}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`Error ${response.status}: ${response.statusText}`);
            }

            const json = await response.json();
            setBasicData(json);
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Error al cargar analytics');
        } finally {
            setLoading(false);
        }
    }, [periodDays]);

    const fetchAdvancedAnalytics = useCallback(async () => {
        if (!basicData) return;

        setAdvancedLoading(true);

        try {
            const response = await fetch(`/api/safety-signals/analytics/advanced?days=${periodDays}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`Error ${response.status}`);
            }

            const json = await response.json();
            
            // Check if we got valid data or just errors
            if (json.errors && json.errors.length > 0 && !json.patterns && !json.driver_risk) {
                console.warn('Advanced analytics returned with errors:', json.errors);
                setAdvancedData(null);
            } else {
                setAdvancedData(json);
            }
        } catch (err) {
            console.error('Advanced analytics error:', err);
            setAdvancedData(null);
        } finally {
            setAdvancedLoading(false);
        }
    }, [basicData, periodDays]);

    useEffect(() => {
        fetchBasicAnalytics();
    }, [fetchBasicAnalytics]);

    useEffect(() => {
        if (basicData) {
            fetchAdvancedAnalytics();
        }
    }, [basicData, fetchAdvancedAnalytics]);

    if (loading && !basicData) {
        return (
            <div className={`flex items-center justify-center p-12 ${className}`}>
                <Loader2 className="size-8 animate-spin text-muted-foreground" />
            </div>
        );
    }

    if (error && !basicData) {
        return (
            <div className={`flex flex-col items-center justify-center gap-4 p-12 ${className}`}>
                <XCircle className="size-12 text-red-500" />
                <p className="text-muted-foreground">{error}</p>
                <Button variant="outline" onClick={fetchBasicAnalytics}>
                    <RefreshCcw className="size-4 mr-2" />
                    Reintentar
                </Button>
            </div>
        );
    }

    if (!basicData) return null;

    return (
        <div className={`space-y-4 ${className}`}>
            {/* Header */}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="text-lg font-semibold flex items-center gap-2">
                        <BarChart3 className="size-5" />
                        Analytics de Señales
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Análisis de patrones, riesgos y predicciones
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
                        onClick={fetchBasicAnalytics}
                        disabled={loading}
                    >
                        <RefreshCcw className={`size-4 ${loading ? 'animate-spin' : ''}`} />
                    </Button>
                </div>
            </div>

            {/* Summary Stats */}
            <div className="grid gap-3 grid-cols-2 lg:grid-cols-5">
                <StatCard
                    icon={Activity}
                    label="Total Señales"
                    value={basicData.summary.total_signals}
                    subValue={`${basicData.summary.avg_daily}/día promedio`}
                />
                <StatCard
                    icon={AlertTriangle}
                    label="Críticas"
                    value={basicData.summary.critical}
                    subValue={`${basicData.summary.critical_rate}% del total`}
                    accent="bg-red-500/10"
                />
                <StatCard
                    icon={CheckCircle2}
                    label="Coacheados"
                    value={basicData.summary.coached}
                    subValue={`${basicData.summary.coached_rate}% completados`}
                    accent="bg-emerald-500/10"
                />
                <StatCard
                    icon={Users}
                    label="Conductores"
                    value={basicData.summary.unique_drivers}
                    subValue="únicos en el período"
                />
                <StatCard
                    icon={Car}
                    label="Vehículos"
                    value={basicData.summary.unique_vehicles}
                    subValue="únicos en el período"
                />
            </div>

            {/* Charts Grid */}
            <div className="grid gap-4 lg:grid-cols-2">
                {/* Signals by Behavior */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">Señales por Comportamiento</CardTitle>
                        <CardDescription>Comportamientos más frecuentes</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <HorizontalBarChart
                            items={basicData.signals_by_behavior.slice(0, 6).map((b) => ({
                                label: b.label_translated || b.label,
                                value: b.value,
                            }))}
                            maxValue={getMaxValue(basicData.signals_by_behavior)}
                            colorClass="bg-primary"
                        />
                    </CardContent>
                </Card>

                {/* Severity Distribution */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">Distribución por Severidad</CardTitle>
                        <CardDescription>Proporción de señales por nivel</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <SeverityDistribution data={basicData.signals_by_severity} />
                    </CardContent>
                </Card>

                {/* Top Vehicles */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base flex items-center gap-2">
                            <Truck className="size-4" />
                            Top Vehículos
                        </CardTitle>
                        <CardDescription>Vehículos con más señales</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <TopList
                            items={basicData.top_vehicles}
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
                        <CardDescription>Conductores con más señales</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <TopList
                            items={basicData.top_drivers}
                            icon={User}
                            emptyMessage="Sin datos de conductores"
                        />
                    </CardContent>
                </Card>

                {/* Daily Trend */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">Tendencia Diaria</CardTitle>
                        <CardDescription>Evolución de señales por día</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <DailyTrendChart data={basicData.signals_by_day} />
                        <div className="flex justify-between text-xs text-muted-foreground mt-2">
                            <span>{basicData.signals_by_day[0]?.date ?? ''}</span>
                            <span>{basicData.signals_by_day[basicData.signals_by_day.length - 1]?.date ?? ''}</span>
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
                        <CardDescription>Distribución de señales durante el día</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <HourlyChart data={basicData.signals_by_hour} />
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

            {/* Coaching Funnel */}
            {basicData.signals_by_state.length > 0 && (
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base flex items-center gap-2">
                            <Shield className="size-4" />
                            Funnel de Coaching
                        </CardTitle>
                        <CardDescription>Estado de revisión de señales</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            {basicData.signals_by_state.map((state) => (
                                <div
                                    key={state.state}
                                    className="flex items-center justify-between rounded-lg border p-3"
                                >
                                    <span className="text-sm text-muted-foreground">{state.label}</span>
                                    <Badge variant="secondary">{state.count}</Badge>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            )}

            {/* Advanced Analytics Section */}
            {advancedLoading && (
                <div className="flex items-center justify-center p-8 text-muted-foreground">
                    <Loader2 className="size-6 animate-spin mr-2" />
                    <span>Cargando análisis avanzado...</span>
                </div>
            )}

            {advancedData && (
                <div className="grid gap-4 lg:grid-cols-2">
                    {/* Driver Risk */}
                    {advancedData.driver_risk && (
                        <DriverRiskSection data={advancedData.driver_risk} />
                    )}

                    {/* Patterns */}
                    {advancedData.patterns && (
                        <PatternsSection data={advancedData.patterns} />
                    )}

                    {/* Predictions */}
                    {advancedData.predictions && (
                        <PredictionsSection data={advancedData.predictions} />
                    )}

                    {/* AI Insights */}
                    {advancedData.insights && (
                        <AIInsightsSection data={advancedData.insights} />
                    )}
                </div>
            )}
        </div>
    );
}
