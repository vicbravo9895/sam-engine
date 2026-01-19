import { Head, Link, router } from '@inertiajs/react';
import { Activity, AlertTriangle, Car, ChevronRight, HelpCircle } from 'lucide-react';
import { useTimezone } from '@/hooks/use-timezone';
import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { 
    AlertIncidentListItem, 
    IncidentStatus, 
    IncidentType,
    INCIDENT_STATUS_OPTIONS,
    INCIDENT_TYPE_OPTIONS,
} from '@/types/samsara';

interface Props {
    incidents: {
        data: AlertIncidentListItem[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: {
        status: string;
        type: string;
    };
    statuses: Record<string, string>;
    types: Record<string, string>;
}

function getIncidentIcon(type: IncidentType) {
    switch (type) {
        case 'collision':
            return Car;
        case 'emergency':
            return AlertTriangle;
        case 'pattern':
            return Activity;
        default:
            return HelpCircle;
    }
}

function getSeverityColor(severity: string) {
    switch (severity) {
        case 'critical':
            return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400';
        case 'warning':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400';
        default:
            return 'bg-slate-100 text-slate-800 dark:bg-slate-900/30 dark:text-slate-400';
    }
}

function getStatusColor(status: IncidentStatus) {
    const option = INCIDENT_STATUS_OPTIONS.find(o => o.value === status);
    const color = option?.color || 'slate';
    return `bg-${color}-100 text-${color}-800 dark:bg-${color}-900/30 dark:text-${color}-400`;
}

function IncidentCard({ incident }: { incident: AlertIncidentListItem }) {
    const Icon = getIncidentIcon(incident.incident_type);
    const { formatRelative } = useTimezone();

    return (
        <Link href={route('samsara.incidents.show', incident.id)}>
            <Card className="hover:shadow-md transition-shadow cursor-pointer">
                <CardContent className="p-4">
                    <div className="flex items-start gap-4">
                        {/* Icon */}
                        <div className={`p-2 rounded-lg ${getSeverityColor(incident.severity)}`}>
                            <Icon className="h-5 w-5" />
                        </div>

                        {/* Content */}
                        <div className="flex-1 min-w-0">
                            <div className="flex items-center justify-between gap-2">
                                <h3 className="font-semibold truncate">
                                    {incident.type_label}
                                </h3>
                                <Badge variant="outline" className={getStatusColor(incident.status)}>
                                    {incident.status_label}
                                </Badge>
                            </div>

                            {incident.primary_event && (
                                <p className="text-sm text-muted-foreground mt-1 truncate">
                                    {incident.primary_event.vehicle_name} - {incident.primary_event.event_description}
                                </p>
                            )}

                            {incident.ai_summary && (
                                <p className="text-sm text-muted-foreground mt-1 line-clamp-2">
                                    {incident.ai_summary}
                                </p>
                            )}

                            <div className="flex items-center gap-4 mt-2 text-xs text-muted-foreground">
                                <span>
                                    {formatRelative(incident.detected_at)}
                                </span>
                                <span className="flex items-center gap-1">
                                    <Activity className="h-3 w-3" />
                                    {incident.related_events_count} evento{incident.related_events_count !== 1 ? 's' : ''} relacionado{incident.related_events_count !== 1 ? 's' : ''}
                                </span>
                            </div>
                        </div>

                        <ChevronRight className="h-5 w-5 text-muted-foreground flex-shrink-0" />
                    </div>
                </CardContent>
            </Card>
        </Link>
    );
}

export default function IncidentsIndex({ incidents, filters, statuses, types }: Props) {
    const handleFilterChange = (key: string, value: string) => {
        router.get(route('samsara.incidents.index'), {
            ...filters,
            [key]: value,
        }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    return (
        <AppLayout>
            <Head title="Incidentes" />

            <div className="py-6">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    {/* Header */}
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight">Incidentes</h1>
                            <p className="text-muted-foreground">
                                Alertas correlacionadas agrupadas en incidentes
                            </p>
                        </div>

                        {/* Filters */}
                        <div className="flex gap-2">
                            <Select
                                value={filters.status}
                                onValueChange={(value) => handleFilterChange('status', value)}
                            >
                                <SelectTrigger className="w-[150px]">
                                    <SelectValue placeholder="Estado" />
                                </SelectTrigger>
                                <SelectContent>
                                    {Object.entries(statuses).map(([value, label]) => (
                                        <SelectItem key={value} value={value}>
                                            {label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            <Select
                                value={filters.type}
                                onValueChange={(value) => handleFilterChange('type', value)}
                            >
                                <SelectTrigger className="w-[150px]">
                                    <SelectValue placeholder="Tipo" />
                                </SelectTrigger>
                                <SelectContent>
                                    {Object.entries(types).map(([value, label]) => (
                                        <SelectItem key={value} value={value}>
                                            {label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    {/* Stats Summary */}
                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
                        {[
                            { label: 'Total', value: incidents.total, color: 'slate' },
                            { label: 'Abiertos', value: incidents.data.filter(i => i.status === 'open').length, color: 'amber' },
                            { label: 'En investigación', value: incidents.data.filter(i => i.status === 'investigating').length, color: 'blue' },
                            { label: 'Resueltos hoy', value: incidents.data.filter(i => i.status === 'resolved').length, color: 'emerald' },
                        ].map((stat) => (
                            <Card key={stat.label}>
                                <CardContent className="p-4">
                                    <p className="text-sm text-muted-foreground">{stat.label}</p>
                                    <p className="text-2xl font-bold">{stat.value}</p>
                                </CardContent>
                            </Card>
                        ))}
                    </div>

                    {/* Incidents List */}
                    {incidents.data.length === 0 ? (
                        <Card>
                            <CardContent className="p-8 text-center">
                                <Activity className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                                <h3 className="font-semibold mb-1">No hay incidentes</h3>
                                <p className="text-muted-foreground">
                                    No se encontraron incidentes con los filtros seleccionados.
                                </p>
                            </CardContent>
                        </Card>
                    ) : (
                        <div className="space-y-3">
                            {incidents.data.map((incident) => (
                                <IncidentCard key={incident.id} incident={incident} />
                            ))}
                        </div>
                    )}

                    {/* Pagination */}
                    {incidents.last_page > 1 && (
                        <div className="flex justify-center gap-2 mt-6">
                            <Button
                                variant="outline"
                                disabled={incidents.current_page === 1}
                                onClick={() => router.get(route('samsara.incidents.index'), {
                                    ...filters,
                                    page: incidents.current_page - 1,
                                })}
                            >
                                Anterior
                            </Button>
                            <span className="flex items-center px-4 text-sm text-muted-foreground">
                                Página {incidents.current_page} de {incidents.last_page}
                            </span>
                            <Button
                                variant="outline"
                                disabled={incidents.current_page === incidents.last_page}
                                onClick={() => router.get(route('samsara.incidents.index'), {
                                    ...filters,
                                    page: incidents.current_page + 1,
                                })}
                            >
                                Siguiente
                            </Button>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
