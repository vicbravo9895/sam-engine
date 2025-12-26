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
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
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
    ExternalLink,
    Image as ImageIcon,
    Loader2,
    MapPin,
    Radar,
    Search,
    ShieldAlert,
    ShieldCheck,
    Sparkles,
    Truck,
    User,
    XCircle,
    Zap,
} from 'lucide-react';
import { type LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

// ============================================================================
// TYPE DEFINITIONS
// ============================================================================

interface ToolUsage {
    tool_name?: string;
    status_label?: string;
    called_at?: string;
    duration_ms?: number;
    result_summary?: string;
    media_urls?: string[];
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
    value: string | Record<string, unknown>;
}

/**
 * Safely renders a value that might be a string or an object.
 * If it's an object with a 'name' property, returns that.
 * Otherwise converts to string.
 */
const renderSafeValue = (value: string | Record<string, unknown> | null | undefined): string => {
    if (value === null || value === undefined) {
        return 'Sin información';
    }
    if (typeof value === 'string') {
        return value;
    }
    if (typeof value === 'object') {
        // If it's an object with 'name', use that
        if ('name' in value && typeof value.name === 'string') {
            return value.name;
        }
        // Otherwise, try to stringify it nicely
        try {
            return JSON.stringify(value);
        } catch {
            return 'Datos complejos';
        }
    }
    return String(value);
};

interface AssessmentView {
    verdict?: string | null;
    likelihood?: string | null;
    reasoning?: string | null;
    evidence?: AssessmentEvidenceItem[];
}

interface MediaInsight {
    camera?: string;
    analysis?: string;
    analysis_preview?: string;
    url?: string | null;
    download_url?: string | null;
}

interface SamsaraEventPayload {
    id: number;
    event_type?: string | null;
    event_description?: string | null;
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
    media_insights: MediaInsight[];
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
        last_check_at?: string | null;
        next_check_minutes?: number | null;
        next_check_available_at?: string | null;
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

// ============================================================================
// CONSTANTS & HELPERS
// ============================================================================

const ALERTS_INDEX_URL = '/samsara/alerts';
const getAlertShowUrl = (id: number) => `/samsara/alerts/${id}`;

const severityConfig: Record<string, { bg: string; text: string; icon: LucideIcon }> = {
    info: { bg: 'bg-blue-500/10', text: 'text-blue-600 dark:text-blue-400', icon: AlertCircle },
    warning: { bg: 'bg-amber-500/10', text: 'text-amber-600 dark:text-amber-400', icon: AlertTriangle },
    critical: { bg: 'bg-red-500/10', text: 'text-red-600 dark:text-red-400', icon: ShieldAlert },
};

const verdictConfig: Record<string, { bg: string; border: string; text: string; icon: LucideIcon }> = {
    low: {
        bg: 'bg-gradient-to-br from-emerald-50 to-emerald-100/50 dark:from-emerald-950/50 dark:to-emerald-900/30',
        border: 'border-emerald-200 dark:border-emerald-800',
        text: 'text-emerald-800 dark:text-emerald-200',
        icon: ShieldCheck,
    },
    medium: {
        bg: 'bg-gradient-to-br from-amber-50 to-amber-100/50 dark:from-amber-950/50 dark:to-amber-900/30',
        border: 'border-amber-200 dark:border-amber-800',
        text: 'text-amber-800 dark:text-amber-200',
        icon: AlertTriangle,
    },
    high: {
        bg: 'bg-gradient-to-br from-red-50 to-red-100/50 dark:from-red-950/50 dark:to-red-900/30',
        border: 'border-red-200 dark:border-red-800',
        text: 'text-red-800 dark:text-red-200',
        icon: XCircle,
    },
    unknown: {
        bg: 'bg-gradient-to-br from-slate-50 to-slate-100/50 dark:from-slate-950/50 dark:to-slate-900/30',
        border: 'border-slate-200 dark:border-slate-800',
        text: 'text-slate-800 dark:text-slate-200',
        icon: Search,
    },
};

const getEventIcon = (iconName?: string | null): LucideIcon => {
    switch (iconName) {
        case 'alert-circle': return AlertCircle;
        case 'alert-triangle': return AlertTriangle;
        case 'shield-alert': return ShieldAlert;
        default: return Bell;
    }
};

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
    if (value < 1000) return `${value} ms`;
    return `${(value / 1000).toFixed(1)} s`;
};

// ============================================================================
// MAIN COMPONENT
// ============================================================================

export default function SamsaraAlertShow({ event, breadcrumbs }: ShowProps) {
    const [isPolling, setIsPolling] = useState(false);
    const [previousStatus, setPreviousStatus] = useState(event.ai_status);
    const [simulatedTools, setSimulatedTools] = useState<string[]>([]);
    const [nextInvestigationEtaMs, setNextInvestigationEtaMs] = useState<number | null>(null);
    const [selectedImage, setSelectedImage] = useState<MediaInsight | null>(null);

    const isProcessing = event.ai_status === 'processing';
    const isInvestigating = event.ai_status === 'investigating';
    const isCompleted = event.ai_status === 'completed';
    const eventLabel = event.display_event_type ?? 'Alerta procesada por AI';

    const computedBreadcrumbs: BreadcrumbItem[] = breadcrumbs?.length
        ? breadcrumbs
        : [
            { title: 'Alertas Samsara', href: ALERTS_INDEX_URL },
            { title: eventLabel, href: getAlertShowUrl(event.id) },
        ];

    // Polling effect for processing/investigating states
    useEffect(() => {
        const shouldPoll = isProcessing || isInvestigating;
        let pollingInterval: ReturnType<typeof setInterval> | undefined;
        let toolInterval: ReturnType<typeof setInterval> | undefined;

        if (shouldPoll) {
            setIsPolling(true);

            if (isProcessing) {
                setSimulatedTools([]);
                const tools = [
                    'Obteniendo estadísticas del vehículo...',
                    'Consultando información del vehículo...',
                    'Identificando conductor asignado...',
                    'Revisando eventos de seguridad...',
                    'Analizando imágenes de cámaras con IA...',
                    'Generando veredicto final...'
                ];

                let currentToolIndex = 0;
                toolInterval = setInterval(() => {
                    if (currentToolIndex < tools.length) {
                        setSimulatedTools((prev) => [...prev, tools[currentToolIndex]]);
                        currentToolIndex++;
                    } else if (toolInterval) {
                        clearInterval(toolInterval);
                    }
                }, 5000);
            } else {
                setSimulatedTools([]);
            }

            pollingInterval = setInterval(() => {
                router.reload({ only: ['event'] });
            }, 3000);
        } else {
            setIsPolling(false);
            setSimulatedTools([]);
        }

        if ((previousStatus === 'processing' || previousStatus === 'investigating') &&
            event.ai_status === 'completed') {
            if (typeof window !== 'undefined' && 'Notification' in window) {
                if (Notification.permission === 'granted') {
                    new Notification('Análisis completado', {
                        body: `El evento "${eventLabel}" ha sido procesado por la AI`,
                        icon: '/favicon.ico',
                    });
                }
            }
        }

        setPreviousStatus(event.ai_status);

        return () => {
            if (pollingInterval) clearInterval(pollingInterval);
            if (toolInterval) clearInterval(toolInterval);
        };
    }, [event.ai_status, event.id, eventLabel, isInvestigating, isProcessing, previousStatus]);

    // Countdown for next investigation
    useEffect(() => {
        if (!isInvestigating) {
            setNextInvestigationEtaMs(null);
            return;
        }

        const nextCheckAt = event.investigation_metadata?.next_check_available_at;
        if (!nextCheckAt) {
            setNextInvestigationEtaMs(null);
            return;
        }

        const targetTime = new Date(nextCheckAt).getTime();
        if (!Number.isFinite(targetTime)) {
            setNextInvestigationEtaMs(null);
            return;
        }

        const tick = () => {
            const diff = targetTime - Date.now();
            setNextInvestigationEtaMs(diff <= 0 ? 0 : diff);
        };

        tick();
        const timer = setInterval(tick, 1000);

        return () => clearInterval(timer);
    }, [event.investigation_metadata?.next_check_available_at, isInvestigating]);

    const isRevalidationImminent = nextInvestigationEtaMs !== null && nextInvestigationEtaMs === 0;
    
    const nextInvestigationCountdownText = useMemo(() => {
        if (!isInvestigating) return null;

        if (nextInvestigationEtaMs === null) {
            const fallbackMinutes = event.investigation_metadata?.next_check_minutes;
            return fallbackMinutes ? `En ${fallbackMinutes} minutos` : null;
        }

        if (nextInvestigationEtaMs === 0) return null; // Se maneja aparte con isRevalidationImminent

        const totalSeconds = Math.ceil(nextInvestigationEtaMs / 1000);
        if (totalSeconds >= 60) {
            const minutes = Math.floor(totalSeconds / 60);
            const seconds = totalSeconds % 60;
            return `En ${minutes}m ${seconds.toString().padStart(2, '0')}s`;
        }

        return `En ${totalSeconds} segundos`;
    }, [event.investigation_metadata?.next_check_minutes, isInvestigating, nextInvestigationEtaMs]);

    const hasImages = event.media_insights.some(m => m.download_url);
    const verdictStyle = verdictConfig[event.verdict_badge?.urgency ?? 'unknown'];
    const VerdictIcon = verdictStyle.icon;
    const EventIcon = getEventIcon(event.event_icon);
    const severityStyle = severityConfig[event.severity] ?? severityConfig.info;

    return (
        <AppLayout breadcrumbs={computedBreadcrumbs}>
            <Head title={`Detalle ${eventLabel}`} />

            <div className="flex flex-1 flex-col gap-6 p-4 max-w-7xl mx-auto">
                {/* ============================================================
                    SECTION 1: HEADER + BACK BUTTON
                ============================================================ */}
                <header className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <Button variant="outline" asChild className="gap-2 w-fit">
                        <Link href={ALERTS_INDEX_URL}>
                            <ArrowLeft className="size-4" />
                            Regresar al listado
                        </Link>
                    </Button>
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge className={`${severityStyle.bg} ${severityStyle.text} px-3 py-1`}>
                            {event.severity_label ?? 'Informativa'}
                        </Badge>
                        <Badge
                            className={`px-3 py-1 ${isProcessing
                                ? 'bg-sky-500/10 text-sky-600 dark:text-sky-400 animate-pulse'
                                : isInvestigating
                                    ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400 animate-pulse'
                                    : isCompleted
                                        ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                        : 'bg-slate-500/10 text-slate-600 dark:text-slate-400'
                                }`}
                        >
                            {event.ai_status_label ?? 'Pendiente'}
                        </Badge>
                    </div>
                </header>

                {/* ============================================================
                    SECTION 2: HERO - EVENT IDENTITY
                ============================================================ */}
                <section className="relative overflow-hidden rounded-2xl border bg-gradient-to-br from-background via-background to-muted/30 p-6 sm:p-8">
                    <div className="flex flex-col gap-6 sm:flex-row sm:items-start sm:gap-8">
                        <div className={`shrink-0 rounded-2xl p-4 ${severityStyle.bg}`}>
                            <EventIcon className={`size-10 ${severityStyle.text}`} />
                        </div>
                        <div className="flex-1 space-y-4">
                            <div>
                                <p className="text-sm font-medium text-muted-foreground uppercase tracking-wide">
                                    {event.display_event_type ?? 'Alerta de Samsara'}
                                </p>
                                <h1 className="text-2xl sm:text-3xl font-bold tracking-tight mt-1">
                                    {event.event_description ?? event.vehicle_name ?? 'Evento sin descripción'}
                                </h1>
                                {event.event_description && event.vehicle_name && (
                                    <p className="text-base text-muted-foreground mt-1">
                                        {event.vehicle_name}
                                    </p>
                                )}
                            </div>
                            <div className="flex flex-wrap gap-4 text-sm">
                                <div className="flex items-center gap-2 text-muted-foreground">
                                    <User className="size-4" />
                                    <span>{event.driver_name ?? 'Sin conductor identificado'}</span>
                                </div>
                                <div className="flex items-center gap-2 text-muted-foreground">
                                    <Calendar className="size-4" />
                                    <span>{formatFullDate(event.occurred_at)}</span>
                                </div>
                                {hasImages && (
                                    <div className="flex items-center gap-2 text-muted-foreground">
                                        <ImageIcon className="size-4" />
                                        <span>{event.media_insights.filter(m => m.download_url).length} imágenes capturadas</span>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </section>

                {/* ============================================================
                    SECTION 3: PROCESSING STATE (if applicable)
                ============================================================ */}
                {isProcessing && isPolling && (
                    <Card className="border-2 border-sky-500/30 bg-sky-50/50 dark:bg-sky-950/20">
                        <CardHeader>
                            <div className="flex items-center gap-3">
                                <Loader2 className="size-6 animate-spin text-sky-600 dark:text-sky-400" />
                                <div className="flex-1">
                                    <CardTitle className="text-sky-900 dark:text-sky-100">
                                        Procesando evento...
                                    </CardTitle>
                                    <CardDescription className="text-sky-700 dark:text-sky-300">
                                        La AI está analizando el evento. Esto tomará ~25 segundos.
                                    </CardDescription>
                                </div>
                                <Badge className="bg-sky-500 text-white animate-pulse">En progreso</Badge>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-2">
                                {simulatedTools.map((tool, idx) => (
                                    <div key={idx} className="flex items-center gap-2 rounded-lg border border-sky-200 bg-white/50 p-2 text-sm dark:border-sky-800 dark:bg-sky-950/30">
                                        <CheckCircle2 className="size-4 shrink-0 text-emerald-500" />
                                        <span className="text-sky-900 dark:text-sky-100">{tool}</span>
                                    </div>
                                ))}
                                {simulatedTools.length < 6 && (
                                    <div className="flex items-center gap-2 rounded-lg border border-sky-200 bg-white/50 p-2 text-sm dark:border-sky-800 dark:bg-sky-950/30">
                                        <Loader2 className="size-4 shrink-0 animate-spin text-sky-500" />
                                        <span className="text-sky-700 dark:text-sky-300">
                                            {['Iniciando análisis...', 'Consultando información del vehículo...', 'Identificando conductor asignado...', 'Revisando eventos de seguridad...', 'Analizando imágenes de cámaras con IA...', 'Generando veredicto final...'][simulatedTools.length]}
                                        </span>
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* ============================================================
                    SECTION 4: INVESTIGATING STATE (if applicable)
                ============================================================ */}
                {isInvestigating && event.investigation_metadata && (
                    <Card className="border-2 border-amber-500/30 bg-amber-50/50 dark:bg-amber-950/20">
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <div className="rounded-full bg-amber-500/20 p-2">
                                        {isRevalidationImminent ? (
                                            <Loader2 className="size-5 animate-spin text-amber-600 dark:text-amber-400" />
                                        ) : (
                                            <Search className="size-5 text-amber-600 dark:text-amber-400" />
                                        )}
                                    </div>
                                    <div>
                                        <CardTitle className="text-amber-900 dark:text-amber-100">
                                            {isRevalidationImminent 
                                                ? 'Ejecutando re-validación...' 
                                                : 'Evento bajo investigación'}
                                        </CardTitle>
                                        <CardDescription className="text-amber-700 dark:text-amber-300">
                                            {isRevalidationImminent 
                                                ? 'La AI está analizando nueva información del evento'
                                                : 'La AI continúa monitoreando este evento'}
                                        </CardDescription>
                                    </div>
                                </div>
                                <Badge className={isRevalidationImminent 
                                    ? "bg-amber-500 text-white animate-pulse" 
                                    : "bg-amber-500 text-white"}>
                                    {event.investigation_metadata.count} de {event.investigation_metadata.max_investigations}
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {/* Estado de re-validación inminente */}
                            {isRevalidationImminent && (
                                <div className="rounded-lg border-2 border-amber-400/50 bg-amber-100/50 p-4 dark:border-amber-600/50 dark:bg-amber-900/30">
                                    <div className="flex items-center gap-3">
                                        <Loader2 className="size-5 animate-spin text-amber-600 dark:text-amber-400 shrink-0" />
                                        <div>
                                            <p className="font-semibold text-amber-900 dark:text-amber-100">
                                                Re-validación en progreso
                                            </p>
                                            <p className="text-sm text-amber-700 dark:text-amber-300">
                                                La página se actualizará automáticamente con los nuevos resultados. 
                                                Este proceso toma aproximadamente 30-60 segundos.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            )}
                            
                            {/* Información de verificaciones */}
                            <div className="grid gap-3 sm:grid-cols-2">
                                {event.investigation_metadata.last_check && (
                                    <div className="rounded-lg border border-amber-200 bg-white/50 p-3 dark:border-amber-800 dark:bg-amber-950/30">
                                        <p className="text-xs font-semibold uppercase text-amber-700 dark:text-amber-400">Última verificación</p>
                                        <p className="text-sm font-medium text-amber-900 dark:text-amber-100">{event.investigation_metadata.last_check}</p>
                                    </div>
                                )}
                                {nextInvestigationCountdownText && !isRevalidationImminent && (
                                    <div className="rounded-lg border border-amber-200 bg-white/50 p-3 dark:border-amber-800 dark:bg-amber-950/30">
                                        <p className="text-xs font-semibold uppercase text-amber-700 dark:text-amber-400">Próxima verificación</p>
                                        <p className="text-sm font-medium text-amber-900 dark:text-amber-100">{nextInvestigationCountdownText}</p>
                                    </div>
                                )}
                                {isRevalidationImminent && (
                                    <div className="rounded-lg border border-amber-400 bg-amber-100/70 p-3 dark:border-amber-600 dark:bg-amber-900/50">
                                        <p className="text-xs font-semibold uppercase text-amber-700 dark:text-amber-400">Estado</p>
                                        <p className="text-sm font-medium text-amber-900 dark:text-amber-100 flex items-center gap-2">
                                            <span className="relative flex h-2 w-2">
                                                <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-500 opacity-75"></span>
                                                <span className="relative inline-flex rounded-full h-2 w-2 bg-amber-600"></span>
                                            </span>
                                            Analizando...
                                        </p>
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* ============================================================
                    SECTION 5: AI VERDICT (the hero of storytelling)
                ============================================================ */}
                {event.verdict_badge && isCompleted && (
                    <section className={`rounded-2xl border-2 ${verdictStyle.border} ${verdictStyle.bg} p-6 sm:p-8`}>
                        <div className="flex flex-col gap-6 sm:flex-row sm:items-start">
                            <div className={`shrink-0 rounded-2xl bg-white/50 dark:bg-black/20 p-4`}>
                                <VerdictIcon className={`size-10 ${verdictStyle.text}`} />
                            </div>
                            <div className="flex-1 space-y-4">
                                <div>
                                    <p className={`text-sm font-semibold uppercase tracking-wide ${verdictStyle.text} opacity-75`}>
                                        Veredicto de la AI
                                    </p>
                                    <h2 className={`text-2xl sm:text-3xl font-bold ${verdictStyle.text}`}>
                                        {event.verdict_badge.verdict}
                                    </h2>
                                    {event.verdict_badge.likelihood && (
                                        <p className={`text-base mt-1 ${verdictStyle.text} opacity-80`}>
                                            Probabilidad: {event.verdict_badge.likelihood}
                                        </p>
                                    )}
                                </div>
                                {event.ai_assessment_view?.reasoning && (
                                    <p className={`text-base leading-relaxed ${verdictStyle.text} opacity-90`}>
                                        {event.ai_assessment_view.reasoning}
                                    </p>
                                )}
                            </div>
                        </div>
                    </section>
                )}

                {/* ============================================================
                    SECTION 6: VISUAL EVIDENCE (Images Gallery)
                ============================================================ */}
                {hasImages && (
                    <section>
                        <div className="flex items-center gap-3 mb-4">
                            <div className="rounded-lg bg-primary/10 p-2">
                                <Camera className="size-5 text-primary" />
                            </div>
                            <div>
                                <h2 className="text-xl font-semibold">Evidencia Visual</h2>
                                <p className="text-sm text-muted-foreground">Imágenes capturadas durante el evento</p>
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            {event.media_insights.filter(m => m.download_url).map((insight, idx) => (
                                <Card key={idx} className="overflow-hidden group">
                                    <div className="relative aspect-video bg-muted">
                                        <img
                                            src={insight.download_url!}
                                            alt={`Evidencia ${insight.camera ?? idx + 1}`}
                                            className="w-full h-full object-cover transition-transform group-hover:scale-105"
                                            loading="lazy"
                                        />
                                        <div className="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity" />
                                        <div className="absolute bottom-3 left-3 right-3 opacity-0 group-hover:opacity-100 transition-opacity">
                                            <Badge className="bg-black/50 text-white backdrop-blur-sm">
                                                {insight.camera ?? `Cámara ${idx + 1}`}
                                            </Badge>
                                        </div>
                                    </div>
                                    {insight.analysis && (
                                        <CardContent className="p-4">
                                            <p className="text-sm font-medium mb-1">{insight.camera ?? `Cámara ${idx + 1}`}</p>
                                            <p className="text-sm text-muted-foreground leading-relaxed">
                                                {insight.analysis}
                                            </p>
                                        </CardContent>
                                    )}
                                </Card>
                            ))}
                        </div>
                    </section>
                )}

                {/* ============================================================
                    SECTION 7: SUPPORTING EVIDENCE (from AI assessment)
                ============================================================ */}
                {event.ai_assessment_view?.evidence && event.ai_assessment_view.evidence.length > 0 && (
                    <section>
                        <div className="flex items-center gap-3 mb-4">
                            <div className="rounded-lg bg-primary/10 p-2">
                                <Sparkles className="size-5 text-primary" />
                            </div>
                            <div>
                                <h2 className="text-xl font-semibold">Análisis Detallado</h2>
                                <p className="text-sm text-muted-foreground">Evidencia recopilada durante la investigación</p>
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            {event.ai_assessment_view.evidence.map((item, idx) => (
                                <Card key={idx}>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-base">{item.label}</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <p className="text-sm text-muted-foreground leading-relaxed">
                                            {renderSafeValue(item.value)}
                                        </p>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    </section>
                )}

                {/* ============================================================
                    SECTION 8: INVESTIGATION TIMELINE (collapsible)
                ============================================================ */}
                <Collapsible defaultOpen={false}>
                    <Card>
                        <CollapsibleTrigger asChild>
                            <CardHeader className="cursor-pointer hover:bg-muted/30 transition-colors">
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-3">
                                        <div className="rounded-lg bg-primary/10 p-2">
                                            <Zap className="size-5 text-primary" />
                                        </div>
                                        <div>
                                            <CardTitle>Recorrido de la AI</CardTitle>
                                            <CardDescription>
                                                {event.ai_actions.total_tools_called} herramientas ejecutadas en {formatDuration(event.ai_actions.total_duration_ms)}
                                            </CardDescription>
                                        </div>
                                    </div>
                                    <ChevronDown className="size-5 text-muted-foreground transition-transform [[data-state=open]_&]:rotate-180" />
                                </div>
                            </CardHeader>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <CardContent className="pt-0">
                                {event.timeline.length === 0 ? (
                                    <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                                        No se recibieron métricas del pipeline para esta alerta.
                                    </div>
                                ) : (
                                    <div className="relative space-y-6">
                                        <span className="absolute left-4 top-0 bottom-0 w-px bg-border" aria-hidden />
                                        {event.timeline.map((step) => (
                                            <div key={`${step.step}-${step.name}`} className="relative pl-10">
                                                <span className="absolute left-2 top-1 flex h-5 w-5 items-center justify-center rounded-full border bg-background text-xs font-semibold">
                                                    {step.step}
                                                </span>
                                                <div className="rounded-lg border p-4 space-y-3">
                                                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                                        <div>
                                                            <p className="font-semibold">{step.title}</p>
                                                            <p className="text-xs text-muted-foreground">{step.description}</p>
                                                        </div>
                                                        <Badge variant="outline" className="w-fit">
                                                            {formatDuration(step.duration_ms)}
                                                        </Badge>
                                                    </div>
                                                    <p className="text-sm text-muted-foreground">{step.summary}</p>
                                                    {step.tools_used.length > 0 && (
                                                        <div className="flex flex-wrap gap-2">
                                                            {step.tools_used.map((tool, idx) => (
                                                                <Badge key={idx} variant="secondary" className="text-xs">
                                                                    {tool.tool_name}
                                                                </Badge>
                                                            ))}
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </CollapsibleContent>
                    </Card>
                </Collapsible>

                {/* ============================================================
                    SECTION 9: TECHNICAL DATA (payload summary)
                ============================================================ */}
                {event.payload_summary.length > 0 && (
                    <Collapsible defaultOpen={false}>
                        <Card>
                            <CollapsibleTrigger asChild>
                                <CardHeader className="cursor-pointer hover:bg-muted/30 transition-colors">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-3">
                                            <div className="rounded-lg bg-muted p-2">
                                                <Radar className="size-5 text-muted-foreground" />
                                            </div>
                                            <div>
                                                <CardTitle>Datos del Webhook</CardTitle>
                                                <CardDescription>Información técnica recibida de Samsara</CardDescription>
                                            </div>
                                        </div>
                                        <ChevronDown className="size-5 text-muted-foreground transition-transform [[data-state=open]_&]:rotate-180" />
                                    </div>
                                </CardHeader>
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                <CardContent className="pt-0">
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        {event.payload_summary.map((item, idx) => (
                                            <div key={idx} className="rounded-lg border bg-muted/20 p-3">
                                                <p className="text-xs uppercase text-muted-foreground font-medium">{item.label}</p>
                                                <p className="text-sm font-medium mt-0.5">{item.value}</p>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </CollapsibleContent>
                        </Card>
                    </Collapsible>
                )}
            </div>
        </AppLayout>
    );
}
