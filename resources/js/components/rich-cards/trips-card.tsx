import { cn } from '@/lib/utils';
import {
    ArrowRight,
    Calendar,
    CheckCircle2,
    Clock,
    MapPin,
    Navigation,
    Route,
    Truck,
} from 'lucide-react';

interface TripLocation {
    address?: string;
    point_of_interest?: string;
    maps_link?: string;
}

interface Trip {
    status: string;
    status_description: string;
    trip_start_time: string;
    trip_end_time?: string;
    duration_minutes?: number;
    duration_formatted?: string;
    start_location?: TripLocation;
    end_location?: TripLocation;
}

interface VehicleTrips {
    vehicle_id: string;
    vehicle_name: string;
    vehicle_vin?: string;
    vehicle_type?: string;
    vehicle_type_description?: string;
    trip_count: number;
    trips: Trip[];
}

interface TripsCardProps {
    data: {
        totalTrips: number;
        searchRangeHours: number;
        periodStart?: string;
        periodEnd?: string;
        summaryByStatus?: Record<string, number>;
        summaryByVehicle?: Record<string, number>;
        trips: VehicleTrips[];
    };
}

const statusColors: Record<string, { bg: string; text: string; border: string; icon: React.ElementType }> = {
    completed: {
        bg: 'bg-green-100 dark:bg-green-950/50',
        text: 'text-green-700 dark:text-green-400',
        border: 'border-green-200 dark:border-green-800',
        icon: CheckCircle2,
    },
    Completado: {
        bg: 'bg-green-100 dark:bg-green-950/50',
        text: 'text-green-700 dark:text-green-400',
        border: 'border-green-200 dark:border-green-800',
        icon: CheckCircle2,
    },
    inProgress: {
        bg: 'bg-blue-100 dark:bg-blue-950/50',
        text: 'text-blue-700 dark:text-blue-400',
        border: 'border-blue-200 dark:border-blue-800',
        icon: Navigation,
    },
    'En progreso': {
        bg: 'bg-blue-100 dark:bg-blue-950/50',
        text: 'text-blue-700 dark:text-blue-400',
        border: 'border-blue-200 dark:border-blue-800',
        icon: Navigation,
    },
};

const defaultStatusColor = {
    bg: 'bg-gray-100 dark:bg-gray-800',
    text: 'text-gray-700 dark:text-gray-300',
    border: 'border-gray-200 dark:border-gray-700',
    icon: Route,
};

function formatDateTime(timestamp: string) {
    try {
        const date = new Date(timestamp);
        return {
            date: date.toLocaleDateString('es-MX', {
                day: '2-digit',
                month: 'short',
            }),
            time: date.toLocaleTimeString('es-MX', {
                hour: '2-digit',
                minute: '2-digit',
            }),
        };
    } catch {
        return { date: '', time: timestamp };
    }
}

function formatAddress(location?: TripLocation): string {
    if (!location) return 'Ubicación desconocida';
    if (location.point_of_interest) return location.point_of_interest;
    if (location.address) return location.address;
    return 'Ubicación desconocida';
}

