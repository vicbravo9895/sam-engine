import { cn } from '@/lib/utils';
import {
    Activity,
    Car,
    ChevronDown,
    ChevronUp,
    Clock,
    ExternalLink,
    MapPin,
    Navigation,
    Power,
    RefreshCw,
    Search,
    Truck,
} from 'lucide-react';
import { useMemo, useState } from 'react';

interface FleetVehicle {
    id: string;
    name: string;
    licensePlate?: string | null;
    make?: string | null;
    model?: string | null;
    location: string | { latitude: number; longitude: number } | Record<string, unknown>;
    isGeofence: boolean;
    lat?: number | null;
    lng?: number | null;
    mapsLink?: string | null;
    isActive: boolean;
    isMoving: boolean;
    engineState: string;
    speedKmh: number;
    odometerKm?: number | null;
    lastUpdate?: string | null;
}

/**
 * Safely converts a location value to a displayable string.
 * Handles cases where location might be an object {latitude, longitude}
 * from historical data with Decimal casts.
 */
function formatLocation(location: FleetVehicle['location']): string {
    if (typeof location === 'string') return location;
    if (location && typeof location === 'object') {
        const lat = (location as Record<string, unknown>).latitude ?? (location as Record<string, unknown>).lat;
        const lng = (location as Record<string, unknown>).longitude ?? (location as Record<string, unknown>).lng;
        if (lat != null && lng != null) {
            return `${lat}, ${lng}`;
        }
        return 'Ubicación desconocida';
    }
    return 'Ubicación desconocida';
}

interface FleetStatusCardProps {
    data: {
        total: number;
        active: number;
        inactive: number;
        lastSync?: string | null;
        filteredByTag?: Array<{
            id: string;
            name: string;
            vehicle_count?: number;
        }> | null;
        vehicles: FleetVehicle[];
    };
}

type SortField = 'name' | 'engineState' | 'speedKmh' | 'location' | 'lastUpdate';
type SortDirection = 'asc' | 'desc';

