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
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { Separator } from '@/components/ui/separator';
import { ReviewPanel } from '@/components/samsara/review-panel';
import { type HumanStatus } from '@/types/samsara';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    ArrowLeft,
    Bell,
    BellRing,
    Calendar,
    Camera,
    Check,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    Clock,
    Copy,
    ExternalLink,
    Eye,
    Flag,
    Image as ImageIcon,
    Info,
    Loader2,
    MapPin,
    MessageSquare,
    Phone,
    Search,
    ShieldAlert,
    ShieldCheck,
    Sparkles,
    Timer,
    Truck,
    User,
    UserCheck,
    Wrench,
    X,
    XCircle,
    Zap,
} from 'lucide-react';
import { type LucideIcon } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

// ============================================================================
// TYPE DEFINITIONS
// Payload fields used throughout this component are documented inline.
// ============================================================================

interface ToolUsage {
    tool_name?: string;
    raw_tool_name?: string;
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

interface MediaInsight {
    camera?: string;
    analysis?: string;
    analysis_preview?: string;
    url?: string | null;
    download_url?: string | null;
    source?: 'media_insights' | 'camera_evidence' | 'tool_output';
}

interface AssessmentView {
    verdict?: string | null;
    likelihood?: string | null;
    reasoning?: string | null;
    evidence?: AssessmentEvidenceItem[];
}

/**
 * Full AI Assessment from ai_assessment column.
 * Fields: verdict, likelihood, confidence, reasoning, supporting_evidence,
 * risk_escalation, recommended_actions, requires_monitoring, next_check_minutes, monitoring_reason
 */
interface AIAssessment {
    verdict?: string | null;
    likelihood?: string | null;
    confidence?: number | string | null;
    reasoning?: string | null;
    supporting_evidence?: {
        camera?: {
            visual_summary?: string;
            media_urls?: string[];
        };
        data_consistency?: {
            has_conflict?: boolean;
            conflicts?: string[];
        };
        vehicle?: string;
        info?: string;
        safety?: string;
    };
    risk_escalation?: 'monitor' | 'warn' | 'notify' | 'escalate' | 'urgent' | 'call' | 'emergency' | null;
    recommended_actions?: string[];
    requires_monitoring?: boolean;
    next_check_minutes?: number;
    monitoring_reason?: string;
    dedupe_key?: string;
}

/**
 * Notification Decision from notification_decision column.
 * Fields: should_notify, escalation_level, channels_to_use, recipients, message_text, reason
 */
interface NotificationDecision {
    should_notify?: boolean;
    escalation_level?: string;
    channels_to_use?: string[];
    recipients?: { name?: string; phone?: string; type?: string }[];
    message_text?: string;
    reason?: string;
}

/**
 * Notification Execution results from notification_execution column.
 */
interface NotificationExecution {
    attempted?: boolean;
    results?: { channel?: string; success?: boolean; error?: string }[];
    throttled?: boolean;
    throttle_reason?: string;
    timestamp_utc?: string;
}

/**
 * Alert Context from alert_context column (triage output).
 * Fields: alert_type, alert_kind, alert_category, event_time_utc, time_window,
 * required_tools, investigation_plan, triage_notes, investigation_strategy,
 * proactive_flag, notification_contacts, location_description
 */
interface AlertContext {
    alert_type?: string;
    alert_kind?: string;
    alert_category?: string;
    event_time_utc?: string;
    time_window?: {
        start_utc?: string;
        end_utc?: string;
        analysis_minutes?: number;
    };
    required_tools?: string[];
    investigation_plan?: string[];
    triage_notes?: string;
    investigation_strategy?: string;
    proactive_flag?: boolean;
    notification_contacts?: {
        primary?: { name?: string; phone?: string };
        fallback?: { name?: string; phone?: string };
        missing_contacts?: boolean;
        contact_source?: string;
    };
    location_description?: string;
}

interface InvestigationMetadata {
    count: number;
    last_check?: string | null;
    last_check_at?: string | null;
    next_check_minutes?: number | null;
    next_check_available_at?: string | null;
    history: { timestamp: string; reason: string; count: number }[];
    max_investigations: number;
}

interface SamsaraEventPayload {
    id: number;
    samsara_event_id?: string | null;
    event_type?: string | null;
    event_description?: string | null;
    display_event_type?: string | null;
    severity: string;
    severity_label?: string | null;
    ai_status: string;
    ai_status_label?: string | null;
    vehicle_name?: string | null;
    vehicle_id?: string | null;
    driver_name?: string | null;
    driver_id?: string | null;
    occurred_at?: string | null;
    // Raw AI Assessment with all fields
    ai_assessment?: AIAssessment | null;
    // Formatted view for display
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
        items: { name: string; summary: string; details?: unknown }[];
    }[];
    investigation_metadata?: InvestigationMetadata;
    verdict_badge?: {
        verdict: string;
        likelihood?: string | null;
        urgency: 'high' | 'medium' | 'low' | 'unknown';
        color: string;
    };
    // Raw payload from Samsara
    raw_payload?: {
        data?: {
            conditions?: { description?: string; details?: unknown }[];
        };
        incidentUrl?: string;
    };
    // Alert context from triage
    alert_context?: AlertContext | null;
    // Notification decision
    notification_decision?: NotificationDecision | null;
    // Notification execution results
    notification_execution?: NotificationExecution | null;
    // Operational fields
    dedupe_key?: string | null;
    risk_escalation?: string | null;
    proactive_flag?: boolean;
    // Human review fields
    human_status?: HumanStatus;
    human_status_label?: string | null;
    reviewed_by?: { id: number; name: string } | null;
    reviewed_at?: string | null;
    reviewed_at_human?: string | null;
    needs_attention?: boolean;
    urgency_level?: 'high' | 'medium' | 'low';
    comments_count?: number;
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

const severityConfig: Record<string, { bg: string; text: string; border: string; icon: LucideIcon }> = {
    info: { 
        bg: 'bg-blue-500/10', 
        text: 'text-blue-600 dark:text-blue-400', 
        border: 'border-blue-500/30',
        icon: AlertCircle 
    },
    warning: { 
        bg: 'bg-amber-500/10', 
        text: 'text-amber-600 dark:text-amber-400', 
        border: 'border-amber-500/30',
        icon: AlertTriangle 
    },
    critical: { 
        bg: 'bg-red-500/10', 
        text: 'text-red-600 dark:text-red-400', 
        border: 'border-red-500/30',
        icon: ShieldAlert 
    },
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

const riskEscalationConfig: Record<string, { label: string; color: string; icon: LucideIcon }> = {
    monitor: { label: 'Monitorear', color: 'bg-slate-500/10 text-slate-600 dark:text-slate-400', icon: Eye },
    warn: { label: 'Advertir', color: 'bg-amber-500/10 text-amber-600 dark:text-amber-400', icon: AlertTriangle },
    notify: { label: 'Notificar', color: 'bg-blue-500/10 text-blue-600 dark:text-blue-400', icon: Bell },
    escalate: { label: 'Escalar', color: 'bg-orange-500/10 text-orange-600 dark:text-orange-400', icon: BellRing },
    urgent: { label: 'Urgente', color: 'bg-red-500/10 text-red-600 dark:text-red-400', icon: ShieldAlert },
    call: { label: 'Llamar', color: 'bg-red-500/10 text-red-600 dark:text-red-400', icon: Phone },
    emergency: { label: 'Emergencia', color: 'bg-red-600/20 text-red-700 dark:text-red-300', icon: ShieldAlert },
};

const getEventIcon = (iconName?: string | null): LucideIcon => {
    switch (iconName) {
        case 'alert-circle': return AlertCircle;
        case 'alert-triangle': return AlertTriangle;
        case 'shield-alert': return ShieldAlert;
        default: return Bell;
    }
};

const getAgentIcon = (agentName: string): LucideIcon => {
    switch (agentName) {
        case 'ingestion_agent':
        case 'triage_agent':
            return Zap;
        case 'panic_investigator':
        case 'investigator_agent':
            return Search;
        case 'final_agent':
            return MessageSquare;
        case 'notification_decision_agent':
            return Sparkles;
        default:
            return Wrench;
    }
};

const formatFullDate = (value?: string | null) => {
    if (!value) return 'Sin registro';
    return new Intl.DateTimeFormat('es-MX', {
        dateStyle: 'full',
        timeStyle: 'short',
    }).format(new Date(value));
};

const formatShortDateTime = (value?: string | null) => {
    if (!value) return 'Sin determinar';
    return new Intl.DateTimeFormat('es-MX', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
};

const formatDuration = (ms?: number | null): string => {
    if (!ms) return '';
    if (ms < 1000) return `${ms}ms`;
    return `${(ms / 1000).toFixed(1)}s`;
};

/**
 * Safely renders a value that might be a string or an object.
 */
const renderSafeValue = (value: string | Record<string, unknown> | null | undefined): string => {
    if (value === null || value === undefined) return 'Sin información';
    if (typeof value === 'string') return value;
    if (typeof value === 'object') {
        if ('name' in value && typeof value.name === 'string') return value.name;
        try { return JSON.stringify(value); } catch { return 'Datos complejos'; }
    }
    return String(value);
};

// ============================================================================
// COPY TO CLIPBOARD UTILITY
// ============================================================================

function CopyButton({ value, label }: { value: string; label?: string }) {
    const [copied, setCopied] = useState(false);

    const handleCopy = useCallback(async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            // Fallback for older browsers
            const textArea = document.createElement('textarea');
            textArea.value = value;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        }
    }, [value]);

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={handleCopy}
                    className="h-6 w-6 p-0 hover:bg-muted"
                >
                    {copied ? (
                        <Check className="size-3 text-emerald-500" />
                    ) : (
                        <Copy className="size-3 text-muted-foreground" />
                    )}
                </Button>
            </TooltipTrigger>
            <TooltipContent>
                {copied ? 'Copiado' : label ?? 'Copiar'}
            </TooltipContent>
        </Tooltip>
    );
}

