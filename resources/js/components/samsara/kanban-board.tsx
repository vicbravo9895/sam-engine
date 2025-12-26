import {
    CheckCircle2,
    Clock,
    Loader2,
    Search,
    XCircle,
} from 'lucide-react';
import { type LucideIcon } from 'lucide-react';
import { KanbanColumn } from './kanban-column';
import { useMemo } from 'react';

// ============================================================================
// TYPE DEFINITIONS
// ============================================================================

type UrgencyLevel = 'high' | 'medium' | 'low';
type HumanStatus = 'pending' | 'reviewed' | 'flagged' | 'resolved' | 'false_positive';

/**
 * Event interface - matches EventListItem from index.tsx
 * Using only existing payload fields.
 */
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
    // Human review fields
    human_status?: HumanStatus;
    human_status_label?: string | null;
    needs_attention?: boolean;
    urgency_level?: UrgencyLevel;
}

interface KanbanBoardProps {
    events: Event[];
    onEventClick: (eventId: number) => void;
}

// ============================================================================
// COLUMN CONFIGURATION
// ============================================================================

interface ColumnConfig {
    status: string;
    label: string;
    sublabel: string;
    icon: LucideIcon;
    color: string;
}

/**
 * Kanban columns based on ai_status.
 * Events are grouped by their ai_status field.
 */
const COLUMNS: ColumnConfig[] = [
    {
        status: 'pending',
        label: 'Pendiente',
        sublabel: 'Esperando procesamiento',
        icon: Clock,
        color: 'slate',
    },
    {
        status: 'processing',
        label: 'Procesando',
        sublabel: 'AI analizando evento',
        icon: Loader2,
        color: 'sky',
    },
    {
        status: 'investigating',
        label: 'Investigando',
        sublabel: 'Monitoreo continuo',
        icon: Search,
        color: 'amber',
    },
    {
        status: 'completed',
        label: 'Completado',
        sublabel: 'Análisis finalizado',
        icon: CheckCircle2,
        color: 'emerald',
    },
    {
        status: 'failed',
        label: 'Error',
        sublabel: 'Requiere revisión manual',
        icon: XCircle,
        color: 'rose',
    },
];

// ============================================================================
// KANBAN BOARD COMPONENT
// ============================================================================

export function KanbanBoard({ events, onEventClick }: KanbanBoardProps) {
    /**
     * Group events by ai_status column.
     * Events are already sorted by priority from the parent component.
     */
    const groupedEvents = useMemo(() => {
        const groups: Record<string, Event[]> = {};

        // Initialize all columns
        COLUMNS.forEach((col) => {
            groups[col.status] = [];
        });

        // Group events by status
        events.forEach((event) => {
            const status = event.ai_status || 'pending';
            if (groups[status]) {
                groups[status].push(event);
            } else {
                // Fallback to pending if unknown status
                groups['pending'].push(event);
            }
        });

        return groups;
    }, [events]);

    return (
        <div className="w-full overflow-x-auto pb-2">
            <div className="flex gap-4 min-w-max">
                {COLUMNS.map((column) => (
                    <KanbanColumn
                        key={column.status}
                        title={column.label}
                        sublabel={column.sublabel}
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
