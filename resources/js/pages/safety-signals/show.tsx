import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type SafetySignalDetail } from '@/types/incidents';
import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    ArrowLeft,
    Car,
    Clock,
    ExternalLink,
    MapPin,
    Shield,
    User,
    Video,
} from 'lucide-react';

interface ShowProps {
    signal: SafetySignalDetail;
}

const severityColors: Record<string, string> = {
    critical: 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-200',
    warning: 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200',
    info: 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-200',
};

export default function SafetySignalShow({ signal }: ShowProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Centro de Control', href: '/dashboard' },
        { title: 'Señales de Seguridad', href: '/safety-signals' },
        { title: signal.primary_label_translated || `Señal #${signal.id}`, href: `/safety-signals/${signal.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Señal: ${signal.primary_label_translated || signal.id}`} />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <header className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                    <div className="flex items-center gap-4">
                        <Button variant="ghost" size="icon" asChild>
                            <Link href="/safety-signals">
                                <ArrowLeft className="size-4" />
                            </Link>
                        </Button>
                        <div>
                            <p className="text-sm font-medium text-muted-foreground">
                                Señal de Seguridad
                            </p>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {signal.primary_label_translated || signal.primary_behavior_label || `Señal #${signal.id}`}
                            </h1>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Badge className={severityColors[signal.severity]}>
                            {signal.severity_label}
                        </Badge>
                        {signal.event_state_translated && (
                            <Badge variant="outline">{signal.event_state_translated}</Badge>
                        )}
                    </div>
                </header>

                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Main Info */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Activity className="size-5" />
                                Información del Evento
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 md:grid-cols-2">
                                <div>
                                    <p className="text-sm text-muted-foreground">Vehículo</p>
                                    <div className="flex items-center gap-2 mt-1">
                                        <Car className="size-4 text-muted-foreground" />
                                        <span className="font-medium">{signal.vehicle_name || 'No asignado'}</span>
                                    </div>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Conductor</p>
                                    <div className="flex items-center gap-2 mt-1">
                                        <User className="size-4 text-muted-foreground" />
                                        <span className="font-medium">{signal.driver_name || 'No asignado'}</span>
                                    </div>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Fecha</p>
                                    <div className="flex items-center gap-2 mt-1">
                                        <Clock className="size-4 text-muted-foreground" />
                                        <span className="font-medium">{signal.occurred_at_human}</span>
                                    </div>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Ubicación</p>
                                    <div className="flex items-center gap-2 mt-1">
                                        <MapPin className="size-4 text-muted-foreground" />
                                        <span className="font-medium text-sm">{signal.address || 'Sin ubicación'}</span>
                                    </div>
                                </div>
                            </div>

                            {signal.max_acceleration_g && (
                                <div>
                                    <p className="text-sm text-muted-foreground">Aceleración máxima</p>
                                    <p className="font-medium">{signal.max_acceleration_g}g</p>
                                </div>
                            )}

                            <div className="flex gap-2 pt-4">
                                {signal.inbox_event_url && (
                                    <Button variant="outline" size="sm" asChild>
                                        <a href={signal.inbox_event_url} target="_blank" rel="noopener noreferrer">
                                            <ExternalLink className="mr-2 size-4" />
                                            Ver en Samsara
                                        </a>
                                    </Button>
                                )}
                                {signal.incident_report_url && (
                                    <Button variant="outline" size="sm" asChild>
                                        <a href={signal.incident_report_url} target="_blank" rel="noopener noreferrer">
                                            <ExternalLink className="mr-2 size-4" />
                                            Reporte
                                        </a>
                                    </Button>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Behavior Labels */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Etiquetas de Comportamiento</CardTitle>
                            <CardDescription>Comportamientos detectados en este evento</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-wrap gap-2">
                                {signal.behavior_labels_translated && signal.behavior_labels_translated.length > 0 ? (
                                    signal.behavior_labels_translated.map((label, index) => (
                                        <Badge key={index} variant="secondary" className="text-sm">
                                            {label.name}
                                        </Badge>
                                    ))
                                ) : signal.behavior_labels && signal.behavior_labels.length > 0 ? (
                                    signal.behavior_labels.map((label, index) => (
                                        <Badge key={index} variant="secondary" className="text-sm">
                                            {typeof label === 'string' ? label : (label as { name: string }).name}
                                        </Badge>
                                    ))
                                ) : (
                                    <p className="text-sm text-muted-foreground">Sin etiquetas</p>
                                )}
                            </div>

                            {signal.context_labels && signal.context_labels.length > 0 && (
                                <div className="mt-4">
                                    <p className="text-sm font-medium mb-2">Contexto</p>
                                    <div className="flex flex-wrap gap-2">
                                        {signal.context_labels.map((label, index) => (
                                            <Badge key={index} variant="outline" className="text-xs">
                                                {typeof label === 'string' ? label : (label as { name: string }).name}
                                            </Badge>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Linked Incidents */}
                    {signal.incidents && signal.incidents.length > 0 && (
                        <Card className="lg:col-span-2">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Shield className="size-5" />
                                    Incidentes Relacionados
                                </CardTitle>
                                <CardDescription>Esta señal es evidencia en los siguientes incidentes</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                                    {signal.incidents.map((incident) => (
                                        <Link 
                                            key={incident.id} 
                                            href={`/incidents/${incident.id}`}
                                            className="block p-4 border rounded-lg hover:bg-muted/50 transition-colors"
                                        >
                                            <div className="flex items-center justify-between mb-2">
                                                <Badge>{incident.priority}</Badge>
                                                <Badge variant="outline">{incident.pivot_role}</Badge>
                                            </div>
                                            <p className="font-medium">{incident.type_label}</p>
                                            <p className="text-sm text-muted-foreground">{incident.status}</p>
                                        </Link>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Media */}
                    {signal.media_urls && signal.media_urls.length > 0 && (
                        <Card className="lg:col-span-2">
                            <CardHeader>
                                <CardTitle>Media</CardTitle>
                                <CardDescription>Imágenes y videos del evento</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                                    {signal.media_urls.map((media, index) => {
                                        const mediaUrl = media.url || media.mediaUrl || '';
                                        const isVideo = mediaUrl.includes('.mp4') || mediaUrl.includes('.webm') || media.type === 'video';
                                        const isLocalUrl = mediaUrl.startsWith('/storage/');
                                        
                                        return (
                                            <div key={index} className="border rounded-lg overflow-hidden bg-muted/20">
                                                {mediaUrl ? (
                                                    isVideo ? (
                                                        isLocalUrl ? (
                                                            <video 
                                                                src={mediaUrl}
                                                                controls
                                                                className="w-full h-48 object-cover"
                                                            />
                                                        ) : (
                                                            <div className="w-full h-48 flex flex-col items-center justify-center text-muted-foreground p-4">
                                                                <Video className="size-8 mb-2 opacity-50" />
                                                                <p className="text-sm text-center">Video pendiente de descarga</p>
                                                                <a 
                                                                    href={mediaUrl} 
                                                                    target="_blank" 
                                                                    rel="noopener noreferrer"
                                                                    className="text-xs text-primary mt-2 hover:underline"
                                                                >
                                                                    Abrir en Samsara
                                                                </a>
                                                            </div>
                                                        )
                                                    ) : (
                                                        <img 
                                                            src={mediaUrl} 
                                                            alt={`Media ${index + 1}`}
                                                            className="w-full h-48 object-cover"
                                                            onError={(e) => {
                                                                (e.target as HTMLImageElement).style.display = 'none';
                                                            }}
                                                        />
                                                    )
                                                ) : (
                                                    <div className="w-full h-48 flex items-center justify-center text-muted-foreground">
                                                        Sin URL
                                                    </div>
                                                )}
                                                {media.input && (
                                                    <div className="p-2 text-xs text-muted-foreground border-t">
                                                        {media.input === 'dashcamRoadFacing' ? '📹 Cámara frontal' :
                                                         media.input === 'dashcamDriverFacing' ? '👤 Cámara conductor' :
                                                         media.input}
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
