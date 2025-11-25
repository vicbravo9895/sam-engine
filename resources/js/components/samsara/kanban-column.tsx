import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { type LucideIcon } from 'lucide-react';
import { EventCard } from './event-card';

interface Event {
    id: number;
    event_title?: string | null;
    event_type?: string | null;
    event_icon?: string | null;
    vehicle_name?: string | null;
    occurred_at_human?: string | null;
    severity: string;
    severity_label?: string | null;
    ai_status: string;
    ai_status_label?: string | null;
    investigation_metadata?: {
        count: number;
        max_investigations: number;
    };
}

interface KanbanColumnProps {
    title: string;
    status: string;
    icon: LucideIcon;
    color: string;
    events: Event[];
    onEventClick: (eventId: number) => void;
    maxVisible?: number;
}

const colorStyles: Record<string, { bg: string; text: string; border: string }> = {
    slate: {
        bg: 'bg-slate-50 dark:bg-slate-950/50',
        text: 'text-slate-700 dark:text-slate-300',
        border: 'border-slate-200 dark:border-slate-800',
    },
    sky: {
        bg: 'bg-sky-50 dark:bg-sky-950/50',
        text: 'text-sky-700 dark:text-sky-300',
        border: 'border-sky-200 dark:border-sky-800',
    },
    amber: {
        bg: 'bg-amber-50 dark:bg-amber-950/50',
        text: 'text-amber-700 dark:text-amber-300',
        border: 'border-amber-200 dark:border-amber-800',
    },
    emerald: {
        bg: 'bg-emerald-50 dark:bg-emerald-950/50',
        text: 'text-emerald-700 dark:text-emerald-300',
        border: 'border-emerald-200 dark:border-emerald-800',
    },
    rose: {
        bg: 'bg-rose-50 dark:bg-rose-950/50',
        text: 'text-rose-700 dark:text-rose-300',
        border: 'border-rose-200 dark:border-rose-800',
    },
};

export function KanbanColumn({
    title,
    status,
    icon: Icon,
    color,
    events,
    onEventClick,
    maxVisible = 10,
}: KanbanColumnProps) {
    const styles = colorStyles[color] || colorStyles.slate;
    const visibleEvents = events.slice(0, maxVisible);
    const hasMore = events.length > maxVisible;

    return (
        <div className="flex flex-col h-full">
            {/* Column Header */}
            <div className={`sticky top-0 z-10 rounded-t-lg border-b ${styles.border} ${styles.bg} p-3`}>
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <Icon className={`size-4 ${styles.text}`} />
                        <h3 className={`font-semibold ${styles.text}`}>{title}</h3>
                    </div>
                    <Badge variant="secondary" className="text-xs">
                        {events.length}
                    </Badge>
                </div>
            </div>

            {/* Column Content */}
            <div className={`flex-1 overflow-y-auto p-2 space-y-2 ${styles.bg} rounded-b-lg border-x border-b ${styles.border}`}>
                {visibleEvents.length === 0 ? (
                    <div className="flex flex-col items-center justify-center p-8 text-center text-muted-foreground">
                        <Icon className="size-8 mb-2 opacity-20" />
                        <p className="text-sm">No hay eventos</p>
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
                                className="w-full text-xs"
                            >
                                Ver {events.length - maxVisible} más...
                            </Button>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}
