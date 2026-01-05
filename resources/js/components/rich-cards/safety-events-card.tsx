import { cn } from '@/lib/utils';
import {
    AlertTriangle,
    Car,
    Eye,
    MapPin,
    Play,
    Shield,
    Smartphone,
    Zap,
} from 'lucide-react';

interface SafetyEvent {
    type_description: string;
    event_state_description?: string;
    timestamp?: string;
    location?: {
        address?: string;
        maps_link?: string;
    };
    driver?: {
        name?: string;
    };
    video_url?: string;
}

interface VehicleEvents {
    vehicle_id: string;
    vehicle_name: string;
    events: SafetyEvent[];
}

interface SafetyEventsCardProps {
    data: {
        totalEvents: number;
        searchRangeMinutes: number;
        periodStart?: string;
        periodEnd?: string;
        summaryByType?: Record<string, number>;
        events: VehicleEvents[];
    };
}

// Icons for event types (both English codes and Spanish descriptions)
const eventTypeIcons: Record<string, React.ElementType> = {
    // English codes
    harshAcceleration: Zap,
    harshBraking: AlertTriangle,
    harshTurn: Car,
    crash: AlertTriangle,
    speeding: Zap,
    distraction: Eye,
    genericDistraction: Eye,
    drowsiness: Eye,
    nearCollision: AlertTriangle,
    cellPhoneUsage: Smartphone,
    seatbeltViolation: Shield,
    // Spanish descriptions
    'Aceleración brusca': Zap,
    'Frenado brusco': AlertTriangle,
    'Giro brusco': Car,
    'Colisión/Choque': AlertTriangle,
    'Exceso de velocidad': Zap,
    'Distracción del conductor': Eye,
    'Conducción distraída': Eye,
    'Somnolencia': Eye,
    'Casi colisión': AlertTriangle,
    'Uso de celular': Smartphone,
    'Sin cinturón de seguridad': Shield,
};

// Default color for events
const defaultEventColor = { bg: 'bg-amber-50 dark:bg-amber-950/30', text: 'text-amber-700 dark:text-amber-400', border: 'border-amber-200 dark:border-amber-800' };