export function TripsCard({ data }: TripsCardProps) {
    const { totalTrips, searchRangeHours, summaryByStatus, trips } = data;

    const hasTrips = totalTrips > 0;

    return (
        <div className="my-3 overflow-hidden rounded-xl border bg-gradient-to-br from-indigo-50 to-violet-50 dark:from-indigo-950/30 dark:to-violet-950/30">
            {/* Header */}
            <div className="flex items-center justify-between border-b bg-white/50 px-4 py-3 dark:bg-black/20">
                <div className="flex items-center gap-3">
                    <div className={cn(
                        'flex size-10 items-center justify-center rounded-full text-white',
                        'bg-gradient-to-br from-indigo-500 to-violet-600'
                    )}>
                        <Route className="size-5" />
                    </div>
                    <div>
                        <h3 className="font-semibold text-gray-900 dark:text-white">
                            Viajes Realizados
                        </h3>
                        <p className="text-xs text-gray-500">
                            Últimas {searchRangeHours} horas
                        </p>
                    </div>
                </div>

                {/* Total Trips Badge */}
                <div className={cn(
                    'flex items-center gap-2 rounded-full px-3 py-1.5',
                    hasTrips
                        ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-400'
                        : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400'
                )}>
                    <span className="text-lg font-bold">{totalTrips}</span>
                    <span className="text-xs">{totalTrips === 1 ? 'viaje' : 'viajes'}</span>
                </div>
            </div>

            {/* No Trips Message */}
            {!hasTrips && (
                <div className="flex flex-col items-center justify-center py-8 text-center">
                    <div className="mb-3 rounded-full bg-gray-100 p-3 dark:bg-gray-800">
                        <Route className="size-8 text-gray-400" />
                    </div>
                    <p className="font-medium text-gray-600 dark:text-gray-400">
                        Sin viajes registrados
                    </p>
                    <p className="text-sm text-gray-500">
                        No se encontraron viajes en el período analizado
                    </p>
                </div>
            )}

            {/* Summary by Status */}
            {hasTrips && summaryByStatus && Object.keys(summaryByStatus).length > 0 && (
                <div className="border-b bg-white/30 px-4 py-2 dark:bg-black/10">
                    <div className="flex flex-wrap gap-2">
                        {Object.entries(summaryByStatus).map(([status, count]) => {
                            const statusStyle = statusColors[status] || defaultStatusColor;
                            const IconComponent = statusStyle.icon;
                            return (
                                <div
                                    key={status}
                                    className={cn(
                                        'flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs',
                                        statusStyle.bg,
                                        statusStyle.text
                                    )}
                                >
                                    <IconComponent className="size-3" />
                                    <span>{status}</span>
                                    <span className="font-bold">{count}</span>
                                </div>
                            );
                        })}
                    </div>
                </div>
            )}

            {/* Trips List by Vehicle */}
            {hasTrips && trips.map((vehicleTrips) => (
                <div key={vehicleTrips.vehicle_id} className="border-b last:border-b-0">
                    {/* Vehicle Header */}
                    <div className="flex items-center gap-2 bg-gray-50 px-4 py-2 dark:bg-gray-900/50">
                        <Truck className="size-4 text-gray-500" />
                        <span className="text-sm font-medium text-gray-700 dark:text-gray-300">
                            {vehicleTrips.vehicle_name}
                        </span>
                        <span className="text-xs text-gray-500">
                            ({vehicleTrips.trip_count} {vehicleTrips.trip_count === 1 ? 'viaje' : 'viajes'})
                        </span>
                    </div>

                    {/* Trips */}
                    <div className="divide-y divide-gray-100 dark:divide-gray-800">
                        {vehicleTrips.trips.map((trip, index) => {
                            const statusStyle = statusColors[trip.status] || statusColors[trip.status_description] || defaultStatusColor;
                            const StatusIcon = statusStyle.icon;
                            const startTime = formatDateTime(trip.trip_start_time);
                            const endTime = trip.trip_end_time ? formatDateTime(trip.trip_end_time) : null;

                            return (
                                <div
                                    key={index}
                                    className="bg-white/50 px-4 py-3 dark:bg-black/10"
                                >
                                    {/* Status and Duration Row */}
                                    <div className="mb-2 flex items-center justify-between">
                                        <div className={cn(
                                            'flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium',
                                            statusStyle.bg,
                                            statusStyle.text
                                        )}>
                                            <StatusIcon className="size-3" />
                                            {trip.status_description}
                                        </div>

                                        {trip.duration_formatted && (
                                            <div className="flex items-center gap-1 text-xs text-gray-500">
                                                <Clock className="size-3" />
                                                {trip.duration_formatted}
                                            </div>
                                        )}
                                    </div>

                                    {/* Route */}
                                    <div className="flex items-stretch gap-2">
                                        {/* Timeline */}
                                        <div className="flex flex-col items-center py-1">
                                            <div className="size-2.5 rounded-full bg-green-500" />
                                            <div className="w-px flex-1 bg-gradient-to-b from-green-500 to-red-500" />
                                            <div className="size-2.5 rounded-full bg-red-500" />
                                        </div>

                                        {/* Locations */}
                                        <div className="flex-1 space-y-3">
                                            {/* Start Location */}
                                            <div>
                                                <div className="flex items-center gap-2">
                                                    <span className="text-xs font-medium text-green-600 dark:text-green-400">
                                                        Inicio
                                                    </span>
                                                    <div className="flex items-center gap-1 text-xs text-gray-500">
                                                        <Calendar className="size-3" />
                                                        {startTime.date}
                                                        <Clock className="ml-1 size-3" />
                                                        {startTime.time}
                                                    </div>
                                                </div>
                                                <div className="mt-0.5 flex items-start gap-1">
                                                    <span className="text-sm text-gray-700 dark:text-gray-300">
                                                        {formatAddress(trip.start_location)}
                                                    </span>
                                                    {trip.start_location?.maps_link && (
                                                        <a
                                                            href={trip.start_location.maps_link}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="ml-1 flex-shrink-0 text-blue-500 hover:text-blue-600"
                                                            title="Ver en mapa"
                                                        >
                                                            <MapPin className="size-3.5" />
                                                        </a>
                                                    )}
                                                </div>
                                            </div>

                                            {/* End Location */}
                                            <div>
                                                <div className="flex items-center gap-2">
                                                    <span className="text-xs font-medium text-red-600 dark:text-red-400">
                                                        {trip.status === 'inProgress' || trip.status_description === 'En progreso' ? 'En curso...' : 'Fin'}
                                                    </span>
                                                    {endTime && (
                                                        <div className="flex items-center gap-1 text-xs text-gray-500">
                                                            <Calendar className="size-3" />
                                                            {endTime.date}
                                                            <Clock className="ml-1 size-3" />
                                                            {endTime.time}
                                                        </div>
                                                    )}
                                                </div>
                                                <div className="mt-0.5 flex items-start gap-1">
                                                    <span className="text-sm text-gray-700 dark:text-gray-300">
                                                        {trip.status === 'inProgress' || trip.status_description === 'En progreso'
                                                            ? 'Viaje en progreso'
                                                            : formatAddress(trip.end_location)
                                                        }
                                                    </span>
                                                    {trip.end_location?.maps_link && trip.status !== 'inProgress' && (
                                                        <a
                                                            href={trip.end_location.maps_link}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="ml-1 flex-shrink-0 text-blue-500 hover:text-blue-600"
                                                            title="Ver en mapa"
                                                        >
                                                            <MapPin className="size-3.5" />
                                                        </a>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
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