// ============================================================================
// DECISION BAR COMPONENT (Sticky top bar)
// Shows: Severity, AI status, Human status, Risk escalation, Review requirement
// ============================================================================

interface DecisionBarProps {
    event: SamsaraEventPayload;
    reviewRequired: boolean;
}

function DecisionBar({ event, reviewRequired }: DecisionBarProps) {
    const severityStyle = severityConfig[event.severity] ?? severityConfig.info;
    const SeverityIcon = severityStyle.icon;
    
    const riskEscalation = event.ai_assessment?.risk_escalation ?? event.risk_escalation;
    const riskConfig = riskEscalation ? riskEscalationConfig[riskEscalation] : null;
    const RiskIcon = riskConfig?.icon ?? Eye;

    // Notification status
    const notificationSent = event.notification_execution?.attempted === true;
    const notificationThrottled = event.notification_execution?.throttled === true;

    return (
        <div className="sticky top-0 z-40 -mx-4 mb-4 border-b bg-background/95 px-4 py-3 backdrop-blur supports-[backdrop-filter]:bg-background/80">
            <div className="flex flex-wrap items-center justify-between gap-3">
                {/* Left: Status badges */}
                <div className="flex flex-wrap items-center gap-2">
                    {/* Severity Badge */}
                    <Badge className={`${severityStyle.bg} ${severityStyle.text} gap-1 px-2.5 py-1`}>
                        <SeverityIcon className="size-3.5" />
                        {event.severity_label ?? 'Info'}
                    </Badge>

                    {/* AI Status Badge */}
                    <Badge
                        className={`px-2.5 py-1 ${
                            event.ai_status === 'processing'
                                ? 'bg-sky-500/10 text-sky-600 dark:text-sky-400 animate-pulse'
                                : event.ai_status === 'investigating'
                                    ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400 animate-pulse'
                                    : event.ai_status === 'completed'
                                        ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                        : event.ai_status === 'failed'
                                            ? 'bg-red-500/10 text-red-600 dark:text-red-400'
                                            : 'bg-slate-500/10 text-slate-600 dark:text-slate-400'
                        }`}
                    >
                        {event.ai_status === 'processing' && <Loader2 className="size-3 animate-spin mr-1" />}
                        {event.ai_status === 'investigating' && <Search className="size-3 mr-1" />}
                        {event.ai_status === 'completed' && <CheckCircle2 className="size-3 mr-1" />}
                        {event.ai_status_label ?? 'Pendiente'}
                    </Badge>

                    {/* Human Status Badge */}
                    {event.human_status && event.human_status !== 'pending' && (
                        <Badge className="bg-blue-500/10 text-blue-600 dark:text-blue-400 gap-1 px-2.5 py-1">
                            <UserCheck className="size-3" />
                            {event.human_status_label}
                        </Badge>
                    )}

                    {/* Risk Escalation Badge */}
                    {riskConfig && (
                        <Badge className={`${riskConfig.color} gap-1 px-2.5 py-1`}>
                            <RiskIcon className="size-3" />
                            {riskConfig.label}
                        </Badge>
                    )}

                    {/* Notification Status */}
                    {notificationSent && (
                        <Badge className="bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 gap-1 px-2.5 py-1">
                            <Bell className="size-3" />
                            Notificado
                        </Badge>
                    )}
                    {notificationThrottled && (
                        <Badge className="bg-slate-500/10 text-slate-600 dark:text-slate-400 gap-1 px-2.5 py-1">
                            <Clock className="size-3" />
                            Throttled
                        </Badge>
                    )}
                </div>

                {/* Right: Review requirement + Back button */}
                <div className="flex items-center gap-2">
                    {/* Review Requirement Badge */}
                    <Badge
                        className={`px-2.5 py-1 ${
                            reviewRequired
                                ? 'bg-red-500/15 text-red-700 dark:text-red-300 border border-red-500/30'
                                : 'bg-slate-500/10 text-slate-600 dark:text-slate-400'
                        }`}
                    >
                        {reviewRequired ? (
                            <>
                                <Flag className="size-3 mr-1" />
                                Revisión requerida
                            </>
                        ) : (
                            <>
                                <Check className="size-3 mr-1" />
                                Revisión opcional
                            </>
                        )}
                    </Badge>

                    <Button variant="outline" size="sm" asChild className="gap-1.5">
                        <Link href={ALERTS_INDEX_URL}>
                            <ArrowLeft className="size-3.5" />
                            <span className="hidden sm:inline">Regresar</span>
                        </Link>
                    </Button>
                </div>
            </div>
        </div>
    );
}

