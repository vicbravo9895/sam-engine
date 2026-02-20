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
import { StaggerContainer, StaggerItem } from '@/components/motion';
import { useTimezone } from '@/hooks/use-timezone';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import type html2canvas from 'html2canvas-pro';
import {
    Activity,
    Car,
    ChevronDown,
    ChevronUp,
    Clock,
    ExternalLink,
    FileImage,
    FileText,
    Filter,
    MapPin,
    Navigation,
    Power,
    RefreshCw,
    Search,
    Tag,
    Truck,
} from 'lucide-react';
import { useRef, useState } from 'react';

interface VehicleStat {
    id: number;
    samsara_id: string;
    name: string;
    license_plate: string | null;
    make: string | null;
    model: string | null;
    year: number | null;
    serial: string | null;
    engine_state: string | null;
    engine_state_label: string;
    is_active: boolean;
    is_moving: boolean;
    speed_kmh: number;
    latitude: number | null;
    longitude: number | null;
    location: string;
    is_geofence: boolean;
    odometer_km: number | null;
    gps_time: string | null;
    synced_at: string | null;
    maps_link: string | null;
}

interface PaginatedVehicleStats {
    data: VehicleStat[];
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

interface TagOption {
    id: string;
    name: string;
    vehicle_count: number;
}

interface Summary {
    total: number;
    active: number;
    inactive: number;
    lastSync: string | null;
}

interface FleetReportProps {
    vehicleStats: PaginatedVehicleStats;
    tags: TagOption[];
    summary: Summary;
    filters: {
        tag_id?: string;
        search?: string;
        status?: string;
        sort_by?: string;
        sort_dir?: string;
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reportes', href: '/fleet-report' },
    { title: 'Reporte de Flota', href: '/fleet-report' },
];

type SortField = 'vehicle_name' | 'engine_state' | 'speed_kmh' | 'location_name' | 'gps_time';

export default function FleetReportIndex({
    vehicleStats,
    tags,
    summary,
    filters,
}: FleetReportProps) {
    const [searchTerm, setSearchTerm] = useState(filters.search || '');
    const [tagFilter, setTagFilter] = useState(filters.tag_id || '');
    const [statusFilter, setStatusFilter] = useState(filters.status || 'all');
    const [tagSearchTerm, setTagSearchTerm] = useState('');
    const [isExporting, setIsExporting] = useState(false);
    const reportRef = useRef<HTMLDivElement>(null);
    const { formatDate, formatRelative } = useTimezone();

    // Filtrar tags basado en búsqueda
    const filteredTags = tags.filter((tag) =>
        tag.name.toLowerCase().includes(tagSearchTerm.toLowerCase())
    );

    const loadHtml2Canvas = () =>
        import('html2canvas-pro').then((m) => m.default) as Promise<typeof html2canvas>;

    const handleExportImage = async () => {
        if (!reportRef.current || isExporting) return;
        setIsExporting(true);
        try {
            const render = await loadHtml2Canvas();
            const canvas = await render(reportRef.current, {
                scale: 2,
                useCORS: true,
                backgroundColor: '#ffffff',
            });
            const link = document.createElement('a');
            link.download = `reporte-flota-${new Date().toISOString().split('T')[0]}.jpg`;
            link.href = canvas.toDataURL('image/jpeg', 0.9);
            link.click();
        } finally {
            setIsExporting(false);
        }
    };

    const handleExportPDF = async () => {
        if (!reportRef.current || isExporting) return;
        setIsExporting(true);
        try {
            const [render, { jsPDF }] = await Promise.all([
                loadHtml2Canvas(),
                import('jspdf'),
            ]);
            const canvas = await render(reportRef.current, {
                scale: 2,
                useCORS: true,
                backgroundColor: '#ffffff',
            });
            const imgData = canvas.toDataURL('image/jpeg', 0.9);
            const pdf = new jsPDF({
                orientation: canvas.width > canvas.height ? 'landscape' : 'portrait',
                unit: 'px',
                format: [canvas.width, canvas.height],
            });
            pdf.addImage(imgData, 'JPEG', 0, 0, canvas.width, canvas.height);
            pdf.save(`reporte-flota-${new Date().toISOString().split('T')[0]}.pdf`);
        } finally {
            setIsExporting(false);
        }
    };

    const handleSearch = () => {
        router.get(
            '/fleet-report',
            {
                search: searchTerm || undefined,
                tag_id: tagFilter || undefined,
                status: statusFilter !== 'all' ? statusFilter : undefined,
                sort_by: filters.sort_by,
                sort_dir: filters.sort_dir,
            },
            {
                preserveState: true,
                preserveScroll: true,
            }
        );
    };

    const handleReset = () => {
        setSearchTerm('');
        setTagFilter('');
        setStatusFilter('all');
        router.get('/fleet-report', {}, { preserveState: true });
    };

    const handleSort = (field: SortField) => {
        const newDir =
            filters.sort_by === field && filters.sort_dir === 'asc' ? 'desc' : 'asc';
        router.get(
            '/fleet-report',
            {
                search: searchTerm || undefined,
                tag_id: tagFilter || undefined,
                status: statusFilter !== 'all' ? statusFilter : undefined,
                sort_by: field,
                sort_dir: newDir,
            },
            {
                preserveState: true,
                preserveScroll: true,
            }
        );
    };

    const formatTimestamp = (timestamp: string | null | undefined) => {
        if (!timestamp) return '-';
        return formatDate(timestamp, 'dd MMM HH:mm');
    };

    const formatLastSync = (timestamp: string | null | undefined) => {
        if (!timestamp) return 'Nunca';
        return formatRelative(timestamp);
    };

    const getEngineStateColor = (state: string | null) => {
        switch (state) {
            case 'on':
                return 'bg-green-500';
            case 'idle':
                return 'bg-yellow-500';
            case 'off':
            default:
                return 'bg-gray-400';
        }
    };

    const getEngineStateTextColor = (state: string | null) => {
        switch (state) {
            case 'on':
                return 'text-green-600 dark:text-green-400';
            case 'idle':
                return 'text-yellow-600 dark:text-yellow-400';
            case 'off':
            default:
                return 'text-gray-500 dark:text-gray-400';
        }
    };

    const getSpeedColor = (speed: number) => {
        if (speed === 0) return 'text-gray-500';
        if (speed < 30) return 'text-yellow-600 dark:text-yellow-400';
        if (speed < 80) return 'text-green-600 dark:text-green-400';
        return 'text-red-600 dark:text-red-400';
    };

    const SortIcon = ({ field }: { field: SortField }) => {
        if (filters.sort_by !== field) return null;
        return filters.sort_dir === 'asc' ? (
            <ChevronUp className="size-3" />
        ) : (
            <ChevronDown className="size-3" />
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Reporte de Flota" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                {/* Header */}
                <header className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                    <div>
                        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                            Reportes • Flota
                        </p>
                        <h1 className="font-display text-2xl font-bold tracking-tight">
                            Reporte de Flota
                        </h1>
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <RefreshCw className="size-3" />
                            Última sincronización: {formatLastSync(summary.lastSync)}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={handleExportImage}
                            disabled={isExporting}
                        >
                            <FileImage className="mr-1.5 size-4" />
                            JPG
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={handleExportPDF}
                            disabled={isExporting}
                        >
                            <FileText className="mr-1.5 size-4" />
                            PDF
                        </Button>
                    </div>
                </header>

                {/* Report Content - For Export */}
                <div ref={reportRef} className="flex flex-col gap-6 rounded-lg bg-background p-4">
                {/* Summary Stats */}
                <section className="grid gap-4 md:grid-cols-3">
                    <Card className="bg-gradient-to-b from-background to-muted/30">
                        <CardHeader className="pb-2">
                            <div className="flex items-center gap-2">
                                <Car className="size-5 text-cyan-500" />
                                <CardDescription>Total Vehículos</CardDescription>
                            </div>
                            <CardTitle className="text-4xl">{summary.total}</CardTitle>
                        </CardHeader>
                    </Card>
                    <Card className="bg-gradient-to-b from-green-50 to-green-100/30 dark:from-green-950/20 dark:to-green-900/10">
                        <CardHeader className="pb-2">
                            <div className="flex items-center gap-2">
                                <Activity className="size-5 text-green-500" />
                                <CardDescription>Activos</CardDescription>
                            </div>
                            <CardTitle className="text-4xl text-green-600 dark:text-green-400">
                                {summary.active}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card className="bg-gradient-to-b from-gray-50 to-gray-100/30 dark:from-gray-950/20 dark:to-gray-900/10">
                        <CardHeader className="pb-2">
                            <div className="flex items-center gap-2">
                                <Power className="size-5 text-gray-500" />
                                <CardDescription>Inactivos</CardDescription>
                            </div>
                            <CardTitle className="text-4xl text-gray-500">
                                {summary.inactive}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                </section>

                {/* Filters */}
                <Card className="rounded-xl shadow-sm">
                    <CardHeader className="flex flex-row items-center gap-3 border-b pb-4">
                        <div className="rounded-full bg-primary/10 p-2 text-primary">
                            <Filter className="size-4" />
                        </div>
                        <div>
                            <CardTitle className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Filtros</CardTitle>
                            <CardDescription className="text-xs">
                                Filtra por tag, nombre, placa o estado
                            </CardDescription>
                        </div>
                    </CardHeader>
                    <CardContent className="pt-4">
                        <div className="grid gap-4 md:grid-cols-4">
                            <div className="md:col-span-2">
                                <Label htmlFor="search">Búsqueda</Label>
                                <div className="relative mt-1">
                                    <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input
                                        id="search"
                                        value={searchTerm}
                                        onChange={(e) => setSearchTerm(e.target.value)}
                                        onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                                        placeholder="Nombre, placa, número económico..."
                                        className="pl-10"
                                    />
                                </div>
                            </div>
                            <div>
                                <Label>Tag / Grupo</Label>
                                <Select
                                    value={tagFilter || '__all__'}
                                    onValueChange={(v) => {
                                        setTagFilter(v === '__all__' ? '' : v);
                                        setTagSearchTerm('');
                                    }}
                                    onOpenChange={(open) => {
                                        if (!open) setTagSearchTerm('');
                                    }}
                                >
                                    <SelectTrigger className="mt-1">
                                        <SelectValue placeholder="Todos los tags" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <div className="relative mb-1 px-1">
                                            <Search className="absolute left-3 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                                            <input
                                                type="text"
                                                value={tagSearchTerm}
                                                onChange={(e) => setTagSearchTerm(e.target.value)}
                                                placeholder="Buscar tag..."
                                                className="w-full rounded-md border border-input bg-background py-1.5 pl-8 pr-2 text-sm outline-none placeholder:text-muted-foreground focus:ring-1 focus:ring-ring"
                                                onKeyDown={(e) => e.stopPropagation()}
                                            />
                                        </div>
                                        <SelectItem value="__all__">
                                            <div className="flex items-center gap-2">
                                                <Tag className="size-3" />
                                                Todos los tags
                                            </div>
                                        </SelectItem>
                                        {filteredTags.map((tag) => (
                                            <SelectItem key={tag.id} value={tag.id}>
                                                <div className="flex items-center justify-between gap-2">
                                                    <span>{tag.name}</span>
                                                    <Badge variant="secondary" className="ml-2 text-xs">
                                                        {tag.vehicle_count}
                                                    </Badge>
                                                </div>
                                            </SelectItem>
                                        ))}
                                        {filteredTags.length === 0 && tagSearchTerm && (
                                            <div className="px-2 py-4 text-center text-sm text-muted-foreground">
                                                No se encontraron tags
                                            </div>
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Estado</Label>
                                <Select
                                    value={statusFilter || 'all'}
                                    onValueChange={(v) => setStatusFilter(v)}
                                >
                                    <SelectTrigger className="mt-1">
                                        <SelectValue placeholder="Todos" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Todos</SelectItem>
                                        <SelectItem value="active">
                                            <div className="flex items-center gap-2">
                                                <span className="size-2 rounded-full bg-green-500" />
                                                Activos
                                            </div>
                                        </SelectItem>
                                        <SelectItem value="inactive">
                                            <div className="flex items-center gap-2">
                                                <span className="size-2 rounded-full bg-gray-400" />
                                                Inactivos
                                            </div>
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="flex items-end gap-2 md:col-span-4">
                                <Button onClick={handleSearch}>Aplicar Filtros</Button>
                                <Button variant="outline" onClick={handleReset}>
                                    Limpiar
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Table */}
                <Card className="rounded-xl shadow-sm">
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow className="bg-muted/50">
                                        <TableHead
                                            className="cursor-pointer hover:bg-muted"
                                            onClick={() => handleSort('vehicle_name')}
                                        >
                                            <div className="flex items-center gap-1">
                                                Vehículo
                                                <SortIcon field="vehicle_name" />
                                            </div>
                                        </TableHead>
                                        <TableHead
                                            className="cursor-pointer hover:bg-muted"
                                            onClick={() => handleSort('engine_state')}
                                        >
                                            <div className="flex items-center gap-1">
                                                Estado
                                                <SortIcon field="engine_state" />
                                            </div>
                                        </TableHead>
                                        <TableHead
                                            className="cursor-pointer hover:bg-muted"
                                            onClick={() => handleSort('location_name')}
                                        >
                                            <div className="flex items-center gap-1">
                                                Ubicación
                                                <SortIcon field="location_name" />
                                            </div>
                                        </TableHead>
                                        <TableHead
                                            className="cursor-pointer hover:bg-muted"
                                            onClick={() => handleSort('speed_kmh')}
                                        >
                                            <div className="flex items-center gap-1">
                                                Velocidad
                                                <SortIcon field="speed_kmh" />
                                            </div>
                                        </TableHead>
                                        <TableHead>Odómetro</TableHead>
                                        <TableHead
                                            className="cursor-pointer hover:bg-muted"
                                            onClick={() => handleSort('gps_time')}
                                        >
                                            <div className="flex items-center gap-1">
                                                Última Actualización
                                                <SortIcon field="gps_time" />
                                            </div>
                                        </TableHead>
                                        <TableHead>Acciones</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {vehicleStats.data.length > 0 ? (
                                        vehicleStats.data.map((vehicle) => (
                                            <TableRow
                                                key={vehicle.id}
                                                className={cn(
                                                    'transition-colors',
                                                    !vehicle.is_active && 'opacity-60'
                                                )}
                                            >
                                                {/* Vehicle Name & Plate */}
                                                <TableCell>
                                                    <div className="flex items-center gap-3">
                                                        <div
                                                            className={cn(
                                                                'flex size-9 shrink-0 items-center justify-center rounded-full',
                                                                vehicle.is_active
                                                                    ? 'bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400'
                                                                    : 'bg-gray-100 text-gray-400 dark:bg-gray-800'
                                                            )}
                                                        >
                                                            <Truck className="size-4" />
                                                        </div>
                                                        <div>
                                                            <p className="font-medium">
                                                                {vehicle.name}
                                                            </p>
                                                            {vehicle.license_plate && (
                                                                <p className="text-xs text-muted-foreground">
                                                                    {vehicle.license_plate}
                                                                </p>
                                                            )}
                                                            {vehicle.make && vehicle.model && (
                                                                <p className="text-xs text-muted-foreground">
                                                                    {vehicle.make} {vehicle.model}{' '}
                                                                    {vehicle.year}
                                                                </p>
                                                            )}
                                                        </div>
                                                    </div>
                                                </TableCell>

                                                {/* Engine State */}
                                                <TableCell>
                                                    <div className="flex items-center gap-2">
                                                        <span
                                                            className={cn(
                                                                'size-2.5 rounded-full',
                                                                getEngineStateColor(vehicle.engine_state),
                                                                vehicle.is_active ? 'animate-pulse' : ''
                                                            )}
                                                        />
                                                        <span
                                                            className={cn(
                                                                'text-sm font-medium',
                                                                getEngineStateTextColor(
                                                                    vehicle.engine_state
                                                                )
                                                            )}
                                                        >
                                                            {vehicle.engine_state_label}
                                                        </span>
                                                    </div>
                                                    {vehicle.is_moving ? (
                                                        <span className="mt-1 inline-flex items-center gap-1 text-xs text-blue-600 dark:text-blue-400">
                                                            <Navigation className="size-3" />
                                                            En movimiento
                                                        </span>
                                                    ) : null}
                                                </TableCell>

                                                {/* Location */}
                                                <TableCell className="max-w-[200px]">
                                                    <div className="flex items-start gap-1.5">
                                                        <MapPin className="mt-0.5 size-3 shrink-0 text-muted-foreground" />
                                                        <span className="truncate text-sm">
                                                            {vehicle.location}
                                                        </span>
                                                    </div>
                                                    {vehicle.is_geofence ? (
                                                        <Badge
                                                            variant="secondary"
                                                            className="mt-1 text-xs"
                                                        >
                                                            Geofence
                                                        </Badge>
                                                    ) : null}
                                                </TableCell>

                                                {/* Speed */}
                                                <TableCell>
                                                    <span
                                                        className={cn(
                                                            'text-lg font-bold',
                                                            getSpeedColor(vehicle.speed_kmh)
                                                        )}
                                                    >
                                                        {vehicle.speed_kmh}
                                                    </span>
                                                    <span className="ml-1 text-xs text-muted-foreground">
                                                        km/h
                                                    </span>
                                                </TableCell>

                                                {/* Odometer */}
                                                <TableCell>
                                                    {vehicle.odometer_km !== null ? (
                                                        <span className="font-mono text-xs">
                                                            {vehicle.odometer_km.toLocaleString('es-MX')}{' '}
                                                            <span className="text-xs text-muted-foreground">
                                                                km
                                                            </span>
                                                        </span>
                                                    ) : (
                                                        <span className="text-sm text-muted-foreground">
                                                            -
                                                        </span>
                                                    )}
                                                </TableCell>

                                                {/* Last Update */}
                                                <TableCell>
                                                    <div className="flex items-center gap-1.5 text-sm text-muted-foreground">
                                                        <Clock className="size-3" />
                                                        {formatTimestamp(vehicle.gps_time)}
                                                    </div>
                                                </TableCell>

                                                {/* Actions */}
                                                <TableCell>
                                                    {vehicle.maps_link ? (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            asChild
                                                        >
                                                            <a
                                                                href={vehicle.maps_link}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                            >
                                                                <ExternalLink className="mr-1 size-3" />
                                                                Maps
                                                            </a>
                                                        </Button>
                                                    ) : null}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    ) : (
                                        <TableRow>
                                            <TableCell
                                                colSpan={7}
                                                className="py-12 text-center"
                                            >
                                                <Car className="mx-auto size-16 text-muted-foreground/20" />
                                                <p className="mt-2 font-display font-semibold text-muted-foreground">
                                                    {filters.search || filters.tag_id
                                                        ? 'No se encontraron vehículos con esos criterios.'
                                                        : 'No hay datos de vehículos disponibles. Ejecuta la sincronización primero.'}
                                                </p>
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>

                        {/* Footer with count */}
                        <div className="border-t bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
                            Mostrando {vehicleStats.data.length} de {vehicleStats.total} vehículos
                            {filters.search && ` (filtrado por "${filters.search}")`}
                            {filters.tag_id && tags.find((t) => t.id === filters.tag_id) && (
                                <span>
                                    {' '}
                                    • Tag:{' '}
                                    <Badge variant="outline" className="ml-1">
                                        {tags.find((t) => t.id === filters.tag_id)?.name}
                                    </Badge>
                                </span>
                            )}
                        </div>
                    </CardContent>
                </Card>
                </div>

                {/* Pagination */}
                {vehicleStats.last_page > 1 && (
                    <nav className="flex items-center justify-center gap-2">
                        {vehicleStats.links.map((link, index) => (
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
