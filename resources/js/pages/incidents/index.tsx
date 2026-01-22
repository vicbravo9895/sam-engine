import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
import { 
    type IncidentListItem, 
    type IncidentStats, 
    type PriorityCounts,
    INCIDENT_STATUS_OPTIONS,
    INCIDENT_PRIORITY_OPTIONS,
} from '@/types/incidents';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Car,
    CheckCircle,
    Clock,
    Eye,
    Filter,
    Flame,
    Shield,
    User,
    Zap,
} from 'lucide-react';
import { useState } from 'react';

interface PaginatedIncidents {
    data: IncidentListItem[];
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
    incidents: PaginatedIncidents;
    stats: IncidentStats;
    priorityCounts: PriorityCounts;
    filters: {
        status?: string;
        priority?: string;
        type?: string;
        subject_type?: string;
        date_from?: string;
        date_to?: string;
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Centro de Control', href: '/dashboard' },
    { title: 'Incidentes', href: '/incidents' },
];

const priorityColors: Record<string, string> = {
    P1: 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-200 border-red-300',
    P2: 'bg-orange-100 text-orange-800 dark:bg-orange-500/20 dark:text-orange-200 border-orange-300',
    P3: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-500/20 dark:text-yellow-200 border-yellow-300',
    P4: 'bg-slate-100 text-slate-800 dark:bg-slate-500/20 dark:text-slate-200 border-slate-300',
};

const statusColors: Record<string, string> = {
    open: 'bg-amber-100 text-amber-800',
    investigating: 'bg-blue-100 text-blue-800',
    pending_action: 'bg-orange-100 text-orange-800',
    resolved: 'bg-emerald-100 text-emerald-800',
    false_positive: 'bg-slate-100 text-slate-800',
};

const typeIcons: Record<string, React.ElementType> = {
    collision: Car,
    emergency: AlertTriangle,
    pattern: Activity,
    safety_violation: Shield,
    tampering: Zap,
    unknown: AlertTriangle,
};

export default function IncidentsIndex({ incidents, stats, priorityCounts, filters }: IndexProps) {
    const [statusFilter, setStatusFilter] = useState(filters.status || '');
    const [priorityFilter, setPriorityFilter] = useState(filters.priority || '');
    const [typeFilter, setTypeFilter] = useState(filters.type || '');

    const handleSearch = () => {
        router.get('/incidents', {
            status: statusFilter || undefined,
            priority: priorityFilter || undefined,
            type: typeFilter || undefined,
        }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleReset = () => {
        setStatusFilter('');
        setPriorityFilter('');
        setTypeFilter('');
        router.get('/incidents', {}, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Incidentes" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <header className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                    <div>
                        <p className="text-sm font-medium text-muted-foreground">
                            Centro de Control • Operaciones
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Incidentes
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Tickets operativos clasificados por prioridad con evidencia de señales.
                        </p>
                    </div>
                </header>

                {/* Priority Cards */}
                <section className="grid gap-4 md:grid-cols-4">
                    <Card 
                        className={`cursor-pointer transition-all hover:shadow-md ${priorityFilter === 'P1' ? 'ring-2 ring-red-500' : ''}`}
                        onClick={() => {
                            setPriorityFilter(priorityFilter === 'P1' ? '' : 'P1');
                            router.get('/incidents', { priority: priorityFilter === 'P1' ? undefined : 'P1' }, { preserveState: true });
                        }}
                    >
                        <CardHeader className="pb-2">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <div className="rounded-full bg-red-500/10 p-2">
                                        <Flame className="size-4 text-red-500" />
                                    </div>
                                    <CardDescription>P1 Crítico</CardDescription>
                                </div>
                                <Badge className={priorityColors.P1}>{priorityCounts.P1}</Badge>
                            </div>
                            <CardTitle className="text-2xl text-red-600">{priorityCounts.P1}</CardTitle>
                        </CardHeader>
                    </Card>
                    <Card 
                        className={`cursor-pointer transition-all hover:shadow-md ${priorityFilter === 'P2' ? 'ring-2 ring-orange-500' : ''}`}
                        onClick={() => {
                            setPriorityFilter(priorityFilter === 'P2' ? '' : 'P2');
                            router.get('/incidents', { priority: priorityFilter === 'P2' ? undefined : 'P2' }, { preserveState: true });
                        }}
                    >
                        <CardHeader className="pb-2">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <div className="rounded-full bg-orange-500/10 p-2">
                                        <AlertTriangle className="size-4 text-orange-500" />
                                    </div>
                                    <CardDescription>P2 Alto</CardDescription>
                                </div>
                                <Badge className={priorityColors.P2}>{priorityCounts.P2}</Badge>
                            </div>
                            <CardTitle className="text-2xl text-orange-600">{priorityCounts.P2}</CardTitle>
                        </CardHeader>
                    </Card>
                    <Card 
                        className={`cursor-pointer transition-all hover:shadow-md ${priorityFilter === 'P3' ? 'ring-2 ring-yellow-500' : ''}`}
                        onClick={() => {
                            setPriorityFilter(priorityFilter === 'P3' ? '' : 'P3');
                            router.get('/incidents', { priority: priorityFilter === 'P3' ? undefined : 'P3' }, { preserveState: true });
                        }}
                    >
                        <CardHeader className="pb-2">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <div className="rounded-full bg-yellow-500/10 p-2">
                                        <Clock className="size-4 text-yellow-600" />
                                    </div>
                                    <CardDescription>P3 Medio</CardDescription>
                                </div>
                                <Badge className={priorityColors.P3}>{priorityCounts.P3}</Badge>
                            </div>
                            <CardTitle className="text-2xl text-yellow-600">{priorityCounts.P3}</CardTitle>
                        </CardHeader>
                    </Card>
                    <Card 
                        className={`cursor-pointer transition-all hover:shadow-md ${priorityFilter === 'P4' ? 'ring-2 ring-slate-500' : ''}`}
                        onClick={() => {
                            setPriorityFilter(priorityFilter === 'P4' ? '' : 'P4');
                            router.get('/incidents', { priority: priorityFilter === 'P4' ? undefined : 'P4' }, { preserveState: true });
                        }}
                    >
                        <CardHeader className="pb-2">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <div className="rounded-full bg-slate-500/10 p-2">
                                        <Activity className="size-4 text-slate-500" />
                                    </div>
                                    <CardDescription>P4 Bajo</CardDescription>
                                </div>
                                <Badge className={priorityColors.P4}>{priorityCounts.P4}</Badge>
                            </div>
                            <CardTitle className="text-2xl text-slate-600">{priorityCounts.P4}</CardTitle>
                        </CardHeader>
                    </Card>
                </section>

                {/* Stats Row */}
                <section className="grid gap-4 md:grid-cols-4">
                    <Card className="bg-gradient-to-b from-background to-muted/30">
                        <CardHeader className="pb-2">
                            <div className="flex items-center gap-2">
                                <Shield className="size-4 text-muted-foreground" />
                                <CardDescription>Total</CardDescription>
                            </div>
                            <CardTitle className="text-3xl">{stats.total}</CardTitle>
                        </CardHeader>
                    </Card>
                    <Card className="bg-gradient-to-b from-background to-amber-500/5">
                        <CardHeader className="pb-2">
                            <div className="flex items-center gap-2">
                                <AlertTriangle className="size-4 text-amber-500" />
                                <CardDescription>Abiertos</CardDescription>
                            </div>
                            <CardTitle className="text-3xl text-amber-600">{stats.open}</CardTitle>
                        </CardHeader>
                    </Card>
                    <Card className="bg-gradient-to-b from-background to-red-500/5">
                        <CardHeader className="pb-2">
                            <div className="flex items-center gap-2">
                                <Flame className="size-4 text-red-500" />
                                <CardDescription>Alta prioridad</CardDescription>
                            </div>
                            <CardTitle className="text-3xl text-red-600">{stats.high_priority}</CardTitle>
                        </CardHeader>
                    </Card>
                    <Card className="bg-gradient-to-b from-background to-emerald-500/5">
                        <CardHeader className="pb-2">
                            <div className="flex items-center gap-2">
                                <CheckCircle className="size-4 text-emerald-500" />
                                <CardDescription>Resueltos hoy</CardDescription>
                            </div>
                            <CardTitle className="text-3xl text-emerald-600">{stats.resolved_today}</CardTitle>
                        </CardHeader>
                    </Card>
                </section>

                {/* Filters */}
                <Card>
                    <CardHeader className="flex flex-row items-center gap-3 border-b pb-4">
                        <div className="rounded-full bg-primary/10 p-2 text-primary">
                            <Filter className="size-4" />
                        </div>
                        <div>
                            <CardTitle className="text-sm">Filtros</CardTitle>
                            <CardDescription className="text-xs">
                                Filtra incidentes por estado, prioridad o tipo
                            </CardDescription>
                        </div>
                    </CardHeader>
                    <CardContent className="pt-4">
                        <div className="grid gap-4 md:grid-cols-4">
                            <div>
                                <Label>Estado</Label>
                                <Select 
                                    value={statusFilter || '__all__'} 
                                    onValueChange={(v) => setStatusFilter(v === '__all__' ? '' : v)}
                                >
                                    <SelectTrigger className="mt-1">
                                        <SelectValue placeholder="Todos" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all__">Todos</SelectItem>
                                        {INCIDENT_STATUS_OPTIONS.map((opt) => (
                                            <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Prioridad</Label>
                                <Select 
                                    value={priorityFilter || '__all__'} 
                                    onValueChange={(v) => setPriorityFilter(v === '__all__' ? '' : v)}
                                >
                                    <SelectTrigger className="mt-1">
                                        <SelectValue placeholder="Todas" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all__">Todas</SelectItem>
                                        {INCIDENT_PRIORITY_OPTIONS.map((opt) => (
                                            <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Tipo</Label>
                                <Select 
                                    value={typeFilter || '__all__'} 
                                    onValueChange={(v) => setTypeFilter(v === '__all__' ? '' : v)}
                                >
                                    <SelectTrigger className="mt-1">
                                        <SelectValue placeholder="Todos" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all__">Todos</SelectItem>
                                        <SelectItem value="collision">Colisión</SelectItem>
                                        <SelectItem value="emergency">Emergencia</SelectItem>
                                        <SelectItem value="pattern">Patrón</SelectItem>
                                        <SelectItem value="safety_violation">Violación</SelectItem>
                                        <SelectItem value="tampering">Manipulación</SelectItem>
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

                {/* Incidents Table */}
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Prioridad</TableHead>
                                    <TableHead>Tipo</TableHead>
                                    <TableHead>Estado</TableHead>
                                    <TableHead>Sujeto</TableHead>
                                    <TableHead>Resumen</TableHead>
                                    <TableHead>Evidencia</TableHead>
                                    <TableHead>Detectado</TableHead>
                                    <TableHead className="text-right">Acciones</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {incidents.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={8} className="h-32 text-center">
                                            <div className="flex flex-col items-center gap-2 text-muted-foreground">
                                                <Shield className="size-8 opacity-50" />
                                                <p>No hay incidentes</p>
                                                <p className="text-xs">Los incidentes se crean automáticamente desde alertas</p>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    incidents.data.map((incident) => {
                                        const TypeIcon = typeIcons[incident.incident_type] || AlertTriangle;
                                        return (
                                            <TableRow key={incident.id} className={incident.is_high_priority ? 'bg-red-50/50 dark:bg-red-950/10' : ''}>
                                                <TableCell>
                                                    <Badge className={`${priorityColors[incident.priority]} border`}>
                                                        {incident.priority}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex items-center gap-2">
                                                        <TypeIcon className="size-4 text-muted-foreground" />
                                                        <span className="text-sm">{incident.type_label}</span>
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="outline" className={statusColors[incident.status]}>
                                                        {incident.status_label}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex items-center gap-1">
                                                        {incident.subject_type === 'driver' ? (
                                                            <User className="size-3 text-muted-foreground" />
                                                        ) : (
                                                            <Car className="size-3 text-muted-foreground" />
                                                        )}
                                                        <span className="text-sm">{incident.subject_name || '-'}</span>
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <p className="max-w-[300px] truncate text-sm text-muted-foreground">
                                                        {incident.ai_summary || 'Sin resumen'}
                                                    </p>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="secondary">
                                                        {incident.safety_signals_count} señales
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    <span className="text-sm text-muted-foreground">
                                                        {incident.detected_at_human}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Button variant="ghost" size="icon" asChild>
                                                        <Link href={`/incidents/${incident.id}`}>
                                                            <Eye className="size-4" />
                                                        </Link>
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {incidents.last_page > 1 && (
                    <nav className="flex items-center justify-center gap-2">
                        {incidents.links.map((link, index) => (
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
