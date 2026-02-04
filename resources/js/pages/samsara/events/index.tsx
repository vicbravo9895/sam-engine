import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { KanbanBoard } from '@/components/samsara/kanban-board';
import { EventQuickViewModal } from '@/components/samsara/event-quick-view-modal';
import { useTimezone } from '@/hooks/use-timezone';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    AlertOctagon,
    BarChart3,
    Bell,
    Calendar,
    Camera,
    CheckCircle2,
    Eye,
    Filter,
    Flag,
    Keyboard,
    LayoutGrid,
    List,
    Loader2,
    Pause,
    Play,
    RefreshCcw,
    Search,
    ShieldAlert,
    Slash,
    Sparkles,
    Truck,
    User,
    UserCheck,
    XCircle,
    Zap,
} from 'lucide-react';
import { type LucideIcon } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

// ============================================================================
// TYPE DEFINITIONS
// ============================================================================

type HumanStatus = 'pending' | 'reviewed' | 'flagged' | 'resolved' | 'false_positive';
type UrgencyLevel = 'high' | 'medium' | 'low';

/**
 * EventListItem - All available fields from the backend.
 * IMPORTANT: Only use these fields, no assumptions about extra data.
 */
interface EventListItem {
    id: number;
    samsara_event_id?: string | null;
    event_type?: string | null;
    event_title?: string | null;
    event_description?: string | null;
    severity: string;
    severity_label?: string | null;
    ai_status: string;
    ai_status_label?: string | null;
    vehicle_name?: string | null;
    driver_name?: string | null;
    occurred_at?: string | null;
    occurred_at_human?: string | null;
    created_at?: string | null;
    ai_message_preview?: string | null;
    ai_assessment_view?: {
        verdict?: string | null;
        likelihood?: string | null;
        reasoning?: string | null;
    } | null;
    event_icon?: string | null;
    verdict_summary?: {
        verdict: string;
        likelihood?: string | null;
        urgency: UrgencyLevel;
    } | null;
    investigation_summary?: {
        label: string;
        items: string[];
    }[];
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

interface PaginationLink {
    label: string;
    url: string | null;
    active: boolean;
}

interface PaginationMeta {
    current_page: number;
    from: number | null;
    to: number | null;
    total: number;
    links: PaginationLink[];
}

interface PaginatedEvents {
    data: EventListItem[];
    links: PaginationLink[];
    meta: PaginationMeta;
}

interface FilterOption {
    label: string;
    value: string;
}

interface FilterOptions {
    severities: FilterOption[];
    statuses: FilterOption[];
    event_types: FilterOption[];
}

interface Filters {
    search: string;
    severity: string;
    status: string;
    event_type: string;
    date_from: string;
    date_to: string;
}

interface IndexProps {
    events: PaginatedEvents;
    filters: Filters;
    filterOptions: FilterOptions;
    stats: {
        total: number;
        critical: number;
        investigating: number;
        completed: number;
        failed: number;
        needs_attention: number;
        human_pending: number;
        human_reviewed: number;
    };
}

// ============================================================================
// CONSTANTS & URL HELPERS
// ============================================================================

const ALERTS_INDEX_URL = '/samsara/alerts';
const getAlertShowUrl = (id: number) => `/samsara/alerts/${id}`;
const POLLING_INTERVAL_MS = 5000;
const STORAGE_KEY_VIEW_MODE = 'sam_alerts_view_mode';
const STORAGE_KEY_QUICK_FILTERS = 'sam_alerts_quick_filters';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Alertas Samsara', href: ALERTS_INDEX_URL },
];

// ============================================================================
// STYLE CONFIGURATIONS
// ============================================================================

const severityStyles: Record<string, { badge: string; text: string; icon: LucideIcon }> = {
    info: {
        badge: 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-200',
        text: 'text-blue-600 dark:text-blue-400',
        icon: AlertCircle,
    },
    warning: {
        badge: 'bg-amber-100 text-amber-900 dark:bg-amber-500/20 dark:text-amber-200',
        text: 'text-amber-600 dark:text-amber-400',
        icon: AlertTriangle,
    },
    critical: {
        badge: 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-100 font-semibold',
        text: 'text-red-600 dark:text-red-400',
        icon: ShieldAlert,
    },
};

