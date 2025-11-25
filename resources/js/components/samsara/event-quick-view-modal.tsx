import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Link } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    ArrowRight,
    Bell,
    Calendar,
    CheckCircle2,
    Clock,
    Loader2,
    Search,
    ShieldAlert,
    Sparkles,
    Truck,
    User,
} from 'lucide-react';
import { type LucideIcon } from 'lucide-react';

interface EventQuickViewModalProps {
    event: {
        id: number;
        event_title?: string | null;
        event_type?: string | null;
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
        verdict_summary?: {
            verdict: string;
            likelihood?: string | null;
            urgency: 'high' | 'medium' | 'low';
        } | null;
        investigation_metadata?: {
            count: number;
            last_check?: string | null;
            next_check_minutes?: number | null;
            max_investigations: number;
        };
    } | null;
    open: boolean;
    onClose: () => void;
}

const severityStyles: Record<string, string> = {
    info: 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-200',
    warning: 'bg-amber-100 text-amber-900 dark:bg-amber-500/20 dark:text-amber-200',
    critical: 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-100 font-semibold',
};

const statusStyles: Record<string, string> = {
    pending: 'bg-slate-100 text-slate-800 dark:bg-slate-500/20 dark:text-slate-200',
    processing: 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-100',
    investigating: 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-100',
    completed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-100',
    failed: 'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-100',
};

const getUrgencyStyles = (urgency?: 'high' | 'medium' | 'low') => {
    switch (urgency) {
        case 'high':
            return 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-200 border-red-300 dark:border-red-500/30';
        case 'medium':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200 border-amber-300 dark:border-amber-500/30';
        case 'low':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200 border-emerald-300 dark:border-emerald-500/30';
        default:
            return 'bg-slate-100 text-slate-800 dark:bg-slate-500/20 dark:text-slate-200';
    }
};

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

export function EventQuickViewModal({ event, open, onClose }: EventQuickViewModalProps) {
    if (!event) return null;

    const Icon = getEventIcon(event.event_icon);

    return (
        <Dialog open={open} onOpenChange={onClose}>
            <DialogContent className="max-w-2xl max-h-[80vh] overflow-y-auto">
                <DialogHeader>
                    <div className="flex items-start gap-3">
                        <div className="rounded-full bg-primary/10 p-2">
                            <Icon className="size-5 text-primary" />
                        </div>
                        <div className="flex-1">
                            <DialogTitle className="text-xl">
                                {event.event_title ?? event.event_type ?? 'Evento sin título'}
                            </DialogTitle>
                            <DialogDescription>
                                Vista rápida del evento
                            </DialogDescription>
                        </div>
                        <div className="flex gap-2">
                            <Badge className={severityStyles[event.severity] ?? severityStyles.info}>
                                {event.severity_label ?? 'Info'}
                            </Badge>
                            <Badge className={statusStyles[event.ai_status] ?? statusStyles.pending}>
                                {event.ai_status_label ?? 'Pendiente'}
                            </Badge>
                        </div>
                    </div>
                </DialogHeader>

                <div className="space-y-4 mt-4">
                    {/* Información básica */}
                    <div className="grid grid-cols-2 gap-3">
                        <div className="flex items-center gap-2 rounded-lg border bg-muted/30 p-3">
                            <Truck className="size-4 text-muted-foreground" />
                            <div>
                                <p className="text-xs text-muted-foreground">Unidad</p>
                                <p className="text-sm font-medium">{event.vehicle_name ?? 'Sin información'}</p>
                            </div>
                        </div>
                        <div className="flex items-center gap-2 rounded-lg border bg-muted/30 p-3">
                            <User className="size-4 text-muted-foreground" />
                            <div>
                                <p className="text-xs text-muted-foreground">Conductor</p>
                                <p className="text-sm font-medium">{event.driver_name ?? 'No detectado'}</p>
                            </div>
                        </div>
                        <div className="col-span-2 flex items-center gap-2 rounded-lg border bg-muted/30 p-3">
                            <Calendar className="size-4 text-muted-foreground" />
                            <div>
                                <p className="text-xs text-muted-foreground">Momento del evento</p>
                                <p className="text-sm font-medium">{event.occurred_at_human ?? 'Sin fecha'}</p>
                            </div>
                        </div>
                    </div>

                    {/* Processing indicator */}
                    {event.ai_status === 'processing' && (
                        <div className="flex items-center gap-3 rounded-lg border-2 border-sky-500/30 bg-sky-50/50 p-4 dark:bg-sky-950/20">
                            <Loader2 className="size-5 animate-spin text-sky-600 dark:text-sky-400" />
                            <div>
                                <p className="font-semibold text-sky-900 dark:text-sky-100">Procesando evento...</p>
                                <p className="text-sm text-sky-700 dark:text-sky-300">
                                    La AI está analizando este evento. Tomará ~25 segundos.
                                </p>
                            </div>
                        </div>
                    )}

                    {/* Investigation metadata */}
                    {event.ai_status === 'investigating' && event.investigation_metadata && (
                        <div className="rounded-lg border-2 border-amber-500/30 bg-amber-50/50 p-4 dark:bg-amber-950/20">
                            <div className="flex items-center gap-2 mb-3">
                                <Search className="size-5 text-amber-600 dark:text-amber-400" />
                                <p className="font-semibold text-amber-900 dark:text-amber-100">
                                    Bajo investigación
                                </p>
                                <Badge className="ml-auto bg-amber-500 text-white">
                                    {event.investigation_metadata.count} de {event.investigation_metadata.max_investigations}
                                </Badge>
                            </div>
                            <div className="grid grid-cols-2 gap-2 text-sm">
                                {event.investigation_metadata.last_check && (
                                    <div>
                                        <p className="text-xs text-amber-700 dark:text-amber-400">Última verificación</p>
                                        <p className="font-medium text-amber-900 dark:text-amber-100">
                                            {event.investigation_metadata.last_check}
                                        </p>
                                    </div>
                                )}
                                {event.investigation_metadata.next_check_minutes && (
                                    <div>
                                        <p className="text-xs text-amber-700 dark:text-amber-400">Próxima verificación</p>
                                        <p className="font-medium text-amber-900 dark:text-amber-100">
                                            En {event.investigation_metadata.next_check_minutes} minutos
                                        </p>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* Verdict */}
                    {event.verdict_summary && (
                        <div className={`flex items-center gap-3 rounded-lg border-2 p-4 ${getUrgencyStyles(event.verdict_summary.urgency)}`}>
                            <CheckCircle2 className="size-5 shrink-0" />
                            <div>
                                <p className="text-sm font-semibold uppercase opacity-75">Veredicto de la AI</p>
                                <p className="font-bold">{event.verdict_summary.verdict}</p>
                                {event.verdict_summary.likelihood && (
                                    <p className="text-sm opacity-80">
                                        Probabilidad: {event.verdict_summary.likelihood}
                                    </p>
                                )}
                            </div>
                        </div>
                    )}

                    {/* AI Message preview */}
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

                    {/* Action button */}
                    <Button asChild className="w-full gap-2">
                        <Link href={`/samsara/alerts/${event.id}`}>
                            Ver análisis completo
                            <ArrowRight className="size-4" />
                        </Link>
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
