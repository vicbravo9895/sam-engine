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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import {
    type IncidentDetail,
    type IncidentAiAssessmentView,
    INCIDENT_STATUS_OPTIONS,
} from '@/types/incidents';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowLeft,
    Car,
    ChevronDown,
    Clock,
    ListChecks,
    Radio,
    Shield,
    Timer,
    User,
} from 'lucide-react';
import { useState } from 'react';

interface ShowProps {
    incident: IncidentDetail;
}

const priorityColors: Record<string, string> = {
    P1: 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-200 border-red-300',
    P2: 'bg-orange-100 text-orange-800 dark:bg-orange-500/20 dark:text-orange-200 border-orange-300',
    P3: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-500/20 dark:text-yellow-200 border-yellow-300',
    P4: 'bg-slate-100 text-slate-800 dark:bg-slate-500/20 dark:text-slate-200 border-slate-300',
};

const statusColors: Record<string, string> = {
    open: 'bg-amber-100 text-amber-800',
    investigating: 'bg-blue-100 text-blue-800',
    pending_action: 'bg-orange-100 text-orange-800',
    resolved: 'bg-emerald-100 text-emerald-800',
    false_positive: 'bg-slate-100 text-slate-800',
};

const roleColors: Record<string, string> = {
    supporting: 'bg-emerald-100 text-emerald-800',
    contradicting: 'bg-red-100 text-red-800',
    context: 'bg-blue-100 text-blue-800',
};

const typeIcons: Record<string, React.ElementType> = {
    collision: Car,
    emergency: AlertTriangle,
    pattern: Activity,
    safety_violation: Shield,
};

/** Build a structured view from raw ai_assessment JSON for display. */
function parseAiAssessment(raw: Record<string, unknown> | null): IncidentAiAssessmentView | null {
    if (!raw || typeof raw !== 'object') return null;
    const arr = (v: unknown): v is string[] =>
        Array.isArray(v) && v.every((x) => typeof x === 'string');
    const num = (v: unknown): number | null =>
        typeof v === 'number' && !Number.isNaN(v) ? v : null;
    const str = (v: unknown): string | null =>
        typeof v === 'string' && v.length > 0 ? v : null;
    return {
        verdict: str(raw.verdict),
        likelihood: str(raw.likelihood),
        confidence: num(raw.confidence) ?? null,
        reasoning: str(raw.reasoning),
        visual_summary: str(raw.visual_summary),
        vehicle_stats_summary: str(raw.vehicle_stats_summary),
        recommended_actions: arr(raw.recommended_actions) ? raw.recommended_actions : undefined,
        next_check_minutes: num(raw.next_check_minutes),
        monitoring_reason: str(raw.monitoring_reason),
        risk_escalation: str(raw.risk_escalation),
    };
}

function verdictLabel(value: string | null | undefined): string {
    if (!value) return '—';
    const v = value.toLowerCase();
    if (v === 'review required' || v.includes('review')) return 'Requiere revisión';
    if (v === 'confirmed' || v.includes('confirm')) return 'Confirmado';
    if (v === 'false_positive' || v.includes('false')) return 'Falso positivo';
    if (v === 'monitor') return 'Monitorear';
    return value;
}

function likelihoodLabel(value: string | null | undefined): string {
    if (!value) return '—';
    const v = value.toLowerCase();
    if (v === 'high') return 'Alta';
    if (v === 'medium') return 'Media';
    if (v === 'low') return 'Baja';
    return value;
}