const statusStyles: Record<string, { badge: string; icon: LucideIcon }> = {
    pending: {
        badge: 'bg-slate-100 text-slate-800 dark:bg-slate-500/20 dark:text-slate-200',
        icon: Bell,
    },
    processing: {
        badge: 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-100 animate-pulse',
        icon: Loader2,
    },
    investigating: {
        badge: 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-100 animate-pulse',
        icon: Search,
    },
    completed: {
        badge: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-100',
        icon: CheckCircle2,
    },
    failed: {
        badge: 'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-100 font-semibold',
        icon: XCircle,
    },
};

const humanStatusStyles: Record<HumanStatus, { badge: string; icon: LucideIcon }> = {
    pending: { badge: 'bg-slate-100 text-slate-600 dark:bg-slate-500/20 dark:text-slate-300', icon: Bell },
    reviewed: { badge: 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-200', icon: Eye },
    flagged: { badge: 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200', icon: Flag },
    resolved: { badge: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200', icon: CheckCircle2 },
    false_positive: { badge: 'bg-slate-100 text-slate-500 dark:bg-slate-500/20 dark:text-slate-400', icon: Slash },
};

const urgencyStyles: Record<UrgencyLevel, string> = {
    high: 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-200 border-red-300 dark:border-red-500/30',
    medium: 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200 border-amber-300 dark:border-amber-500/30',
    low: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200 border-emerald-300 dark:border-emerald-500/30',
};

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

const formatDate = (value?: string | null, timezone?: string) => {
    if (!value) return 'Sin fecha';
    // JavaScript handles ISO8601 with timezone offset correctly
    return new Intl.DateTimeFormat('es-MX', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: timezone,
    }).format(new Date(value));
};

const formatEventType = (type?: string | null) => {
    if (!type) return 'Alerta sin clasificar';
    const normalized = type.toLowerCase();
    switch (normalized) {
        case 'panic_button':
        case 'panicbutton':
            return 'Botón de pánico';
        case 'alertincident':
            return 'Incidente reportado';
        case 'safety_event':
            return 'Evento de seguridad';
        default:
            return type
                .split('_')
                .map((part) => part.charAt(0).toUpperCase() + part.slice(1).toLowerCase())
                .join(' ');
    }
};

/**
 * Get a compact event title from available fields.
 */
const formatEventTitle = (event: EventListItem): string => {
    return event.event_description ?? event.event_title ?? formatEventType(event.event_type);
};

/**
 * Calculate a priority score for sorting events within columns.
 * Higher score = higher priority (show first).
 * Uses ONLY existing payload fields.
 */
const getEventPriorityScore = (event: EventListItem): number => {
    let score = 0;

    // Severity: critical = 1000, warning = 500, info = 0
    if (event.severity === 'critical') score += 1000;
    else if (event.severity === 'warning') score += 500;

    // needs_attention adds 300
    if (event.needs_attention) score += 300;

    // AI status boost: processing/investigating need visibility
    if (event.ai_status === 'processing') score += 200;
    else if (event.ai_status === 'investigating') score += 150;
    else if (event.ai_status === 'failed') score += 250;

    // verdict_summary.urgency: high = 100, medium = 50, low = 0
    if (event.verdict_summary?.urgency === 'high') score += 100;
    else if (event.verdict_summary?.urgency === 'medium') score += 50;

    // urgency_level from human review
    if (event.urgency_level === 'high') score += 75;
    else if (event.urgency_level === 'medium') score += 25;

    return score;
};

/**
 * Sort events by priority (desc) then by occurred_at (desc, most recent first).
 * Stable sort to maintain relative order for equal priorities.
 */
const sortEventsByPriority = (events: EventListItem[]): EventListItem[] => {
    return [...events].sort((a, b) => {
        const priorityDiff = getEventPriorityScore(b) - getEventPriorityScore(a);
        if (priorityDiff !== 0) return priorityDiff;

        // Fallback to date (most recent first)
        const dateA = a.occurred_at ? new Date(a.occurred_at).getTime() : 0;
        const dateB = b.occurred_at ? new Date(b.occurred_at).getTime() : 0;
        return dateB - dateA;
    });
};

/**
 * Determine Kanban column key for an event.
 * Precedence: ai_status (primary grouping), but we also consider human_status for special treatment.
 * - If ai_status is 'processing' or 'investigating', those take priority
 * - If ai_status is 'completed' or 'failed', use those
 * - If ai_status is 'pending', use that
 */
const getColumnKey = (event: EventListItem): string => {
    // AI status is the primary determinant
    return event.ai_status || 'pending';
};

// ============================================================================
// QUICK FILTER DEFINITIONS
// ============================================================================

interface QuickFilter {
    id: string;
    label: string;
    icon: LucideIcon;
    /** Return true if this filter should include the event */
    predicate: (event: EventListItem) => boolean;
    /** Optional: backend filter to apply */
    backendFilter?: Partial<Filters>;
    /** Style when active */
    activeClass: string;
}

const QUICK_FILTERS: QuickFilter[] = [
    {
        id: 'critical',
        label: 'Críticas',
        icon: ShieldAlert,
        predicate: (e) => e.severity === 'critical',
        backendFilter: { severity: 'critical' },
        activeClass: 'bg-red-100 text-red-800 border-red-300 dark:bg-red-500/20 dark:text-red-200 dark:border-red-500/30',
    },
    {
        id: 'needs_attention',
        label: 'Requieren atención',
        icon: UserCheck,
        predicate: (e) => e.needs_attention === true,
        // No backend filter - client-side only
        activeClass: 'bg-amber-100 text-amber-800 border-amber-300 dark:bg-amber-500/20 dark:text-amber-200 dark:border-amber-500/30',
    },
    {
        id: 'investigating',
        label: 'Investigando',
        icon: Search,
        predicate: (e) => e.ai_status === 'investigating',
        backendFilter: { status: 'investigating' },
        activeClass: 'bg-amber-100 text-amber-800 border-amber-300 dark:bg-amber-500/20 dark:text-amber-200 dark:border-amber-500/30',
    },
    {
        id: 'failed',
        label: 'Con error',
        icon: XCircle,
        predicate: (e) => e.ai_status === 'failed',
        backendFilter: { status: 'failed' },
        activeClass: 'bg-rose-100 text-rose-800 border-rose-300 dark:bg-rose-500/20 dark:text-rose-200 dark:border-rose-500/30',
    },
    {
        id: 'with_images',
        label: 'Con evidencia',
        icon: Camera,
        predicate: (e) => e.has_images === true,
        activeClass: 'bg-blue-100 text-blue-800 border-blue-300 dark:bg-blue-500/20 dark:text-blue-200 dark:border-blue-500/30',
    },
];

// ============================================================================
// MAIN COMPONENT
// ============================================================================

export default function SamsaraAlertsIndex({
    events,
    filters,
    filterOptions,
    stats,
}: IndexProps) {
    // State
    const [localFilters, setLocalFilters] = useState<Filters>(filters);
    const [viewMode, setViewMode] = useState<'list' | 'kanban'>(() => {
        if (typeof window !== 'undefined') {
            const saved = localStorage.getItem(STORAGE_KEY_VIEW_MODE);
            return (saved === 'list' || saved === 'kanban') ? saved : 'kanban';
        }
        return 'kanban';
    });
    const [quickViewId, setQuickViewId] = useState<number | null>(null);
    const [activeQuickFilters, setActiveQuickFilters] = useState<string[]>(() => {
        if (typeof window !== 'undefined') {
            try {
                const saved = localStorage.getItem(STORAGE_KEY_QUICK_FILTERS);
                return saved ? JSON.parse(saved) : [];
            } catch {
                return [];
            }
        }
        return [];
    });
    const [isPollingPaused, setIsPollingPaused] = useState(false);
    const [isPollingActive, setIsPollingActive] = useState(false);
    const searchInputRef = useRef<HTMLInputElement>(null);
    const { timezone } = useTimezone();

    // Bind formatDate with timezone
    const formatDateTz = useCallback((value?: string | null) => formatDate(value, timezone), [timezone]);

    // Persist view mode
    useEffect(() => {
        localStorage.setItem(STORAGE_KEY_VIEW_MODE, viewMode);
    }, [viewMode]);

    // Persist quick filters
    useEffect(() => {
        localStorage.setItem(STORAGE_KEY_QUICK_FILTERS, JSON.stringify(activeQuickFilters));
    }, [activeQuickFilters]);

    // Check if polling should be active
    const shouldPoll = useMemo(() => {
        return events.data.some(
            (e) => e.ai_status === 'processing' || e.ai_status === 'investigating'
        );
    }, [events.data]);

    // Polling effect
    useEffect(() => {
        if (!shouldPoll || isPollingPaused) {
            setIsPollingActive(false);
            return;
        }

        setIsPollingActive(true);
        const interval = setInterval(() => {
            // Only reload events and stats
            router.reload({ only: ['events', 'stats'] });
        }, POLLING_INTERVAL_MS);

        return () => {
            clearInterval(interval);
            setIsPollingActive(false);
        };
    }, [shouldPoll, isPollingPaused]);

    // Update selected event when events data changes (for modal)
    const selectedEvent = useMemo(() => {
        if (!quickViewId) return null;
        return events.data.find((e) => e.id === quickViewId) ?? null;
    }, [quickViewId, events.data]);

    // Filter events client-side based on active quick filters (for filters without backend support)
    const filteredEvents = useMemo(() => {
        let result = events.data;

        // Apply client-side quick filters that don't have backend support
        const clientOnlyFilters = activeQuickFilters.filter(
            (id) => !QUICK_FILTERS.find((f) => f.id === id)?.backendFilter
        );

        if (clientOnlyFilters.length > 0) {
            result = result.filter((event) =>
                clientOnlyFilters.every((filterId) => {
                    const filter = QUICK_FILTERS.find((f) => f.id === filterId);
                    return filter ? filter.predicate(event) : true;
                })
            );
        }

        return result;
    }, [events.data, activeQuickFilters]);

    // Sort events by priority
    const sortedEvents = useMemo(() => sortEventsByPriority(filteredEvents), [filteredEvents]);

    // Handlers
    const sanitizedFilters = (values: Filters) =>
        Object.fromEntries(Object.entries(values).filter(([, value]) => value));

    const hasActiveFilters = useMemo(
        () => Object.values(localFilters).some((value) => value),
        [localFilters],
    );

    const handleSubmit = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get(ALERTS_INDEX_URL, sanitizedFilters(localFilters), {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const handleReset = () => {
        setLocalFilters({
            search: '',
            severity: '',
            status: '',
            event_type: '',
            date_from: '',
            date_to: '',
        });
        setActiveQuickFilters([]);
        router.get(ALERTS_INDEX_URL, {}, { replace: true });
    };

    const handleQuickFilterToggle = useCallback((filterId: string) => {
        const filter = QUICK_FILTERS.find((f) => f.id === filterId);
        if (!filter) return;

        setActiveQuickFilters((prev) => {
            const isActive = prev.includes(filterId);
            const next = isActive ? prev.filter((id) => id !== filterId) : [...prev, filterId];

            // If filter has backend support, apply it
            if (filter.backendFilter) {
                const newFilters = { ...localFilters };
                if (isActive) {
                    // Remove filter
                    Object.keys(filter.backendFilter).forEach((key) => {
                        newFilters[key as keyof Filters] = '';
                    });
                } else {
                    // Apply filter
                    Object.assign(newFilters, filter.backendFilter);
                }
                setLocalFilters(newFilters);
                router.get(ALERTS_INDEX_URL, sanitizedFilters(newFilters), {
                    preserveScroll: true,
                    preserveState: true,
                    replace: true,
                });
            }

            return next;
        });
    }, [localFilters]);

    const openQuickView = useCallback((eventId: number) => {
        setQuickViewId(eventId);
    }, []);

    const closeQuickView = useCallback(() => {
        setQuickViewId(null);
    }, []);

    const handleRefresh = useCallback(() => {
        router.reload({ only: ['events', 'stats'] });
    }, []);

    // Keyboard shortcuts
    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            // Don't trigger shortcuts when typing in inputs
            if (e.target instanceof HTMLInputElement || e.target instanceof HTMLTextAreaElement) {
                if (e.key === 'Escape') {
                    (e.target as HTMLElement).blur();
                }
                return;
            }

            switch (e.key) {
                case 'k':
                    e.preventDefault();
                    setViewMode((prev) => (prev === 'kanban' ? 'list' : 'kanban'));
                    break;
                case '/':
                    e.preventDefault();
                    searchInputRef.current?.focus();
                    break;
                case 'r':
                    e.preventDefault();
                    handleRefresh();
                    break;
                case 'Escape':
                    if (quickViewId) {
                        closeQuickView();
                    }
                    break;
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [quickViewId, closeQuickView, handleRefresh]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Alertas de Samsara" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                {/* ============================================================
                    HEADER
                ============================================================ */}
                <header className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                    <div>
                        <p className="text-sm font-medium text-muted-foreground">
                            Panel operativo • Ingesta AI
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Alertas de Samsara
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Monitorea y resuelve alertas de pánico, seguridad y eventos críticos.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {/* Polling Status Indicator */}
                        {shouldPoll && (
                            <div className="flex items-center gap-2 rounded-lg border px-3 py-1.5">
                                {isPollingActive ? (
                                    <>
                                        <span className="relative flex h-2 w-2">
                                            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                                            <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
                                        </span>
                                        <span className="text-xs text-muted-foreground">Auto-actualizando</span>
                                    </>
                                ) : (
                                    <>
                                        <span className="h-2 w-2 rounded-full bg-slate-300" />
                                        <span className="text-xs text-muted-foreground">Pausado</span>
                                    </>
                                )}
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-6 w-6 p-0"
                                    onClick={() => setIsPollingPaused(!isPollingPaused)}
                                >
                                    {isPollingPaused ? (
                                        <Play className="size-3" />
                                    ) : (
                                        <Pause className="size-3" />
                                    )}
                                </Button>
                            </div>
                        )}

                        {/* View Toggle */}
                        <div className="flex items-center rounded-lg border bg-background p-1">
                            <Button
                                variant={viewMode === 'kanban' ? 'secondary' : 'ghost'}
                                size="sm"
                                onClick={() => setViewMode('kanban')}
                                className="gap-2"
                            >
                                <LayoutGrid className="size-4" />
                                <span className="hidden sm:inline">Kanban</span>
                            </Button>
                            <Button
                                variant={viewMode === 'list' ? 'secondary' : 'ghost'}
                                size="sm"
                                onClick={() => setViewMode('list')}
                                className="gap-2"
                            >
                                <List className="size-4" />
                                <span className="hidden sm:inline">Lista</span>
                            </Button>
                        </div>

                        {/* Refresh */}
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Button variant="outline" size="sm" onClick={handleRefresh}>
                                    <RefreshCcw className="size-4" />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>Refrescar (R)</TooltipContent>
                        </Tooltip>

                        {/* Reset Filters */}
                        {hasActiveFilters && (
                            <Button variant="secondary" size="sm" className="gap-2" onClick={handleReset}>
                                <XCircle className="size-4" />
                                <span className="hidden sm:inline">Limpiar</span>
                            </Button>
                        )}

                        {/* Keyboard Shortcuts Hint */}
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Button variant="ghost" size="sm" className="text-muted-foreground">
                                    <Keyboard className="size-4" />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent className="max-w-xs">
                                <p className="font-semibold mb-1">Atajos de teclado</p>
                                <ul className="text-xs space-y-0.5">
                                    <li><kbd className="px-1 bg-muted rounded">/</kbd> Buscar</li>
                                    <li><kbd className="px-1 bg-muted rounded">K</kbd> Alternar vista</li>
                                    <li><kbd className="px-1 bg-muted rounded">R</kbd> Refrescar</li>
                                    <li><kbd className="px-1 bg-muted rounded">Esc</kbd> Cerrar modal</li>
                                </ul>
                            </TooltipContent>
                        </Tooltip>
                    </div>
                </header>

                {/* ============================================================
                    STATS CARDS
                ============================================================ */}
                <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                    <StatsCard
                        icon={BarChart3}
                        label="Total"
                        value={stats.total}
                        onClick={() => handleReset()}
                    />
                    <StatsCard
                        icon={UserCheck}
                        label="Requieren atención"
                        value={stats.needs_attention}
                        accent="text-amber-600 dark:text-amber-300"
                        onClick={() => handleQuickFilterToggle('needs_attention')}
                        active={activeQuickFilters.includes('needs_attention')}
                    />
                    <StatsCard
                        icon={ShieldAlert}
                        label="Críticas"
                        value={stats.critical}
                        accent="text-red-600 dark:text-red-300"
                        onClick={() => handleQuickFilterToggle('critical')}
                        active={activeQuickFilters.includes('critical')}
                    />
                    <StatsCard
                        icon={Search}
                        label="Investigando"
                        value={stats.investigating}
                        accent="text-amber-600 dark:text-amber-300"
                        onClick={() => handleQuickFilterToggle('investigating')}
                        active={activeQuickFilters.includes('investigating')}
                    />
                    <StatsCard
                        icon={CheckCircle2}
                        label="Completadas"
                        value={stats.completed}
                        accent="text-emerald-600 dark:text-emerald-300"
                    />
                    <StatsCard
                        icon={XCircle}
                        label="Con error"
                        value={stats.failed}
                        accent="text-rose-600 dark:text-rose-300"
                        onClick={() => handleQuickFilterToggle('failed')}
                        active={activeQuickFilters.includes('failed')}
                    />
                </section>

                {/* ============================================================
                    QUICK FILTERS BAR
                ============================================================ */}
                <section className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-medium text-muted-foreground">Filtros rápidos:</span>
                    {QUICK_FILTERS.map((filter) => {
                        const isActive = activeQuickFilters.includes(filter.id);
                        const Icon = filter.icon;
                        return (
                            <Button
                                key={filter.id}
                                variant="outline"
                                size="sm"
                                onClick={() => handleQuickFilterToggle(filter.id)}
                                className={`gap-1.5 transition-colors ${isActive ? filter.activeClass : ''}`}
                            >
                                <Icon className="size-3.5" />
                                {filter.label}
                            </Button>
                        );
                    })}
                    {activeQuickFilters.length > 0 && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                setActiveQuickFilters([]);
                                handleReset();
                            }}
                            className="text-muted-foreground"
                        >
                            Limpiar todos
                        </Button>
                    )}
                </section>

                {/* ============================================================
                    FILTERS SECTION
                ============================================================ */}
                <section className="rounded-xl border bg-card">
                    <header className="flex items-center gap-3 border-b px-4 py-3">
                        <div className="rounded-full bg-primary/10 p-2 text-primary">
                            <Filter className="size-4" />
                        </div>
                        <div className="flex-1">
                            <p className="text-sm font-semibold">Filtros avanzados</p>
                        </div>
                        {hasActiveFilters && (
                            <Badge variant="secondary" className="text-xs">
                                {Object.values(localFilters).filter(Boolean).length} activos
                            </Badge>
                        )}
                    </header>
                    <form onSubmit={handleSubmit} className="grid gap-4 px-4 py-3 lg:grid-cols-7">
                        <div className="lg:col-span-2">
                            <Label htmlFor="search">Búsqueda</Label>
                            <div className="relative mt-1">
                                <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    ref={searchInputRef}
                                    id="search"
                                    name="search"
                                    value={localFilters.search}
                                    onChange={(e) =>
                                        setLocalFilters((prev) => ({ ...prev, search: e.target.value }))
                                    }
                                    placeholder="Unidad, conductor, evento... (/)"
                                    className="pl-10"
                                />
                            </div>
                        </div>

                        <FilterSelect
                            label="Severidad"
                            value={localFilters.severity}
                            onChange={(value) => setLocalFilters((prev) => ({ ...prev, severity: value }))}
                            options={filterOptions.severities}
                        />

                        <FilterSelect
                            label="Estado AI"
                            value={localFilters.status}
                            onChange={(value) => setLocalFilters((prev) => ({ ...prev, status: value }))}
                            options={filterOptions.statuses}
                        />

                        <FilterSelect
                            label="Tipo"
                            value={localFilters.event_type}
                            onChange={(value) => setLocalFilters((prev) => ({ ...prev, event_type: value }))}
                            options={[{ label: 'Todos', value: '' }, ...filterOptions.event_types]}
                        />

                        <div>
                            <Label htmlFor="date_from">Desde</Label>
                            <Input
                                id="date_from"
                                type="date"
                                value={localFilters.date_from}
                                onChange={(e) => setLocalFilters((prev) => ({ ...prev, date_from: e.target.value }))}
                                className="mt-1"
                            />
                        </div>

                        <div>
                            <Label htmlFor="date_to">Hasta</Label>
                            <Input
                                id="date_to"
                                type="date"
                                value={localFilters.date_to}
                                onChange={(e) => setLocalFilters((prev) => ({ ...prev, date_to: e.target.value }))}
                                className="mt-1"
                            />
                        </div>

                        <div className="flex items-end lg:col-span-7">
                            <Button type="submit" className="w-full lg:w-auto">
                                <Filter className="size-4 mr-2" />
                                Aplicar
                            </Button>
                        </div>
                    </form>
                </section>

                {/* ============================================================
                    EVENTS VIEW (KANBAN OR LIST)
                ============================================================ */}
                {viewMode === 'kanban' ? (
                    <KanbanBoard
                        events={sortedEvents}
                        onEventClick={openQuickView}
                    />
                ) : (
                    <ListView
                        events={sortedEvents}
                        onEventClick={openQuickView}
                        formatEventTitle={formatEventTitle}
                        formatDate={formatDateTz}
                        getAlertShowUrl={getAlertShowUrl}
                    />
                )}

                {/* Quick View Modal */}
                <EventQuickViewModal
                    event={selectedEvent}
                    open={quickViewId !== null}
                    onClose={closeQuickView}
                />

                {/* Pagination (only for list view) */}
                {events.meta?.links?.length > 0 && viewMode === 'list' && (
                    <nav className="flex flex-col items-center justify-between gap-4 rounded-xl border px-4 py-3 text-sm text-muted-foreground md:flex-row">
                        <p>
                            Mostrando {events.meta.from ?? 0} - {events.meta.to ?? 0} de {events.meta.total ?? 0} alertas
                        </p>
                        <div className="flex flex-wrap items-center gap-2">
                            {events.meta.links.map((link, idx) => (
                                <Button
                                    key={`${link.label}-${idx}`}
                                    asChild
                                    variant={link.active ? 'secondary' : 'ghost'}
                                    size="sm"
                                    disabled={!link.url}
                                >
                                    <Link href={link.url ?? '#'} preserveScroll preserveState>
                                        <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                    </Link>
                                </Button>
                            ))}
                        </div>
                    </nav>
                )}
            </div>
        </AppLayout>
    );
}