// ============================================================================
// NEXT ACTIONS CARD COMPONENT
// Shows: recommended_actions, notification message, missing contact warnings
// ============================================================================

interface NextActionsCardProps {
    event: SamsaraEventPayload;
}

function NextActionsCard({ event }: NextActionsCardProps) {
    const recommendedActions = event.ai_assessment?.recommended_actions ?? [];
    const notificationMessage = event.notification_decision?.message_text;
    const notificationContacts = event.alert_context?.notification_contacts;
    const monitoringReason = event.ai_assessment?.monitoring_reason;

    const hasContent = recommendedActions.length > 0 || notificationMessage || notificationContacts?.missing_contacts || monitoringReason;

    if (!hasContent) return null;

    return (
        <Card className="border-2 border-primary/20 bg-primary/5">
            <CardHeader className="pb-3">
                <div className="flex items-center gap-2">
                    <div className="rounded-lg bg-primary/10 p-2">
                        <Sparkles className="size-4 text-primary" />
                    </div>
                    <div>
                        <CardTitle className="text-base">Próximos Pasos</CardTitle>
                        <CardDescription className="text-xs">Acciones recomendadas por la AI</CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent className="space-y-3">
                {/* Recommended Actions */}
                {recommendedActions.length > 0 && (
                    <div className="space-y-2">
                        {recommendedActions.map((action, idx) => (
                            <div
                                key={idx}
                                className="flex items-start gap-2 rounded-lg border bg-background p-3"
                            >
                                <div className="rounded-full bg-primary/10 p-1 mt-0.5">
                                    <ChevronRight className="size-3 text-primary" />
                                </div>
                                <span className="text-sm">{action}</span>
                            </div>
                        ))}
                    </div>
                )}

                {/* Monitoring Reason */}
                {monitoringReason && event.ai_status === 'investigating' && (
                    <div className="flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-50/50 dark:bg-amber-950/20 p-3">
                        <Search className="size-4 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                        <div>
                            <p className="text-xs font-medium text-amber-700 dark:text-amber-300 uppercase">En monitoreo</p>
                            <p className="text-sm text-amber-900 dark:text-amber-100">{monitoringReason}</p>
                        </div>
                    </div>
                )}

                {/* Notification Message Preview */}
                {notificationMessage && (
                    <div className="rounded-lg border bg-background p-3">
                        <div className="flex items-center justify-between mb-2">
                            <p className="text-xs font-medium text-muted-foreground uppercase">Mensaje de notificación</p>
                            <CopyButton value={notificationMessage} label="Copiar mensaje" />
                        </div>
                        <p className="text-sm text-muted-foreground italic">"{notificationMessage}"</p>
                    </div>
                )}

                {/* Missing Contacts Warning */}
                {notificationContacts?.missing_contacts && (
                    <div className="flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-50/50 dark:bg-amber-950/20 p-3">
                        <AlertTriangle className="size-4 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                        <div>
                            <p className="text-sm font-medium text-amber-900 dark:text-amber-100">
                                Contactos no disponibles
                            </p>
                            <p className="text-xs text-amber-700 dark:text-amber-300">
                                No se encontraron contactos para notificar. Verifique la configuración del vehículo o conductor.
                            </p>
                        </div>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

// ============================================================================
// CONTEXT CARD COMPONENT
// Shows: vehicle_name, driver_name, location, occurred_at, tags, external IDs
// ============================================================================

interface ContextCardProps {
    event: SamsaraEventPayload;
}

function ContextCard({ event }: ContextCardProps) {
    const [showDetails, setShowDetails] = useState(false);

    // Extract location from alert_context or payload_summary
    const location = event.alert_context?.location_description 
        ?? event.payload_summary?.find(p => p.label === 'Ubicación aproximada')?.value
        ?? null;

    // Extract external IDs and tags from raw_payload if available
    const rawPayload = event.raw_payload;
    const externalIds = rawPayload?.data?.conditions?.[0]?.details as { panicButton?: { vehicle?: { externalIds?: Record<string, string> } } } | undefined;
    const vehicleExternalIds = externalIds?.panicButton?.vehicle?.externalIds;

    // Event time in UTC from alert_context
    const eventTimeUtc = event.alert_context?.event_time_utc;

    return (
        <Card>
            <CardHeader className="pb-3">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <div className="rounded-lg bg-muted p-2">
                            <Info className="size-4 text-muted-foreground" />
                        </div>
                        <CardTitle className="text-base">Contexto</CardTitle>
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setShowDetails(!showDetails)}
                        className="gap-1 text-xs"
                    >
                        {showDetails ? 'Menos' : 'Más'}
                        <ChevronDown className={`size-3 transition-transform ${showDetails ? 'rotate-180' : ''}`} />
                    </Button>
                </div>
            </CardHeader>
            <CardContent className="space-y-3">
                {/* Primary Info Grid */}
                <div className="grid gap-3 sm:grid-cols-2">
                    {/* Vehicle */}
                    <div className="flex items-center gap-3 rounded-lg border bg-muted/30 p-3">
                        <Truck className="size-4 text-muted-foreground shrink-0" />
                        <div className="flex-1 min-w-0">
                            <p className="text-xs font-medium text-muted-foreground uppercase">Vehículo</p>
                            <div className="flex items-center gap-1">
                                <p className="text-sm font-medium truncate">
                                    {event.vehicle_name ?? 'Sin identificar'}
                                </p>
                                {event.vehicle_id && (
                                    <CopyButton value={event.vehicle_id} label="Copiar ID vehículo" />
                                )}
                            </div>
                        </div>
                        {!event.vehicle_name && (
                            <Badge variant="outline" className="text-xs text-amber-600 dark:text-amber-400 border-amber-500/30">
                                Faltante
                            </Badge>
                        )}
                    </div>

                    {/* Driver */}
                    <div className="flex items-center gap-3 rounded-lg border bg-muted/30 p-3">
                        <User className="size-4 text-muted-foreground shrink-0" />
                        <div className="flex-1 min-w-0">
                            <p className="text-xs font-medium text-muted-foreground uppercase">Conductor</p>
                            <div className="flex items-center gap-1">
                                <p className="text-sm font-medium truncate">
                                    {event.driver_name ?? 'Sin identificar'}
                                </p>
                                {event.driver_id && (
                                    <CopyButton value={event.driver_id} label="Copiar ID conductor" />
                                )}
                            </div>
                        </div>
                        {!event.driver_name && (
                            <Badge variant="outline" className="text-xs text-amber-600 dark:text-amber-400 border-amber-500/30">
                                Faltante
                            </Badge>
                        )}
                    </div>

                    {/* Location */}
                    <div className="flex items-center gap-3 rounded-lg border bg-muted/30 p-3">
                        <MapPin className="size-4 text-muted-foreground shrink-0" />
                        <div className="flex-1 min-w-0">
                            <p className="text-xs font-medium text-muted-foreground uppercase">Ubicación</p>
                            <p className="text-sm font-medium truncate">
                                {location ?? 'Sin ubicación'}
                            </p>
                        </div>
                    </div>

                    {/* Time */}
                    <div className="flex items-center gap-3 rounded-lg border bg-muted/30 p-3">
                        <Calendar className="size-4 text-muted-foreground shrink-0" />
                        <div className="flex-1 min-w-0">
                            <p className="text-xs font-medium text-muted-foreground uppercase">Fecha/Hora</p>
                            <p className="text-sm font-medium">
                                {formatShortDateTime(event.occurred_at)}
                            </p>
                            {eventTimeUtc && (
                                <p className="text-xs text-muted-foreground">
                                    UTC: {eventTimeUtc}
                                </p>
                            )}
                        </div>
                    </div>
                </div>

                {/* Expandable Details */}
                {showDetails && (
                    <div className="space-y-3 pt-2 border-t">
                        {/* Event Description */}
                        <div className="rounded-lg border bg-muted/30 p-3">
                            <p className="text-xs font-medium text-muted-foreground uppercase mb-1">Descripción</p>
                            <p className="text-sm">{event.event_description ?? 'Sin descripción'}</p>
                        </div>

                        {/* Samsara Event ID */}
                        {event.samsara_event_id && (
                            <div className="flex items-center justify-between rounded-lg border bg-muted/30 p-3">
                                <div>
                                    <p className="text-xs font-medium text-muted-foreground uppercase">Samsara Event ID</p>
                                    <p className="text-sm font-mono">{event.samsara_event_id}</p>
                                </div>
                                <CopyButton value={event.samsara_event_id} label="Copiar Event ID" />
                            </div>
                        )}

                        {/* Dedupe Key */}
                        {event.dedupe_key && (
                            <div className="flex items-center justify-between rounded-lg border bg-muted/30 p-3">
                                <div>
                                    <p className="text-xs font-medium text-muted-foreground uppercase">Dedupe Key</p>
                                    <p className="text-sm font-mono text-xs">{event.dedupe_key}</p>
                                </div>
                                <CopyButton value={event.dedupe_key} label="Copiar Dedupe Key" />
                            </div>
                        )}

                        {/* Incident URL */}
                        {rawPayload?.incidentUrl && (
                            <div className="flex items-center justify-between rounded-lg border bg-muted/30 p-3">
                                <div className="flex-1 min-w-0">
                                    <p className="text-xs font-medium text-muted-foreground uppercase">Incident URL</p>
                                    <p className="text-sm font-mono truncate text-xs">{rawPayload.incidentUrl}</p>
                                </div>
                                <div className="flex items-center gap-1">
                                    <CopyButton value={rawPayload.incidentUrl} label="Copiar URL" />
                                    <Button variant="ghost" size="sm" asChild className="h-6 w-6 p-0">
                                        <a href={rawPayload.incidentUrl} target="_blank" rel="noopener noreferrer">
                                            <ExternalLink className="size-3 text-muted-foreground" />
                                        </a>
                                    </Button>
                                </div>
                            </div>
                        )}

                        {/* External IDs */}
                        {vehicleExternalIds && Object.keys(vehicleExternalIds).length > 0 && (
                            <div className="rounded-lg border bg-muted/30 p-3">
                                <p className="text-xs font-medium text-muted-foreground uppercase mb-2">IDs Externos</p>
                                <div className="flex flex-wrap gap-2">
                                    {Object.entries(vehicleExternalIds).map(([key, value]) => (
                                        <div key={key} className="flex items-center gap-1">
                                            <Badge variant="secondary" className="text-xs font-mono">
                                                {key}: {value}
                                            </Badge>
                                            <CopyButton value={value} label={`Copiar ${key}`} />
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

// ============================================================================
// AI VERDICT CARD COMPONENT
// Shows: verdict, likelihood, reasoning summary
// ============================================================================

interface AIVerdictCardProps {
    event: SamsaraEventPayload;
}

function AIVerdictCard({ event }: AIVerdictCardProps) {
    if (!event.verdict_badge || event.ai_status !== 'completed') return null;

    const verdictStyle = verdictConfig[event.verdict_badge.urgency ?? 'unknown'];
    const VerdictIcon = verdictStyle.icon;

    return (
        <Card className={`border-2 ${verdictStyle.border} ${verdictStyle.bg}`}>
            <CardContent className="p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
                    <div className="shrink-0 rounded-xl bg-white/50 dark:bg-black/20 p-3">
                        <VerdictIcon className={`size-8 ${verdictStyle.text}`} />
                    </div>
                    <div className="flex-1 space-y-2">
                        <div>
                            <p className={`text-xs font-semibold uppercase tracking-wide ${verdictStyle.text} opacity-75`}>
                                Veredicto AI
                            </p>
                            <h2 className={`text-xl sm:text-2xl font-bold ${verdictStyle.text}`}>
                                {event.verdict_badge.verdict}
                            </h2>
                            {event.verdict_badge.likelihood && (
                                <p className={`text-sm mt-0.5 ${verdictStyle.text} opacity-80`}>
                                    Probabilidad: {event.verdict_badge.likelihood}
                                </p>
                            )}
                        </div>
                        {event.ai_assessment_view?.reasoning && (
                            <p className={`text-sm leading-relaxed ${verdictStyle.text} opacity-90`}>
                                {event.ai_assessment_view.reasoning}
                            </p>
                        )}
                    </div>
                    {event.ai_message && (
                        <CopyButton value={event.ai_message} label="Copiar mensaje AI" />
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

// ============================================================================
// UNIFIED EVIDENCE GALLERY COMPONENT
// Merges images from: media_insights, camera.media_urls, tool media_urls
// ============================================================================

interface UnifiedMediaItem {
    url: string;
    camera?: string;
    analysis?: string;
    source: 'media_insights' | 'camera_evidence' | 'tool_output';
}

interface EvidenceGalleryProps {
    event: SamsaraEventPayload;
    onSelectImage: (item: UnifiedMediaItem) => void;
}

function EvidenceGallery({ event, onSelectImage }: EvidenceGalleryProps) {
    // Merge all image sources and deduplicate by URL
    const allMedia = useMemo<UnifiedMediaItem[]>(() => {
        const urlSet = new Set<string>();
        const items: UnifiedMediaItem[] = [];

        // Source 1: media_insights from event (already processed)
        event.media_insights
            .filter(m => m.download_url)
            .forEach(m => {
                if (!urlSet.has(m.download_url!)) {
                    urlSet.add(m.download_url!);
                    items.push({
                        url: m.download_url!,
                        camera: m.camera,
                        analysis: m.analysis,
                        source: 'media_insights',
                    });
                }
            });

        // Source 2: camera.media_urls from ai_assessment.supporting_evidence
        const cameraUrls = event.ai_assessment?.supporting_evidence?.camera?.media_urls ?? [];
        cameraUrls.forEach((url, idx) => {
            if (!urlSet.has(url)) {
                urlSet.add(url);
                items.push({
                    url,
                    camera: `Cámara ${idx + 1}`,
                    source: 'camera_evidence',
                });
            }
        });

        // Source 3: tool media_urls from ai_actions.agents[].tools_used[].media_urls
        event.ai_actions.agents.forEach(agent => {
            agent.tools_used.forEach(tool => {
                (tool.media_urls ?? []).forEach((url, idx) => {
                    if (!urlSet.has(url)) {
                        urlSet.add(url);
                        items.push({
                            url,
                            camera: `${tool.tool_name ?? 'Tool'} - ${idx + 1}`,
                            analysis: tool.result_summary,
                            source: 'tool_output',
                        });
                    }
                });
            });
        });

        return items;
    }, [event.media_insights, event.ai_assessment, event.ai_actions]);

    if (allMedia.length === 0) return null;

    const sourceLabels: Record<string, string> = {
        media_insights: 'Procesado',
        camera_evidence: 'Cámara',
        tool_output: 'Herramienta',
    };

    return (
        <section>
            <div className="flex items-center justify-between mb-4">
                <div className="flex items-center gap-3">
                    <div className="rounded-lg bg-primary/10 p-2">
                        <Camera className="size-5 text-primary" />
                    </div>
                    <div>
                        <h2 className="text-lg font-semibold">Evidencia Visual</h2>
                        <p className="text-sm text-muted-foreground">
                            {allMedia.length} imagen{allMedia.length !== 1 ? 'es' : ''} capturada{allMedia.length !== 1 ? 's' : ''}
                        </p>
                    </div>
                </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {allMedia.map((item, idx) => (
                    <button
                        key={`${item.url}-${idx}`}
                        onClick={() => onSelectImage(item)}
                        className="group relative aspect-video overflow-hidden rounded-xl border bg-muted text-left transition-all hover:ring-2 hover:ring-primary hover:ring-offset-2"
                    >
                        <img
                            src={item.url}
                            alt={item.camera ?? `Evidencia ${idx + 1}`}
                            className="w-full h-full object-cover transition-transform group-hover:scale-105"
                            loading="lazy"
                        />
                        <div className="absolute inset-0 bg-gradient-to-t from-black/70 via-black/20 to-transparent opacity-0 group-hover:opacity-100 transition-opacity" />
                        <div className="absolute bottom-0 left-0 right-0 p-3 opacity-0 group-hover:opacity-100 transition-opacity">
                            <div className="flex items-center justify-between">
                                <Badge className="bg-black/60 text-white backdrop-blur-sm text-xs">
                                    {item.camera ?? `Imagen ${idx + 1}`}
                                </Badge>
                                <Badge variant="outline" className="bg-black/40 text-white/80 border-white/30 text-xs">
                                    {sourceLabels[item.source]}
                                </Badge>
                            </div>
                        </div>
                        {/* Always visible camera label */}
                        <div className="absolute top-2 left-2">
                            <Badge className="bg-black/50 text-white backdrop-blur-sm text-xs">
                                {item.camera ?? `#${idx + 1}`}
                            </Badge>
                        </div>
                    </button>
                ))}
            </div>
        </section>
    );
}

// ============================================================================
// IMAGE LIGHTBOX MODAL
// ============================================================================

interface ImageLightboxProps {
    image: UnifiedMediaItem | null;
    onClose: () => void;
}

function ImageLightbox({ image, onClose }: ImageLightboxProps) {
    return (
        <Dialog open={!!image} onOpenChange={() => onClose()}>
            <DialogContent className="max-w-4xl p-0 overflow-hidden">
                <DialogHeader className="p-4 pb-2">
                    <DialogTitle className="flex items-center gap-2">
                        <Camera className="size-4" />
                        {image?.camera ?? 'Evidencia visual'}
                    </DialogTitle>
                </DialogHeader>
                {image && (
                    <div className="relative">
                        <img
                            src={image.url}
                            alt={image.camera ?? 'Evidencia'}
                            className="w-full h-auto max-h-[70vh] object-contain bg-black"
                        />
                        {image.analysis && (
                            <div className="p-4 border-t bg-muted/50">
                                <p className="text-xs font-medium text-muted-foreground uppercase mb-1">Análisis AI</p>
                                <p className="text-sm">{image.analysis}</p>
                            </div>
                        )}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

// ============================================================================
// AI REASONING & DATA QUALITY SECTION
// Shows: confidence, data_consistency, conflicts, missing data chips
// ============================================================================

interface AIReasoningCardProps {
    event: SamsaraEventPayload;
}

function AIReasoningCard({ event }: AIReasoningCardProps) {
    const assessment = event.ai_assessment;
    if (!assessment && !event.ai_assessment_view) return null;

    const dataConsistency = assessment?.supporting_evidence?.data_consistency;
    const hasConflict = dataConsistency?.has_conflict === true;
    const conflicts = dataConsistency?.conflicts ?? [];

    // Evidence from ai_assessment_view
    const evidence = event.ai_assessment_view?.evidence ?? [];

    // Determine what data is missing
    const missingData: string[] = [];
    if (!event.driver_name) missingData.push('Conductor');
    if (!event.vehicle_name) missingData.push('Vehículo');
    if (!assessment?.supporting_evidence?.vehicle) missingData.push('Stats vehículo');
    if (!assessment?.supporting_evidence?.safety) missingData.push('Eventos seguridad');

    const confidence = assessment?.confidence;
    const confidenceValue = typeof confidence === 'number' 
        ? confidence 
        : typeof confidence === 'string' 
            ? parseFloat(confidence) 
            : null;

    return (
        <Card>
            <CardHeader className="pb-3">
                <div className="flex items-center gap-2">
                    <div className="rounded-lg bg-muted p-2">
                        <Sparkles className="size-4 text-muted-foreground" />
                    </div>
                    <div>
                        <CardTitle className="text-base">Análisis y Calidad de Datos</CardTitle>
                        <CardDescription className="text-xs">Razonamiento de la AI y consistencia</CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                {/* Confidence Score */}
                {confidenceValue !== null && !isNaN(confidenceValue) && (
                    <div className="flex items-center gap-3">
                        <span className="text-sm font-medium text-muted-foreground">Confianza:</span>
                        <div className="flex-1 h-2 bg-muted rounded-full overflow-hidden">
                            <div 
                                className={`h-full transition-all ${
                                    confidenceValue >= 0.7 
                                        ? 'bg-emerald-500' 
                                        : confidenceValue >= 0.4 
                                            ? 'bg-amber-500' 
                                            : 'bg-red-500'
                                }`}
                                style={{ width: `${Math.min(100, confidenceValue * 100)}%` }}
                            />
                        </div>
                        <span className="text-sm font-mono">{(confidenceValue * 100).toFixed(0)}%</span>
                    </div>
                )}

                {/* Data Conflicts Warning */}
                {hasConflict && (
                    <div className="rounded-lg border border-amber-500/30 bg-amber-50/50 dark:bg-amber-950/20 p-3">
                        <div className="flex items-start gap-2">
                            <AlertTriangle className="size-4 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                            <div>
                                <p className="text-sm font-medium text-amber-900 dark:text-amber-100">
                                    Conflicto de datos detectado
                                </p>
                                {conflicts.length > 0 && (
                                    <ul className="mt-1 space-y-1">
                                        {conflicts.map((conflict, idx) => (
                                            <li key={idx} className="text-xs text-amber-700 dark:text-amber-300">
                                                • {conflict}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        </div>
                    </div>
                )}

                {/* Missing Data Chips */}
                {missingData.length > 0 && (
                    <div>
                        <p className="text-xs font-medium text-muted-foreground uppercase mb-2">Datos faltantes</p>
                        <div className="flex flex-wrap gap-1.5">
                            {missingData.map(item => (
                                <Badge 
                                    key={item} 
                                    variant="outline" 
                                    className="text-xs text-amber-600 dark:text-amber-400 border-amber-500/30 bg-amber-50/50 dark:bg-amber-950/20"
                                >
                                    {item}
                                </Badge>
                            ))}
                        </div>
                    </div>
                )}

                {/* Supporting Evidence */}
                {evidence.length > 0 && (
                    <div className="space-y-2">
                        <p className="text-xs font-medium text-muted-foreground uppercase">Evidencia recopilada</p>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {evidence.map((item, idx) => (
                                <div key={idx} className="rounded-lg border bg-muted/30 p-3">
                                    <p className="text-xs font-medium text-muted-foreground">{item.label}</p>
                                    <p className="text-sm mt-0.5 line-clamp-3">
                                        {renderSafeValue(item.value)}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Camera Visual Summary */}
                {assessment?.supporting_evidence?.camera?.visual_summary && (
                    <div className="rounded-lg border bg-muted/30 p-3">
                        <p className="text-xs font-medium text-muted-foreground uppercase mb-1">Resumen visual de cámaras</p>
                        <p className="text-sm">{assessment.supporting_evidence.camera.visual_summary}</p>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

// ============================================================================
// AI EXECUTION TIMELINE COMPONENT
// Shows: agents, durations, tools used with collapsible details
// ============================================================================

interface AITimelineProps {
    event: SamsaraEventPayload;
}

function AITimeline({ event }: AITimelineProps) {
    const [expandedSteps, setExpandedSteps] = useState<number[]>([]);

    const toggleStep = (step: number) => {
        setExpandedSteps(prev => 
            prev.includes(step) ? prev.filter(s => s !== step) : [...prev, step]
        );
    };

    const { agents, total_duration_ms, total_tools_called } = event.ai_actions;

    if (agents.length === 0) return null;

    return (
        <Card>
            <CardHeader className="pb-3">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <div className="rounded-lg bg-muted p-2">
                            <Timer className="size-4 text-muted-foreground" />
                        </div>
                        <div>
                            <CardTitle className="text-base">Timeline de Ejecución</CardTitle>
                            <CardDescription className="text-xs">Agentes AI y herramientas utilizadas</CardDescription>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Badge variant="outline" className="text-xs">
                            {formatDuration(total_duration_ms)}
                        </Badge>
                        {total_tools_called > 0 && (
                            <Badge variant="secondary" className="text-xs">
                                {total_tools_called} herramientas
                            </Badge>
                        )}
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <div className="relative space-y-3 pl-6">
                    {/* Timeline line */}
                    <div className="absolute left-2 top-2 bottom-2 w-0.5 bg-border" />

                    {agents.map((agent, idx) => {
                        const AgentIcon = getAgentIcon(agent.name);
                        const isExpanded = expandedSteps.includes(agent.step);
                        const hasTools = agent.tools_used.length > 0;

                        return (
                            <div key={`${agent.step}-${agent.name}`} className="relative">
                                {/* Timeline dot */}
                                <div className="absolute -left-4 top-3 size-3 rounded-full bg-primary ring-4 ring-background" />

                                <div 
                                    className={`rounded-lg border transition-colors ${
                                        isExpanded ? 'bg-muted/50 border-primary/30' : 'bg-background hover:bg-muted/30'
                                    }`}
                                >
                                    <button
                                        onClick={() => hasTools && toggleStep(agent.step)}
                                        className="w-full p-3 text-left"
                                        disabled={!hasTools}
                                    >
                                        <div className="flex items-start gap-3">
                                            <div className="rounded-full bg-primary/10 p-1.5 text-primary shrink-0">
                                                <AgentIcon className="size-3.5" />
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center justify-between gap-2">
                                                    <p className="text-sm font-medium">{agent.title}</p>
                                                    <div className="flex items-center gap-2">
                                                        {agent.duration_ms && (
                                                            <Badge variant="outline" className="text-xs shrink-0">
                                                                {formatDuration(agent.duration_ms)}
                                                            </Badge>
                                                        )}
                                                        {hasTools && (
                                                            <ChevronDown 
                                                                className={`size-4 text-muted-foreground transition-transform ${isExpanded ? 'rotate-180' : ''}`}
                                                            />
                                                        )}
                                                    </div>
                                                </div>
                                                <p className="text-xs text-muted-foreground mt-0.5">
                                                    {agent.description}
                                                </p>
                                                {agent.summary && agent.summary !== 'Sin información generada para este paso.' && (
                                                    <p className="text-xs text-foreground/80 mt-1 line-clamp-2">
                                                        {agent.summary}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    </button>

                                    {/* Expanded Tools */}
                                    {isExpanded && hasTools && (
                                        <div className="px-3 pb-3 pt-1 border-t space-y-2">
                                            {agent.tools_used.map((tool, toolIdx) => (
                                                <div 
                                                    key={`${tool.tool_name}-${toolIdx}`}
                                                    className="flex items-start gap-2 rounded-md bg-background p-2 text-xs"
                                                >
                                                    <Wrench className="size-3 text-muted-foreground shrink-0 mt-0.5" />
                                                    <div className="flex-1 min-w-0">
                                                        <div className="flex items-center justify-between gap-2">
                                                            <span className="font-medium">{tool.tool_name}</span>
                                                            {tool.duration_ms && (
                                                                <span className="text-muted-foreground">
                                                                    {formatDuration(tool.duration_ms)}
                                                                </span>
                                                            )}
                                                        </div>
                                                        {tool.result_summary && (
                                                            <p className="text-muted-foreground mt-0.5 line-clamp-2">
                                                                {tool.result_summary}
                                                            </p>
                                                        )}
                                                        {tool.media_urls && tool.media_urls.length > 0 && (
                                                            <div className="flex items-center gap-1 mt-1">
                                                                <ImageIcon className="size-3 text-muted-foreground" />
                                                                <span className="text-muted-foreground">
                                                                    {tool.media_urls.length} imagen{tool.media_urls.length !== 1 ? 'es' : ''}
                                                                </span>
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </CardContent>
        </Card>
    );
}

// ============================================================================
// PROCESSING STATE CARD
// ============================================================================

interface ProcessingCardProps {
    simulatedTools: string[];
}

function ProcessingCard({ simulatedTools }: ProcessingCardProps) {
    const allTools = [
        'Obteniendo estadísticas del vehículo...',
        'Consultando información del vehículo...',
        'Identificando conductor asignado...',
        'Revisando eventos de seguridad...',
        'Analizando imágenes de cámaras con IA...',
        'Generando veredicto final...'
    ];

    return (
        <Card className="border-2 border-sky-500/30 bg-sky-50/50 dark:bg-sky-950/20">
            <CardHeader className="pb-3">
                <div className="flex items-center gap-3">
                    <Loader2 className="size-5 animate-spin text-sky-600 dark:text-sky-400" />
                    <div className="flex-1">
                        <CardTitle className="text-sky-900 dark:text-sky-100">
                            Procesando evento...
                        </CardTitle>
                        <CardDescription className="text-sky-700 dark:text-sky-300">
                            La AI está analizando el evento. Aproximadamente 25 segundos.
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
                    {simulatedTools.length < allTools.length && (
                        <div className="flex items-center gap-2 rounded-lg border border-sky-200 bg-white/50 p-2 text-sm dark:border-sky-800 dark:bg-sky-950/30">
                            <Loader2 className="size-4 shrink-0 animate-spin text-sky-500" />
                            <span className="text-sky-700 dark:text-sky-300">
                                {allTools[simulatedTools.length]}
                            </span>
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

// ============================================================================
// INVESTIGATING STATE CARD
// ============================================================================

interface InvestigatingCardProps {
    event: SamsaraEventPayload;
    nextInvestigationCountdownText: string | null;
    isRevalidationImminent: boolean;
}

function InvestigatingCard({ event, nextInvestigationCountdownText, isRevalidationImminent }: InvestigatingCardProps) {
    const metadata = event.investigation_metadata;
    if (!metadata) return null;

    return (
        <Card className="border-2 border-amber-500/30 bg-amber-50/50 dark:bg-amber-950/20">
            <CardHeader className="pb-3">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <div className="rounded-full bg-amber-500/20 p-2">
                            {isRevalidationImminent ? (
                                <Loader2 className="size-4 animate-spin text-amber-600 dark:text-amber-400" />
                            ) : (
                                <Search className="size-4 text-amber-600 dark:text-amber-400" />
                            )}
                        </div>
                        <div>
                            <CardTitle className="text-amber-900 dark:text-amber-100 text-base">
                                {isRevalidationImminent ? 'Ejecutando re-validación...' : 'Evento bajo investigación'}
                            </CardTitle>
                            <CardDescription className="text-amber-700 dark:text-amber-300 text-xs">
                                {isRevalidationImminent 
                                    ? 'La AI está analizando nueva información'
                                    : 'La AI continúa monitoreando este evento'}
                            </CardDescription>
                        </div>
                    </div>
                    <Badge className={`${isRevalidationImminent ? 'animate-pulse' : ''} bg-amber-500 text-white`}>
                        {metadata.count} de {metadata.max_investigations}
                    </Badge>
                </div>
            </CardHeader>
            <CardContent>
                <div className="grid gap-3 sm:grid-cols-2">
                    {metadata.last_check && (
                        <div className="rounded-lg border border-amber-200 bg-white/50 p-3 dark:border-amber-800 dark:bg-amber-950/30">
                            <p className="text-xs font-semibold uppercase text-amber-700 dark:text-amber-400">Última verificación</p>
                            <p className="text-sm font-medium text-amber-900 dark:text-amber-100">{metadata.last_check}</p>
                        </div>
                    )}
                    {!isRevalidationImminent && nextInvestigationCountdownText && (
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
                                    <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-500 opacity-75" />
                                    <span className="relative inline-flex rounded-full h-2 w-2 bg-amber-600" />
                                </span>
                                Analizando...
                            </p>
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

// ============================================================================
// MAIN COMPONENT
// ============================================================================

export default function SamsaraAlertShow({ event, breadcrumbs }: ShowProps) {
    const [isPolling, setIsPolling] = useState(false);
    const [previousStatus, setPreviousStatus] = useState(event.ai_status);
    const [simulatedTools, setSimulatedTools] = useState<string[]>([]);
    const [nextInvestigationEtaMs, setNextInvestigationEtaMs] = useState<number | null>(null);
    const [selectedImage, setSelectedImage] = useState<UnifiedMediaItem | null>(null);

    const isProcessing = event.ai_status === 'processing';
    const isInvestigating = event.ai_status === 'investigating';
    const isCompleted = event.ai_status === 'completed';
    const eventLabel = event.display_event_type ?? 'Alerta procesada por AI';

    /**
     * REVIEW REQUIRED LOGIC
     * Computed from existing payload fields:
     * - event.severity === 'critical'
     * - event.ai_assessment?.risk_escalation in ['notify', 'escalate', 'urgent', 'call', 'emergency']
     * - event.needs_attention === true
     * - event.urgency_level === 'high'
     * - event.notification_decision?.should_notify === true
     */
    const reviewRequired = useMemo(() => {
        if (event.severity === 'critical') return true;
        
        const riskEscalation = event.ai_assessment?.risk_escalation ?? event.risk_escalation;
        if (riskEscalation && ['notify', 'escalate', 'urgent', 'call', 'emergency'].includes(riskEscalation)) {
            return true;
        }
        
        if (event.needs_attention === true) return true;
        if (event.urgency_level === 'high') return true;
        if (event.notification_decision?.should_notify === true) return true;
        
        return false;
    }, [event]);

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
                        setSimulatedTools(prev => [...prev, tools[currentToolIndex]]);
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

        if (nextInvestigationEtaMs === 0) return null;

        const totalSeconds = Math.ceil(nextInvestigationEtaMs / 1000);
        if (totalSeconds >= 60) {
            const minutes = Math.floor(totalSeconds / 60);
            const seconds = totalSeconds % 60;
            return `En ${minutes}m ${seconds.toString().padStart(2, '0')}s`;
        }

        return `En ${totalSeconds} segundos`;
    }, [event.investigation_metadata?.next_check_minutes, isInvestigating, nextInvestigationEtaMs]);

    return (
        <AppLayout breadcrumbs={computedBreadcrumbs}>
            <Head title={`Detalle ${eventLabel}`} />

            <div className="flex flex-1 flex-col gap-6 p-4 max-w-7xl mx-auto">
                {/* ============================================================
                    DECISION BAR (Sticky)
                    Uses: severity, ai_status, human_status, risk_escalation,
                    notification_execution, needs_attention, urgency_level,
                    notification_decision.should_notify
                ============================================================ */}
                <DecisionBar event={event} reviewRequired={reviewRequired} />

                {/* ============================================================
                    MAIN CONTENT GRID
                    Left column: Main content
                    Right column: Review panel (on larger screens)
                ============================================================ */}
                <div className="grid gap-6 lg:grid-cols-3">
                    {/* Main Content Column */}
                    <div className="lg:col-span-2 space-y-6">
                        {/* Processing State Card */}
                        {isProcessing && isPolling && (
                            <ProcessingCard simulatedTools={simulatedTools} />
                        )}

                        {/* Investigating State Card */}
                        {isInvestigating && (
                            <InvestigatingCard 
                                event={event}
                                nextInvestigationCountdownText={nextInvestigationCountdownText}
                                isRevalidationImminent={isRevalidationImminent}
                            />
                        )}

                        {/* AI Verdict Card - Hero section when completed */}
                        <AIVerdictCard event={event} />

                        {/* Next Actions Card */}
                        <NextActionsCard event={event} />

                        {/* Context Card */}
                        <ContextCard event={event} />

                        {/* Evidence Gallery */}
                        <EvidenceGallery 
                            event={event} 
                            onSelectImage={setSelectedImage}
                        />

                        {/* AI Reasoning & Data Quality */}
                        <AIReasoningCard event={event} />

                        {/* AI Execution Timeline */}
                        <AITimeline event={event} />
                    </div>

                    {/* Right Column: Review Panel */}
                    <div className="lg:col-span-1">
                        <div className="sticky top-20">
                            <Card className="border-2 border-primary/20">
                                <CardHeader className="pb-3">
                                    <div className="flex items-center gap-2">
                                        <div className="rounded-lg bg-primary/10 p-2">
                                            <UserCheck className="size-4 text-primary" />
                                        </div>
                                        <div>
                                            <CardTitle className="text-base">Revisión Humana</CardTitle>
                                            <CardDescription className="text-xs">
                                                {reviewRequired 
                                                    ? 'Esta alerta requiere revisión'
                                                    : 'Revisión opcional'}
                                            </CardDescription>
                                        </div>
                                        {reviewRequired && (
                                            <Badge className="ml-auto bg-red-500/15 text-red-700 dark:text-red-300 border border-red-500/30 text-xs">
                                                Requerida
                                            </Badge>
                                        )}
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <ReviewPanel
                                        eventId={event.id}
                                        currentStatus={(event.human_status ?? 'pending') as HumanStatus}
                                        aiTimeline={event.timeline}
                                        aiTotalDuration={event.ai_actions.total_duration_ms}
                                        aiTotalTools={event.ai_actions.total_tools_called}
                                    />
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                </div>

                {/* Image Lightbox Modal */}
                <ImageLightbox 
                    image={selectedImage} 
                    onClose={() => setSelectedImage(null)} 
                />
            </div>
        </AppLayout>
    );
}
