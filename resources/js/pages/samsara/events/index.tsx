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
import { KanbanBoard } from '@/components/samsara/kanban-board';
import { EventQuickViewModal } from '@/components/samsara/event-quick-view-modal';
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
    Filter,
    LayoutGrid,
    List,
    RefreshCcw,
    Search,
    ShieldAlert,
    Truck,
    User,
    XCircle,
} from 'lucide-react';
import { type LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

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
        urgency: 'high' | 'medium' | 'low';
    } | null;
    investigation_summary?: {
        label: string;
        items: string[];
    }[];
    has_images?: boolean;
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
    };
}

const ALERTS_INDEX_URL = '/samsara/alerts';
const getAlertShowUrl = (id: number) => `/samsara/alerts/${id}`;

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Alertas Samsara',
        href: ALERTS_INDEX_URL,
    },
];

const severityStyles: Record<string, string> = {
    info: 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-200',
    warning:
        'bg-amber-100 text-amber-900 dark:bg-amber-500/20 dark:text-amber-200',
    critical:
        'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-100 font-semibold',
};

const statusStyles: Record<string, string> = {
    pending:
        'bg-slate-100 text-slate-800 dark:bg-slate-500/20 dark:text-slate-200',
    processing:
        'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-100 animate-pulse',
    investigating:
        'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-100 animate-pulse',
    completed:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-100',
    failed:
        'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-100 font-semibold',
};