// ============================================================================
// STATS CARD COMPONENT
// ============================================================================

interface StatsCardProps {
    icon: LucideIcon;
    label: string;
    value: number;
    accent?: string;
    onClick?: () => void;
    active?: boolean;
}

function StatsCard({ icon: Icon, label, value, accent, onClick, active }: StatsCardProps) {
    return (
        <Card
            className={`bg-gradient-to-b from-background to-muted/30 transition-all ${
                onClick ? 'cursor-pointer hover:shadow-md hover:scale-[1.02]' : ''
            } ${active ? 'ring-2 ring-primary ring-offset-2' : ''}`}
            onClick={onClick}
        >
            <CardHeader className="p-3 pb-1">
                <div className="flex items-center justify-between">
                    <div className="rounded-md bg-primary/10 p-1.5 text-primary">
                        <Icon className="size-3.5" />
                    </div>
                    {active && (
                        <Badge variant="secondary" className="text-[10px] px-1.5 py-0">
                            Activo
                        </Badge>
                    )}
                </div>
                <CardTitle className={`text-2xl font-bold ${accent ?? ''}`}>
                    {value.toLocaleString('es-MX')}
                </CardTitle>
            </CardHeader>
            <CardContent className="p-3 pt-0">
                <p className="text-xs text-muted-foreground">{label}</p>
            </CardContent>
        </Card>
    );
}