export function FleetStatusCard({ data }: FleetStatusCardProps) {
    const { total, active, inactive, lastSync, filteredByTag, vehicles } = data;

    const [searchTerm, setSearchTerm] = useState('');
    const [sortField, setSortField] = useState<SortField>('engineState');
    const [sortDirection, setSortDirection] = useState<SortDirection>('asc');
    const [showOnlyActive, setShowOnlyActive] = useState(false);

    const formatTimestamp = (timestamp: string | null | undefined) => {
        if (!timestamp) return '-';
        const date = new Date(timestamp);
        return date.toLocaleString('es-MX', {
            hour: '2-digit',
            minute: '2-digit',
            day: '2-digit',
            month: 'short',
        });
    };

    const formatLastSync = (timestamp: string | null | undefined) => {
        if (!timestamp) return 'Nunca';
        const date = new Date(timestamp);
        const now = new Date();
        const diffMs = now.getTime() - date.getTime();
        const diffMins = Math.floor(diffMs / 60000);

        if (diffMins < 1) return 'Hace unos segundos';
        if (diffMins < 60) return `Hace ${diffMins} min`;
        return formatTimestamp(timestamp);
    };

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

    const getEngineStateTextColor = (state: string) => {
        switch (state) {
            case 'Encendido':
                return 'text-green-600 dark:text-green-400';
            case 'Apagado':
                return 'text-gray-500 dark:text-gray-400';
            case 'Ralentí':
                return 'text-yellow-600 dark:text-yellow-400';
            default:
                return 'text-gray-500';
        }
    };

    const getSpeedColor = (speed: number) => {
        if (speed === 0) return 'text-gray-500';
        if (speed < 30) return 'text-yellow-600 dark:text-yellow-400';
        if (speed < 80) return 'text-green-600 dark:text-green-400';
        return 'text-red-600 dark:text-red-400';
    };

    const handleSort = (field: SortField) => {
        if (sortField === field) {
            setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
        } else {
            setSortField(field);
            setSortDirection('asc');
        }
    };

    const filteredAndSortedVehicles = useMemo(() => {
        let result = [...vehicles];

        // Filter by search term
        if (searchTerm) {
            const term = searchTerm.toLowerCase();
            result = result.filter(
                (v) =>
                    v.name.toLowerCase().includes(term) ||
                    v.licensePlate?.toLowerCase().includes(term) ||
                    formatLocation(v.location).toLowerCase().includes(term)
            );
        }

        // Filter by active status
        if (showOnlyActive) {
            result = result.filter((v) => v.isActive);
        }

        // Sort
        result.sort((a, b) => {
            let comparison = 0;

            switch (sortField) {
                case 'name':
                    comparison = a.name.localeCompare(b.name);
                    break;
                case 'engineState':
                    // Sort by activity: active first, then by speed
                    const aScore = a.isActive ? (a.speedKmh > 0 ? 2 : 1) : 0;
                    const bScore = b.isActive ? (b.speedKmh > 0 ? 2 : 1) : 0;
                    comparison = bScore - aScore;
                    break;
                case 'speedKmh':
                    comparison = (b.speedKmh || 0) - (a.speedKmh || 0);
                    break;
                case 'location':
                    comparison = formatLocation(a.location).localeCompare(formatLocation(b.location));
                    break;
                case 'lastUpdate':
                    const aTime = a.lastUpdate ? new Date(a.lastUpdate).getTime() : 0;
                    const bTime = b.lastUpdate ? new Date(b.lastUpdate).getTime() : 0;
                    comparison = bTime - aTime;
                    break;
            }

            return sortDirection === 'asc' ? comparison : -comparison;
        });

        return result;
    }, [vehicles, searchTerm, showOnlyActive, sortField, sortDirection]);

    const SortIcon = ({ field }: { field: SortField }) => {
        if (sortField !== field) return null;
        return sortDirection === 'asc' ? (
            <ChevronUp className="size-3" />
        ) : (
            <ChevronDown className="size-3" />
        );
    };

    return (
        <div className="my-3 overflow-hidden rounded-xl border bg-gradient-to-br from-slate-50 to-gray-50 shadow-lg dark:from-slate-950/50 dark:to-gray-950/50">
            {/* Header */}
            <div className="border-b bg-gradient-to-r from-cyan-600 to-blue-500 px-6 py-4 text-white">
                <div className="flex items-start justify-between">
                    <div className="flex items-center gap-4">
                        <div className="flex size-14 items-center justify-center rounded-full bg-white/20 backdrop-blur-sm">
                            <Truck className="size-7" />
                        </div>
                        <div>
                            <h2 className="text-xl font-bold">Estado de la Flota</h2>
                            {filteredByTag && filteredByTag.length > 0 && (
                                <p className="mt-0.5 text-sm text-cyan-100">
                                    Filtrado por:{' '}
                                    {filteredByTag.map((tag) => tag.name).join(', ')}
                                </p>
                            )}
                            <div className="mt-1 flex items-center gap-1 text-xs text-cyan-200">
                                <RefreshCw className="size-3" />
                                Última sincronización: {formatLastSync(lastSync)}
                            </div>
                        </div>
                    </div>
                </div>

                {/* Stats Summary */}
                <div className="mt-4 grid grid-cols-3 gap-3">
                    <div className="rounded-lg bg-white/10 px-4 py-3 backdrop-blur-sm">
                        <div className="flex items-center gap-2">
                            <Car className="size-5 text-cyan-200" />
                            <span className="text-2xl font-bold">{total}</span>
                        </div>
                        <p className="mt-0.5 text-xs text-cyan-200">Total</p>
                    </div>
                    <div className="rounded-lg bg-green-500/30 px-4 py-3 backdrop-blur-sm">
                        <div className="flex items-center gap-2">
                            <Activity className="size-5 text-green-200" />
                            <span className="text-2xl font-bold">{active}</span>
                        </div>
                        <p className="mt-0.5 text-xs text-green-200">Activos</p>
                    </div>
                    <div className="rounded-lg bg-gray-500/30 px-4 py-3 backdrop-blur-sm">
                        <div className="flex items-center gap-2">
                            <Power className="size-5 text-gray-300" />
                            <span className="text-2xl font-bold">{inactive}</span>
                        </div>
                        <p className="mt-0.5 text-xs text-gray-300">Inactivos</p>
                    </div>
                </div>
            </div>

            {/* Filters */}
            <div className="flex flex-wrap items-center gap-3 border-b bg-white/50 px-4 py-3 dark:bg-black/20">
                <div className="relative flex-1 min-w-[200px]">
                    <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                    <input
                        type="text"
                        placeholder="Buscar por nombre, placa o ubicación..."
                        value={searchTerm}
                        onChange={(e) => setSearchTerm(e.target.value)}
                        className="w-full rounded-lg border border-gray-200 bg-white py-2 pl-10 pr-4 text-sm text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-800 dark:text-white dark:placeholder-gray-500"
                    />
                </div>
                <button
                    onClick={() => setShowOnlyActive(!showOnlyActive)}
                    className={cn(
                        'flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition-colors',
                        showOnlyActive
                            ? 'bg-green-500 text-white'
                            : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700'
                    )}
                >
                    <Activity className="size-4" />
                    Solo activos
                </button>
            </div>

            {/* Table */}
            <div className="overflow-x-auto">
                <table className="w-full">
                    <thead>
                        <tr className="border-b bg-gray-50 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                            <th
                                className="cursor-pointer px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-800"
                                onClick={() => handleSort('name')}
                            >
                                <div className="flex items-center gap-1">
                                    Vehículo
                                    <SortIcon field="name" />
                                </div>
                            </th>
                            <th
                                className="cursor-pointer px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-800"
                                onClick={() => handleSort('engineState')}
                            >
                                <div className="flex items-center gap-1">
                                    Estado
                                    <SortIcon field="engineState" />
                                </div>
                            </th>
                            <th
                                className="cursor-pointer px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-800"
                                onClick={() => handleSort('location')}
                            >
                                <div className="flex items-center gap-1">
                                    Ubicación
                                    <SortIcon field="location" />
                                </div>
                            </th>
                            <th
                                className="cursor-pointer px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-800"
                                onClick={() => handleSort('speedKmh')}
                            >
                                <div className="flex items-center gap-1">
                                    Velocidad
                                    <SortIcon field="speedKmh" />
                                </div>
                            </th>
                            <th
                                className="cursor-pointer px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-800"
                                onClick={() => handleSort('lastUpdate')}
                            >
                                <div className="flex items-center gap-1">
                                    Última Actualización
                                    <SortIcon field="lastUpdate" />
                                </div>
                            </th>
                            <th className="px-4 py-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                        {filteredAndSortedVehicles.length > 0 ? (
                            filteredAndSortedVehicles.map((vehicle) => (
                                <tr
                                    key={vehicle.id}
                                    className={cn(
                                        'bg-white transition-colors hover:bg-gray-50 dark:bg-gray-950 dark:hover:bg-gray-900',
                                        !vehicle.isActive && 'opacity-60'
                                    )}
                                >
                                    {/* Vehicle Name & Plate */}
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-3">
                                            <div
                                                className={cn(
                                                    'flex size-9 shrink-0 items-center justify-center rounded-full',
                                                    vehicle.isActive
                                                        ? 'bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400'
                                                        : 'bg-gray-100 text-gray-400 dark:bg-gray-800'
                                                )}
                                            >
                                                <Truck className="size-4" />
                                            </div>
                                            <div>
                                                <p className="font-medium text-gray-900 dark:text-white">
                                                    {vehicle.name}
                                                </p>
                                                {vehicle.licensePlate && (
                                                    <p className="text-xs text-gray-500">
                                                        {vehicle.licensePlate}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    </td>

                                    {/* Engine State */}
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-2">
                                            <span
                                                className={cn(
                                                    'size-2.5 rounded-full',
                                                    getEngineStateColor(vehicle.engineState),
                                                    vehicle.isActive && 'animate-pulse'
                                                )}
                                            />
                                            <span
                                                className={cn(
                                                    'text-sm font-medium',
                                                    getEngineStateTextColor(vehicle.engineState)
                                                )}
                                            >
                                                {vehicle.engineState}
                                            </span>
                                        </div>
                                        {vehicle.isMoving && (
                                            <span className="mt-1 inline-flex items-center gap-1 text-xs text-blue-600 dark:text-blue-400">
                                                <Navigation className="size-3" />
                                                En movimiento
                                            </span>
                                        )}
                                    </td>

                                    {/* Location */}
                                    <td className="max-w-[200px] px-4 py-3">
                                        <div className="flex items-start gap-1.5">
                                            <MapPin className="mt-0.5 size-3 shrink-0 text-gray-400" />
                                            <span className="truncate text-sm text-gray-700 dark:text-gray-300">
                                                {formatLocation(vehicle.location)}
                                            </span>
                                        </div>
                                        {vehicle.isGeofence && (
                                            <span className="mt-1 inline-block rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                                📍 Geofence
                                            </span>
                                        )}
                                    </td>

                                    {/* Speed */}
                                    <td className="px-4 py-3">
                                        <span
                                            className={cn(
                                                'text-lg font-bold',
                                                getSpeedColor(vehicle.speedKmh)
                                            )}
                                        >
                                            {vehicle.speedKmh}
                                        </span>
                                        <span className="ml-1 text-xs text-gray-500">km/h</span>
                                    </td>

                                    {/* Last Update */}
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-1.5 text-sm text-gray-500">
                                            <Clock className="size-3" />
                                            {formatTimestamp(vehicle.lastUpdate)}
                                        </div>
                                    </td>

                                    {/* Actions */}
                                    <td className="px-4 py-3">
                                        {vehicle.mapsLink && (
                                            <a
                                                href={vehicle.mapsLink}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex items-center gap-1 rounded-lg bg-blue-500 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-blue-600"
                                            >
                                                <ExternalLink className="size-3" />
                                                Maps
                                            </a>
                                        )}
                                    </td>
                                </tr>
                            ))
                        ) : (
                            <tr>
                                <td
                                    colSpan={6}
                                    className="px-4 py-12 text-center text-gray-500 dark:text-gray-400"
                                >
                                    <Car className="mx-auto size-12 text-gray-300 dark:text-gray-600" />
                                    <p className="mt-2">
                                        {searchTerm
                                            ? 'No se encontraron vehículos con ese criterio de búsqueda.'
                                            : 'No hay vehículos para mostrar.'}
                                    </p>
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            {/* Footer */}
            <div className="border-t bg-gray-50 px-4 py-3 text-xs text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                Mostrando {filteredAndSortedVehicles.length} de {vehicles.length} vehículos
                {searchTerm && ` (filtrado por "${searchTerm}")`}
            </div>
        </div>
    );
}
