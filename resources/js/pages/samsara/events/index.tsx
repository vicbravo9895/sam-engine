import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import type { EventListItem } from '@/types/samsara';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronDown, Filter, Search, ShieldX } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';

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
    attention: string;
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

const ALERTS_INDEX_URL = '/samsara/alerts';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Alertas Samsara', href: ALERTS_INDEX_URL },
];

const severityDotColors: Record<string, string> = {
    critical: 'bg-red-500',
    warning: 'bg-amber-500',
    info: 'bg-muted-foreground',
};

const severityBadgeVariants: Record<string, 'destructive' | 'warning' | 'info' | 'secondary'> = {
    critical: 'destructive',
    warning: 'warning',
    info: 'secondary',
};

const statusBadgeVariants: Record<string, 'success' | 'warning' | 'destructive' | 'secondary'> = {
    completed: 'success',
    investigating: 'warning',
    failed: 'destructive',
    pending: 'secondary',
    processing: 'secondary',
};

const severityRowBorder: Record<string, string> = {
    critical: 'border-l-2 border-l-red-500',
    warning: 'border-l-2 border-l-amber-500',
    info: 'border-l-2 border-l-transparent',
};

function sanitizedFilters(values: Filters): Record<string, string> {
    return Object.fromEntries(
        Object.entries(values).filter(([, v]) => v !== '' && v != null)
    ) as Record<string, string>;
}

function formatEventTypeLabel(event: EventListItem): string {
    return event.event_title ?? event.event_description ?? event.event_type ?? '\u2014';
}

function formatSlaRemaining(event: EventListItem): string | null {
    if (event.ack_status !== 'pending' || !event.ack_due_at) return null;
    const due = new Date(event.ack_due_at).getTime();
    const now = Date.now();
    const diffMs = due - now;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMs <= 0) return 'Vencido';
    if (diffDays > 0) return `${diffDays}d`;
    if (diffHours > 0) return `${diffHours}h`;
    if (diffMins > 0) return `${diffMins}m`;
    return '<1m';
}

function getSlaColorClass(event: EventListItem): string {
    if (event.ack_status !== 'pending' || !event.ack_due_at) return 'text-muted-foreground';
    const due = new Date(event.ack_due_at).getTime();
    const now = Date.now();
    if (due <= now) return 'text-red-600 dark:text-red-400 font-semibold';
    const diffMins = (due - now) / 60000;
    if (diffMins <= 60) return 'text-amber-600 dark:text-amber-400';
    return 'text-muted-foreground';
}

interface FilterSelectProps {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: FilterOption[];
    placeholder?: string;
}

