import { cn } from '@/lib/utils';
import {
    AlertTriangle,
    Battery,
    Droplets,
    Gauge,
    MapPin,
    Navigation,
    Power,
    Thermometer,
    Truck,
} from 'lucide-react';

interface VehicleStatsCardProps {
    data: {
        vehicleName: string;
        vehicleId: string;
        make?: string;
        model?: string;
        year?: string;
        licensePlate?: string;
        stats: {
            ubicacion?: {
                latitud: number;
                longitud: number;
                nombre: string;
                es_geofence: boolean;
                velocidad_kmh: number;
                mapa: string;
            };
            motor_estado?: string;
            combustible_porcentaje?: number;
            odometro_km?: number;
            bateria_voltaje?: number;
            motor_rpm?: number;
            refrigerante_celsius?: number;
            temperatura_ambiente_celsius?: number;
            motor_carga_porcentaje?: number;
            tiene_fallas?: boolean;
        };
    };
}

export function VehicleStatsCard({ data }: VehicleStatsCardProps) {
    const { stats } = data;

    const getEngineStateColor = (state: string) => {
        switch (state) {
            case 'Encendido':
                return 'bg-green-500';
            case 'Apagado':
                return 'bg-gray-400';
            case 'Ralentí':
                return 'bg-yellow-500';
            default:
                return 'bg-gray-400';
        }
    };

    const getFuelColor = (percent: number) => {
        if (percent > 50) return 'bg-green-500';
        if (percent > 25) return 'bg-yellow-500';
        return 'bg-red-500';
    };

    return (
        <div className="my-3 overflow-hidden rounded-xl border bg-gradient-to-br from-slate-50 to-gray-50 dark:from-slate-950/50 dark:to-gray-950/50">
            {/* Header */}
            <div className="flex items-center justify-between border-b bg-white/50 px-4 py-3 dark:bg-black/20">
                <div className="flex items-center gap-3">
                    <div className="flex size-10 items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white">
                        <Truck className="size-5" />
                    </div>
                    <div>
                        <h3 className="font-semibold text-gray-900 dark:text-white">
                            {data.vehicleName}
                        </h3>
                        <p className="text-xs text-gray-500">
                            {data.make} {data.model} {data.year && `(${data.year})`}
                            {data.licensePlate && ` • ${data.licensePlate}`}
                        </p>
                    </div>
                </div>

                {/* Engine State Badge */}
                {stats.motor_estado && (
                    <div className="flex items-center gap-2">
                        <span
                            className={cn(
                                'size-2.5 rounded-full animate-pulse',
                                getEngineStateColor(stats.motor_estado)
                            )}
                        />
                        <span className="text-sm font-medium">{stats.motor_estado}</span>
                    </div>
                )}
            </div>

            {/* Stats Grid */}
            <div className="grid grid-cols-2 gap-px bg-gray-200 dark:bg-gray-700 sm:grid-cols-4">
                {/* Location */}
                {stats.ubicacion && (
                    <a
                        href={stats.ubicacion.mapa}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="flex flex-col bg-white p-3 transition-colors hover:bg-blue-50 dark:bg-gray-900 dark:hover:bg-blue-950/30"
                    >
                        <div className="flex items-center gap-1.5 text-xs text-gray-500">
                            <MapPin className="size-3 text-blue-500" />
                            Ubicación
                        </div>
                        <p className="mt-1 truncate text-sm font-medium text-gray-900 dark:text-white">
                            {stats.ubicacion.nombre}
                        </p>
                        <div className="mt-1 flex items-center gap-1 text-xs text-gray-400">
                            <Navigation className="size-3" />
                            {stats.ubicacion.velocidad_kmh} km/h
                        </div>
                    </a>
                )}

                {/* Fuel */}
                {stats.combustible_porcentaje !== undefined && (
                    <div className="flex flex-col bg-white p-3 dark:bg-gray-900">
                        <div className="flex items-center gap-1.5 text-xs text-gray-500">
                            <Droplets className="size-3 text-cyan-500" />
                            Combustible
                        </div>
                        <p className="mt-1 text-lg font-bold text-gray-900 dark:text-white">
                            {stats.combustible_porcentaje}%
                        </p>
                        <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                            <div
                                className={cn('h-full rounded-full transition-all', getFuelColor(stats.combustible_porcentaje))}
                                style={{ width: `${stats.combustible_porcentaje}%` }}
                            />
                        </div>
                    </div>
                )}

                {/* Odometer */}
                {stats.odometro_km !== undefined && (
                    <div className="flex flex-col bg-white p-3 dark:bg-gray-900">
                        <div className="flex items-center gap-1.5 text-xs text-gray-500">
                            <Gauge className="size-3 text-purple-500" />
                            Odómetro
                        </div>
                        <p className="mt-1 text-lg font-bold text-gray-900 dark:text-white">
                            {stats.odometro_km.toLocaleString()}
                        </p>
                        <span className="text-xs text-gray-400">kilómetros</span>
                    </div>
                )}

                {/* Battery */}
                {stats.bateria_voltaje !== undefined && (
                    <div className="flex flex-col bg-white p-3 dark:bg-gray-900">
                        <div className="flex items-center gap-1.5 text-xs text-gray-500">
                            <Battery className="size-3 text-yellow-500" />
                            Batería
                        </div>
                        <p className="mt-1 text-lg font-bold text-gray-900 dark:text-white">
                            {stats.bateria_voltaje}V
                        </p>
                        <span className={cn(
                            'text-xs',
                            stats.bateria_voltaje >= 12.4 ? 'text-green-500' : 'text-orange-500'
                        )}>
                            {stats.bateria_voltaje >= 12.4 ? 'Normal' : 'Bajo'}
                        </span>
                    </div>
                )}

                {/* RPM */}
                {stats.motor_rpm !== undefined && (
                    <div className="flex flex-col bg-white p-3 dark:bg-gray-900">
                        <div className="flex items-center gap-1.5 text-xs text-gray-500">
                            <Power className="size-3 text-orange-500" />
                            RPM
                        </div>
                        <p className="mt-1 text-lg font-bold text-gray-900 dark:text-white">
                            {stats.motor_rpm.toLocaleString()}
                        </p>
                        <span className="text-xs text-gray-400">rev/min</span>
                    </div>
                )}

                {/* Coolant Temperature */}
                {stats.refrigerante_celsius !== undefined && (
                    <div className="flex flex-col bg-white p-3 dark:bg-gray-900">
                        <div className="flex items-center gap-1.5 text-xs text-gray-500">
                            <Thermometer className="size-3 text-red-500" />
                            Refrigerante
                        </div>
                        <p className={cn(
                            'mt-1 text-lg font-bold',
                            stats.refrigerante_celsius > 100 ? 'text-red-500' : 'text-gray-900 dark:text-white'
                        )}>
                            {stats.refrigerante_celsius}°C
                        </p>
                        <span className={cn(
                            'text-xs',
                            stats.refrigerante_celsius > 100 ? 'text-red-500' : 'text-gray-400'
                        )}>
                            {stats.refrigerante_celsius > 100 ? '⚠️ Alto' : 'Normal'}
                        </span>
                    </div>
                )}

                {/* Engine Load */}
                {stats.motor_carga_porcentaje !== undefined && (
                    <div className="flex flex-col bg-white p-3 dark:bg-gray-900">
                        <div className="flex items-center gap-1.5 text-xs text-gray-500">
                            <Gauge className="size-3 text-indigo-500" />
                            Carga Motor
                        </div>
                        <p className="mt-1 text-lg font-bold text-gray-900 dark:text-white">
                            {stats.motor_carga_porcentaje}%
                        </p>
                        <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                            <div
                                className="h-full rounded-full bg-indigo-500"
                                style={{ width: `${stats.motor_carga_porcentaje}%` }}
                            />
                        </div>
                    </div>
                )}

                {/* Faults */}
                {stats.tiene_fallas !== undefined && (
                    <div className={cn(
                        'flex flex-col p-3',
                        stats.tiene_fallas ? 'bg-red-50 dark:bg-red-950/30' : 'bg-white dark:bg-gray-900'
                    )}>
                        <div className="flex items-center gap-1.5 text-xs text-gray-500">
                            <AlertTriangle className={cn('size-3', stats.tiene_fallas ? 'text-red-500' : 'text-gray-400')} />
                            Fallas
                        </div>
                        <p className={cn(
                            'mt-1 text-sm font-bold',
                            stats.tiene_fallas ? 'text-red-600' : 'text-green-600'
                        )}>
                            {stats.tiene_fallas ? '⚠️ Detectadas' : '✓ Sin fallas'}
                        </p>
                    </div>
                )}
            </div>
        </div>
    );
}

