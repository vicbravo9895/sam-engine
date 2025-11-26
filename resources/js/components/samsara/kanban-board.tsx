import {
    CheckCircle2,
    Clock,
    Loader2,
    Search,
    XCircle,
} from 'lucide-react';
import { KanbanColumn } from './kanban-column';

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

interface KanbanBoardProps {
    events: Event[];
    onEventClick: (eventId: number) => void;
}

const columns = [
    { status: 'pending', label: 'Pendiente', icon: Clock, color: 'slate' },
    { status: 'processing', label: 'Procesando', icon: Loader2, color: 'sky' },
    { status: 'investigating', label: 'Investigando', icon: Search, color: 'amber' },
    { status: 'completed', label: 'Completado', icon: CheckCircle2, color: 'emerald' },
    { status: 'failed', label: 'Error', icon: XCircle, color: 'rose' },
];

export function KanbanBoard({ events, onEventClick }: KanbanBoardProps) {
    // Group events by status
    const groupedEvents = columns.reduce((acc, column) => {
        acc[column.status] = events.filter(event => event.ai_status === column.status);
        return acc;
    }, {} as Record<string, Event[]>);

    return (
        <div className="w-full overflow-x-auto">
            <div className="flex gap-4 min-w-max pb-4">
                {columns.map((column) => (
                    <KanbanColumn
                        key={column.status}
                        title={column.label}
                        status={column.status}
                        icon={column.icon}
                        color={column.color}
                        events={groupedEvents[column.status] || []}
                        onEventClick={onEventClick}
                        maxVisible={10}
                    />
                ))}
            </div>
        </div>
    );
}
