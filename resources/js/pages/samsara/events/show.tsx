import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Disclosure } from '@headlessui/react';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    ArrowLeft,
    Bell,
    Calendar,
    Camera,
    CheckCircle2,
    ChevronDown,
    Clock,
    Loader2,
    MapPin,
    Radar,
    Search,
    ShieldAlert,
    Sparkles,
    Truck,
    User,
} from 'lucide-react';
import { type LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

interface ToolUsage {
    tool_name?: string;
    status_label?: string;
    called_at?: string;
    duration_ms?: number;
    result_summary?: string;
    details?: {
        images_analyzed?: number;
        analyses?: {
            camera?: string;
            analysis?: string;
            analysis_preview?: string;
        }[];
    };
}

interface TimelineStep {
    step: number;
    name: string;
    title: string;
    description: string;
    started_at?: string | null;
    completed_at?: string | null;
    duration_ms?: number | null;
    summary: string;
    summary_details?: { label: string; value: string }[];
    tools_used: ToolUsage[];
}

interface PayloadSummaryItem {
    label: string;
    value: string;
}

interface AssessmentEvidenceItem {
    label: string;
    value: string;
}

interface AssessmentView {
    verdict?: string | null;
    likelihood?: string | null;
    reasoning?: string | null;
    evidence?: AssessmentEvidenceItem[];
}

interface SamsaraEventPayload {
    id: number;
    event_type?: string | null;
    display_event_type?: string | null;
    severity: string;
    severity_label?: string | null;
    ai_status: string;
    ai_status_label?: string | null;
    vehicle_name?: string | null;
    driver_name?: string | null;
    occurred_at?: string | null;
    ai_assessment_view?: AssessmentView | null;
    ai_message?: string | null;
    ai_actions: {
        agents: TimelineStep[];
        total_duration_ms: number;
        total_tools_called: number;
    };
    payload_summary: PayloadSummaryItem[];
    timeline: TimelineStep[];
    media_insights: {
        camera?: string;
        analysis?: string;
        analysis_preview?: string;
    }[];
    event_icon?: string | null;
    investigation_actions?: {
        label: string;
        icon: string;
        items: {
            name: string;
            summary: string;
            details?: any;
        }[];
    }[];
    investigation_metadata?: {
        count: number;
        last_check?: string | null;
        next_check_minutes?: number | null;
        history: {
            timestamp: string;
            reason: string;
            count: number;
        }[];
        max_investigations: number;
    };
    verdict_badge?: {
        verdict: string;
        likelihood?: string | null;
        urgency: 'high' | 'medium' | 'low' | 'unknown';
        color: string;
    };
}

interface ShowProps {
    event: SamsaraEventPayload;
    breadcrumbs?: BreadcrumbItem[];
}

const ALERTS_INDEX_URL = '/samsara/alerts';
const getAlertShowUrl = (id: number) => `/samsara/alerts/${id}`;

const severityPalette: Record<string, string> = {
    info: 'bg-blue-500/10 text-blue-500',
    warning: 'bg-amber-500/10 text-amber-500',
    critical: 'bg-red-500/15 text-red-500 font-semibold',
};

const statusPalette: Record<string, string> = {
    pending: 'bg-slate-500/10 text-slate-500',
    processing: 'bg-sky-500/10 text-sky-500',
    investigating: 'bg-amber-500/10 text-amber-500',
    completed: 'bg-emerald-500/15 text-emerald-400',
    failed: 'bg-rose-500/15 text-rose-400',
};

export default function SamsaraAlertShow({ event, breadcrumbs }: ShowProps) {
    const [isPolling, setIsPolling] = useState(false);
    const [previousStatus, setPreviousStatus] = useState(event.ai_status);
    const [simulatedTools, setSimulatedTools] = useState<string[]>([]);

    const eventLabel =
        event.display_event_type ?? 'Alerta procesada por AI';

    const computedBreadcrumbs: BreadcrumbItem[] =
        breadcrumbs && breadcrumbs.length > 0
            ? breadcrumbs
            : [
                {
                    title: 'Alertas Samsara',
                    href: ALERTS_INDEX_URL,
                },
                {
                    title: eventLabel,
                    href: getAlertShowUrl(event.id),
                },
            ];

    // Polling para eventos en processing o investigating
    useEffect(() => {
        const shouldPoll = event.ai_status === 'processing' || event.ai_status === 'investigating';

        if (shouldPoll) {
            setIsPolling(true);

            // Simular herramientas siendo usadas
            const tools = [
                'Obteniendo estadísticas del vehículo...',
                'Consultando información del vehículo...',
                'Identificando conductor asignado...',
                'Analizando imágenes de cámaras con IA...',
                'Generando veredicto final...'
            ];

            let currentToolIndex = 0;
            const toolInterval = setInterval(() => {
                if (currentToolIndex < tools.length) {
                    setSimulatedTools(prev => [...prev, tools[currentToolIndex]]);
                    currentToolIndex++;
                } else {
                    clearInterval(toolInterval);
                }
            }, 5000); // Cada 5 segundos muestra una nueva herramienta

            // Polling cada 3 segundos
            const pollingInterval = setInterval(() => {
                router.reload({
                    only: ['event'],
                });
            }, 3000);

            return () => {
                clearInterval(pollingInterval);
                clearInterval(toolInterval);
            };
        } else {
            setIsPolling(false);

            // Detectar si cambió de processing/investigating a completed
            if ((previousStatus === 'processing' || previousStatus === 'investigating') &&
                event.ai_status === 'completed') {
                // Mostrar notificación de completado
                if (typeof window !== 'undefined' && 'Notification' in window) {
                    if (Notification.permission === 'granted') {
                        new Notification('Análisis completado', {
                            body: `El evento "${eventLabel}" ha sido procesado por la AI`,
                            icon: '/favicon.ico'
                        });
                    }
                }
            }

            setPreviousStatus(event.ai_status);
        }
    }, [event.ai_status, event.id, previousStatus, eventLabel]);

    const summaryChips = useMemo(
        () => [
            {
                label: 'Severidad',
                value: event.severity_label ?? 'Informativa',
                className: severityPalette[event.severity] ?? severityPalette.info,
            },
            {
                label: 'Estado AI',
                value: event.ai_status_label ?? 'Pendiente',
                className: statusPalette[event.ai_status] ?? statusPalette.pending,
            },
            {
                label: 'Tipo',
                value: event.display_event_type ?? 'No especificado',
                className: 'bg-muted text-muted-foreground',
            },
        ],
        [event],
    );

    const formatFullDate = (value?: string | null) => {
        if (!value) return 'Sin registro';
        return new Intl.DateTimeFormat('es-MX', {
            dateStyle: 'full',
            timeStyle: 'short',
        }).format(new Date(value));
    };

    const formatDateTime = (value?: string | null) => {
        if (!value) return 'Sin determinar';
        return new Intl.DateTimeFormat('es-MX', {
            dateStyle: 'long',
            timeStyle: 'medium',
        }).format(new Date(value));
    };

    const formatDuration = (value?: number | null) => {
        if (!value) return '—';
        if (value < 1000) {
            return `${value} ms`;
        }
        return `${(value / 1000).toFixed(1)} s`;
    };

    const getEventIcon = (iconName?: string | null) => {
        switch (iconName) {
            case 'alert-circle':
                return AlertCircle;
            case 'alert-triangle':
                return AlertTriangle;
            case 'shield-alert':
                return ShieldAlert;
            default:
                return Bell;
        }
    };

    const getCategoryIcon = (iconName?: string) => {
        switch (iconName) {
            case 'truck':
                return Truck;
            case 'user':
                return User;
            case 'camera':
                return Camera;
            default:
                return CheckCircle2;
        }
    };

    const getVerdictStyles = (color?: string) => {
        switch (color) {
            case 'red':
                return 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-200 border-red-300 dark:border-red-500/30';
            case 'amber':
                return 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200 border-amber-300 dark:border-amber-500/30';
            case 'emerald':
                return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200 border-emerald-300 dark:border-emerald-500/30';
            default:
                return 'bg-slate-100 text-slate-800 dark:bg-slate-500/20 dark:text-slate-200';
        }
    };

    const getPayloadIcon = (label: string) => {
        const normalized = label.toLowerCase();
        if (normalized.includes('ubicación') || normalized.includes('location')) {
            return MapPin;
        }
        if (normalized.includes('alerta') || normalized.includes('tipo')) {
            return Bell;
        }
        if (normalized.includes('hora') || normalized.includes('evento') || normalized.includes('time')) {
            return Clock;
        }
        if (normalized.includes('cámara') || normalized.includes('camera')) {
            return Camera;
        }
        return null;
    };

    return (
        <AppLayout breadcrumbs={computedBreadcrumbs}>
            <Head title={`Detalle ${eventLabel}`} />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div className="flex items-start gap-3">
                        {(() => {
                            const Icon = getEventIcon(event.event_icon);
                            return (
                                <div className="rounded-full bg-primary/10 p-3 text-primary">
                                    <Icon className="size-6" />
                                </div>
                            );
                        })()}
                        <div>
                            <p className="text-sm uppercase text-muted-foreground">
                                Panel de monitoreo AI
                            </p>
                            <h1 className="text-3xl font-semibold tracking-tight">
                                {eventLabel}
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                {event.ai_message ??
                                    'Seguimiento del pipeline de análisis para esta alerta.'}
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button variant="outline" asChild className="gap-2">
                            <Link href={ALERTS_INDEX_URL}>
                                <ArrowLeft className="size-4" />
                                Regresar al listado
                            </Link>
                        </Button>
                        {summaryChips.map((chip) => (
                            <Badge
                                key={chip.label}
                                className={`px-3 py-1 text-xs font-semibold ${chip.className}`}
                            >
                                {chip.value}
                            </Badge>
                        ))}
                    </div>
                </div>

                {(event.ai_status === 'processing' || event.ai_status === 'investigating') && isPolling && (
                    <Card className="border-2 border-sky-500/30 bg-sky-50/50 dark:bg-sky-950/20">
                        <CardHeader>
                            <div className="flex items-center gap-3">
                                <Loader2 className="size-6 animate-spin text-sky-600 dark:text-sky-400" />
                                <div className="flex-1">
                                    <CardTitle className="text-sky-900 dark:text-sky-100">
                                        {event.ai_status === 'processing' ? 'Procesando evento...' : 'Revalidando evento...'}
                                    </CardTitle>
                                    <CardDescription className="text-sky-700 dark:text-sky-300">
                                        La AI está analizando el evento. Esto tomará aproximadamente 25 segundos.
                                    </CardDescription>
                                </div>
                                <Badge className="bg-sky-500 text-white animate-pulse">
                                    En progreso
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-2">
                                <p className="text-xs font-semibold uppercase text-sky-700 dark:text-sky-400">
                                    Herramientas ejecutándose
                                </p>
                                <div className="space-y-2">
                                    {simulatedTools.map((tool, idx) => (
                                        <div
                                            key={idx}
                                            className="flex items-center gap-2 rounded-lg border border-sky-200 bg-white/50 p-2 text-sm dark:border-sky-800 dark:bg-sky-950/30"
                                        >
                                            <CheckCircle2 className="size-4 shrink-0 text-emerald-500" />
                                            <span className="text-sky-900 dark:text-sky-100">{tool}</span>
                                        </div>
                                    ))}
                                    {simulatedTools.length < 5 && (
                                        <div className="flex items-center gap-2 rounded-lg border border-sky-200 bg-white/50 p-2 text-sm dark:border-sky-800 dark:bg-sky-950/30">
                                            <Loader2 className="size-4 shrink-0 animate-spin text-sky-500" />
                                            <span className="text-sky-700 dark:text-sky-300">
                                                {simulatedTools.length === 0 && 'Iniciando análisis...'}
                                                {simulatedTools.length === 1 && 'Consultando información del vehículo...'}
                                                {simulatedTools.length === 2 && 'Identificando conductor asignado...'}
                                                {simulatedTools.length === 3 && 'Analizando imágenes de cámaras con IA...'}
                                                {simulatedTools.length === 4 && 'Generando veredicto final...'}
                                            </span>
                                        </div>
                                    )}
                                </div>
                                <div className="mt-3 rounded-lg border border-sky-200 bg-sky-100/50 p-2 dark:border-sky-900/30">
                                    <p className="text-xs text-sky-800 dark:text-sky-200">
                                        <strong>Actualizando automáticamente:</strong> Esta página se refrescará cada 3 segundos hasta que el análisis se complete.
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {event.verdict_badge && (
                    <div
                        className={`flex items-center gap-3 rounded-xl border-2 p-4 ${getVerdictStyles(event.verdict_badge.color)
                            }`}
                    >
                        <CheckCircle2 className="size-6 shrink-0" />
                        <div>
                            <p className="text-sm font-semibold uppercase opacity-75">
                                Veredicto de la AI
                            </p>
                            <p className="text-lg font-bold">
                                {event.verdict_badge.verdict}
                            </p>
                            {event.verdict_badge.likelihood && (
                                <p className="text-sm opacity-80">
                                    Probabilidad: {event.verdict_badge.likelihood}
                                </p>
                            )}
                        </div>
                    </div>
                )}

                {event.ai_status === 'investigating' && event.investigation_metadata && (
                    <Card className="border-2 border-amber-500/30 bg-amber-50/50 dark:bg-amber-950/20">
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <div className="rounded-full bg-amber-500/20 p-2">
                                        <Search className="size-5 text-amber-600 dark:text-amber-400" />
                                    </div>
                                    <div>
                                        <CardTitle className="text-amber-900 dark:text-amber-100">
                                            Evento bajo investigación
                                        </CardTitle>
                                        <CardDescription className="text-amber-700 dark:text-amber-300">
                                            La AI está monitoreando este evento para obtener más contexto
                                        </CardDescription>
                                    </div>
                                </div>
                                <Badge className="bg-amber-500 text-white">
                                    {event.investigation_metadata.count} de {event.investigation_metadata.max_investigations}
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-3 sm:grid-cols-2">
                                {event.investigation_metadata.last_check && (
                                    <div className="rounded-lg border border-amber-200 bg-white/50 p-3 dark:border-amber-800 dark:bg-amber-950/30">
                                        <p className="text-xs font-semibold uppercase text-amber-700 dark:text-amber-400">
                                            Última verificación
                                        </p>
                                        <p className="text-sm font-medium text-amber-900 dark:text-amber-100">
                                            {event.investigation_metadata.last_check}
                                        </p>
                                    </div>
                                )}
                                {event.investigation_metadata.next_check_minutes && (
                                    <div className="rounded-lg border border-amber-200 bg-white/50 p-3 dark:border-amber-800 dark:bg-amber-950/30">
                                        <p className="text-xs font-semibold uppercase text-amber-700 dark:text-amber-400">
                                            Próxima verificación
                                        </p>
                                        <p className="text-sm font-medium text-amber-900 dark:text-amber-100">
                                            En {event.investigation_metadata.next_check_minutes} minutos
                                        </p>
                                    </div>
                                )}
                            </div>

                            {event.investigation_metadata.history.length > 0 && (
                                <div className="rounded-lg border border-amber-200 bg-white/50 p-3 dark:border-amber-800 dark:bg-amber-950/30">
                                    <p className="mb-3 text-xs font-semibold uppercase text-amber-700 dark:text-amber-400">
                                        Historial de investigaciones
                                    </p>
                                    <div className="space-y-2">
                                        {event.investigation_metadata.history.map((entry, idx) => (
                                            <div
                                                key={idx}
                                                className="flex items-start gap-2 rounded border border-amber-100 bg-amber-50/50 p-2 text-xs dark:border-amber-900 dark:bg-amber-950/50"
                                            >
                                                <Badge variant="outline" className="shrink-0 border-amber-300 text-amber-700 dark:border-amber-700 dark:text-amber-300">
                                                    #{entry.count}
                                                </Badge>
                                                <div className="flex-1">
                                                    <p className="font-medium text-amber-900 dark:text-amber-100">
                                                        {entry.reason}
                                                    </p>
                                                    <p className="text-amber-600 dark:text-amber-400">
                                                        {new Date(entry.timestamp).toLocaleString('es-MX', {
                                                            dateStyle: 'medium',
                                                            timeStyle: 'short',
                                                        })}
                                                    </p>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            <div className="rounded-lg border border-amber-200 bg-amber-100/50 p-3 dark:border-amber-800 dark:bg-amber-900/30">
                                <p className="text-xs text-amber-800 dark:text-amber-200">
                                    <strong>Nota:</strong> Este evento será revalidado automáticamente. Si después de {event.investigation_metadata.max_investigations} intentos la AI no puede determinar un veredicto definitivo, se escalará para revisión humana.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                )}

                <section className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <QuickStat
                        icon={Radar}
                        label="Unidad monitoreada"
                        primary={event.vehicle_name ?? 'Sin información de la unidad'}
                        secondary="Dato provisto por Samsara"
                    />
                    <QuickStat
                        icon={Sparkles}
                        label="Operador detectado"
                        primary={event.driver_name ?? 'Sin conductor identificado'}
                        secondary="Última asignación conocida"
                    />
                    <QuickStat
                        icon={Clock}
                        label="Momento del evento"
                        primary={formatFullDate(event.occurred_at)}
                        secondary="Horario reportado por Samsara"
                    />
                </section>

                <section className="grid gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Evaluación de la AI</CardTitle>
                            <CardDescription>
                                Basada en el assessment estructurado retornado por el pipeline.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            {event.ai_assessment_view ? (
                                <>
                                    <div className="flex flex-wrap gap-3">
                                        <Badge className="bg-emerald-500/15 text-emerald-500">
                                            Veredicto:{' '}
                                            {event.ai_assessment_view.verdict ?? 'Sin veredicto'}
                                        </Badge>
                                        <Badge className="bg-blue-500/15 text-blue-500">
                                            Probabilidad:{' '}
                                            {event.ai_assessment_view.likelihood ?? 'Sin dato'}
                                        </Badge>
                                    </div>
                                    <p className="text-base leading-relaxed text-foreground">
                                        {event.ai_assessment_view.reasoning ??
                                            'La AI no generó razonamiento para esta alerta.'}
                                    </p>
                                    {event.ai_assessment_view.evidence?.length ? (
                                        <div className="space-y-2 rounded-lg border bg-muted/30 p-4 text-sm">
                                            <p className="font-semibold uppercase text-muted-foreground">
                                                Evidencia usada
                                            </p>
                                            {event.ai_assessment_view.evidence.map((item) => (
                                                <div key={item.label}>
                                                    <p className="text-xs font-semibold uppercase text-muted-foreground">
                                                        {item.label}
                                                    </p>
                                                    <p>{item.value}</p>
                                                </div>
                                            ))}
                                        </div>
                                    ) : null}
                                </>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    La AI no dejó registro del análisis para esta alerta.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Resumen del payload</CardTitle>
                            <CardDescription>
                                Datos clave recibidos desde Samsara.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            {event.payload_summary.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    No se recibieron más datos del webhook.
                                </p>
                            )}
                            {event.payload_summary.map((item) => {
                                const icon = getPayloadIcon(item.label);
                                const Icon = icon;
                                return (
                                    <div key={item.label} className="flex items-start gap-2">
                                        {Icon && (
                                            <Icon className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
                                        )}
                                        <div className="flex-1">
                                            <p className="text-xs uppercase text-muted-foreground">
                                                {item.label}
                                            </p>
                                            <p className="font-medium">{item.value}</p>
                                        </div>
                                    </div>
                                );
                            })}
                        </CardContent>
                    </Card>
                </section>

                {event.investigation_actions &&
                    event.investigation_actions.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Acciones de Investigación</CardTitle>
                                <CardDescription>
                                    Resumen de las herramientas ejecutadas durante el
                                    análisis.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {event.investigation_actions.map((category) => {
                                    const Icon = getCategoryIcon(category.icon);
                                    return (
                                        <div
                                            key={category.label}
                                            className="rounded-lg border bg-muted/30 p-4"
                                        >
                                            <div className="mb-3 flex items-center gap-2">
                                                <div className="rounded-full bg-primary/10 p-1.5 text-primary">
                                                    <Icon className="size-4" />
                                                </div>
                                                <p className="font-semibold">
                                                    {category.label}
                                                </p>
                                            </div>
                                            <div className="space-y-2">
                                                {category.items.map((item, idx) => (
                                                    <div
                                                        key={idx}
                                                        className="flex items-start gap-2 text-sm"
                                                    >
                                                        <CheckCircle2 className="mt-0.5 size-3 shrink-0 text-emerald-500" />
                                                        <div>
                                                            <span className="font-medium">
                                                                {item.name}:
                                                            </span>{' '}
                                                            <span className="text-muted-foreground">
                                                                {item.summary}
                                                            </span>
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    );
                                })}
                            </CardContent>
                        </Card>
                    )}

                <section className="grid gap-4 lg:grid-cols-5">
                    <Card className="lg:col-span-3">
                        <CardHeader>
                            <CardTitle>Recorrido de la AI</CardTitle>
                            <CardDescription>
                                Visualiza paso a paso lo que ejecutó cada agente automatizado.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            {event.timeline.length === 0 && (
                                <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                                    No se recibieron métricas del pipeline para esta alerta.
                                </div>
                            )}
                            <div className="relative space-y-10">
                                <span className="absolute left-4 top-0 bottom-0 w-px bg-border" aria-hidden />
                                {event.timeline.map((step) => (
                                    <div key={`${step.step}-${step.name}`} className="relative pl-10">
                                        <span className="absolute left-3 top-1 flex h-4 w-4 items-center justify-center rounded-full border bg-background text-xs font-semibold">
                                            {step.step}
                                        </span>
                                        <div className="rounded-lg border p-4">
                                            <div className="flex flex-col gap-1">
                                                <p className="text-xs uppercase text-muted-foreground">
                                                    Paso {step.step}
                                                </p>
                                                <p className="text-base font-semibold text-foreground">
                                                    {step.title}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {step.description}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {formatDateTime(step.started_at)} •{' '}
                                                    {formatDuration(step.duration_ms)}
                                                </p>
                                            </div>
                                            <div className="mt-3 space-y-2 text-sm text-foreground/90">
                                                <p>{step.summary || 'Sin resumen para este agente.'}</p>
                                                {step.summary_details?.length ? (
                                                    <ul className="grid gap-1 text-sm text-muted-foreground">
                                                        {step.summary_details.map((detail) => (
                                                            <li key={`${detail.label}-${detail.value}`}>
                                                                <span className="font-medium text-foreground">
                                                                    {detail.label}:
                                                                </span>{' '}
                                                                {detail.value}
                                                            </li>
                                                        ))}
                                                    </ul>
                                                ) : null}
                                            </div>
                                            {step.tools_used.length > 0 && (
                                                <div className="mt-4 space-y-2 rounded-lg border bg-muted/30 p-3">
                                                    <p className="text-xs font-semibold uppercase text-muted-foreground">
                                                        Acciones ejecutadas
                                                    </p>
                                                    {step.tools_used.map((tool, index) => (
                                                        <div
                                                            key={`${tool.tool_name}-${index}`}
                                                            className="rounded border bg-background/60 p-3"
                                                        >
                                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                                <p className="font-semibold">
                                                                    {tool.tool_name}
                                                                </p>
                                                                <Badge
                                                                    className={`${tool.status_label === 'Completada'
                                                                        ? 'bg-emerald-500/10 text-emerald-500'
                                                                        : 'bg-rose-500/10 text-rose-500'
                                                                        }`}
                                                                >
                                                                    {tool.status_label ?? 'Sin estado'}
                                                                </Badge>
                                                            </div>
                                                            <p className="mt-1 text-xs text-muted-foreground">
                                                                {tool.called_at
                                                                    ? formatDateTime(tool.called_at)
                                                                    : 'Sin timestamp'}{' '}
                                                                • {formatDuration(tool.duration_ms)}
                                                            </p>
                                                            <p className="mt-2 text-sm">
                                                                {tool.result_summary ?? 'Sin resultado'}
                                                            </p>
                                                            {tool.details?.analyses?.length ? (
                                                                <div className="mt-2 space-y-2 rounded border bg-muted/40 p-2 text-xs">
                                                                    {tool.details.analyses.map((analysis, idx) => (
                                                                        <div key={idx}>
                                                                            <p className="font-semibold">
                                                                                {analysis.camera ?? 'Cámara'}
                                                                            </p>
                                                                            <p>
                                                                                {analysis.analysis_preview ??
                                                                                    analysis.analysis}
                                                                            </p>
                                                                        </div>
                                                                    ))}
                                                                </div>
                                                            ) : null}
                                                        </div>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Salud del pipeline</CardTitle>
                            <CardDescription>
                                Tiempo total y herramientas utilizadas durante el análisis.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            <div className="space-y-2 rounded border bg-muted/30 p-4 text-sm">
                                <p className="text-xs uppercase text-muted-foreground">
                                    Duración total
                                </p>
                                <p className="text-2xl font-semibold">
                                    {formatDuration(event.ai_actions.total_duration_ms)}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Herramientas llamadas:{' '}
                                    <strong>
                                        {event.ai_actions.total_tools_called ?? 0}
                                    </strong>
                                </p>
                            </div>
                            {event.media_insights.length > 0 && (
                                <div className="space-y-2 text-sm">
                                    <p className="text-xs uppercase text-muted-foreground">
                                        Insights de cámaras
                                    </p>
                                    <div className="space-y-2">
                                        {event.media_insights.map((analysis, idx) => (
                                            <div
                                                key={`${analysis.camera}-${idx}`}
                                                className="rounded-lg border bg-background/70 p-3"
                                            >
                                                <p className="text-xs uppercase text-muted-foreground">
                                                    {analysis.camera ?? 'Cámara'}
                                                </p>
                                                <p>{analysis.analysis ?? analysis.analysis_preview}</p>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                            {event.ai_message && (
                                <div className="rounded-xl border bg-gradient-to-br from-primary/5 via-background to-background p-4 text-sm">
                                    <p className="text-xs uppercase text-muted-foreground">
                                        Mensaje final
                                    </p>
                                    <p className="mt-2 whitespace-pre-line">
                                        {event.ai_message}
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </section>
            </div>
        </AppLayout>
    );
}

function QuickStat({
    icon: Icon,
    label,
    primary,
    secondary,
}: {
    icon: LucideIcon;
    label: string;
    primary: string;
    secondary: string;
}) {
    return (
        <Card>
            <CardContent className="flex items-center gap-3 py-6">
                <span className="rounded-full bg-primary/10 p-2 text-primary">
                    <Icon className="size-5" />
                </span>
                <div>
                    <p className="text-xs uppercase text-muted-foreground">{label}</p>
                    <p className="text-lg font-semibold leading-tight">{primary}</p>
                    <p className="text-xs text-muted-foreground">{secondary}</p>
                </div>
            </CardContent>
        </Card>
    );
}