function FilterSelect({ label, value, onChange, options, placeholder = 'Todos' }: FilterSelectProps) {
    const emptyToken = '__all__';
    const mapValue = (val: string) => (val === '' ? emptyToken : val);
    const resolveValue = (val: string) => (val === emptyToken ? '' : val);

    return (
        <div>
            <Label className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{label}</Label>
            <Select value={mapValue(value)} onValueChange={(v) => onChange(resolveValue(v))}>
                <SelectTrigger className="mt-1 h-9">
                    <SelectValue placeholder={placeholder} />
                </SelectTrigger>
                <SelectContent>
                    {options.map((opt) => (
                        <SelectItem key={opt.value || emptyToken} value={mapValue(opt.value)}>
                            {opt.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

export default function SamsaraAlertsIndex({ events, filters, filterOptions, stats }: IndexProps) {
    const [localFilters, setLocalFilters] = useState<Filters>(filters);
    const [filtersExpanded, setFiltersExpanded] = useState(false);
    const searchInputRef = useRef<HTMLInputElement>(null);

    const hasActiveFilters = Object.values(localFilters).some((v) => v !== '');

    const applyFilters = useCallback((newFilters: Filters) => {
        router.get(ALERTS_INDEX_URL, sanitizedFilters(newFilters), {
            preserveState: true,
            preserveScroll: true,
        });
    }, []);

    const handleFilterChange = useCallback((key: keyof Filters, value: string) => {
        setLocalFilters((prev) => {
            const next = { ...prev, [key]: value };
            applyFilters(next);
            return next;
        });
    }, [applyFilters]);

    const handleSearch = useCallback(() => {
        applyFilters(localFilters);
    }, [localFilters, applyFilters]);

    const handleReset = useCallback(() => {
        const empty: Filters = { search: '', severity: '', status: '', event_type: '', date_from: '', date_to: '', attention: '' };
        setLocalFilters(empty);
        router.get(ALERTS_INDEX_URL, {}, { preserveState: true });
    }, []);

    const handleAttentionToggle = useCallback((checked: boolean | 'indeterminate') => {
        setLocalFilters((prev) => {
            const next = { ...prev, attention: checked === true ? 'actionable' : '' };
            applyFilters(next);
            return next;
        });
    }, [applyFilters]);

    const handleRowClick = useCallback((event: EventListItem) => {
        router.visit(`/samsara/alerts/${event.id}`);
    }, []);

    const paginationLinks = events.links?.length ? events.links : events.meta?.links ?? [];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Alertas" />
            <div className="flex flex-1 flex-col gap-4 p-4 sm:p-6">
                {/* Page header */}
                <header>
                    <h1 className="font-display text-2xl font-bold tracking-tight">Alertas</h1>
                    <p className="text-sm text-muted-foreground">
                        Monitorea y gestiona alertas de flota procesadas por AI
                    </p>
                </header>

                {/* Search + filters */}
                <section className="rounded-xl border bg-card shadow-sm">
                    <div className="flex flex-wrap items-end gap-3 p-4">
                        <div className="min-w-[200px] flex-1">
                            <Label htmlFor="search" className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                                Busqueda
                            </Label>
                            <div className="relative mt-1">
                                <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    ref={searchInputRef}
                                    id="search"
                                    value={localFilters.search}
                                    onChange={(e) => setLocalFilters((p) => ({ ...p, search: e.target.value }))}
                                    onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                                    placeholder="Unidad, conductor, evento..."
                                    className="h-9 pl-9"
                                />
                            </div>
                        </div>

                        <FilterSelect label="Severidad" value={localFilters.severity} onChange={(v) => handleFilterChange('severity', v)} options={filterOptions.severities} />
                        <FilterSelect label="Estado" value={localFilters.status} onChange={(v) => handleFilterChange('status', v)} options={filterOptions.statuses} />

                        <Button size="sm" onClick={handleSearch} className="h-9 gap-2">
                            <Filter className="size-4" />
                            Aplicar
                        </Button>

                        {hasActiveFilters ? (
                            <Button variant="ghost" size="sm" onClick={handleReset} className="h-9 text-muted-foreground">
                                Limpiar
                            </Button>
                        ) : null}

                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setFiltersExpanded((prev) => !prev)}
                            className="h-9 gap-1 text-muted-foreground"
                        >
                            <ChevronDown className={`size-4 transition-transform duration-200 ${filtersExpanded ? 'rotate-180' : ''}`} />
                            Mas filtros
                        </Button>
                    </div>

                    {/* Collapsible advanced filters */}
                    {filtersExpanded ? (
                        <div className="flex flex-wrap items-end gap-3 border-t px-4 py-3">
                            <FilterSelect label="Tipo" value={localFilters.event_type} onChange={(v) => handleFilterChange('event_type', v)} options={[{ label: 'Todos', value: '' }, ...filterOptions.event_types]} />
                            <div>
                                <Label htmlFor="date_from" className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Desde</Label>
                                <Input id="date_from" type="date" value={localFilters.date_from} onChange={(e) => handleFilterChange('date_from', e.target.value)} className="mt-1 h-9" />
                            </div>
                            <div>
                                <Label htmlFor="date_to" className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Hasta</Label>
                                <Input id="date_to" type="date" value={localFilters.date_to} onChange={(e) => handleFilterChange('date_to', e.target.value)} className="mt-1 h-9" />
                            </div>
                            <label className="flex cursor-pointer items-center gap-2 pb-2">
                                <Checkbox checked={localFilters.attention === 'actionable'} onCheckedChange={handleAttentionToggle} />
                                <span className="text-sm">Solo accionables</span>
                            </label>
                        </div>
                    ) : null}
                </section>

                {/* Stats pills */}
                <section className="flex flex-wrap items-center gap-2">
                    <Badge variant="secondary" className="font-mono text-xs">{stats.total.toLocaleString()} total</Badge>
                    {stats.critical > 0 ? <Badge variant="critical">{stats.critical} criticas</Badge> : null}
                    {stats.needs_attention > 0 ? <Badge variant="warning">{stats.needs_attention} atencion</Badge> : null}
                    {stats.investigating > 0 ? <Badge variant="info">{stats.investigating} investigando</Badge> : null}
                    {stats.failed > 0 ? <Badge variant="destructive">{stats.failed} fallidas</Badge> : null}
                </section>

                {/* Data table */}
                <section className="rounded-xl border bg-card shadow-sm">
                    {events.data.length === 0 ? (
                        <div className="flex flex-col items-center justify-center gap-3 py-20 text-center text-muted-foreground">
                            <ShieldX className="size-14 text-muted-foreground/20" />
                            <p className="font-display font-semibold">No hay alertas con esos filtros</p>
                            <p className="text-sm text-muted-foreground/70">Ajusta los criterios o limpia los filtros</p>
                        </div>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="w-[80px]">ID</TableHead>
                                    <TableHead>Vehiculo</TableHead>
                                    <TableHead>Tipo</TableHead>
                                    <TableHead>Severidad</TableHead>
                                    <TableHead>Creada</TableHead>
                                    <TableHead>Propietario</TableHead>
                                    <TableHead>SLA</TableHead>
                                    <TableHead>Estado</TableHead>
                                    <TableHead>Veredicto</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {events.data.map((event) => (
                                    <TableRow
                                        key={event.id}
                                        className={`cursor-pointer text-sm ${severityRowBorder[event.severity] ?? 'border-l-2 border-l-transparent'}`}
                                        onClick={() => handleRowClick(event)}
                                    >
                                        <TableCell className="font-mono text-xs font-medium">
                                            <div className="flex items-center gap-2">
                                                <span className={`size-2 shrink-0 rounded-full ${severityDotColors[event.severity] ?? severityDotColors.info}`} />
                                                {event.id}
                                            </div>
                                        </TableCell>
                                        <TableCell>{event.vehicle_name ?? '\u2014'}</TableCell>
                                        <TableCell className="max-w-[160px] truncate">{formatEventTypeLabel(event)}</TableCell>
                                        <TableCell>
                                            <Badge variant={severityBadgeVariants[event.severity] ?? 'secondary'}>
                                                {event.severity_label ?? 'Info'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground text-xs">{event.occurred_at_human ?? event.occurred_at ?? '\u2014'}</TableCell>
                                        <TableCell className={event.owner_name ? '' : 'text-muted-foreground'}>{event.owner_name ?? 'Sin asignar'}</TableCell>
                                        <TableCell>
                                            {formatSlaRemaining(event) ? (
                                                <span className={`font-mono text-xs ${getSlaColorClass(event)}`}>{formatSlaRemaining(event)}</span>
                                            ) : (
                                                <span className="text-muted-foreground">Sin SLA</span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={statusBadgeVariants[event.ai_status] ?? 'secondary'}>
                                                {event.ai_status_label ?? event.ai_status}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="max-w-[180px] truncate text-xs text-muted-foreground">{event.ai_assessment_view?.verdict ?? '\u2014'}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </section>

                {/* Pagination */}
                {paginationLinks.length > 0 ? (
                    <nav className="flex flex-col items-center justify-between gap-4 rounded-xl border bg-card px-4 py-3 text-sm text-muted-foreground shadow-sm md:flex-row">
                        <p className="font-mono text-xs">
                            {events.meta?.from ?? 0} - {events.meta?.to ?? 0} de {events.meta?.total ?? 0}
                        </p>
                        <div className="flex flex-wrap items-center gap-1">
                            {paginationLinks.map((link, idx) =>
                                link.url ? (
                                    <Button key={`${link.label}-${idx}`} asChild variant={link.active ? 'secondary' : 'ghost'} size="sm" className="h-8">
                                        <Link href={link.url} preserveScroll preserveState>
                                            <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                        </Link>
                                    </Button>
                                ) : (
                                    <Button key={`${link.label}-${idx}`} variant="ghost" size="sm" disabled className="h-8">
                                        <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                    </Button>
                                )
                            )}
                        </div>
                    </nav>
                ) : null}
            </div>
        </AppLayout>
    );
}
