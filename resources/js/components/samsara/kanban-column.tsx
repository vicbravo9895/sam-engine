import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type LucideIcon } from 'lucide-react';
import { EventCard } from './event-card';
import { useState, useEffect } from 'react';

// ============================================================================
// TYPE DEFINITIONS
// ============================================================================

type UrgencyLevel = 'high' | 'medium' | 'low';
type HumanStatus = 'pending' | 'reviewed' | 'flagged' | 'resolved' | 'false_positive';

interface Event {
    id: number;
    event_title?: string | null;
    event_type?: string | null;
    event_description?: string | null;
    event_icon?: string | null;
    vehicle_name?: string | null;
    driver_name?: string | null;
    occurred_at?: string | null;
    occurred_at_human?: string | null;
    created_at?: string | null;
    severity: string;
    severity_label?: string | null;
    ai_status: string;
    ai_status_label?: string | null;
    ai_message_preview?: string | null;
    verdict_summary?: {
        verdict: string;
        likelihood?: string | null;
        urgency: UrgencyLevel;
    } | null;
    has_images?: boolean;
    investigation_metadata?: {
        count: number;
        max_investigations: number;
    } | null;
    human_status?: HumanStatus;
    human_status_label?: string | null;
    needs_attention?: boolean;
    urgency_level?: UrgencyLevel;
}

interface KanbanColumnProps {
    title: string;
    sublabel?: string;
    status: string;
    icon: LucideIcon;
    color: string;
    events: Event[];
    onEventClick: (eventId: number) => void;
    maxVisible?: number;
}

// ============================================================================
// STYLE CONFIGURATIONS
// ============================================================================

const colorStyles: Record<string, { bg: string; text: string; border: string; headerBg: string }> = {
    slate: {
        bg: 'bg-slate-50/50 dark:bg-slate-950/30',
        text: 'text-slate-700 dark:text-slate-300',
        border: 'border-slate-200 dark:border-slate-800',
        headerBg: 'bg-slate-100/80 dark:bg-slate-900/80',
    },
    sky: {
        bg: 'bg-sky-50/50 dark:bg-sky-950/30',
        text: 'text-sky-700 dark:text-sky-300',
        border: 'border-sky-200 dark:border-sky-800',
        headerBg: 'bg-sky-100/80 dark:bg-sky-900/80',
    },
    amber: {
        bg: 'bg-amber-50/50 dark:bg-amber-950/30',
        text: 'text-amber-700 dark:text-amber-300',
        border: 'border-amber-200 dark:border-amber-800',
        headerBg: 'bg-amber-100/80 dark:bg-amber-900/80',
    },
    emerald: {
        bg: 'bg-emerald-50/50 dark:bg-emerald-950/30',
        text: 'text-emerald-700 dark:text-emerald-300',
        border: 'border-emerald-200 dark:border-emerald-800',
        headerBg: 'bg-emerald-100/80 dark:bg-emerald-900/80',
    },
    rose: {
        bg: 'bg-rose-50/50 dark:bg-rose-950/30',
        text: 'text-rose-700 dark:text-rose-300',
        border: 'border-rose-200 dark:border-rose-800',
        headerBg: 'bg-rose-100/80 dark:bg-rose-900/80',
    },
};

// ============================================================================
// KANBAN COLUMN COMPONENT
// ============================================================================

export function KanbanColumn({
    title,
    sublabel,
    status,
    icon: Icon,
    color,
    events,
    onEventClick,
    maxVisible = 10,
}: KanbanColumnProps) {
    const styles = colorStyles[color] || colorStyles.slate;
    const [visibleCount, setVisibleCount] = useState(maxVisible);

    // Reset visible count when events change (e.g., filters applied)
    useEffect(() => {
        setVisibleCount(maxVisible);
    }, [events.length, maxVisible]);

    const visibleEvents = events.slice(0, visibleCount);
    const hasMore = events.length > visibleCount;
    const remainingCount = events.length - visibleCount;

    const loadMore = () => {
        setVisibleCount((prev) => Math.min(prev + maxVisible, events.length));
    };

    // Count special indicators
    const criticalCount = events.filter((e) => e.severity === 'critical').length;
    const needsAttentionCount = events.filter((e) => e.needs_attention).length;

    return (
        <div className="flex flex-col w-80 h-[calc(100vh-340px)] min-h-[550px] max-h-[750px]">
            {/* Column Header */}
            <div className={`sticky top-0 z-10 rounded-t-lg border ${styles.border} ${styles.headerBg} p-3 backdrop-blur-sm`}>
                <div className="flex items-center justify-between gap-2">
                    <div className="flex items-center gap-2 min-w-0">
                        <div className={`rounded-md p-1.5 ${styles.bg}`}>
                            <Icon className={`size-4 ${styles.text} ${status === 'processing' || status === 'investigating' ? 'animate-pulse' : ''}`} />
                        </div>
                        <div className="min-w-0">
                            <h3 className={`font-semibold text-sm ${styles.text} truncate`}>{title}</h3>
                            {sublabel && (
                                <p className="text-[10px] text-muted-foreground truncate">{sublabel}</p>
                            )}
                        </div>
                    </div>
                    <div className="flex items-center gap-1.5 shrink-0">
                        {/* Critical indicator */}
                        {criticalCount > 0 && (
                            <Badge className="bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-200 text-[10px] px-1.5 py-0">
                                {criticalCount} crítica{criticalCount !== 1 ? 's' : ''}
                            </Badge>
                        )}
                        {/* Needs attention indicator */}
                        {needsAttentionCount > 0 && criticalCount === 0 && (
                            <Badge className="bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200 text-[10px] px-1.5 py-0">
                                {needsAttentionCount} atención
                            </Badge>
                        )}
                        {/* Total count */}
                        <Badge variant="secondary" className="text-xs font-semibold">
                            {events.length}
                        </Badge>
                    </div>
                </div>
            </div>

            {/* Column Content with Scroll */}
            <div className={`flex-1 overflow-y-auto p-2 space-y-2 ${styles.bg} rounded-b-lg border-x border-b ${styles.border}`}>
                {visibleEvents.length === 0 ? (
                    <div className="flex flex-col items-center justify-center p-8 text-center text-muted-foreground h-full">
                        <div className={`rounded-full p-4 ${styles.bg} mb-3`}>
                            <Icon className={`size-8 opacity-30 ${styles.text}`} />
                        </div>
                        <p className="text-sm font-medium">Sin eventos</p>
                        <p className="text-xs opacity-75">
                            {status === 'pending' && 'Todos los eventos están siendo procesados'}
                            {status === 'processing' && 'No hay eventos en procesamiento'}
                            {status === 'investigating' && 'No hay eventos bajo investigación'}
                            {status === 'completed' && 'No hay eventos completados'}
                            {status === 'failed' && 'No hay eventos con error'}
                        </p>
                    </div>
                ) : (
                    <>
                        {visibleEvents.map((event) => (
                            <EventCard
                                key={event.id}
                                event={event}
                                onClick={() => onEventClick(event.id)}
                                showProgress={status === 'processing'}
                            />
                        ))}
                        {hasMore && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className={`w-full text-xs ${styles.text} hover:${styles.bg}`}
                                onClick={loadMore}
                            >
                                Cargar {Math.min(remainingCount, maxVisible)} más...
                                <span className="ml-1 text-muted-foreground">
                                    ({remainingCount} restantes)
                                </span>
                            </Button>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}
