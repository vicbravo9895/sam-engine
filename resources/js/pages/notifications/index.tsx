import { AnimatedCounter, StaggerContainer, StaggerItem } from '@/components/motion';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { Head, Link, router } from '@inertiajs/react';
import { Bell, CheckCircle, Filter, MessageSquare, Phone, Search, XCircle } from 'lucide-react';
import { useCallback, useState } from 'react';

interface PaginationLink { label: string; url: string | null; active: boolean; }
interface PaginationMeta { current_page: number; from: number | null; to: number | null; total: number; links: PaginationLink[]; }
interface DeliveryEvent { id: number; status: string; received_at: string; error_message: string | null; }

interface NotificationResultItem {
    id: number;
    alert_id: number;
    vehicle_name: string | null;
    event_type: string | null;
    channel: string;
    to_number: string;
    success: boolean;
    status_current: string;
    error: string | null;
    created_at: string;
    created_at_human: string;
    delivery_events: DeliveryEvent[];
}

interface Filters { search: string; channel: string; status: string; date_from: string; date_to: string; }
interface ChannelStat { channel: string; total: number; delivered: number; rate: number; }

interface IndexProps {
    results: { data: NotificationResultItem[]; links: PaginationLink[]; meta: PaginationMeta; };
    filters: Filters;
    stats: { total: number; delivered: number; failed: number; deliverability_rate: number; by_channel: ChannelStat[]; };
}

const NOTIFICATIONS_URL = '/notifications';
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Notificaciones', href: NOTIFICATIONS_URL }];

const channelIcons: Record<string, React.ReactNode> = {
    sms: <MessageSquare className="size-4" />,
    whatsapp: <MessageSquare className="size-4" />,
    call: <Phone className="size-4" />,
};

const channelBadgeVariants: Record<string, 'info' | 'success' | 'secondary'> = {
    sms: 'info',
    whatsapp: 'success',
    call: 'secondary',
};

const statusBadgeVariants: Record<string, 'success' | 'warning' | 'destructive' | 'secondary'> = {
    delivered: 'success',
    read: 'success',
    failed: 'destructive',
    undelivered: 'destructive',
    sent: 'warning',
    queued: 'secondary',
};

const channelLabels: Record<string, string> = { sms: 'SMS', whatsapp: 'WhatsApp', call: 'Llamada' };

