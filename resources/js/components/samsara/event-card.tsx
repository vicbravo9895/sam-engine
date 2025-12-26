import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { type HumanStatus } from '@/types/samsara';
import {
    AlertCircle,
    AlertTriangle,
    Bell,
    Camera,
    CheckCircle2,
    Clock,
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
import { useEffect, useState, memo } from 'react';

// ============================================================================
// TYPE DEFINITIONS
// ============================================================================

type UrgencyLevel = 'high' | 'medium' | 'low';

interface EventCardProps {
    event: {
        id: number;
        event_title?: string | null;
        event_type?: string | null;
        event_description?: string | null;
        event_icon?: string | null;
        vehicle_name?: string | null;
        driver_name?: string | null;
        occurred_at_human?: string | null;
        occurred_at?: string | null;
        created_at?: string | null;
        severity: string;
        severity_label?: string | null;
        ai_status: string;
        ai_status_label?: string | null;
        ai_message_preview?: string | null;
        has_images?: boolean;
        verdict_summary?: {
            verdict: string;
            likelihood?: string | null;
            urgency: UrgencyLevel;
        } | null;
        investigation_metadata?: {
            count: number;
            max_investigations: number;
        } | null;
        // Human review fields
        human_status?: HumanStatus;
        human_status_label?: string | null;
        needs_attention?: boolean;
        urgency_level?: UrgencyLevel;
    };
    onClick: () => void;
    showProgress?: boolean;
}

// ============================================================================
// STYLE CONFIGURATIONS
// ============================================================================

const severityStyles: Record<string, { badge: string; border: string }> = {
    info: {
        badge: 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-200',
        border: 'border-l-blue-500 dark:border-l-blue-400',
    },
    warning: {
        badge: 'bg-amber-100 text-amber-900 dark:bg-amber-500/20 dark:text-amber-200',
        border: 'border-l-amber-500 dark:border-l-amber-400',
    },
    critical: {
        badge: 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-100 font-semibold',
        border: 'border-l-red-500 dark:border-l-red-400',
    },
};

const statusStyles: Record<string, string> = {
    pending: 'bg-slate-100 text-slate-800 dark:bg-slate-500/20 dark:text-slate-200',
    processing: 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-100',
    investigating: 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-100',
    completed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-100',
    failed: 'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-100 font-semibold',
};

const urgencyStyles: Record<UrgencyLevel, string> = {
    high: 'bg-red-50 border-red-200 text-red-800 dark:bg-red-500/10 dark:border-red-500/30 dark:text-red-200',
    medium: 'bg-amber-50 border-amber-200 text-amber-800 dark:bg-amber-500/10 dark:border-amber-500/30 dark:text-amber-200',
    low: 'bg-emerald-50 border-emerald-200 text-emerald-800 dark:bg-emerald-500/10 dark:border-emerald-500/30 dark:text-emerald-200',
};

const humanStatusStyles: Record<HumanStatus, { badge: string; icon: LucideIcon }> = {
    pending: { badge: 'text-slate-500', icon: Clock },
    reviewed: { badge: 'text-blue-500', icon: Eye },
    flagged: { badge: 'text-amber-500', icon: Flag },
    resolved: { badge: 'text-emerald-500', icon: CheckCircle2 },
    false_positive: { badge: 'text-slate-400', icon: Slash },
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

const formatEventTitle = (event: EventCardProps['event']): string => {
    return event.event_description ?? event.event_title ?? event.event_type ?? 'Evento sin título';
};

const formatDate = (value?: string | null): string => {
    if (!value) return 'Sin fecha';
    return new Intl.DateTimeFormat('es-MX', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
};

// ============================================================================
// PROCESSING PROGRESS TOOLS
// ============================================================================

const PROCESSING_TOOLS = [
    'Obteniendo estadísticas...',
    'Consultando vehículo...',
    'Identificando conductor...',
    'Analizando cámaras...',
    'Generando veredicto...',
];

const PROCESSING_DURATION_MS = 25000;

// ============================================================================
// EVENT CARD COMPONENT
// ============================================================================

export const EventCard = memo(function EventCard({ event, onClick, showProgress = false }: EventCardProps) {
    const Icon = getEventIcon(event.event_icon);
    const [progress, setProgress] = useState(0);
    const [currentTool, setCurrentTool] = useState(0);

    // Calculate progress based on created_at (25 seconds from creation)
    useEffect(() => {
        if (!showProgress || !event.created_at) {
            setProgress(0);
            setCurrentTool(0);
            return;
        }

        const toolDuration = PROCESSING_DURATION_MS / PROCESSING_TOOLS.length;
        const eventCreatedAt = new Date(event.created_at).getTime();

        const updateProgress = () => {
            const now = Date.now();
            const elapsed = now - eventCreatedAt;
            const newProgress = Math.min((elapsed / PROCESSING_DURATION_MS) * 100, 100);
            setProgress(newProgress);

            const newToolIndex = Math.min(
                Math.floor(elapsed / toolDuration),
                PROCESSING_TOOLS.length - 1
            );
            setCurrentTool(newToolIndex);
        };

        updateProgress();
        const progressInterval = setInterval(updateProgress, 100);

        return () => clearInterval(progressInterval);
    }, [showProgress, event.id, event.created_at]);

    // Derived states
    const isUrgent = event.severity === 'critical' && 
        (event.ai_status === 'pending' || event.ai_status === 'processing');
    const isFailed = event.ai_status === 'failed';
    const isInvestigating = event.ai_status === 'investigating';
    
    const severityStyle = severityStyles[event.severity] ?? severityStyles.info;

    return (
        <Card
            className={`cursor-pointer transition-all hover:shadow-lg hover:scale-[1.01] active:scale-[0.99] 
                border-l-4 ${severityStyle.border} overflow-hidden relative group
                ${isUrgent ? 'ring-1 ring-red-500/30' : ''}
                ${isFailed ? 'ring-1 ring-rose-500/30' : ''}`}
            onClick={onClick}
        >
            {/* Urgency pulse overlay */}
            {isUrgent && (
                <div className="absolute inset-0 pointer-events-none">
                    <div className="absolute inset-0 bg-red-500/5 dark:bg-red-400/10 animate-pulse" />
                </div>
            )}

            <CardContent className="p-0 relative z-10">
                {/* ========================================
                    HEADER SECTION
                ======================================== */}
                <div className="p-3 pb-2 bg-gradient-to-br from-muted/40 to-muted/10">
                    <div className="flex items-start justify-between gap-2 mb-2">
                        <div className="flex items-start gap-2 flex-1 min-w-0">
                            <div className={`rounded-md p-1.5 shrink-0 ${
                                isUrgent ? 'bg-red-100 dark:bg-red-950/50' :
                                isFailed ? 'bg-rose-100 dark:bg-rose-950/50' :
                                'bg-primary/15'
                            }`}>
                                <Icon className={`size-4 ${
                                    isUrgent ? 'text-red-600 dark:text-red-400' :
                                    isFailed ? 'text-rose-600 dark:text-rose-400' :
                                    'text-primary'
                                }`} />
                            </div>
                            <div className="flex-1 min-w-0">
                                <h4 className="text-sm font-semibold leading-tight line-clamp-2">
                                    {formatEventTitle(event)}
                                </h4>
                            </div>
                        </div>
                        <Badge
                            variant="secondary"
                            className={`text-[10px] font-semibold px-2 py-0.5 shrink-0 ${severityStyle.badge}`}
                        >
                            {event.severity_label ?? 'Info'}
                        </Badge>
                    </div>

                    {/* Vehicle & Driver Info */}
                    <div className="space-y-1">
                        <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Truck className="size-3 shrink-0" />
                            <span className="font-medium truncate">
                                {event.vehicle_name ?? 'Sin vehículo'}
                            </span>
                        </div>
                        {event.driver_name && (
                            <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                <User className="size-3 shrink-0" />
                                <span className="truncate">{event.driver_name}</span>
                            </div>
                        )}
                    </div>
                </div>

                {/* ========================================
                    VERDICT SUMMARY (if available)
                ======================================== */}
                {event.verdict_summary && (
                    <div className={`px-3 py-2 border-t ${urgencyStyles[event.verdict_summary.urgency]}`}>
                        <div className="flex items-center gap-1.5">
                            <Sparkles className="size-3 shrink-0" />
                            <span className="text-xs font-semibold truncate">
                                {event.verdict_summary.verdict}
                            </span>
                            {event.verdict_summary.likelihood && (
                                <span className="text-[10px] opacity-75 shrink-0">
                                    ({event.verdict_summary.likelihood})
                                </span>
                            )}
                        </div>
                    </div>
                )}

                {/* ========================================
                    PROCESSING PROGRESS (if processing)
                ======================================== */}
                {showProgress && (
                    <div className="px-3 py-2 bg-sky-50/50 dark:bg-sky-950/20 border-t border-sky-200/50 dark:border-sky-800/50">
                        <div className="flex items-center gap-2 mb-1.5">
                            <Loader2 className="size-3.5 animate-spin text-sky-600 dark:text-sky-400 shrink-0" />
                            <span className="text-xs text-sky-700 dark:text-sky-300 font-medium truncate">
                                {PROCESSING_TOOLS[currentTool]}
                            </span>
                        </div>
                        <Progress value={progress} className="h-1.5 bg-sky-100 dark:bg-sky-900/50" />
                    </div>
                )}

                {/* ========================================
                    INVESTIGATING STATUS
                ======================================== */}
                {isInvestigating && event.investigation_metadata && (
                    <div className="px-3 py-2 bg-amber-50/50 dark:bg-amber-950/20 border-t border-amber-200/50 dark:border-amber-800/50">
                        <div className="flex items-center gap-2">
                            <Search className="size-3.5 text-amber-600 dark:text-amber-400 shrink-0 animate-pulse" />
                            <span className="text-xs text-amber-700 dark:text-amber-300 font-medium">
                                Investigación {event.investigation_metadata.count}/{event.investigation_metadata.max_investigations}
                            </span>
                        </div>
                    </div>
                )}

                {/* ========================================
                    FAILED STATUS
                ======================================== */}
                {isFailed && (
                    <div className="px-3 py-2 bg-rose-50/50 dark:bg-rose-950/20 border-t border-rose-200/50 dark:border-rose-800/50">
                        <div className="flex items-center gap-2">
                            <AlertCircle className="size-3.5 text-rose-600 dark:text-rose-400 shrink-0" />
                            <span className="text-xs text-rose-700 dark:text-rose-300 font-medium">
                                Requiere revisión manual
                            </span>
                        </div>
                    </div>
                )}

                {/* ========================================
                    NEEDS ATTENTION INDICATOR
                ======================================== */}
                {event.needs_attention && !isFailed && (
                    <div className="px-3 py-1.5 bg-amber-50/80 dark:bg-amber-950/30 border-t border-amber-200/50 dark:border-amber-800/50">
                        <div className="flex items-center gap-1.5 text-amber-700 dark:text-amber-300">
                            <UserCheck className="size-3.5" />
                            <span className="text-[10px] font-semibold">Requiere revisión</span>
                        </div>
                    </div>
                )}

                {/* ========================================
                    AI MESSAGE PREVIEW (truncated)
                ======================================== */}
                {event.ai_message_preview && !showProgress && !isInvestigating && !isFailed && (
                    <div className="px-3 py-2 border-t">
                        <p className="text-[11px] text-muted-foreground line-clamp-2 leading-relaxed">
                            {event.ai_message_preview}
                        </p>
                    </div>
                )}

                {/* ========================================
                    FOOTER SECTION
                ======================================== */}
                <div className="px-3 py-2.5 flex items-center justify-between gap-2 border-t bg-muted/20">
                    <div className="flex items-center gap-3 text-xs text-muted-foreground min-w-0">
                        {/* Date */}
                        <div className="flex items-center gap-1 min-w-0">
                            <Clock className="size-3 shrink-0" />
                            <span className="font-medium truncate">
                                {event.occurred_at_human ?? formatDate(event.occurred_at)}
                            </span>
                        </div>

                        {/* Has images indicator */}
                        {event.has_images && (
                            <div className="flex items-center gap-1 text-primary shrink-0">
                                <Camera className="size-3" />
                            </div>
                        )}

                        {/* Human status indicator (if not pending) */}
                        {event.human_status && event.human_status !== 'pending' && (
                            (() => {
                                const statusConfig = humanStatusStyles[event.human_status];
                                const HumanIcon = statusConfig.icon;
                                return (
                                    <div className={`flex items-center gap-1 shrink-0 ${statusConfig.badge}`}>
                                        <HumanIcon className="size-3" />
                                    </div>
                                );
                            })()
                        )}
                    </div>

                    {/* AI Status Badge */}
                    <Badge
                        variant="outline"
                        className={`text-[10px] font-medium px-2 py-0.5 shrink-0 ${statusStyles[event.ai_status] ?? statusStyles.pending}`}
                    >
                        {event.ai_status_label ?? 'Pendiente'}
                    </Badge>
                </div>
            </CardContent>
        </Card>
    );
});
