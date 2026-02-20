import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Download, Shield, ChevronDown, ChevronRight } from 'lucide-react';
import { Fragment, useState } from 'react';

interface DomainEvent {
    id: string;
    company_id: number;
    occurred_at: string;
    entity_type: string;
    entity_id: string;
    event_type: string;
    actor_type: string;
    actor_id: string | null;
    payload: Record<string, unknown>;
    traceparent: string | null;
}

interface PaginatedEvents {
    data: DomainEvent[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface Props {
    events: PaginatedEvents;
    companies: Array<{ id: number; name: string }>;
    entityTypes: string[];
    eventTypes: string[];
    filters: {
        company_id?: string;
        entity_type?: string;
        event_type?: string;
        from?: string;
        to?: string;
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Super Admin', href: '/super-admin' },
    { title: 'Audit Log', href: '/super-admin/audit' },
];

const ENTITY_COLORS: Record<string, string> = {
    user: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
    company: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
    alert: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
    notification: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
    signal: 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200',
    normalization: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
};

function hasBeforeAfter(payload: Record<string, unknown>): payload is { before: unknown; after: unknown } {
    return payload !== null && typeof payload === 'object' && 'before' in payload && 'after' in payload;
}

function BeforeAfterDiff({ payload }: { payload: { before: unknown; after: unknown } }) {
    const beforeStr = JSON.stringify(payload.before, null, 2);
    const afterStr = JSON.stringify(payload.after, null, 2);

    return (
        <div className="mt-2 grid grid-cols-2 gap-3">
            <div className="rounded-md border border-red-200 bg-red-50/50 dark:border-red-900/50 dark:bg-red-950/20 p-3">
                <p className="text-xs font-medium uppercase tracking-wider text-red-700 dark:text-red-400">Before</p>
                <pre className="mt-1 max-h-64 overflow-auto text-xs">{beforeStr}</pre>
            </div>
            <div className="rounded-md border border-green-200 bg-green-50/50 dark:border-green-900/50 dark:bg-green-950/20 p-3">
                <p className="text-xs font-medium uppercase tracking-wider text-green-700 dark:text-green-400">After</p>
                <pre className="mt-1 max-h-64 overflow-auto text-xs">{afterStr}</pre>
            </div>
        </div>
    );
}

export default function AuditIndex({ events, companies, entityTypes, eventTypes, filters }: Props) {
    const [expandedIds, setExpandedIds] = useState<Set<string>>(new Set());

    const ALL_VALUE = '__all__';

    function toggleExpanded(id: string) {
        setExpandedIds((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    }

    function applyFilter(key: string, value: string) {
        const normalized = value === ALL_VALUE ? undefined : value || undefined;
        const newFilters = { ...filters, [key]: normalized };
        router.get('/super-admin/audit', newFilters, { preserveState: true });
    }

    const exportParams = new URLSearchParams(
        Object.entries(filters).filter(([, v]) => v) as [string, string][],
    ).toString();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Audit Log" />

            <div className="mx-auto max-w-7xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight flex items-center gap-2">
                            <Shield className="h-6 w-6" />
                            Audit Log
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            {events.total.toLocaleString()} eventos encontrados
                        </p>
                    </div>
                    <Button variant="outline" size="sm" asChild>
                        <a href={`/super-admin/audit/export?${exportParams}`} download>
                            <Download className="mr-2 h-4 w-4" />
                            Export CSV
                        </a>
                    </Button>
                </div>

                {/* Filters */}
                <Card className="rounded-xl">
                    <CardContent className="pt-6">
                        <div className="grid gap-3 md:grid-cols-5">
                            <Select value={filters.company_id ?? ALL_VALUE} onValueChange={(v) => applyFilter('company_id', v)}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Empresa" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL_VALUE}>Todas</SelectItem>
                                    {companies.map((c) => (
                                        <SelectItem key={c.id} value={String(c.id)}>
                                            {c.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            <Select value={filters.entity_type ?? ALL_VALUE} onValueChange={(v) => applyFilter('entity_type', v)}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Entidad" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL_VALUE}>Todas</SelectItem>
                                    {entityTypes.map((t) => (
                                        <SelectItem key={t} value={t}>
                                            {t}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            <Select value={filters.event_type ?? ALL_VALUE} onValueChange={(v) => applyFilter('event_type', v)}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Tipo de evento" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL_VALUE}>Todos</SelectItem>
                                    {eventTypes.map((t) => (
                                        <SelectItem key={t} value={t}>
                                            {t}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            <Input
                                type="date"
                                value={filters.from ?? ''}
                                onChange={(e) => applyFilter('from', e.target.value)}
                                placeholder="Desde"
                            />

                            <Input
                                type="date"
                                value={filters.to ?? ''}
                                onChange={(e) => applyFilter('to', e.target.value)}
                                placeholder="Hasta"
                            />
                        </div>
                    </CardContent>
                </Card>

                {/* Events table */}
                <Card className="rounded-xl">
                    <CardHeader>
                        <CardTitle className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                            Eventos
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {events.data.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-12">
                                <Shield className="mb-3 size-16 text-muted-foreground/20" />
                                <p className="font-display font-semibold text-muted-foreground">
                                    No se encontraron eventos con los filtros aplicados.
                                </p>
                            </div>
                        ) : (
                            <>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b border-gray-100 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">
                                                <th className="px-3 py-3">Timestamp</th>
                                                <th className="px-3 py-3">Entidad</th>
                                                <th className="px-3 py-3">Evento</th>
                                                <th className="px-3 py-3">Actor</th>
                                                <th className="px-3 py-3">Company</th>
                                                <th className="px-3 py-3 w-10">Payload</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {events.data.map((event) => {
                                                const isExpanded = expandedIds.has(event.id);
                                                const hasPayload = event.payload && Object.keys(event.payload).length > 0;
                                                return (
                                                    <Fragment key={event.id}>
                                                        <tr
                                                            key={event.id}
                                                            className="border-b border-gray-100 transition-colors hover:bg-muted/50"
                                                        >
                                                            <td className="whitespace-nowrap px-3 py-2 font-mono text-xs">
                                                                {new Date(event.occurred_at).toLocaleString()}
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                <Badge
                                                                    variant="secondary"
                                                                    className={ENTITY_COLORS[event.entity_type] ?? ''}
                                                                >
                                                                    {event.entity_type}
                                                                </Badge>
                                                            </td>
                                                            <td className="px-3 py-2 font-mono text-xs">{event.event_type}</td>
                                                            <td className="px-3 py-2 text-xs">
                                                                {event.actor_type}
                                                                {event.actor_id ? ` #${event.actor_id}` : null}
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                <span className="font-mono text-xs">
                                                                    {companies.find((c) => c.id === event.company_id)?.name ??
                                                                        `#${event.company_id}`}
                                                                </span>
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                {hasPayload ? (
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => toggleExpanded(event.id)}
                                                                        className="inline-flex items-center justify-center rounded p-1 hover:bg-muted"
                                                                        aria-expanded={isExpanded}
                                                                    >
                                                                        {isExpanded ? (
                                                                            <ChevronDown className="h-4 w-4" />
                                                                        ) : (
                                                                            <ChevronRight className="h-4 w-4" />
                                                                        )}
                                                                    </button>
                                                                ) : null}
                                                            </td>
                                                        </tr>
                                                        {isExpanded && hasPayload && (
                                                            <tr key={`${event.id}-expanded`} className="border-b border-gray-100">
                                                                <td colSpan={6} className="px-3 py-0">
                                                                    {hasBeforeAfter(event.payload) ? (
                                                                        <BeforeAfterDiff payload={event.payload} />
                                                                    ) : (
                                                                        <pre className="mt-2 max-h-64 overflow-auto rounded-md bg-muted p-3 text-xs">
                                                                            {JSON.stringify(event.payload, null, 2)}
                                                                        </pre>
                                                                    )}
                                                                </td>
                                                            </tr>
                                                        )}
                                                    </Fragment>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>

                                {/* Pagination */}
                                {events.last_page > 1 ? (
                                    <div className="mt-4 flex items-center justify-between">
                                        <p className="text-muted-foreground text-sm">
                                            Página {events.current_page} de {events.last_page}
                                        </p>
                                        <div className="flex gap-1">
                                            {events.links.map((link, idx) => {
                                                if (!link.url) return null;
                                                return (
                                                    <Button
                                                        key={idx}
                                                        variant={link.active ? 'default' : 'outline'}
                                                        size="sm"
                                                        onClick={() => router.get(link.url!)}
                                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                                    />
                                                );
                                            })}
                                        </div>
                                    </div>
                                ) : null}
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