export default function SamsaraAlertsIndex({
    events,
    filters,
    filterOptions,
    stats,
}: IndexProps) {
    const [localFilters, setLocalFilters] = useState<Filters>(filters);
    const [viewMode, setViewMode] = useState<'list' | 'kanban'>('kanban');
    const [quickViewId, setQuickViewId] = useState<number | null>(null);

    const sanitizedFilters = (values: Filters) =>
        Object.fromEntries(
            Object.entries(values).filter(([, value]) => value),
        );

    const hasActiveFilters = useMemo(
        () => Object.values(localFilters).some((value) => value),
        [localFilters],
    );

    // Polling para eventos en processing o investigating
    useEffect(() => {
        const hasActiveEvents = events.data.some(
            (e) => e.ai_status === 'processing' || e.ai_status === 'investigating'
        );

        if (hasActiveEvents) {
            const interval = setInterval(() => {
                router.reload({ only: ['events', 'stats'] });
            }, 5000); // Cada 5 segundos

            return () => clearInterval(interval);
        }
    }, [events.data]);

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
        router.get(ALERTS_INDEX_URL, {}, { replace: true });
    };

    const openQuickView = (eventId: number) => {
        setQuickViewId(eventId);
    };

    const closeQuickView = () => {
        setQuickViewId(null);
    };

    const selectedEvent = quickViewId
        ? events.data.find((e) => e.id === quickViewId) ?? null
        : null;

    const formatDate = (value?: string | null) => {
        if (!value) return 'Sin fecha';
        return new Intl.DateTimeFormat('es-MX', {
            dateStyle: 'medium',
            timeStyle: 'short',
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
                    .map(
                        (part) =>
                            part.charAt(0).toUpperCase() + part.slice(1).toLowerCase(),
                    )
                    .join(' ');
        }
    };

    const getEventIcon = (iconName?: string | null) => {
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

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Alertas de Samsara" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <header className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                    <div>
                        <p className="text-sm font-medium text-muted-foreground">
                            Panel operativo • Ingesta AI
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Alertas de Samsara
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Monitorea botones de pánico, severidad y estado del
                            procesamiento con AI.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <div className="flex items-center rounded-lg border bg-background p-1">
                            <Button
                                variant={viewMode === 'kanban' ? 'secondary' : 'ghost'}
                                size="sm"
                                onClick={() => setViewMode('kanban')}
                                className="gap-2"
                            >
                                <LayoutGrid className="size-4" />
                                Kanban
                            </Button>
                            <Button
                                variant={viewMode === 'list' ? 'secondary' : 'ghost'}
                                size="sm"
                                onClick={() => setViewMode('list')}
                                className="gap-2"
                            >
                                <List className="size-4" />
                                Lista
                            </Button>
                        </div>
                        <Button variant="outline" asChild>
                            <Link href={ALERTS_INDEX_URL} prefetch>
                                Refrescar listado
                            </Link>
                        </Button>
                        <Button
                            variant="secondary"
                            className="gap-2"
                            onClick={handleReset}
                        >
                            <RefreshCcw className="size-4" />
                            Limpiar filtros
                        </Button>
                    </div>
                </header>

                <section className="grid gap-4 md:grid-cols-2 lg:grid-cols-5">
                    <StatsCard
                        icon={BarChart3}
                        label="Alertas totales"
                        value={stats.total}
                        description="Eventos registrados en la plataforma"
                    />
                    <StatsCard
                        icon={AlertOctagon}
                        label="Críticas"
                        value={stats.critical}
                        description="Severidad marcada como crítica"
                        accent="text-red-600 dark:text-red-300"
                    />
                    <StatsCard
                        icon={Search}
                        label="Investigando"
                        value={stats.investigating}
                        description="Bajo monitoreo continuo de AI"
                        accent="text-amber-600 dark:text-amber-300"
                    />
                    <StatsCard
                        icon={CheckCircle2}
                        label="Resueltas"
                        value={stats.completed}
                        description="Procesadas por el pipeline de AI"
                        accent="text-emerald-600 dark:text-emerald-300"
                    />
                    <StatsCard
                        icon={XCircle}
                        label="Con error"
                        value={stats.failed}
                        description="Requieren revisión manual"
                        accent="text-amber-600 dark:text-amber-300"
                    />
                </section>

                <section className="rounded-xl border bg-card">
                    <header className="flex items-center gap-3 border-b px-6 py-4">
                        <div className="rounded-full bg-primary/10 p-2 text-primary">
                            <Filter className="size-4" />
                        </div>
                        <div>
                            <p className="text-sm font-semibold">Filtros</p>
                            <p className="text-xs text-muted-foreground">
                                Usa los filtros para encontrar rápidamente la alerta que buscas.
                            </p>
                        </div>
                        {hasActiveFilters && (
                            <Badge variant="secondary" className="ml-auto">
                                {Object.values(localFilters).filter(Boolean).length}{' '}
                                activos
                            </Badge>
                        )}
                    </header>
                    <form
                        onSubmit={handleSubmit}
                        className="grid gap-4 px-6 py-4 lg:grid-cols-6"
                    >
                        <div className="lg:col-span-2">
                            <Label htmlFor="search">Búsqueda rápida</Label>
                            <div className="relative mt-1">
                                <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    id="search"
                                    name="search"
                                    value={localFilters.search}
                                    onChange={(event) =>
                                        setLocalFilters((previous) => ({
                                            ...previous,
                                            search: event.currentTarget.value,
                                        }))
                                    }
                                    placeholder="Unidad, evento, conductor..."
                                    className="pl-10"
                                />
                            </div>
                        </div>

                        <FilterSelect
                            label="Severidad"
                            value={localFilters.severity}
                            onChange={(value) =>
                                setLocalFilters((previous) => ({
                                    ...previous,
                                    severity: value,
                                }))
                            }
                            options={filterOptions.severities}
                        />

                        <FilterSelect
                            label="Estado AI"
                            value={localFilters.status}
                            onChange={(value) =>
                                setLocalFilters((previous) => ({
                                    ...previous,
                                    status: value,
                                }))
                            }
                            options={filterOptions.statuses}
                        />

                        <FilterSelect
                            label="Tipo de alerta"
                            value={localFilters.event_type}
                            onChange={(value) =>
                                setLocalFilters((previous) => ({
                                    ...previous,
                                    event_type: value,
                                }))
                            }
                            options={[
                                { label: 'Todos', value: '' },
                                ...filterOptions.event_types,
                            ]}
                        />

                        <div>
                            <Label htmlFor="date_from">Desde</Label>
                            <Input
                                id="date_from"
                                type="date"
                                value={localFilters.date_from}
                                onChange={(event) =>
                                    setLocalFilters((previous) => ({
                                        ...previous,
                                        date_from: event.currentTarget.value,
                                    }))
                                }
                            />
                        </div>

                        <div>
                            <Label htmlFor="date_to">Hasta</Label>
                            <Input
                                id="date_to"
                                type="date"
                                value={localFilters.date_to}
                                onChange={(event) =>
                                    setLocalFilters((previous) => ({
                                        ...previous,
                                        date_to: event.currentTarget.value,
                                    }))
                                }
                            />
                        </div>

                        <div className="lg:col-span-6">
                            <Button type="submit" className="w-full lg:w-auto">
                                Aplicar filtros
                            </Button>
                        </div>
                    </form>
                </section>

                {/* Kanban or List View */}
                {viewMode === 'kanban' ? (
                    <KanbanBoard
                        events={events.data}
                        onEventClick={openQuickView}
                    />
                ) : (
                    <section className="flex flex-col gap-4">
                        {events.data.length === 0 ? (
                            <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center text-muted-foreground">
                                <p className="text-lg font-semibold">
                                    No encontramos alertas con esos criterios
                                </p>
                                <p className="text-sm">
                                    Ajusta los filtros o intenta con un término diferente.
                                </p>
                            </div>
                        ) : (
                            events.data.map((event) => (
                                <Card key={event.id} className="border shadow-sm">
                                    <CardHeader className="flex flex-col gap-3 border-b pb-4 sm:flex-row sm:items-start sm:justify-between">
                                        <div className="flex items-start gap-3">
                                            {(() => {
                                                const Icon = getEventIcon(event.event_icon);
                                                return (
                                                    <div className="rounded-full bg-primary/10 p-2 text-primary">
                                                        <Icon className="size-5" />
                                                    </div>
                                                );
                                            })()}
                                            <div>
                                                <p className="text-xs uppercase text-muted-foreground">
                                                    Tipo de alerta
                                                </p>
                                                <CardTitle className="text-xl">
                                                    {event.event_description ?? event.event_title ??
                                                        formatEventType(event.event_type)}
                                                </CardTitle>
                                                <CardDescription>
                                                    {event.occurred_at_human
                                                        ? `${event.occurred_at_human}`
                                                        : 'Fecha no disponible'}
                                                </CardDescription>
                                            </div>
                                        </div>
                                        <div className="flex flex-col items-end gap-2 text-sm text-muted-foreground">
                                            <div className="flex gap-2">
                                                <Badge
                                                    className={
                                                        severityStyles[event.severity] ??
                                                        severityStyles.info
                                                    }
                                                >
                                                    {event.severity_label ?? 'Informativa'}
                                                </Badge>
                                                <Badge
                                                    className={
                                                        statusStyles[event.ai_status] ??
                                                        statusStyles.pending
                                                    }
                                                >
                                                    {event.ai_status_label ?? 'Pendiente'}
                                                </Badge>
                                            </div>
                                        </div>
                                    </CardHeader>
                                    <CardContent className="space-y-4 py-4">
                                        {event.verdict_summary && (
                                            <div
                                                className={`flex items-center gap-2 rounded-lg border-2 px-3 py-2 text-sm font-semibold ${getUrgencyStyles(
                                                    event.verdict_summary.urgency,
                                                )
                                                    }`}
                                            >
                                                <CheckCircle2 className="size-4" />
                                                <span>
                                                    {event.verdict_summary.verdict}
                                                    {event.verdict_summary.likelihood && (
                                                        <span className="ml-1 font-normal opacity-80">
                                                            (Probabilidad:{' '}
                                                            {event.verdict_summary.likelihood})
                                                        </span>
                                                    )}
                                                </span>
                                            </div>
                                        )}
                                        <div className="grid gap-3 text-sm md:grid-cols-3">
                                            <InfoItem
                                                icon={Truck}
                                                label="Unidad monitoreada"
                                                value={
                                                    event.vehicle_name ??
                                                    'Unidad sin nombre asignado'
                                                }
                                            />
                                            <InfoItem
                                                icon={User}
                                                label="Operador identificado"
                                                value={
                                                    event.driver_name ??
                                                    'No se detectó conductor asignado'
                                                }
                                            />
                                            <InfoItem
                                                icon={Calendar}
                                                label="Momento del evento"
                                                value={formatDate(event.occurred_at)}
                                            />
                                        </div>
                                        {event.investigation_summary &&
                                            event.investigation_summary.length > 0 && (
                                                <div className="rounded-lg border bg-muted/30 p-3">
                                                    <p className="mb-2 text-xs font-semibold uppercase text-muted-foreground">
                                                        Investigación realizada
                                                    </p>
                                                    <div className="space-y-2">
                                                        {event.investigation_summary.map(
                                                            (category) => (
                                                                <div
                                                                    key={category.label}
                                                                    className="flex items-start gap-2 text-xs"
                                                                >
                                                                    <CheckCircle2 className="mt-0.5 size-3 shrink-0 text-emerald-500" />
                                                                    <div>
                                                                        <span className="font-medium">
                                                                            {category.label}:
                                                                        </span>{' '}
                                                                        <span className="text-muted-foreground">
                                                                            {category.items.join(
                                                                                ', ',
                                                                            )}
                                                                        </span>
                                                                    </div>
                                                                </div>
                                                            ),
                                                        )}
                                                    </div>
                                                </div>
                                            )}
                                        <p className="text-sm text-muted-foreground">
                                            {event.ai_message_preview ??
                                                'Sin mensaje generado por la AI aún.'}
                                        </p>
                                        <div className="flex items-center justify-end">
                                            <Button variant="outline" size="sm" asChild>
                                                <Link href={getAlertShowUrl(event.id)} prefetch>
                                                    Ver análisis completo
                                                </Link>
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Card>
                            ))
                        )}
                    </section>
                )}

                {/* Quick View Modal */}
                <EventQuickViewModal
                    event={selectedEvent}
                    open={quickViewId !== null}
                    onClose={closeQuickView}
                />

                {events.meta?.links?.length > 0 && viewMode === 'list' && (
                    <nav className="flex flex-col items-center justify-between gap-4 rounded-xl border px-4 py-3 text-sm text-muted-foreground md:flex-row">
                        <p>
                            Mostrando {events.meta.from ?? 0} - {events.meta.to ?? 0} de{' '}
                            {events.meta.total ?? 0} alertas
                        </p>
                        <div className="flex flex-wrap items-center gap-2">
                            {events.meta.links.map((link) => (
                                <Button
                                    key={link.label}
                                    asChild
                                    variant={link.active ? 'secondary' : 'ghost'}
                                    size="sm"
                                    disabled={!link.url}
                                >
                                    <Link
                                        href={link.url ?? '#'}
                                        preserveScroll
                                        preserveState
                                    >
                                        <span
                                            dangerouslySetInnerHTML={{
                                                __html: link.label,
                                            }}
                                        />
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

function StatsCard({
    icon: Icon,
    label,
    value,
    description,
    accent,
}: {
    icon: LucideIcon;
    label: string;
    value: number;
    description: string;
    accent?: string;
}) {
    return (
        <Card className="bg-gradient-to-b from-background to-muted/30">
            <CardHeader className="pb-2">
                <div className="flex items-center gap-2">
                    <div className="rounded-md bg-primary/10 p-1.5 text-primary">
                        <Icon className="size-3.5" />
                    </div>
                    <CardDescription>{label}</CardDescription>
                </div>
                <CardTitle className={`text-3xl ${accent ?? ''}`}>
                    {value.toLocaleString('es-MX')}
                </CardTitle>
            </CardHeader>
            <CardContent>
                <p className="text-sm text-muted-foreground">{description}</p>
            </CardContent>
        </Card>
    );
}

function FilterSelect({
    label,
    value,
    onChange,
    options,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: FilterOption[];
}) {
    const emptyToken = '__all__';
    const mapValue = (val: string) => (val === '' ? emptyToken : val);
    const resolveValue = (val: string) => (val === emptyToken ? '' : val);

    return (
        <div>
            <Label>{label}</Label>
            <Select
                value={mapValue(value)}
                onValueChange={(selected) => onChange(resolveValue(selected))}
            >
                <SelectTrigger className="mt-1">
                    <SelectValue placeholder="Selecciona" />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem
                            key={option.value || emptyToken}
                            value={mapValue(option.value)}
                        >
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

function InfoItem({
    icon: Icon,
    label,
    value,
}: {
    icon?: LucideIcon;
    label: string;
    value: string;
}) {
    return (
        <div>
            <div className="mb-1 flex items-center gap-1.5">
                {Icon && (
                    <Icon className="size-3 text-muted-foreground" />
                )}
                <p className="text-xs uppercase text-muted-foreground">{label}</p>
            </div>
            <p className="text-sm font-semibold">{value}</p>
        </div>
    );
}