// ============================================================================
// FILTER SELECT COMPONENT
// ============================================================================

interface FilterSelectProps {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: FilterOption[];
}

function FilterSelect({ label, value, onChange, options }: FilterSelectProps) {
    const emptyToken = '__all__';
    const mapValue = (val: string) => (val === '' ? emptyToken : val);
    const resolveValue = (val: string) => (val === emptyToken ? '' : val);

    return (
        <div>
            <Label>{label}</Label>
            <Select value={mapValue(value)} onValueChange={(selected) => onChange(resolveValue(selected))}>
                <SelectTrigger className="mt-1">
                    <SelectValue placeholder="Todos" />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option.value || emptyToken} value={mapValue(option.value)}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

// ============================================================================
// LIST VIEW COMPONENT
// ============================================================================

interface ListViewProps {
    events: EventListItem[];
    onEventClick: (eventId: number) => void;
    formatEventTitle: (event: EventListItem) => string;
    formatDate: (value?: string | null) => string;
    getAlertShowUrl: (id: number) => string;
}

function ListView({ events, onEventClick, formatEventTitle, formatDate, getAlertShowUrl }: ListViewProps) {
    if (events.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center text-muted-foreground">
                <Search className="size-12 opacity-20" />
                <p className="text-lg font-semibold">No encontramos alertas con esos criterios</p>
                <p className="text-sm">Ajusta los filtros o intenta con un término diferente.</p>
            </div>
        );
    }

    return (
        <section className="flex flex-col gap-3">
            {events.map((event) => (
                <ListCard
                    key={event.id}
                    event={event}
                    onQuickView={() => onEventClick(event.id)}
                    formatEventTitle={formatEventTitle}
                    formatDate={formatDate}
                    getAlertShowUrl={getAlertShowUrl}
                />
            ))}
        </section>
    );
}