function IncidentAiEvaluationCard({
    assessment,
    raw,
}: {
    assessment: IncidentAiAssessmentView | null;
    raw: Record<string, unknown>;
}) {
    const [technicalOpen, setTechnicalOpen] = useState(false);
    if (!assessment) return null;

    const hasContent =
        assessment.verdict ||
        assessment.likelihood ||
        assessment.reasoning ||
        assessment.visual_summary ||
        (assessment.recommended_actions && assessment.recommended_actions.length > 0) ||
        assessment.monitoring_reason ||
        assessment.next_check_minutes != null;

    if (!hasContent) return null;

    return (
        <Card className="lg:col-span-3 overflow-hidden border-violet-500/10 bg-gradient-to-b from-violet-500/[0.02] to-transparent dark:from-violet-500/[0.04] dark:to-transparent">
            <CardHeader className="pb-4">
                <div className="flex items-center gap-3">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400">
                        <Shield className="size-5" aria-hidden />
                    </div>
                    <div>
                        <CardTitle className="text-lg tracking-tight">Evaluación AI</CardTitle>
                        <CardDescription className="mt-0.5">
                            Análisis automático del incidente
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent className="space-y-6">
                {/* Metrics strip — same height, clear hierarchy */}
                <div className="grid gap-3 sm:grid-cols-3">
                    {assessment.verdict && (
                        <div
                            className="relative rounded-xl border border-violet-500/25 bg-violet-500/10 p-4 shadow-sm ring-1 ring-violet-500/5 transition-shadow duration-200 hover:shadow-md"
                            role="group"
                        >
                            <p className="text-[10px] font-semibold uppercase tracking-widest text-violet-500 dark:text-violet-400">
                                Veredicto
                            </p>
                            <p className="mt-2 text-base font-semibold leading-snug text-foreground">
                                {verdictLabel(assessment.verdict)}
                            </p>
                        </div>
                    )}
                    {assessment.likelihood && (
                        <div className="rounded-xl border border-border/60 bg-muted/20 p-4 transition-colors duration-200 hover:bg-muted/30">
                            <p className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">
                                Probabilidad
                            </p>
                            <p className="mt-2 text-base font-medium leading-snug">
                                {likelihoodLabel(assessment.likelihood)}
                            </p>
                        </div>
                    )}
                    {assessment.confidence != null && (
                        <div className="rounded-xl border border-border/60 bg-muted/20 p-4 transition-colors duration-200 hover:bg-muted/30">
                            <p className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">
                                Confianza
                            </p>
                            <p className="mt-2 text-base font-medium tabular-nums leading-snug">
                                {Math.round(assessment.confidence * 100)}%
                            </p>
                        </div>
                    )}
                </div>

                {/* Narrative blocks — readable line length and line-height */}
                {assessment.reasoning && (
                    <section aria-labelledby="ai-reasoning-heading">
                        <h3
                            id="ai-reasoning-heading"
                            className="mb-2 text-[10px] font-semibold uppercase tracking-widest text-muted-foreground"
                        >
                            Razonamiento
                        </h3>
                        <p className="max-w-prose rounded-xl bg-muted/30 px-4 py-3 text-[15px] leading-[1.65] text-muted-foreground">
                            {assessment.reasoning}
                        </p>
                    </section>
                )}

                {assessment.visual_summary && (
                    <section aria-labelledby="ai-visual-heading">
                        <h3
                            id="ai-visual-heading"
                            className="mb-2 text-[10px] font-semibold uppercase tracking-widest text-muted-foreground"
                        >
                            Resumen visual
                        </h3>
                        <p className="max-w-prose rounded-xl bg-muted/20 px-4 py-3 text-[15px] leading-[1.65]">
                            {assessment.visual_summary}
                        </p>
                    </section>
                )}

                {/* Recommended actions — list with left accent, hover state */}
                {assessment.recommended_actions && assessment.recommended_actions.length > 0 && (
                    <section aria-labelledby="ai-actions-heading">
                        <h3
                            id="ai-actions-heading"
                            className="mb-3 flex items-center gap-2 text-[10px] font-semibold uppercase tracking-widest text-muted-foreground"
                        >
                            <ListChecks className="size-3.5 text-emerald-500" aria-hidden />
                            Acciones recomendadas
                        </h3>
                        <ul className="space-y-2" role="list">
                            {assessment.recommended_actions.map((action, i) => (
                                <li
                                    key={i}
                                    className="flex items-start gap-3 rounded-lg border-l-2 border-emerald-500/50 bg-emerald-500/5 py-2.5 pl-4 pr-3 transition-colors duration-150 hover:bg-emerald-500/10 hover:border-emerald-500/70"
                                >
                                    <span
                                        className="mt-1.5 size-2 shrink-0 rounded-full bg-emerald-500/80"
                                        aria-hidden
                                    />
                                    <span className="text-[15px] leading-[1.6]">{action}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {/* Monitoring callout — prominent, timer icon */}
                {(assessment.monitoring_reason || assessment.next_check_minutes != null) && (
                    <section
                        className="flex gap-3 rounded-xl border border-amber-500/25 bg-amber-500/10 px-4 py-3 ring-1 ring-amber-500/10"
                        aria-label="Monitoreo programado"
                    >
                        <Timer className="size-5 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden />
                        <div className="min-w-0 flex-1">
                            <p className="text-[10px] font-semibold uppercase tracking-widest text-amber-600 dark:text-amber-400">
                                Monitoreo
                            </p>
                            {assessment.next_check_minutes != null && (
                                <p className="mt-1 text-sm font-medium">
                                    Próxima revisión en <strong>{assessment.next_check_minutes} min</strong>
                                </p>
                            )}
                            {assessment.monitoring_reason && (
                                <p className="mt-1 text-[15px] leading-[1.55] text-muted-foreground">
                                    {assessment.monitoring_reason}
                                </p>
                            )}
                        </div>
                    </section>
                )}

                {/* Technical JSON — accessible trigger, reduced-motion friendly */}
                <Collapsible open={technicalOpen} onOpenChange={setTechnicalOpen}>
                    <CollapsibleTrigger asChild>
                        <button
                            type="button"
                            className="flex min-h-[44px] min-w-[44px] items-center gap-2 rounded-lg px-2 py-2.5 text-left text-xs font-medium text-muted-foreground outline-none transition-colors hover:bg-muted/50 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                            aria-expanded={technicalOpen}
                            aria-controls="technical-json-content"
                            id="technical-json-trigger"
                        >
                            <ChevronDown
                                className={`size-4 shrink-0 transition-transform duration-200 ${technicalOpen ? 'rotate-180' : ''}`}
                                aria-hidden
                            />
                            <span>Ver JSON técnico</span>
                        </button>
                    </CollapsibleTrigger>
                    <CollapsibleContent
                        id="technical-json-content"
                        aria-labelledby="technical-json-trigger"
                        className="overflow-hidden"
                    >
                        <pre className="mt-2 max-h-52 overflow-auto rounded-xl border border-border/60 bg-muted/50 px-4 py-3 text-xs font-mono leading-relaxed">
                            {JSON.stringify(raw, null, 2)}
                        </pre>
                    </CollapsibleContent>
                </Collapsible>
            </CardContent>
        </Card>
    );
}

export default function IncidentShow({ incident }: ShowProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Centro de Control', href: '/dashboard' },
        { title: 'Incidentes', href: '/incidents' },
        { title: `${incident.priority} - ${incident.type_label}`, href: `/incidents/${incident.id}` },
    ];

    const TypeIcon = typeIcons[incident.incident_type] || AlertTriangle;

    const handleStatusChange = (newStatus: string) => {
        router.patch(`/incidents/${incident.id}/status`, {
            status: newStatus,
        }, {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Incidente ${incident.priority}: ${incident.type_label}`} />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <header className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                    <div className="flex items-center gap-4">
                        <Button variant="ghost" size="icon" asChild>
                            <Link href="/incidents">
                                <ArrowLeft className="size-4" />
                            </Link>
                        </Button>
                        <div className="flex items-center gap-3">
                            <div className={`rounded-full p-3 ${incident.is_high_priority ? 'bg-red-100' : 'bg-slate-100'}`}>
                                <TypeIcon className={`size-6 ${incident.is_high_priority ? 'text-red-600' : 'text-slate-600'}`} />
                            </div>
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">
                                    Incidente #{incident.id}
                                </p>
                                <h1 className="text-2xl font-semibold tracking-tight">
                                    {incident.type_label}
                                </h1>
                            </div>
                        </div>
                    </div>
                    <div className="flex items-center gap-3">
                        <Badge className={`${priorityColors[incident.priority]} border text-lg px-4 py-1`}>
                            {incident.priority}
                        </Badge>
                        <Select value={incident.status} onValueChange={handleStatusChange}>
                            <SelectTrigger className="w-[180px]">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {INCIDENT_STATUS_OPTIONS.map((opt) => (
                                    <SelectItem key={opt.value} value={opt.value}>
                                        {opt.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </header>

                <div className="grid gap-6 lg:grid-cols-3">
                    {/* Main Info */}
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Shield className="size-5" />
                                Detalles del Incidente
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            <div className="grid gap-4 md:grid-cols-2">
                                <div>
                                    <p className="text-sm text-muted-foreground">Prioridad</p>
                                    <Badge className={`${priorityColors[incident.priority]} border mt-1`}>
                                        {incident.priority_label}
                                    </Badge>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Estado</p>
                                    <Badge variant="outline" className={`${statusColors[incident.status]} mt-1`}>
                                        {incident.status_label}
                                    </Badge>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Severidad</p>
                                    <p className="font-medium">{incident.severity_label}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Fuente</p>
                                    <p className="font-medium capitalize">{incident.source.replace('_', ' ')}</p>
                                </div>
                            </div>

                            <div>
                                <p className="text-sm text-muted-foreground mb-1">Sujeto</p>
                                <div className="flex items-center gap-2">
                                    {incident.subject_type === 'driver' ? (
                                        <User className="size-4 text-muted-foreground" />
                                    ) : (
                                        <Car className="size-4 text-muted-foreground" />
                                    )}
                                    <span className="font-medium">
                                        {incident.subject_name || 'No especificado'}
                                    </span>
                                    {incident.subject_type && (
                                        <Badge variant="outline" className="text-xs">
                                            {incident.subject_type === 'driver' ? 'Conductor' : 'Vehículo'}
                                        </Badge>
                                    )}
                                </div>
                            </div>

                            {incident.ai_summary && (
                                <div>
                                    <p className="text-sm text-muted-foreground mb-1">Resumen AI</p>
                                    <p className="text-sm bg-muted/50 p-3 rounded-lg">
                                        {incident.ai_summary}
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Timeline */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Clock className="size-5" />
                                Timeline
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <p className="text-sm text-muted-foreground">Detectado</p>
                                <p className="font-medium">{incident.detected_at_human}</p>
                                {incident.detected_at && (
                                    <p className="text-xs text-muted-foreground">
                                        {new Date(incident.detected_at).toLocaleString('es-MX')}
                                    </p>
                                )}
                            </div>
                            {incident.resolved_at && (
                                <div>
                                    <p className="text-sm text-muted-foreground">Resuelto</p>
                                    <p className="font-medium">{incident.resolved_at_human}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {new Date(incident.resolved_at).toLocaleString('es-MX')}
                                    </p>
                                </div>
                            )}
                            <div>
                                <p className="text-sm text-muted-foreground">Señales vinculadas</p>
                                <p className="font-medium text-2xl">{incident.safety_signals.length}</p>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Safety Signals */}
                    <Card className="lg:col-span-3">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Radio className="size-5" />
                                Señales de Seguridad Vinculadas
                            </CardTitle>
                            <CardDescription>
                                Evidencia del incidente basada en señales del stream
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {incident.safety_signals.length === 0 ? (
                                <div className="text-center py-8 text-muted-foreground">
                                    <Radio className="size-8 mx-auto mb-2 opacity-50" />
                                    <p>No hay señales vinculadas</p>
                                </div>
                            ) : (
                                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                                    {incident.safety_signals.map((signal) => (
                                        <Link
                                            key={signal.id}
                                            href={`/safety-signals/${signal.id}`}
                                            className="block p-4 border rounded-lg hover:bg-muted/50 transition-colors"
                                        >
                                            <div className="flex items-center justify-between mb-2">
                                                <Badge variant="outline" className={roleColors[signal.pivot_role]}>
                                                    {signal.pivot_role === 'supporting' ? 'Soporte' : 
                                                     signal.pivot_role === 'contradicting' ? 'Contradicción' : 'Contexto'}
                                                </Badge>
                                                <span className="text-xs text-muted-foreground">
                                                    Relevancia: {Math.round(signal.pivot_relevance_score * 100)}%
                                                </span>
                                            </div>
                                            <p className="font-medium">
                                                {signal.primary_label_translated || signal.primary_behavior_label}
                                            </p>
                                            <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
                                                <Car className="size-3" />
                                                <span>{signal.vehicle_name || '-'}</span>
                                            </div>
                                            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                                <User className="size-3" />
                                                <span>{signal.driver_name || '-'}</span>
                                            </div>
                                            <p className="text-xs text-muted-foreground mt-2">
                                                {signal.occurred_at_human}
                                            </p>
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* AI Assessment — structured, no raw JSON for operators */}
                    {incident.ai_assessment && Object.keys(incident.ai_assessment).length > 0 && (
                        <IncidentAiEvaluationCard assessment={parseAiAssessment(incident.ai_assessment)} raw={incident.ai_assessment} />
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