export default function NotificationsIndex({ results, filters, stats }: IndexProps) {
    const [localFilters, setLocalFilters] = useState<Filters>(filters);

    const applyFilters = useCallback(() => {
        const params = Object.fromEntries(
            Object.entries(localFilters).filter(([, v]) => v !== '')
        ) as Record<string, string>;
        router.get(NOTIFICATIONS_URL, params, { preserveState: true });
    }, [localFilters]);

    const handleSearch = useCallback(() => { applyFilters(); }, [applyFilters]);

    const handleFilterChange = useCallback((key: keyof Filters, value: string) => {
        setLocalFilters((p) => ({ ...p, [key]: value }));
    }, []);

    const handleReset = useCallback(() => {
        const empty: Filters = { search: '', channel: '', status: '', date_from: '', date_to: '' };
        setLocalFilters(empty);
        router.get(NOTIFICATIONS_URL, {}, { preserveState: true });
    }, []);

    const hasActiveFilters = Object.values(localFilters).some((v) => v !== '');
    const paginationLinks = results.links?.length ? results.links : results.meta?.links ?? [];

    const deliverColor = stats.deliverability_rate >= 90
        ? 'text-green-600 dark:text-green-400'
        : stats.deliverability_rate >= 70
          ? 'text-amber-600 dark:text-amber-400'
          : 'text-red-600 dark:text-red-400';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notificaciones" />
            <div className="flex flex-1 flex-col gap-4 p-4 sm:p-6">
                <header>
                    <h1 className="font-display flex items-center gap-2 text-2xl font-bold tracking-tight">
                        <Bell className="size-6 text-muted-foreground" />
                        Notificaciones
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Historial de notificaciones enviadas por SMS, WhatsApp y llamadas
                    </p>
                </header>

                {/* Stats */}
                <StaggerContainer className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StaggerItem>
                        <div className="relative overflow-hidden rounded-xl border bg-card p-4">
                            <div className="absolute top-3 right-3 text-muted-foreground/10">
                                <Bell className="size-8" />
                            </div>
                            <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Total</p>
                            <p className="mt-2 font-display text-2xl font-bold">
                                <AnimatedCounter value={stats.total} />
                            </p>
                        </div>
                    </StaggerItem>
                    <StaggerItem>
                        <div className="relative overflow-hidden rounded-xl border bg-card p-4">
                            <div className="absolute top-3 right-3 text-green-500/10">
                                <CheckCircle className="size-8" />
                            </div>
                            <p className="flex items-center gap-1 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                <CheckCircle className="size-3 text-green-500" /> Entregadas
                            </p>
                            <p className="mt-2 font-display text-2xl font-bold text-green-600 dark:text-green-400">
                                <AnimatedCounter value={stats.delivered} />
                            </p>
                        </div>
                    </StaggerItem>
                    <StaggerItem>
                        <div className="relative overflow-hidden rounded-xl border bg-card p-4">
                            <div className="absolute top-3 right-3 text-red-500/10">
                                <XCircle className="size-8" />
                            </div>
                            <p className="flex items-center gap-1 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                <XCircle className="size-3 text-red-500" /> Fallidas
                            </p>
                            <p className="mt-2 font-display text-2xl font-bold text-red-600 dark:text-red-400">
                                <AnimatedCounter value={stats.failed} />
                            </p>
                        </div>
                    </StaggerItem>
                    <StaggerItem>
                        <div className="relative overflow-hidden rounded-xl border bg-card p-4">
                            <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Tasa de Entrega</p>
                            <p className={`mt-2 font-display text-2xl font-bold ${deliverColor}`}>
                                {stats.deliverability_rate}%
                            </p>
                            {/* Mini gauge bar */}
                            <div className="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-muted">
                                <div
                                    className="h-full rounded-full bg-current transition-all duration-700"
                                    style={{ width: `${Math.min(stats.deliverability_rate, 100)}%` }}
                                />
                            </div>
                        </div>
                    </StaggerItem>
                </StaggerContainer>

                {/* Filters */}
                <section className="flex flex-wrap items-end gap-3 rounded-xl border bg-card p-4 shadow-sm">
                    <div className="min-w-[180px] flex-1">
                        <Label htmlFor="search" className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Busqueda</Label>
                        <div className="relative mt-1">
                            <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input id="search" value={localFilters.search} onChange={(e) => setLocalFilters((p) => ({ ...p, search: e.target.value }))} onKeyDown={(e) => e.key === 'Enter' && handleSearch()} placeholder="Telefono, vehiculo..." className="h-9 pl-9" />
                        </div>
                    </div>
                    <div>
                        <Label className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Canal</Label>
                        <Select value={localFilters.channel || 'all'} onValueChange={(v) => handleFilterChange('channel', v === 'all' ? '' : v)}>
                            <SelectTrigger className="mt-1 h-9 w-[130px]"><SelectValue placeholder="Todos" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Todos</SelectItem>
                                <SelectItem value="sms">SMS</SelectItem>
                                <SelectItem value="whatsapp">WhatsApp</SelectItem>
                                <SelectItem value="call">Llamada</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div>
                        <Label className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Estado</Label>
                        <Select value={localFilters.status || 'all'} onValueChange={(v) => handleFilterChange('status', v === 'all' ? '' : v)}>
                            <SelectTrigger className="mt-1 h-9 w-[130px]"><SelectValue placeholder="Todos" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Todos</SelectItem>
                                <SelectItem value="delivered">Entregado</SelectItem>
                                <SelectItem value="read">Leido</SelectItem>
                                <SelectItem value="sent">Enviado</SelectItem>
                                <SelectItem value="queued">En cola</SelectItem>
                                <SelectItem value="failed">Fallido</SelectItem>
                                <SelectItem value="undelivered">No entregado</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div>
                        <Label htmlFor="date_from" className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Desde</Label>
                        <Input id="date_from" type="date" value={localFilters.date_from} onChange={(e) => handleFilterChange('date_from', e.target.value)} className="mt-1 h-9" />
                    </div>
                    <div>
                        <Label htmlFor="date_to" className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Hasta</Label>
                        <Input id="date_to" type="date" value={localFilters.date_to} onChange={(e) => handleFilterChange('date_to', e.target.value)} className="mt-1 h-9" />
                    </div>
                    <Button size="sm" onClick={handleSearch} className="h-9 gap-2"><Filter className="size-4" /> Aplicar</Button>
                    {hasActiveFilters ? <Button variant="ghost" size="sm" onClick={handleReset} className="h-9 text-muted-foreground">Limpiar</Button> : null}
                </section>

                {/* Table */}
                <section className="rounded-xl border bg-card shadow-sm">
                    {results.data.length === 0 ? (
                        <div className="flex flex-col items-center justify-center gap-3 py-20 text-center text-muted-foreground">
                            <Bell className="size-14 text-muted-foreground/20" />
                            <p className="font-display font-semibold">No hay notificaciones con esos filtros</p>
                            <p className="text-sm text-muted-foreground/70">Ajusta los criterios o limpia los filtros</p>
                        </div>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead>Alerta</TableHead>
                                    <TableHead>Vehiculo</TableHead>
                                    <TableHead>Canal</TableHead>
                                    <TableHead>Destinatario</TableHead>
                                    <TableHead>Estado</TableHead>
                                    <TableHead>Enviada</TableHead>
                                    <TableHead>Error</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {results.data.map((row) => (
                                    <TableRow key={row.id} className="text-sm">
                                        <TableCell className="font-mono text-xs font-medium">
                                            {row.alert_id != null ? (
                                                <Link href={`/samsara/alerts/${row.alert_id}`} className="text-primary hover:underline">#{row.alert_id}</Link>
                                            ) : (
                                                <span>#{row.alert_id}</span>
                                            )}
                                        </TableCell>
                                        <TableCell>{row.vehicle_name ?? '\u2014'}</TableCell>
                                        <TableCell>
                                            <Badge variant={channelBadgeVariants[row.channel] ?? 'secondary'} className="gap-1">
                                                {channelIcons[row.channel] ?? null}
                                                {channelLabels[row.channel] ?? row.channel}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">{row.to_number}</TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-1.5">
                                                <span className={`size-1.5 rounded-full ${row.success ? 'bg-green-500' : 'bg-red-500'}`} />
                                                <Badge variant={statusBadgeVariants[row.status_current] ?? 'secondary'}>
                                                    {row.status_current}
                                                </Badge>
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-xs text-muted-foreground">{row.created_at_human ?? row.created_at}</TableCell>
                                        <TableCell className="max-w-[200px] truncate text-xs text-muted-foreground" title={row.error ?? undefined}>{row.error ?? '\u2014'}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </section>

                {/* Pagination */}
                {paginationLinks.length > 0 ? (
                    <nav className="flex flex-col items-center justify-between gap-4 rounded-xl border bg-card px-4 py-3 text-sm text-muted-foreground shadow-sm md:flex-row">
                        <p className="font-mono text-xs">{results.meta?.from ?? 0} - {results.meta?.to ?? 0} de {results.meta?.total ?? 0}</p>
                        <div className="flex flex-wrap items-center gap-1">
                            {paginationLinks.map((link, idx) =>
                                link.url ? (
                                    <Button key={`${link.label}-${idx}`} asChild variant={link.active ? 'secondary' : 'ghost'} size="sm" className="h-8">
                                        <Link href={link.url} preserveScroll preserveState><span dangerouslySetInnerHTML={{ __html: link.label }} /></Link>
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