// ============================================================================
// LIST CARD COMPONENT
// ============================================================================

interface ListCardProps {
    event: EventListItem;
    onQuickView: () => void;
    formatEventTitle: (event: EventListItem) => string;
    formatDate: (value?: string | null) => string;
    getAlertShowUrl: (id: number) => string;
}

function ListCard({ event, onQuickView, formatEventTitle, formatDate, getAlertShowUrl }: ListCardProps) {
    const severityStyle = severityStyles[event.severity] ?? severityStyles.info;
    const statusStyle = statusStyles[event.ai_status] ?? statusStyles.pending;
    const SeverityIcon = severityStyle.icon;
    const StatusIcon = statusStyle.icon;

    const isProcessing = event.ai_status === 'processing';
    const isInvestigating = event.ai_status === 'investigating';
    const isFailed = event.ai_status === 'failed';

    return (
        <Card
            className={`transition-all hover:shadow-md cursor-pointer ${
                isFailed ? 'border-l-4 border-l-rose-500' : 
                event.severity === 'critical' ? 'border-l-4 border-l-red-500' :
                event.severity === 'warning' ? 'border-l-4 border-l-amber-500' :
                'border-l-4 border-l-blue-500'
            }`}
            onClick={onQuickView}
        >
            <CardHeader className="flex flex-col gap-3 border-b p-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex items-start gap-3">
                    <div className={`rounded-full p-2 ${event.severity === 'critical' ? 'bg-red-100 dark:bg-red-500/20' : 'bg-primary/10'}`}>
                        <SeverityIcon className={`size-5 ${event.severity === 'critical' ? 'text-red-600 dark:text-red-400' : 'text-primary'}`} />
                    </div>
                    <div className="flex-1 min-w-0">
                        <CardTitle className="text-lg line-clamp-1">{formatEventTitle(event)}</CardTitle>
                        <CardDescription className="flex flex-wrap items-center gap-2 mt-1">
                            <span>{event.occurred_at_human ?? formatDate(event.occurred_at)}</span>
                            {event.has_images && (
                                <span className="flex items-center gap-1 text-primary">
                                    <Camera className="size-3" />
                                    Evidencia
                                </span>
                            )}
                        </CardDescription>
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {/* Quick chips */}
                    {event.needs_attention && (
                        <Badge className="bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200 gap-1">
                            <UserCheck className="size-3" />
                            Requiere atención
                        </Badge>
                    )}
                    {event.human_status && event.human_status !== 'pending' && (
                        <Badge className={humanStatusStyles[event.human_status].badge}>
                            {event.human_status_label}
                        </Badge>
                    )}
                    <Badge className={severityStyle.badge}>{event.severity_label ?? 'Info'}</Badge>
                    <Badge className={statusStyle.badge}>
                        {(isProcessing || isInvestigating) && <StatusIcon className="size-3 mr-1" />}
                        {event.ai_status_label ?? 'Pendiente'}
                    </Badge>
                </div>
            </CardHeader>
            <CardContent className="p-4 space-y-3">
                {/* Verdict Summary */}
                {event.verdict_summary && (
                    <div className={`flex items-center gap-2 rounded-lg border-2 px-3 py-2 text-sm font-semibold ${urgencyStyles[event.verdict_summary.urgency]}`}>
                        <Sparkles className="size-4 shrink-0" />
                        <span>
                            {event.verdict_summary.verdict}
                            {event.verdict_summary.likelihood && (
                                <span className="ml-1 font-normal opacity-80">
                                    ({event.verdict_summary.likelihood})
                                </span>
                            )}
                        </span>
                    </div>
                )}

                {/* Info Grid */}
                <div className="grid gap-3 text-sm sm:grid-cols-3">
                    <div className="flex items-center gap-2">
                        <Truck className="size-4 text-muted-foreground shrink-0" />
                        <div className="min-w-0">
                            <p className="text-xs text-muted-foreground">Unidad</p>
                            <p className="font-medium truncate">{event.vehicle_name ?? 'Sin identificar'}</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <User className="size-4 text-muted-foreground shrink-0" />
                        <div className="min-w-0">
                            <p className="text-xs text-muted-foreground">Conductor</p>
                            <p className="font-medium truncate">{event.driver_name ?? 'No detectado'}</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Calendar className="size-4 text-muted-foreground shrink-0" />
                        <div className="min-w-0">
                            <p className="text-xs text-muted-foreground">Fecha</p>
                            <p className="font-medium truncate">{formatDate(event.occurred_at)}</p>
                        </div>
                    </div>
                </div>

                {/* AI Message Preview */}
                {event.ai_message_preview && (
                    <p className="text-sm text-muted-foreground line-clamp-2">
                        {event.ai_message_preview}
                    </p>
                )}

                {/* Actions */}
                <div className="flex items-center justify-end gap-2 pt-2">
                    <Button variant="outline" size="sm" asChild onClick={(e) => e.stopPropagation()}>
                        <Link href={getAlertShowUrl(event.id)}>
                            Ver análisis completo
                        </Link>
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}
