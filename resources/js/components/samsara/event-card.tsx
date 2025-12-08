import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import {
    AlertCircle,
    AlertTriangle,
    Bell,
    Camera,
    CheckCircle2,
    Clock,
    Loader2,
    Search,
    ShieldAlert,
    Truck,
} from 'lucide-react';
import { type LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

interface EventCardProps {
    event: {
        id: number;
        event_title?: string | null;
        event_type?: string | null;
        event_description?: string | null;
        event_icon?: string | null;
        vehicle_name?: string | null;
        occurred_at_human?: string | null;
        occurred_at?: string | null;
        created_at?: string | null;
        severity: string;
        severity_label?: string | null;
        ai_status: string;
        ai_status_label?: string | null;
        has_images?: boolean;
        investigation_metadata?: {
            count: number;
            max_investigations: number;
        };
    };
    onClick: () => void;
    showProgress?: boolean;
}

const severityStyles: Record<string, string> = {
    info: 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-200',
    warning: 'bg-amber-100 text-amber-900 dark:bg-amber-500/20 dark:text-amber-200',
    critical: 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-100 font-semibold',
};

const statusStyles: Record<string, string> = {
    pending: 'bg-slate-100 text-slate-800 dark:bg-slate-500/20 dark:text-slate-200',
    processing: 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-100 animate-pulse',
    investigating: 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-100 animate-pulse',
    completed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-100',
    failed: 'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-100 font-semibold',
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

export function EventCard({ event, onClick, showProgress = false }: EventCardProps) {
    const Icon = getEventIcon(event.event_icon);
    const [progress, setProgress] = useState(0);
    const [currentTool, setCurrentTool] = useState(0);

    const tools = [
        'Obteniendo estadísticas...',
        'Consultando vehículo...',
        'Identificando conductor...',
        'Analizando cámaras...',
        'Generando veredicto...'
    ];

    // Calcular progreso basado en created_at del evento (25 segundos desde creación)
    useEffect(() => {
        if (!showProgress || !event.created_at) {
            setProgress(0);
            setCurrentTool(0);
            return;
        }

        const duration = 25000; // 25 segundos
        const toolDuration = duration / tools.length; // ~5 segundos por tool

        // Calcular tiempo transcurrido desde que se creó el evento (ingesta)
        const eventCreatedAt = new Date(event.created_at).getTime();

        const updateProgress = () => {
            const now = Date.now();
            const elapsed = now - eventCreatedAt;
            const newProgress = Math.min((elapsed / duration) * 100, 100);
            setProgress(newProgress);

            // Cambiar de tool cada ~5 segundos desde la creación
            const newToolIndex = Math.min(
                Math.floor(elapsed / toolDuration),
                tools.length - 1
            );
            setCurrentTool(newToolIndex);
        };

        // Actualizar inmediatamente
        updateProgress();

        // Continuar actualizando cada 100ms
        const progressInterval = setInterval(updateProgress, 100);

        return () => clearInterval(progressInterval);
    }, [showProgress, event.id, event.created_at]);

    // Determinar si el evento es urgente (crítico y pendiente/procesando)
    const isUrgent = event.severity === 'critical' &&
        (event.ai_status === 'pending' || event.ai_status === 'processing');

    // Determinar color del borde según severidad
    const getBorderColor = () => {
        switch (event.severity) {
            case 'critical':
                return 'border-l-red-500 dark:border-l-red-400';
            case 'warning':
                return 'border-l-amber-500 dark:border-l-amber-400';
            default:
                return 'border-l-blue-500 dark:border-l-blue-400';
        }
    };

    return (
        <Card
            className={`cursor-pointer transition-all hover:shadow-lg hover:scale-[1.01] active:scale-[0.99] border-l-4 ${getBorderColor()} overflow-hidden relative ${isUrgent ? 'animate-pulse-border' : ''
                }`}
            onClick={onClick}
        >
            {/* Indicador de urgencia - pulso sutil */}
            {isUrgent && (
                <div className="absolute inset-0 pointer-events-none">
                    <div className="absolute inset-0 bg-red-500/5 dark:bg-red-400/10 animate-pulse-slow" />
                </div>
            )}

            <CardContent className="p-0 relative z-10">
                {/* Header Section */}
                <div className="p-3 pb-2 bg-gradient-to-br from-muted/30 to-muted/10">
                    <div className="flex items-start justify-between gap-2 mb-2">
                        <div className="flex items-center gap-2 flex-1 min-w-0">
                            <div className={`rounded-md p-1.5 shrink-0 ${isUrgent
                                ? 'bg-red-100 dark:bg-red-950/50'
                                : 'bg-primary/15'
                                }`}>
                                <Icon className={`size-4 ${isUrgent
                                    ? 'text-red-600 dark:text-red-400'
                                    : 'text-primary'
                                    }`} />
                            </div>
                            <div className="flex-1 min-w-0">
                                <h4 className="text-sm font-semibold leading-tight line-clamp-2">
                                    {event.event_description ?? event.event_title ?? event.event_type ?? 'Evento sin título'}
                                </h4>
                            </div>
                        </div>
                        <Badge
                            variant="secondary"
                            className={`text-[10px] font-semibold px-2 py-0.5 shrink-0 ${severityStyles[event.severity] ?? severityStyles.info}`}
                        >
                            {event.severity_label ?? 'Info'}
                        </Badge>
                    </div>

                    {/* Vehicle Info */}
                    <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <Truck className="size-3.5 shrink-0" />
                        <span className="font-medium truncate">
                            {event.vehicle_name ?? 'Sin vehículo'}
                        </span>
                    </div>
                </div>

                {/* Progress Section (solo si processing) */}
                {showProgress && (
                    <div className="px-3 py-2 bg-sky-50/50 dark:bg-sky-950/20 border-y border-sky-200/50 dark:border-sky-800/50">
                        <div className="flex items-center gap-2 mb-1.5">
                            <Loader2 className="size-3.5 animate-spin text-sky-600 dark:text-sky-400 shrink-0" />
                            <span className="text-xs text-sky-700 dark:text-sky-300 font-medium truncate">
                                {tools[currentTool]}
                            </span>
                        </div>
                        <Progress value={progress} className="h-1.5 bg-sky-100 dark:bg-sky-900/50" />
                    </div>
                )}

                {/* Investigation Section (solo si investigating) */}
                {event.ai_status === 'investigating' && event.investigation_metadata && (
                    <div className="px-3 py-2 bg-amber-50/50 dark:bg-amber-950/20 border-y border-amber-200/50 dark:border-amber-800/50">
                        <div className="flex items-center gap-2">
                            <Search className="size-3.5 text-amber-600 dark:text-amber-400 shrink-0" />
                            <span className="text-xs text-amber-700 dark:text-amber-300 font-medium">
                                Investigación {event.investigation_metadata.count}/{event.investigation_metadata.max_investigations}
                            </span>
                        </div>
                    </div>
                )}

                {/* Footer Section */}
                <div className="px-3 py-2.5 flex items-center justify-between gap-2">
                    <div className="flex items-center gap-3 text-xs text-muted-foreground">
                        <div className="flex items-center gap-1.5">
                            <Clock className="size-3.5 shrink-0" />
                            <span className="font-medium">
                                {event.occurred_at_human ?? 'Sin fecha'}
                            </span>
                        </div>
                        {event.has_images && (
                            <div className="flex items-center gap-1 text-primary">
                                <Camera className="size-3.5" />
                                <span className="text-[10px] font-medium">Evidencia</span>
                            </div>
                        )}
                    </div>
                    <Badge
                        variant="outline"
                        className={`text-[10px] font-medium px-2 py-0.5 ${statusStyles[event.ai_status] ?? statusStyles.pending}`}
                    >
                        {event.ai_status_label ?? 'Pendiente'}
                    </Badge>
                </div>
            </CardContent>
        </Card>
    );
}
