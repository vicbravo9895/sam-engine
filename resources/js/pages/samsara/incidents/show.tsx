import { Head, Link, router, useForm } from '@inertiajs/react';
import { 
    Activity, 
    AlertTriangle, 
    ArrowLeft, 
    Car, 
    CheckCircle,
    Clock,
    ExternalLink,
    HelpCircle,
    Link2,
    XCircle,
} from 'lucide-react';
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
import { Textarea } from '@/components/ui/textarea';
import { 
    AlertIncident,
    IncidentStatus,
    IncidentType,
    RelatedEventItem,
    INCIDENT_STATUS_OPTIONS,
} from '@/types/samsara';

interface Props {
    incident: AlertIncident;
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
    switch (status) {
        case 'open':
            return 'bg-amber-100 text-amber-800 border-amber-200';
        case 'investigating':
            return 'bg-blue-100 text-blue-800 border-blue-200';
        case 'resolved':
            return 'bg-emerald-100 text-emerald-800 border-emerald-200';
        case 'false_positive':
            return 'bg-slate-100 text-slate-800 border-slate-200';
        default:
            return 'bg-slate-100 text-slate-800 border-slate-200';
    }
}

function CorrelationStrengthBar({ strength }: { strength: number }) {
    const percentage = Math.round(strength * 100);
    const color = strength >= 0.7 ? 'bg-emerald-500' : strength >= 0.4 ? 'bg-amber-500' : 'bg-slate-400';
    
    return (
        <div className="flex items-center gap-2">
            <div className="flex-1 h-2 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                <div 
                    className={`h-full ${color} transition-all`} 
                    style={{ width: `${percentage}%` }}
                />
            </div>
            <span className="text-xs text-muted-foreground w-8">{percentage}%</span>
        </div>
    );
}

function RelatedEventCard({ event }: { event: RelatedEventItem }) {
    const { formatTime } = useTimezone();
    const timeDelta = event.correlation.time_delta_seconds;
    const isBeforePrimary = timeDelta < 0;

    return (
        <Card className="overflow-hidden">
            <div className="flex">
                {/* Time indicator */}
                <div className={`w-1 ${isBeforePrimary ? 'bg-blue-500' : 'bg-amber-500'}`} />
                
                <CardContent className="flex-1 p-4">
                    <div className="flex items-start justify-between gap-2">
                        <div className="flex-1">
                            <div className="flex items-center gap-2">
                                <Badge variant="outline" className={getSeverityColor(event.severity)}>
                                    {event.severity}
                                </Badge>
                                <span className="font-medium">{event.event_type}</span>
                            </div>
                            
                            {event.event_description && (
                                <p className="text-sm text-muted-foreground mt-1">
                                    {event.event_description}
                                </p>
                            )}
                            
                            <div className="flex items-center gap-4 mt-2 text-xs text-muted-foreground">
                                <span className="flex items-center gap-1">
                                    <Clock className="h-3 w-3" />
                                    {formatTime(event.occurred_at)}
                                </span>
                                <span className="flex items-center gap-1">
                                    <Link2 className="h-3 w-3" />
                                    {event.correlation.type_label} ({event.correlation.time_delta})
                                </span>
                            </div>

                            {/* Correlation strength */}
                            <div className="mt-2">
                                <span className="text-xs text-muted-foreground">Fuerza de correlación:</span>
                                <CorrelationStrengthBar strength={event.correlation.strength} />
                            </div>
                        </div>
                        
                        <Link href={route('samsara.alerts.show', event.id)}>
                            <Button variant="ghost" size="sm">
                                <ExternalLink className="h-4 w-4" />
                            </Button>
                        </Link>
                    </div>
                </CardContent>
            </div>
        </Card>
    );
}

