import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import {
    AlertCircle,
    AlertTriangle,
    Bell,
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
        event_icon?: string | null;
        vehicle_name?: string | null;
        occurred_at_human?: string | null;
        occurred_at?: string | null;
        severity: string;
        severity_label?: string | null;
        ai_status: string;
        ai_status_label?: string | null;
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

    // Simular progreso realista de 25 segundos
    useEffect(() => {
        if (!showProgress) {
            setProgress(0);
            setCurrentTool(0);
            return;
        }

        const startTime = Date.now();
        const duration = 25000; // 25 segundos
        const toolDuration = duration / tools.length; // ~5 segundos por tool

        const progressInterval = setInterval(() => {
            const elapsed = Date.now() - startTime;
            const newProgress = Math.min((elapsed / duration) * 100, 100);
            setProgress(newProgress);

            // Cambiar de tool cada ~5 segundos
            const newToolIndex = Math.min(
                Math.floor(elapsed / toolDuration),
                tools.length - 1
            );
            setCurrentTool(newToolIndex);

            if (elapsed >= duration) {
                clearInterval(progressInterval);
            }
        }, 100);

        return () => clearInterval(progressInterval);
    }, [showProgress, event.id]);

    return (
        <Card
            className="cursor-pointer transition-all hover:shadow-md hover:scale-[1.02] active:scale-[0.98] w-full"
            onClick={onClick}
        >
            <CardContent className="p-3 space-y-2.5">
                {/* Header con icon y título */}
                <div className="flex items-start gap-2 min-w-0">
                    <div className="rounded-full bg-primary/10 p-1.5 shrink-0">
                        <Icon className="size-4 text-primary" />
                    </div>
                    <div className="flex-1 min-w-0">
                        <p className="text-sm font-semibold truncate">
                            {event.event_title ?? event.event_type ?? 'Evento sin título'}
                        </p>
                        <div className="flex items-center gap-1.5 text-xs text-muted-foreground mt-0.5 min-w-0">
                            <Truck className="size-3 shrink-0" />
                            <span className="truncate">
                                {event.vehicle_name ?? 'Sin vehículo'}
                            </span>
                        </div>
                    </div>
                </div>

                {/* Progress bar (solo si processing) */}
                {showProgress && (
                    <div className="space-y-1">
                        <div className="flex items-center gap-2 text-xs text-muted-foreground min-w-0">
                            <Loader2 className="size-3 animate-spin shrink-0" />
                            <span className="truncate">{tools[currentTool]}</span>
                        </div>
                        <Progress value={progress} className="h-1" />
                    </div>
                )}

                {/* Investigation badge (solo si investigating) */}
                {event.ai_status === 'investigating' && event.investigation_metadata && (
                    <div className="flex items-center gap-2 text-xs min-w-0">
                        <Search className="size-3 text-amber-600 shrink-0" />
                        <span className="text-amber-700 dark:text-amber-300 font-medium truncate">
                            Investigación {event.investigation_metadata.count}/{event.investigation_metadata.max_investigations}
                        </span>
                    </div>
                )}

                {/* Footer con badges y tiempo */}
                <div className="flex items-center justify-between gap-2 min-w-0">
                    <div className="flex gap-1.5 flex-wrap min-w-0">
                        <Badge
                            className={`text-xs px-2 py-0 ${severityStyles[event.severity] ?? severityStyles.info}`}
                        >
                            {event.severity_label ?? 'Info'}
                        </Badge>
                        <Badge
                            className={`text-xs px-2 py-0 ${statusStyles[event.ai_status] ?? statusStyles.pending}`}
                        >
                            {event.ai_status_label ?? 'Pendiente'}
                        </Badge>
                    </div>
                    <div className="flex items-center gap-1 text-xs text-muted-foreground shrink-0">
                        <Clock className="size-3" />
                        <span className="truncate max-w-[80px]">{event.occurred_at_human ?? 'Sin fecha'}</span>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
