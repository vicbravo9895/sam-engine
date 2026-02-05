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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type SafetySignalListItem, type SafetySignalStats } from '@/types/incidents';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    BarChart3,
    Car,
    Clock,
    ExternalLink,
    Eye,
    Filter,
    MapPin,
    Radio,
    Search,
    Shield,
    User,
} from 'lucide-react';
import { useState } from 'react';

interface PaginatedSignals {
    data: SafetySignalListItem[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{
        url: string | null;
        label: string;
        active: boolean;
    }>;
}

interface IndexProps {
    signals: PaginatedSignals;
    stats: SafetySignalStats;
    filters: {
        severity?: string;
        vehicle_id?: string;
        driver_id?: string;
        behavior?: string;
        event_state?: string;
        date_from?: string;
        date_to?: string;
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Centro de Control', href: '/dashboard' },
    { title: 'Señales de Seguridad', href: '/safety-signals' },
];

const severityColors: Record<string, string> = {
    critical: 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-200',
    warning: 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200',
    info: 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-200',
};

const stateColors: Record<string, string> = {
    needsReview: 'bg-amber-100 text-amber-800',
    needsCoaching: 'bg-blue-100 text-blue-800',
    dismissed: 'bg-slate-100 text-slate-800',
    coached: 'bg-emerald-100 text-emerald-800',
};

export default function SafetySignalsIndex({ signals, stats, filters }: IndexProps) {
    const [severityFilter, setSeverityFilter] = useState(filters.severity || '');
    const [behaviorFilter, setBehaviorFilter] = useState(filters.behavior || '');
    const [stateFilter, setStateFilter] = useState(filters.event_state || '');

    const handleSearch = () => {
        router.get('/safety-signals', {
            severity: severityFilter || undefined,
            behavior: behaviorFilter || undefined,
            event_state: stateFilter || undefined,
        }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleReset = () => {
        setSeverityFilter('');
        setBehaviorFilter('');
        setStateFilter('');
        router.get('/safety-signals', {}, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Señales de Seguridad" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <header className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                    <div>
                        <p className="text-sm font-medium text-muted-foreground">
                            Centro de Control • Stream
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Señales de Seguridad
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Eventos de seguridad capturados del stream de Samsara en tiempo real.
                        </p>
                    </div>
                </header>

                {/* Stats */}
                <section className="grid gap-4 md:grid-cols-4">
                    <Card className="bg-gradient-to-b from-background to-muted/30">
                        <CardHeader className="pb-2">
                            <div className="flex items-center gap-2">
                                <Radio className="size-4 text-muted-foreground" />
                                <CardDescription>Total</CardDescription>
                            </div>
                            <CardTitle className="text-3xl">{stats.total.toLocaleString()}</CardTitle>
                        </CardHeader>
                    </Card>
                    <Card className="bg-gradient-to-b from-background to-red-500/5">
                        <CardHeader className="pb-2">
                            <div className="flex items-center gap-2">
                                <AlertTriangle className="size-4 text-red-500" />
                                <CardDescription>Críticos</CardDescription>
                            </div>
                            <CardTitle className="text-3xl text-red-600">{stats.critical}</CardTitle>
                        </CardHeader>
                    </Card>
                    <Card className="bg-gradient-to-b from-background to-amber-500/5">
                        <CardHeader className="pb-2">
                            <div className="flex items-center gap-2">
                                <Eye className="size-4 text-amber-500" />
                                <CardDescription>Por revisar</CardDescription>
                            </div>
                            <CardTitle className="text-3xl text-amber-600">{stats.needs_review}</CardTitle>
                        </CardHeader>
                    </Card>
                    <Card className="bg-gradient-to-b from-background to-blue-500/5">
                        <CardHeader className="pb-2">
                            <div className="flex items-center gap-2">
                                <Clock className="size-4 text-blue-500" />
                                <CardDescription>Hoy</CardDescription>
                            </div>
                            <CardTitle className="text-3xl text-blue-600">{stats.today}</CardTitle>
                        </CardHeader>
                    </Card>
                </section>

                {/* Analytics Link */}
                <Card className="bg-gradient-to-r from-primary/5 to-transparent">
                    <CardHeader className="flex flex-row items-center justify-between py-3">
                        <div className="flex items-center gap-3">
                            <div className="rounded-full bg-primary/10 p-2 text-primary">
                                <BarChart3 className="size-4" />
                            </div>
                            <div>
                                <CardTitle className="text-sm">Centro de Analytics</CardTitle>
                                <CardDescription className="text-xs">
                                    Patrones, riesgos, predicciones e insights de AI
                                </CardDescription>
                            </div>
                        </div>
                        <Button variant="outline" size="sm" asChild className="gap-2">
                            <Link href="/analytics">
                                Ver Analytics
                                <ExternalLink className="size-4" />
                            </Link>
                        </Button>
                    </CardHeader>
                </Card>

                {/* Filters */}
                <Card>
                    <CardHeader className="flex flex-row items-center gap-3 border-b pb-4">
                        <div className="rounded-full bg-primary/10 p-2 text-primary">
                            <Filter className="size-4" />
                        </div>
                        <div>
                            <CardTitle className="text-sm">Filtros</CardTitle>
                            <CardDescription className="text-xs">
                                Filtra señales por severidad, comportamiento o estado
                            </CardDescription>
                        </div>
                    </CardHeader>
                    <CardContent className="pt-4">
                        <div className="grid gap-4 md:grid-cols-4">
                            <div>
                                <Label>Severidad</Label>
                                <Select 
                                    value={severityFilter || '__all__'} 
                                    onValueChange={(v) => setSeverityFilter(v === '__all__' ? '' : v)}
                                >
                                    <SelectTrigger className="mt-1">
                                        <SelectValue placeholder="Todas" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all__">Todas</SelectItem>
                                        <SelectItem value="critical">Crítico</SelectItem>
                                        <SelectItem value="warning">Advertencia</SelectItem>
                                        <SelectItem value="info">Información</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Comportamiento</Label>
                                <div className="relative mt-1">
                                    <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input
                                        value={behaviorFilter}
                                        onChange={(e) => setBehaviorFilter(e.target.value)}
                                        onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                                        placeholder="Ej: Speeding, Braking..."
                                        className="pl-10"
                                    />
                                </div>
                            </div>
                            <div>
                                <Label>Estado</Label>
                                <Select 
                                    value={stateFilter || '__all__'} 
                                    onValueChange={(v) => setStateFilter(v === '__all__' ? '' : v)}
                                >
                                    <SelectTrigger className="mt-1">
                                        <SelectValue placeholder="Todos" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all__">Todos</SelectItem>
                                        <SelectItem value="needsReview">Por revisar</SelectItem>
                                        <SelectItem value="needsCoaching">Necesita coaching</SelectItem>
                                        <SelectItem value="coached">Entrenado</SelectItem>
                                        <SelectItem value="dismissed">Descartado</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="flex items-end gap-2">
                                <Button onClick={handleSearch}>Aplicar</Button>
                                <Button variant="outline" onClick={handleReset}>Limpiar</Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Signals Table */}
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Comportamiento</TableHead>
                                    <TableHead>Severidad</TableHead>
                                    <TableHead>Vehículo</TableHead>
                                    <TableHead>Conductor</TableHead>
                                    <TableHead>Ubicación</TableHead>
                                    <TableHead>Estado</TableHead>
                                    <TableHead>Fecha</TableHead>
                                    <TableHead className="text-right">Acciones</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {signals.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={8} className="h-32 text-center">
                                            <div className="flex flex-col items-center gap-2 text-muted-foreground">
                                                <Radio className="size-8 opacity-50" />
                                                <p>No hay señales de seguridad</p>
                                                <p className="text-xs">Ejecuta el daemon para importar eventos</p>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    signals.data.map((signal) => (
                                        <TableRow key={signal.id}>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    <Activity className="size-4 text-muted-foreground" />
                                                    <div>
                                                        <p className="font-medium">
                                                            {signal.primary_label_translated || signal.primary_behavior_label || 'Sin etiqueta'}
                                                        </p>
                                                        {signal.used_in_evidence && (
                                                            <Badge variant="outline" className="mt-1 text-xs">
                                                                <Shield className="mr-1 size-3" />
                                                                En incidente
                                                            </Badge>
                                                        )}
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <Badge className={severityColors[signal.severity]}>
                                                    {signal.severity_label}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-1">
                                                    <Car className="size-3 text-muted-foreground" />
                                                    <span className="text-sm">{signal.vehicle_name || '-'}</span>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-1">
                                                    <User className="size-3 text-muted-foreground" />
                                                    <span className="text-sm">{signal.driver_name || '-'}</span>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-1 max-w-[200px]">
                                                    <MapPin className="size-3 shrink-0 text-muted-foreground" />
                                                    <span className="text-sm truncate">{signal.address || '-'}</span>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {signal.event_state && (
                                                    <Badge variant="outline" className={stateColors[signal.event_state]}>
                                                        {signal.event_state_translated}
                                                    </Badge>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <span className="text-sm text-muted-foreground">
                                                    {signal.occurred_at_human}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex items-center justify-end gap-1">
                                                    {signal.inbox_event_url && (
                                                        <Button variant="ghost" size="icon" asChild>
                                                            <a href={signal.inbox_event_url} target="_blank" rel="noopener noreferrer">
                                                                <ExternalLink className="size-4" />
                                                            </a>
                                                        </Button>
                                                    )}
                                                    <Button variant="ghost" size="icon" asChild>
                                                        <Link href={`/safety-signals/${signal.id}`}>
                                                            <Eye className="size-4" />
                                                        </Link>
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {signals.last_page > 1 && (
                    <nav className="flex items-center justify-center gap-2">
                        {signals.links.map((link, index) => (
                            <Button
                                key={index}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url)}
                            >
                                <span dangerouslySetInnerHTML={{ __html: link.label }} />
                            </Button>
                        ))}
                    </nav>
                )}
            </div>
        </AppLayout>
    );
}