function formatTime(timestamp: string) {
    try {
        const date = new Date(timestamp);
        return date.toLocaleTimeString('es-MX', {
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return timestamp;
    }
}

export function SafetyEventsCard({ data }: SafetyEventsCardProps) {
    const { totalEvents, searchRangeMinutes, summaryByType, events } = data;

    const hasEvents = totalEvents > 0;

    return (
        <div className="my-3 overflow-hidden rounded-xl border bg-gradient-to-br from-slate-50 to-gray-50 dark:from-slate-950/50 dark:to-gray-950/50">
            {/* Header */}
            <div className="flex items-center justify-between border-b bg-white/50 px-4 py-3 dark:bg-black/20">
                <div className="flex items-center gap-3">
                    <div className={cn(
                        'flex size-10 items-center justify-center rounded-full text-white',
                        hasEvents
                            ? 'bg-gradient-to-br from-orange-500 to-red-600'
                            : 'bg-gradient-to-br from-green-500 to-emerald-600'
                    )}>
                        <Shield className="size-5" />
                    </div>
                    <div>
                        <h3 className="font-semibold text-gray-900 dark:text-white">
                            Eventos de Seguridad
                        </h3>
                        <p className="text-xs text-gray-500">
                            Últimos {searchRangeMinutes} minutos
                        </p>
                    </div>
                </div>

                {/* Total Events Badge */}
                <div className={cn(
                    'flex items-center gap-2 rounded-full px-3 py-1.5',
                    hasEvents
                        ? 'bg-orange-100 text-orange-700 dark:bg-orange-950/50 dark:text-orange-400'
                        : 'bg-green-100 text-green-700 dark:bg-green-950/50 dark:text-green-400'
                )}>
                    <span className="text-lg font-bold">{totalEvents}</span>
                    <span className="text-xs">{totalEvents === 1 ? 'evento' : 'eventos'}</span>
                </div>
            </div>

            {/* No Events Message */}
            {!hasEvents && (
                <div className="flex flex-col items-center justify-center py-8 text-center">
                    <div className="mb-3 rounded-full bg-green-100 p-3 dark:bg-green-950/50">
                        <Shield className="size-8 text-green-600 dark:text-green-400" />
                    </div>
                    <p className="font-medium text-green-700 dark:text-green-400">
                        ¡Sin eventos de seguridad!
                    </p>
                    <p className="text-sm text-gray-500">
                        No se detectaron incidentes en el período analizado
                    </p>
                </div>
            )}

            {/* Summary by Type */}
            {hasEvents && summaryByType && Object.keys(summaryByType).length > 0 && (
                <div className="border-b bg-white/30 px-4 py-2 dark:bg-black/10">
                    <div className="flex flex-wrap gap-2">
                        {Object.entries(summaryByType).map(([type, count]) => {
                            const IconComponent = eventTypeIcons[type] || AlertTriangle;
                            return (
                                <div
                                    key={type}
                                    className="flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-1 text-xs dark:bg-gray-800"
                                >
                                    <IconComponent className="size-3 text-gray-500" />
                                    <span className="text-gray-700 dark:text-gray-300">
                                        {type}
                                    </span>
                                    <span className="font-bold text-gray-900 dark:text-white">{count}</span>
                                </div>
                            );
                        })}
                    </div>
                </div>
            )}

            {/* Events List */}
            {hasEvents && events.map((vehicleEvents) => (
                <div key={vehicleEvents.vehicle_id} className="border-b last:border-b-0">
                    {/* Vehicle Header */}
                    <div className="bg-gray-50 px-4 py-2 dark:bg-gray-900/50">
                        <span className="text-sm font-medium text-gray-700 dark:text-gray-300">
                            🚛 {vehicleEvents.vehicle_name}
                        </span>
                        <span className="ml-2 text-xs text-gray-500">
                            ({vehicleEvents.events.length} {vehicleEvents.events.length === 1 ? 'evento' : 'eventos'})
                        </span>
                    </div>

                    {/* Events */}
                    <div className="divide-y divide-gray-100 dark:divide-gray-800">
                        {vehicleEvents.events.map((event, index) => {
                            const IconComponent = eventTypeIcons[event.type_description] || AlertTriangle;

                            return (
                                <div
                                    key={index}
                                    className={cn(
                                        'flex items-start gap-3 px-4 py-3',
                                        defaultEventColor.bg
                                    )}
                                >
                                    <div className={cn(
                                        'mt-0.5 rounded-full p-1.5',
                                        defaultEventColor.bg,
                                        defaultEventColor.border,
                                        'border'
                                    )}>
                                        <IconComponent className={cn('size-4', defaultEventColor.text)} />
                                    </div>

                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className={cn('font-medium', defaultEventColor.text)}>
                                                {event.type_description}
                                            </span>
                                            {event.event_state_description && (
                                                <span className="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-400">
                                                    {event.event_state_description}
                                                </span>
                                            )}
                                        </div>

                                        <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-gray-500">
                                            {event.timestamp && (
                                                <span>🕐 {formatTime(event.timestamp)}</span>
                                            )}

                                            {event.driver?.name && (
                                                <span>👤 {event.driver.name}</span>
                                            )}

                                            {event.location?.maps_link && (
                                                <a
                                                    href={event.location.maps_link}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="flex items-center gap-1 text-blue-500 hover:underline"
                                                >
                                                    <MapPin className="size-3" />
                                                    Ver ubicación
                                                </a>
                                            )}
                                        </div>

                                        {/* Video link */}
                                        {event.video_url && (
                                            <div className="mt-2">
                                                <a
                                                    href={event.video_url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="inline-flex items-center gap-1 rounded bg-blue-100 px-2 py-1 text-xs font-medium text-blue-700 hover:bg-blue-200 dark:bg-blue-900/50 dark:text-blue-300"
                                                >
                                                    <Play className="size-3" />
                                                    Ver video
                                                </a>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            ))}
        </div>
    );
}