export default function IncidentShow({ incident }: Props) {
    const Icon = getIncidentIcon(incident.incident_type);
    const { formatRelative, formatDate } = useTimezone();
    
    const form = useForm({
        status: incident.status,
        summary: incident.ai_summary || '',
    });

    const handleStatusUpdate = () => {
        form.patch(route('samsara.incidents.update-status', incident.id), {
            preserveScroll: true,
        });
    };

    // Sort related events by time
    const sortedEvents = [...(incident.related_events || [])].sort(
        (a, b) => a.correlation.time_delta_seconds - b.correlation.time_delta_seconds
    );

    return (
        <AppLayout>
            <Head title={`Incidente #${incident.id}`} />

            <div className="py-6">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    {/* Back button */}
                    <Link href={route('samsara.incidents.index')} className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground mb-6">
                        <ArrowLeft className="h-4 w-4" />
                        Volver a incidentes
                    </Link>

                    {/* Header */}
                    <div className="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4 mb-6">
                        <div className="flex items-start gap-4">
                            <div className={`p-3 rounded-xl ${getSeverityColor(incident.severity)}`}>
                                <Icon className="h-8 w-8" />
                            </div>
                            <div>
                                <div className="flex items-center gap-2">
                                    <h1 className="text-2xl font-bold tracking-tight">
                                        {incident.type_label}
                                    </h1>
                                    <Badge variant="outline" className={getStatusColor(incident.status)}>
                                        {incident.status_label}
                                    </Badge>
                                </div>
                                <p className="text-muted-foreground">
                                    Detectado {formatRelative(incident.detected_at)}
                                </p>
                                {incident.resolved_at && (
                                    <p className="text-sm text-muted-foreground">
                                        Resuelto: {formatDate(incident.resolved_at, 'PPp')}
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="grid gap-6 lg:grid-cols-3">
                        {/* Main content */}
                        <div className="lg:col-span-2 space-y-6">
                            {/* AI Summary */}
                            {incident.ai_summary && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">Resumen del incidente</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <p className="text-muted-foreground">{incident.ai_summary}</p>
                                    </CardContent>
                                </Card>
                            )}

                            {/* Primary Event */}
                            {incident.primary_event && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">Evento principal</CardTitle>
                                        <CardDescription>
                                            El evento que desencadenó la detección del incidente
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="space-y-3">
                                            <div className="flex items-center justify-between">
                                                <span className="text-sm font-medium">Tipo</span>
                                                <span>{incident.primary_event.event_type}</span>
                                            </div>
                                            {incident.primary_event.event_description && (
                                                <div className="flex items-center justify-between">
                                                    <span className="text-sm font-medium">Descripción</span>
                                                    <span>{incident.primary_event.event_description}</span>
                                                </div>
                                            )}
                                            <div className="flex items-center justify-between">
                                                <span className="text-sm font-medium">Vehículo</span>
                                                <span>{incident.primary_event.vehicle_name || 'N/A'}</span>
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <span className="text-sm font-medium">Conductor</span>
                                                <span>{incident.primary_event.driver_name || 'No asignado'}</span>
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <span className="text-sm font-medium">Hora</span>
                                                <span>{formatDate(incident.primary_event.occurred_at, 'PPp')}</span>
                                            </div>
                                            {incident.primary_event.verdict && (
                                                <div className="flex items-center justify-between">
                                                    <span className="text-sm font-medium">Veredicto</span>
                                                    <Badge variant="outline">{incident.primary_event.verdict}</Badge>
                                                </div>
                                            )}
                                            
                                            {incident.primary_event.ai_message && (
                                                <div className="pt-3 border-t">
                                                    <p className="text-sm font-medium mb-1">Análisis AI:</p>
                                                    <p className="text-sm text-muted-foreground">
                                                        {incident.primary_event.ai_message}
                                                    </p>
                                                </div>
                                            )}

                                            <div className="pt-3">
                                                <Link href={route('samsara.alerts.show', incident.primary_event.id)}>
                                                    <Button variant="outline" size="sm" className="w-full">
                                                        <ExternalLink className="h-4 w-4 mr-2" />
                                                        Ver evento completo
                                                    </Button>
                                                </Link>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

                            {/* Related Events Timeline */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Eventos correlacionados</CardTitle>
                                    <CardDescription>
                                        {sortedEvents.length} evento{sortedEvents.length !== 1 ? 's' : ''} relacionado{sortedEvents.length !== 1 ? 's' : ''}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    {sortedEvents.length === 0 ? (
                                        <p className="text-muted-foreground text-center py-4">
                                            No hay eventos correlacionados adicionales
                                        </p>
                                    ) : (
                                        <div className="space-y-3">
                                            {sortedEvents.map((event) => (
                                                <RelatedEventCard key={event.id} event={event} />
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </div>

                        {/* Sidebar */}
                        <div className="space-y-6">
                            {/* Status Update */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Actualizar estado</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div>
                                        <label className="text-sm font-medium mb-2 block">Estado</label>
                                        <Select
                                            value={form.data.status}
                                            onValueChange={(value) => form.setData('status', value as IncidentStatus)}
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {INCIDENT_STATUS_OPTIONS.map((option) => (
                                                    <SelectItem key={option.value} value={option.value}>
                                                        {option.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div>
                                        <label className="text-sm font-medium mb-2 block">Notas</label>
                                        <Textarea
                                            value={form.data.summary}
                                            onChange={(e) => form.setData('summary', e.target.value)}
                                            placeholder="Agregar notas sobre el incidente..."
                                            rows={3}
                                        />
                                    </div>

                                    <Button 
                                        onClick={handleStatusUpdate}
                                        disabled={form.processing}
                                        className="w-full"
                                    >
                                        {form.processing ? 'Guardando...' : 'Guardar cambios'}
                                    </Button>
                                </CardContent>
                            </Card>

                            {/* Quick Stats */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Información</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="text-muted-foreground">ID</span>
                                        <span className="font-mono">#{incident.id}</span>
                                    </div>
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="text-muted-foreground">Tipo</span>
                                        <span>{incident.type_label}</span>
                                    </div>
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="text-muted-foreground">Severidad</span>
                                        <Badge variant="outline" className={getSeverityColor(incident.severity)}>
                                            {incident.severity}
                                        </Badge>
                                    </div>
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="text-muted-foreground">Eventos</span>
                                        <span>{incident.related_events_count + 1}</span>
                                    </div>
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="text-muted-foreground">Detectado</span>
                                        <span>{formatDate(incident.detected_at, "dd/MM HH:mm")}</span>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
