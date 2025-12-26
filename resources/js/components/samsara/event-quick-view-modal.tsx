import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { ReviewPanel } from '@/components/samsara/review-panel';
import { type HumanStatus } from '@/types/samsara';
import { Link } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    ArrowRight,
    Bell,
    Calendar,
    Camera,
    CheckCircle2,
    ChevronDown,
    ChevronUp,
    ExternalLink,
    Eye,
    Flag,
    Loader2,
    Search,
    ShieldAlert,
    Slash,
    Sparkles,
    Truck,
    User,
    UserCheck,
} from 'lucide-react';
import { type LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

// ============================================================================
// TYPE DEFINITIONS
// ============================================================================

type UrgencyLevel = 'high' | 'medium' | 'low';

interface EventQuickViewModalProps {
    event: {
        id: number;
        event_title?: string | null;
        event_type?: string | null;
        event_description?: string | null;
        event_icon?: string | null;
        severity: string;
        severity_label?: string | null;
        ai_status: string;
        ai_status_label?: string | null;
        vehicle_name?: string | null;
        driver_name?: string | null;
        occurred_at?: string | null;
        occurred_at_human?: string | null;
        ai_message_preview?: string | null;
        has_images?: boolean;
        verdict_summary?: {
            verdict: string;
            likelihood?: string | null;
            urgency: UrgencyLevel;
        } | null;
        investigation_metadata?: {
            count: number;
            last_check?: string | null;
            last_check_at?: string | null;
            next_check_minutes?: number | null;
            next_check_available_at?: string | null;
            max_investigations: number;
        } | null;
        // Human review fields
        human_status?: HumanStatus;
        human_status_label?: string | null;
        needs_attention?: boolean;
        urgency_level?: UrgencyLevel;
    } | null;
    open: boolean;
    onClose: () => void;
}

// ============================================================================
// STYLE CONFIGURATIONS
// ============================================================================

const severityStyles: Record<string, { badge: string; icon: LucideIcon }> = {
    info: {
        badge: 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-200',
        icon: AlertCircle,
    },
    warning: {
        badge: 'bg-amber-100 text-amber-900 dark:bg-amber-500/20 dark:text-amber-200',
        icon: AlertTriangle,
    },
    critical: {
        badge: 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-100 font-semibold',
        icon: ShieldAlert,
    },
};

const statusStyles: Record<string, { badge: string; icon: LucideIcon }> = {
    pending: { badge: 'bg-slate-100 text-slate-800 dark:bg-slate-500/20 dark:text-slate-200', icon: Bell },
    processing: { badge: 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-100', icon: Loader2 },
    investigating: { badge: 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-100', icon: Search },
    completed: { badge: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-100', icon: CheckCircle2 },
    failed: { badge: 'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-100', icon: AlertCircle },
};

const urgencyStyles: Record<UrgencyLevel, string> = {
    high: 'bg-red-50 border-red-300 text-red-800 dark:bg-red-500/10 dark:border-red-500/30 dark:text-red-200',
    medium: 'bg-amber-50 border-amber-300 text-amber-800 dark:bg-amber-500/10 dark:border-amber-500/30 dark:text-amber-200',
    low: 'bg-emerald-50 border-emerald-300 text-emerald-800 dark:bg-emerald-500/10 dark:border-emerald-500/30 dark:text-emerald-200',
};

const humanStatusStyles: Record<HumanStatus, { badge: string; icon: LucideIcon }> = {
    pending: { badge: 'bg-slate-100 text-slate-800 dark:bg-slate-500/20 dark:text-slate-200', icon: Bell },
    reviewed: { badge: 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-200', icon: Eye },
    flagged: { badge: 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200', icon: Flag },
    resolved: { badge: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200', icon: CheckCircle2 },
    false_positive: { badge: 'bg-slate-100 text-slate-600 dark:bg-slate-500/20 dark:text-slate-300', icon: Slash },
};

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

const getEventIcon = (iconName?: string | null): LucideIcon => {
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

const formatEventTitle = (event: EventQuickViewModalProps['event']): string => {
    if (!event) return 'Evento';
    return event.event_description ?? event.event_title ?? event.event_type ?? 'Evento sin título';
};

// ============================================================================
// EVENT QUICK VIEW MODAL COMPONENT
// ============================================================================

export function EventQuickViewModal({ event, open, onClose }: EventQuickViewModalProps) {
    const [showReviewPanel, setShowReviewPanel] = useState(false);
    const [currentHumanStatus, setCurrentHumanStatus] = useState<HumanStatus>(
        event?.human_status ?? 'pending'
    );

    // Reset state when event changes
    useEffect(() => {
        if (event) {
            setCurrentHumanStatus(event.human_status ?? 'pending');
        }
    }, [event?.id, event?.human_status]);

    // Close review panel when modal closes
    useEffect(() => {
        if (!open) {
            setShowReviewPanel(false);
        }
    }, [open]);

    if (!event) return null;

    const Icon = getEventIcon(event.event_icon);
    const severityConfig = severityStyles[event.severity] ?? severityStyles.info;
    const statusConfig = statusStyles[event.ai_status] ?? statusStyles.pending;
    const StatusIcon = statusConfig.icon;
    
    const isProcessing = event.ai_status === 'processing';
    const isInvestigating = event.ai_status === 'investigating';
    const isFailed = event.ai_status === 'failed';

    return (
        <Dialog open={open} onOpenChange={onClose}>
            <DialogContent className="max-w-2xl max-h-[85vh] overflow-y-auto">
                <DialogHeader className="pb-2">
                    <div className="flex items-start gap-3">
                        <div className={`rounded-full p-2.5 shrink-0 ${
                            event.severity === 'critical' 
                                ? 'bg-red-100 dark:bg-red-500/20' 
                                : 'bg-primary/10'
                        }`}>
                            <Icon className={`size-5 ${
                                event.severity === 'critical' 
                                    ? 'text-red-600 dark:text-red-400' 
                                    : 'text-primary'
                            }`} />
                        </div>
                        <div className="flex-1 min-w-0">
                            <DialogTitle className="text-lg leading-tight line-clamp-2">
                                {formatEventTitle(event)}
                            </DialogTitle>
                            <DialogDescription className="mt-1">
                                Vista rápida • ID #{event.id}
                            </DialogDescription>
                        </div>
                    </div>
                    {/* Status badges */}
                    <div className="flex flex-wrap gap-2 mt-3">
                        <Badge className={severityConfig.badge}>
                            {event.severity_label ?? 'Info'}
                        </Badge>
                        <Badge className={statusConfig.badge}>
                            {(isProcessing || isInvestigating) && (
                                <StatusIcon className="size-3 mr-1 animate-spin" />
                            )}
                            {event.ai_status_label ?? 'Pendiente'}
                        </Badge>
                        {event.has_images && (
                            <Badge variant="outline" className="gap-1">
                                <Camera className="size-3" />
                                Evidencia
                            </Badge>
                        )}
                    </div>
                </DialogHeader>

                <div className="space-y-4 mt-2">
                    {/* ========================================
                        BASIC INFO
                    ======================================== */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div className="flex items-center gap-3 rounded-lg border bg-muted/30 p-3">
                            <Truck className="size-4 text-muted-foreground shrink-0" />
                            <div className="min-w-0">
                                <p className="text-xs text-muted-foreground">Unidad</p>
                                <p className="text-sm font-medium truncate">
                                    {event.vehicle_name ?? 'Sin identificar'}
                                </p>
                            </div>
                        </div>
                        <div className="flex items-center gap-3 rounded-lg border bg-muted/30 p-3">
                            <User className="size-4 text-muted-foreground shrink-0" />
                            <div className="min-w-0">
                                <p className="text-xs text-muted-foreground">Conductor</p>
                                <p className="text-sm font-medium truncate">
                                    {event.driver_name ?? 'No detectado'}
                                </p>
                            </div>
                        </div>
                        <div className="col-span-1 sm:col-span-2 flex items-center gap-3 rounded-lg border bg-muted/30 p-3">
                            <Calendar className="size-4 text-muted-foreground shrink-0" />
                            <div className="min-w-0">
                                <p className="text-xs text-muted-foreground">Momento del evento</p>
                                <p className="text-sm font-medium">
                                    {event.occurred_at_human ?? 'Sin fecha'}
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* ========================================
                        PROCESSING INDICATOR
                    ======================================== */}
                    {isProcessing && (
                        <div className="flex items-center gap-3 rounded-lg border-2 border-sky-500/30 bg-sky-50/50 p-4 dark:bg-sky-950/20">
                            <Loader2 className="size-5 animate-spin text-sky-600 dark:text-sky-400 shrink-0" />
                            <div>
                                <p className="font-semibold text-sky-900 dark:text-sky-100">
                                    Procesando evento...
                                </p>
                                <p className="text-sm text-sky-700 dark:text-sky-300">
                                    La AI está analizando este evento. Tomará aproximadamente 25 segundos.
                                </p>
                            </div>
                        </div>
                    )}

                    {/* ========================================
                        INVESTIGATION METADATA
                    ======================================== */}
                    {isInvestigating && event.investigation_metadata && (
                        <div className="rounded-lg border-2 border-amber-500/30 bg-amber-50/50 p-4 dark:bg-amber-950/20">
                            <div className="flex items-center gap-2 mb-3">
                                <Search className="size-5 text-amber-600 dark:text-amber-400 animate-pulse" />
                                <p className="font-semibold text-amber-900 dark:text-amber-100">
                                    Bajo investigación
                                </p>
                                <Badge className="ml-auto bg-amber-500 text-white">
                                    {event.investigation_metadata.count} de {event.investigation_metadata.max_investigations}
                                </Badge>
                            </div>
                            <div className="grid grid-cols-2 gap-3 text-sm">
                                {event.investigation_metadata.last_check && (
                                    <div>
                                        <p className="text-xs text-amber-700 dark:text-amber-400">
                                            Última verificación
                                        </p>
                                        <p className="font-medium text-amber-900 dark:text-amber-100">
                                            {event.investigation_metadata.last_check}
                                        </p>
                                    </div>
                                )}
                                {event.investigation_metadata.next_check_minutes && (
                                    <div>
                                        <p className="text-xs text-amber-700 dark:text-amber-400">
                                            Próxima verificación
                                        </p>
                                        <p className="font-medium text-amber-900 dark:text-amber-100">
                                            En {event.investigation_metadata.next_check_minutes} minutos
                                        </p>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* ========================================
                        FAILED STATUS
                    ======================================== */}
                    {isFailed && (
                        <div className="flex items-center gap-3 rounded-lg border-2 border-rose-500/30 bg-rose-50/50 p-4 dark:bg-rose-950/20">
                            <AlertCircle className="size-5 text-rose-600 dark:text-rose-400 shrink-0" />
                            <div>
                                <p className="font-semibold text-rose-900 dark:text-rose-100">
                                    Error en el procesamiento
                                </p>
                                <p className="text-sm text-rose-700 dark:text-rose-300">
                                    Este evento requiere revisión manual. Abre el análisis completo para más detalles.
                                </p>
                            </div>
                        </div>
                    )}

                    {/* ========================================
                        VERDICT SUMMARY
                    ======================================== */}
                    {event.verdict_summary && (
                        <div className={`flex items-center gap-3 rounded-lg border-2 p-4 ${urgencyStyles[event.verdict_summary.urgency]}`}>
                            <Sparkles className="size-5 shrink-0" />
                            <div>
                                <p className="text-xs font-semibold uppercase opacity-75">
                                    Veredicto AI
                                </p>
                                <p className="font-bold text-base">
                                    {event.verdict_summary.verdict}
                                </p>
                                {event.verdict_summary.likelihood && (
                                    <p className="text-sm opacity-80">
                                        Probabilidad: {event.verdict_summary.likelihood}
                                    </p>
                                )}
                            </div>
                        </div>
                    )}

                    {/* ========================================
                        AI MESSAGE PREVIEW
                    ======================================== */}
                    {event.ai_message_preview && (
                        <div className="rounded-lg border bg-muted/30 p-4">
                            <div className="flex items-center gap-2 mb-2">
                                <Sparkles className="size-4 text-primary" />
                                <p className="text-sm font-semibold">Mensaje de la AI</p>
                            </div>
                            <p className="text-sm text-muted-foreground leading-relaxed">
                                {event.ai_message_preview}
                            </p>
                        </div>
                    )}

                    {/* ========================================
                        NEEDS ATTENTION INDICATOR
                    ======================================== */}
                    {event.needs_attention && (
                        <div className="flex items-center gap-3 rounded-lg border-2 border-amber-500/50 bg-amber-50/50 p-3 dark:bg-amber-950/20">
                            <UserCheck className="size-5 text-amber-600 dark:text-amber-400 shrink-0" />
                            <div className="flex-1">
                                <p className="text-sm font-semibold text-amber-900 dark:text-amber-100">
                                    Requiere revisión humana
                                </p>
                                <p className="text-xs text-amber-700 dark:text-amber-300">
                                    Esta alerta necesita validación de un operador
                                </p>
                            </div>
                        </div>
                    )}

                    {/* ========================================
                        HUMAN REVIEW TOGGLE
                    ======================================== */}
                    <div className="border-t pt-4">
                        <button
                            onClick={() => setShowReviewPanel(!showReviewPanel)}
                            className="flex items-center justify-between w-full text-sm font-medium text-muted-foreground hover:text-foreground transition-colors py-1"
                        >
                            <div className="flex items-center gap-2">
                                <UserCheck className="size-4" />
                                <span>Revisión humana</span>
                                {currentHumanStatus !== 'pending' && (
                                    <Badge className={humanStatusStyles[currentHumanStatus].badge}>
                                        {(() => {
                                            const HumanIcon = humanStatusStyles[currentHumanStatus].icon;
                                            return <HumanIcon className="size-3 mr-1" />;
                                        })()}
                                        {event.human_status_label ?? 'Sin revisar'}
                                    </Badge>
                                )}
                            </div>
                            {showReviewPanel ? (
                                <ChevronUp className="size-4" />
                            ) : (
                                <ChevronDown className="size-4" />
                            )}
                        </button>

                        {showReviewPanel && (
                            <div className="mt-4">
                                <ReviewPanel
                                    eventId={event.id}
                                    currentStatus={currentHumanStatus}
                                    onStatusChange={(newStatus) => setCurrentHumanStatus(newStatus)}
                                />
                            </div>
                        )}
                    </div>

                    {/* ========================================
                        ACTION BUTTONS
                    ======================================== */}
                    <div className="flex flex-col sm:flex-row gap-2 pt-2">
                        <Button asChild className="flex-1 gap-2">
                            <Link href={`/samsara/alerts/${event.id}`}>
                                Ver análisis completo
                                <ArrowRight className="size-4" />
                            </Link>
                        </Button>
                        <Button variant="outline" asChild className="gap-2">
                            <a
                                href={`/samsara/alerts/${event.id}`}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <ExternalLink className="size-4" />
                                Nueva pestaña
                            </a>
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
