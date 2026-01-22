import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type IncidentDetail, INCIDENT_STATUS_OPTIONS } from '@/types/incidents';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowLeft,
    Car,
    Clock,
    Radio,
    Shield,
    User,
} from 'lucide-react';

interface ShowProps {
    incident: IncidentDetail;
}

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

const roleColors: Record<string, string> = {
    supporting: 'bg-emerald-100 text-emerald-800',
    contradicting: 'bg-red-100 text-red-800',
    context: 'bg-blue-100 text-blue-800',
};

const typeIcons: Record<string, React.ElementType> = {
    collision: Car,
    emergency: AlertTriangle,
    pattern: Activity,
    safety_violation: Shield,
};

export default function IncidentShow({ incident }: ShowProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Centro de Control', href: '/dashboard' },
        { title: 'Incidentes', href: '/incidents' },
        { title: `${incident.priority} - ${incident.type_label}`, href: `/incidents/${incident.id}` },
    ];

    const TypeIcon = typeIcons[incident.incident_type] || AlertTriangle;

    const handleStatusChange = (newStatus: string) => {
        router.patch(`/incidents/${incident.id}/status`, {
            status: newStatus,
        }, {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Incidente ${incident.priority}: ${incident.type_label}`} />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <header className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                    <div className="flex items-center gap-4">
                        <Button variant="ghost" size="icon" asChild>
                            <Link href="/incidents">
                                <ArrowLeft className="size-4" />
                            </Link>
                        </Button>
                        <div className="flex items-center gap-3">
                            <div className={`rounded-full p-3 ${incident.is_high_priority ? 'bg-red-100' : 'bg-slate-100'}`}>
                                <TypeIcon className={`size-6 ${incident.is_high_priority ? 'text-red-600' : 'text-slate-600'}`} />
                            </div>
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">
                                    Incidente #{incident.id}
                                </p>
                                <h1 className="text-2xl font-semibold tracking-tight">
                                    {incident.type_label}
                                </h1>
                            </div>
                        </div>
                    </div>
                    <div className="flex items-center gap-3">
                        <Badge className={`${priorityColors[incident.priority]} border text-lg px-4 py-1`}>
                            {incident.priority}
                        </Badge>
                        <Select value={incident.status} onValueChange={handleStatusChange}>
                            <SelectTrigger className="w-[180px]">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {INCIDENT_STATUS_OPTIONS.map((opt) => (
                                    <SelectItem key={opt.value} value={opt.value}>
                                        {opt.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </header>

                <div className="grid gap-6 lg:grid-cols-3">
                    {/* Main Info */}
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Shield className="size-5" />
                                Detalles del Incidente
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            <div className="grid gap-4 md:grid-cols-2">
                                <div>
                                    <p className="text-sm text-muted-foreground">Prioridad</p>
                                    <Badge className={`${priorityColors[incident.priority]} border mt-1`}>
                                        {incident.priority_label}
                                    </Badge>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Estado</p>
                                    <Badge variant="outline" className={`${statusColors[incident.status]} mt-1`}>
                                        {incident.status_label}
                                    </Badge>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Severidad</p>
                                    <p className="font-medium">{incident.severity_label}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Fuente</p>
                                    <p className="font-medium capitalize">{incident.source.replace('_', ' ')}</p>
                                </div>
                            </div>

                            <div>
                                <p className="text-sm text-muted-foreground mb-1">Sujeto</p>
                                <div className="flex items-center gap-2">
                                    {incident.subject_type === 'driver' ? (
                                        <User className="size-4 text-muted-foreground" />
                                    ) : (
                                        <Car className="size-4 text-muted-foreground" />
                                    )}
                                    <span className="font-medium">
                                        {incident.subject_name || 'No especificado'}
                                    </span>
                                    {incident.subject_type && (
                                        <Badge variant="outline" className="text-xs">
                                            {incident.subject_type === 'driver' ? 'Conductor' : 'Vehículo'}
                                        </Badge>
                                    )}
                                </div>
                            </div>

                            {incident.ai_summary && (
                                <div>
                                    <p className="text-sm text-muted-foreground mb-1">Resumen AI</p>
                                    <p className="text-sm bg-muted/50 p-3 rounded-lg">
                                        {incident.ai_summary}
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Timeline */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Clock className="size-5" />
                                Timeline
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <p className="text-sm text-muted-foreground">Detectado</p>
                                <p className="font-medium">{incident.detected_at_human}</p>
                                {incident.detected_at && (
                                    <p className="text-xs text-muted-foreground">
                                        {new Date(incident.detected_at).toLocaleString('es-MX')}
                                    </p>
                                )}
                            </div>
                            {incident.resolved_at && (
                                <div>
                                    <p className="text-sm text-muted-foreground">Resuelto</p>
                                    <p className="font-medium">{incident.resolved_at_human}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {new Date(incident.resolved_at).toLocaleString('es-MX')}
                                    </p>
                                </div>
                            )}
                            <div>
                                <p className="text-sm text-muted-foreground">Señales vinculadas</p>
                                <p className="font-medium text-2xl">{incident.safety_signals.length}</p>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Safety Signals */}
                    <Card className="lg:col-span-3">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Radio className="size-5" />
                                Señales de Seguridad Vinculadas
                            </CardTitle>
                            <CardDescription>
                                Evidencia del incidente basada en señales del stream
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {incident.safety_signals.length === 0 ? (
                                <div className="text-center py-8 text-muted-foreground">
                                    <Radio className="size-8 mx-auto mb-2 opacity-50" />
                                    <p>No hay señales vinculadas</p>
                                </div>
                            ) : (
                                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                                    {incident.safety_signals.map((signal) => (
                                        <Link
                                            key={signal.id}
                                            href={`/safety-signals/${signal.id}`}
                                            className="block p-4 border rounded-lg hover:bg-muted/50 transition-colors"
                                        >
                                            <div className="flex items-center justify-between mb-2">
                                                <Badge variant="outline" className={roleColors[signal.pivot_role]}>
                                                    {signal.pivot_role === 'supporting' ? 'Soporte' : 
                                                     signal.pivot_role === 'contradicting' ? 'Contradicción' : 'Contexto'}
                                                </Badge>
                                                <span className="text-xs text-muted-foreground">
                                                    Relevancia: {Math.round(signal.pivot_relevance_score * 100)}%
                                                </span>
                                            </div>
                                            <p className="font-medium">
                                                {signal.primary_label_translated || signal.primary_behavior_label}
                                            </p>
                                            <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
                                                <Car className="size-3" />
                                                <span>{signal.vehicle_name || '-'}</span>
                                            </div>
                                            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                                <User className="size-3" />
                                                <span>{signal.driver_name || '-'}</span>
                                            </div>
                                            <p className="text-xs text-muted-foreground mt-2">
                                                {signal.occurred_at_human}
                                            </p>
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* AI Assessment */}
                    {incident.ai_assessment && Object.keys(incident.ai_assessment).length > 0 && (
                        <Card className="lg:col-span-3">
                            <CardHeader>
                                <CardTitle>Evaluación AI</CardTitle>
                                <CardDescription>Análisis automático del incidente</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <pre className="text-sm bg-muted/50 p-4 rounded-lg overflow-auto">
                                    {JSON.stringify(incident.ai_assessment, null, 2)}
                                </pre>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
