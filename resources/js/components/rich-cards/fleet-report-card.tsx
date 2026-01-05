import { cn } from '@/lib/utils';
import {
    AlertCircle,
    AlertTriangle,
    Battery,
    CheckCircle2,
    ChevronDown,
    ChevronUp,
    ChevronLeft,
    ChevronRight,
    Droplets,
    ExternalLink,
    Gauge,
    MapPin,
    Navigation,
    Power,
    Route,
    Shield,
    Thermometer,
    Timer,
    Truck,
    Video,
    Zap,
    Eye,
    Car,
    Smartphone,
    Clock,
    User,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';

interface FleetReportCardProps {
    data: {
        vehicle: {
            id: string;
            name: string;
            make?: string | null;
            model?: string | null;
            licensePlate?: string | null;
        };
        summary: {
            status: 'OK' | 'Atención' | 'Crítico';
            highlights: string[];
            notes: string[];
        };
        location?: {
            vehicleName: string;
            vehicleId: string;
            lat: number;
            lng: number;
            locationName: string;
            isGeofence: boolean;
            speedKmh: number;
            headingDegrees?: number;
            mapsLink: string;
            timestamp: string;
            make?: string;
            model?: string;
            licensePlate?: string;
        } | null;
        vehicleStats?: {
            vehicleName: string;
            vehicleId: string;
            make?: string;
            model?: string;
            year?: string;
            licensePlate?: string;
            stats: any;
        } | null;
        safetyEvents?: {
            totalEvents: number;
            searchRangeHours: number;
            periodStart?: string | null;
            periodEnd?: string | null;
            summaryByType?: Record<string, number>;
            summaryByState?: Record<string, number>;
            events: Array<{
                vehicle_id: string;
                vehicle_name: string;
                events: any[];
            }>;
        } | null;
        trips?: {
            totalTrips: number;
            searchRangeHours: number;
            periodStart?: string | null;
            periodEnd?: string | null;
            summaryByStatus?: Record<string, number>;
            summaryByVehicle?: Record<string, number>;
            trips: Array<{
                vehicle_id: string;
                vehicle_name: string;
                trips: any[];
            }>;
        } | null;
        dashcamMedia?: {
            vehicleId: string;
            vehicleName: string;
            totalImages: number;
            images: Array<{
                id: string;
                type: string;
                typeDescription: string;
                timestamp: string;
                url: string;
                isPersisted: boolean;
            }>;
        } | null;
    };
}

export function FleetReportCard({ data }: FleetReportCardProps) {
    const { vehicle, summary, location, vehicleStats, safetyEvents, trips, dashcamMedia } = data;

    const [MapComponent, setMapComponent] = useState<React.ComponentType<any> | null>(null);
    const [TileLayerComponent, setTileLayerComponent] = useState<React.ComponentType<any> | null>(null);
    const [MarkerComponent, setMarkerComponent] = useState<React.ComponentType<any> | null>(null);
    const [PopupComponent, setPopupComponent] = useState<React.ComponentType<any> | null>(null);
    const [expandedEvents, setExpandedEvents] = useState<Set<string>>(new Set());
    const [expandedTrips, setExpandedTrips] = useState<Set<string>>(new Set());
    const [dashcamTab, setDashcamTab] = useState<'all' | 'dashcamRoadFacing' | 'dashcamDriverFacing'>('all');
    const [selectedImageIndex, setSelectedImageIndex] = useState<number | null>(null);

    // Dynamically import Leaflet components (SSR safe)
    useEffect(() => {
        if (location) {
            import('react-leaflet').then((mod) => {
                setMapComponent(() => mod.MapContainer);
                setTileLayerComponent(() => mod.TileLayer);
                setMarkerComponent(() => mod.Marker);
                setPopupComponent(() => mod.Popup);
            });

            // Import Leaflet CSS
            import('leaflet/dist/leaflet.css');
            
            // Fix default marker icon issue in Leaflet
            import('leaflet').then((L) => {
                // @ts-ignore
                delete L.Icon.Default.prototype._getIconUrl;
                L.Icon.Default.mergeOptions({
                    iconRetinaUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-icon-2x.png',
                    iconUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-icon.png',
                    shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-shadow.png',
                });
            });
        }
    }, [location]);

    const formatTimestamp = (timestamp: string) => {
        const date = new Date(timestamp);
        return date.toLocaleString('es-MX', {
            hour: '2-digit',
            minute: '2-digit',
            day: '2-digit',
            month: 'short',
            year: 'numeric',
        });
    };

    const getStatusColor = (status: string) => {
        switch (status) {
            case 'OK':
                return 'bg-green-500 text-white';
            case 'Atención':
                return 'bg-yellow-500 text-white';
            case 'Crítico':
                return 'bg-red-500 text-white';
            default:
                return 'bg-gray-500 text-white';
        }
    };

    const getStatusIcon = (status: string) => {
        switch (status) {
            case 'OK':
                return <CheckCircle2 className="size-5" />;
            case 'Atención':
                return <AlertTriangle className="size-5" />;
            case 'Crítico':
                return <AlertCircle className="size-5" />;
            default:
                return null;
        }
    };

    const getSpeedColor = (speed: number) => {
        if (speed === 0) return 'text-gray-500';
        if (speed < 30) return 'text-yellow-500';
        if (speed < 80) return 'text-green-500';
        return 'text-red-500';
    };

    const getFuelColor = (percent: number) => {
        if (percent > 50) return 'text-green-500';
        if (percent > 25) return 'text-yellow-500';
        return 'text-red-500';
    };

    const getFuelBgColor = (percent: number) => {
        if (percent > 50) return 'bg-green-500';
        if (percent > 25) return 'bg-yellow-500';
        return 'bg-red-500';
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

    const getEventTypeIcon = (typeDescription: string): React.ReactNode => {
        const type = typeDescription.toLowerCase();
        if (type.includes('aceleración') || type.includes('velocidad')) {
            return <Zap className="size-4" />;
        }
        if (type.includes('frenado') || type.includes('colisión') || type.includes('choque')) {
            return <AlertTriangle className="size-4" />;
        }
        if (type.includes('giro')) {
            return <Car className="size-4" />;
        }
        if (type.includes('distracción') || type.includes('somnolencia')) {
            return <Eye className="size-4" />;
        }
        if (type.includes('celular')) {
            return <Smartphone className="size-4" />;
        }
        return <Shield className="size-4" />;
    };

    const toggleEvent = (eventId: string) => {
        const newExpanded = new Set(expandedEvents);
        if (newExpanded.has(eventId)) {
            newExpanded.delete(eventId);
        } else {
            newExpanded.add(eventId);
        }
        setExpandedEvents(newExpanded);
    };

    const toggleTrip = (tripId: string) => {
        const newExpanded = new Set(expandedTrips);
        if (newExpanded.has(tripId)) {
            newExpanded.delete(tripId);
        } else {
            newExpanded.add(tripId);
        }
        setExpandedTrips(newExpanded);
    };

    const translateEventType = (type: string): string => {
        const translations: Record<string, string> = {
            'harshAcceleration': 'Aceleración Brusca',
            'harshBraking': 'Frenado Brusco',
            'harshTurn': 'Giro Brusco',
            'crash': 'Colisión/Choque',
            'speeding': 'Exceso de Velocidad',
            'distraction': 'Distracción del Conductor',
            'genericDistraction': 'Distracción del Conductor',
            'drowsiness': 'Somnolencia',
            'obstructedCamera': 'Cámara Obstruida',
            'nearCollision': 'Casi Colisión',
            'followingDistance': 'Distancia de Seguimiento Insegura',
            'laneViolation': 'Violación de Carril',
            'rollingStop': 'Parada Incompleta',
            'cellPhoneUsage': 'Uso de Celular',
            'seatbeltViolation': 'Sin Cinturón de Seguridad',
            'smoking': 'Fumando',
            'foodDrink': 'Comiendo/Bebiendo',
            'Inattentive Driving': 'Conducción Distraída',
            'Harsh Acceleration': 'Aceleración Brusca',
            'Harsh Braking': 'Frenado Brusco',
            'Harsh Turn': 'Giro Brusco',
            'Crash': 'Colisión/Choque',
            'Speeding': 'Exceso de Velocidad',
            'Drowsiness': 'Somnolencia',
            'Obstructed Camera': 'Cámara Obstruida',
            'Near Collision': 'Casi Colisión',
            'Following Distance': 'Distancia de Seguimiento Insegura',
            'Lane Violation': 'Violación de Carril',
            'Rolling Stop': 'Parada Incompleta',
            'Cell Phone Usage': 'Uso de Celular',
            'Seatbelt Violation': 'Sin Cinturón de Seguridad',
            'Smoking': 'Fumando',
            'Eating or Drinking': 'Comiendo/Bebiendo',
        };
        return translations[type] || type;
    };

    const filteredDashcamImages = () => {
        if (!dashcamMedia || !dashcamMedia.images) return [];
        if (dashcamTab === 'all') return dashcamMedia.images;
        return dashcamMedia.images.filter(img => img.type === dashcamTab);
    };

    const handlePrevImage = () => {
        if (selectedImageIndex !== null && selectedImageIndex > 0) {
            setSelectedImageIndex(selectedImageIndex - 1);
        }
    };

    const handleNextImage = () => {
        const filtered = filteredDashcamImages();
        if (selectedImageIndex !== null && selectedImageIndex < filtered.length - 1) {
            setSelectedImageIndex(selectedImageIndex + 1);
        }
    };

    const handleKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'ArrowLeft') handlePrevImage();
        if (e.key === 'ArrowRight') handleNextImage();
        if (e.key === 'Escape') setSelectedImageIndex(null);
    };

    return (
        <div className="my-3 overflow-hidden rounded-xl border bg-gradient-to-br from-slate-50 to-gray-50 dark:from-slate-950/50 dark:to-gray-950/50 shadow-lg">
            {/* Header - Report Title */}
            <div className="border-b bg-gradient-to-r from-blue-600 to-blue-500 px-6 py-4 text-white">
                <div className="flex items-start justify-between">
                    <div className="flex items-center gap-4">
                        <div className="flex size-14 items-center justify-center rounded-full bg-white/20 backdrop-blur-sm">
                            <Truck className="size-7" />
                        </div>
                        <div>
                            <h2 className="text-xl font-bold">Reporte de Flota</h2>
                            <p className="text-sm text-blue-100 mt-0.5">
                                {vehicle.name} • {vehicle.make} {vehicle.model}
                                {vehicle.licensePlate && ` • ${vehicle.licensePlate}`}
                            </p>
                            <p className="text-xs text-blue-200 mt-1">ID: {vehicle.id}</p>
                        </div>
                    </div>
                    <div
                        className={cn(
                            'flex items-center gap-2 rounded-lg px-4 py-2.5 font-semibold shadow-md',
                            getStatusColor(summary.status)
                        )}
                    >
                        {getStatusIcon(summary.status)}
                        <span className="text-sm">{summary.status}</span>
                    </div>
                </div>

                {/* Summary Highlights */}
                {summary.highlights.length > 0 && (
                    <div className="mt-4 rounded-lg bg-white/10 backdrop-blur-sm p-3">
                        <p className="text-xs font-semibold uppercase tracking-wide text-blue-100 mb-2">Resumen Ejecutivo</p>
                        <div className="space-y-1.5">
                            {summary.highlights.map((highlight, idx) => (
                                <div key={idx} className="flex items-start gap-2 text-sm text-white">
                                    <span className="mt-0.5 text-blue-200">•</span>
                                    <span>{highlight}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* Main Content Grid */}
            <div className="p-6">
                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Left Column - Location & Stats */}
                    <div className="space-y-6">
                        {/* Location Section with Map */}
                        {location && (
                            <div className="overflow-hidden rounded-lg border bg-white shadow-sm dark:bg-gray-900">
                                <div className="flex items-center justify-between border-b bg-gradient-to-r from-blue-50 to-cyan-50 px-4 py-3 dark:from-blue-950/30 dark:to-cyan-950/30">
                                    <div className="flex items-center gap-2">
                                        <div className="flex size-8 items-center justify-center rounded-full bg-blue-500 text-white">
                                            <MapPin className="size-4" />
                                        </div>
                                        <h4 className="font-semibold text-gray-900 dark:text-white">Ubicación Actual</h4>
                                    </div>
                                    {location.mapsLink && (
                                        <a
                                            href={location.mapsLink}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="flex items-center gap-1.5 rounded-lg bg-blue-500 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-blue-600"
                                        >
                                            <ExternalLink className="size-3" />
                                            Maps
                                        </a>
                                    )}
                                </div>

                                {/* Map */}
                                <div className="relative h-64 w-full bg-gray-100 dark:bg-gray-800">
                                    {MapComponent && TileLayerComponent && MarkerComponent && PopupComponent ? (
                                        <MapComponent
                                            center={[location.lat, location.lng]}
                                            zoom={15}
                                            style={{ height: '100%', width: '100%' }}
                                            scrollWheelZoom={false}
                                        >
                                            <TileLayerComponent
                                                attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
                                                url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                                            />
                                            <MarkerComponent position={[location.lat, location.lng]}>
                                                <PopupComponent>
                                                    <strong>{location.vehicleName}</strong>
                                                    <br />
                                                    {location.locationName}
                                                </PopupComponent>
                                            </MarkerComponent>
                                        </MapComponent>
                                    ) : (
                                        <div className="flex h-full items-center justify-center">
                                            <div className="text-gray-400">Cargando mapa...</div>
                                        </div>
                                    )}
                                </div>

                                {/* Location Info Footer */}
                                <div className="grid grid-cols-3 divide-x bg-white/70 dark:bg-black/30">
                                    <div className="px-4 py-3">
                                        <div className="flex items-center gap-1.5 text-xs text-gray-500">
                                            <MapPin className="size-3" />
                                            Ubicación
                                        </div>
                                        <p className="mt-1 text-sm font-medium text-gray-900 dark:text-white truncate">
                                            {location.locationName}
                                        </p>
                                        {location.isGeofence && (
                                            <span className="mt-1 inline-block rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                                📍 Geofence
                                            </span>
                                        )}
                                    </div>
                                    <div className="px-4 py-3">
                                        <div className="flex items-center gap-1.5 text-xs text-gray-500">
                                            <Navigation className="size-3" />
                                            Velocidad
                                        </div>
                                        <p className={cn('mt-1 text-lg font-bold', getSpeedColor(location.speedKmh))}>
                                            {location.speedKmh} km/h
                                        </p>
                                    </div>
                                    <div className="px-4 py-3">
                                        <div className="flex items-center gap-1.5 text-xs text-gray-500">
                                            <Timer className="size-3" />
                                            Actualizado
                                        </div>
                                        <p className="mt-1 text-xs font-medium text-gray-900 dark:text-white">
                                            {formatTimestamp(location.timestamp)}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Vehicle Stats Section */}
                        {vehicleStats && (
                            <div className="overflow-hidden rounded-lg border bg-white shadow-sm dark:bg-gray-900">
                                <div className="border-b bg-gradient-to-r from-indigo-50 to-purple-50 px-4 py-3 dark:from-indigo-950/30 dark:to-purple-950/30">
                                    <div className="flex items-center gap-2">
                                        <div className="flex size-8 items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white">
                                            <Truck className="size-4" />
                                        </div>
                                        <h4 className="font-semibold text-gray-900 dark:text-white">Estadísticas del Vehículo</h4>
                                    </div>
                                </div>
                                <div className="grid grid-cols-2 gap-px bg-gray-200 dark:bg-gray-700 sm:grid-cols-3 lg:grid-cols-4">
                                    {vehicleStats.stats.motor_estado && (
                                        <div className="bg-white px-4 py-4 dark:bg-gray-900">
                                            <div className="flex items-center gap-1.5 text-xs text-gray-500">
                                                <Power className="size-3" />
                                                Motor
                                            </div>
                                            <div className="mt-2 flex items-center gap-2">
                                                <span
                                                    className={cn(
                                                        'size-2.5 rounded-full animate-pulse',
                                                        getEngineStateColor(vehicleStats.stats.motor_estado)
                                                    )}
                                                />
                                                <p className="text-sm font-semibold text-gray-900 dark:text-white">
                                                    {vehicleStats.stats.motor_estado}
                                                </p>
                                            </div>
                                        </div>
                                    )}
                                    {vehicleStats.stats.combustible_porcentaje !== undefined && (
                                        <div className="bg-white px-4 py-4 dark:bg-gray-900">
                                            <div className="flex items-center gap-1.5 text-xs text-gray-500">
                                                <Droplets className="size-3" />
                                                Combustible
                                            </div>
                                            <p className={cn('mt-2 text-xl font-bold', getFuelColor(vehicleStats.stats.combustible_porcentaje))}>
                                                {vehicleStats.stats.combustible_porcentaje}%
                                            </p>
                                            <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                                <div
                                                    className={cn('h-full rounded-full transition-all', getFuelBgColor(vehicleStats.stats.combustible_porcentaje))}
                                                    style={{ width: `${vehicleStats.stats.combustible_porcentaje}%` }}
                                                />
                                            </div>
                                        </div>
                                    )}
                                    {vehicleStats.stats.odometro_km !== undefined && (
                                        <div className="bg-white px-4 py-4 dark:bg-gray-900">
                                            <div className="flex items-center gap-1.5 text-xs text-gray-500">
                                                <Gauge className="size-3" />
                                                Odómetro
                                            </div>
                                            <p className="mt-2 text-lg font-semibold text-gray-900 dark:text-white">
                                                {vehicleStats.stats.odometro_km.toLocaleString('es-MX')} km
                                            </p>
                                        </div>
                                    )}
                                    {location && location.speedKmh !== undefined && (
                                        <div className="bg-white px-4 py-4 dark:bg-gray-900">
                                            <div className="flex items-center gap-1.5 text-xs text-gray-500">
                                                <Zap className="size-3" />
                                                Velocidad Actual
                                            </div>
                                            <p className={cn('mt-2 text-xl font-bold', getSpeedColor(location.speedKmh))}>
                                                {location.speedKmh} km/h
                                            </p>
                                        </div>
                                    )}
                                    {vehicleStats.stats.bateria_voltaje !== undefined && (
                                        <div className="bg-white px-4 py-4 dark:bg-gray-900">
                                            <div className="flex items-center gap-1.5 text-xs text-gray-500">
                                                <Battery className="size-3" />
                                                Batería
                                            </div>
                                            <p className="mt-2 text-lg font-semibold text-gray-900 dark:text-white">
                                                {vehicleStats.stats.bateria_voltaje}V
                                            </p>
                                            <span className={cn(
                                                'text-xs',
                                                vehicleStats.stats.bateria_voltaje >= 12.4 ? 'text-green-500' : 'text-orange-500'
                                            )}>
                                                {vehicleStats.stats.bateria_voltaje >= 12.4 ? 'Normal' : 'Bajo'}
                                            </span>
                                        </div>
                                    )}
                                    {vehicleStats.stats.motor_rpm !== undefined && (
                                        <div className="bg-white px-4 py-4 dark:bg-gray-900">
                                            <div className="flex items-center gap-1.5 text-xs text-gray-500">
                                                <Power className="size-3" />
                                                RPM
                                            </div>
                                            <p className="mt-2 text-lg font-semibold text-gray-900 dark:text-white">
                                                {vehicleStats.stats.motor_rpm.toLocaleString('es-MX')}
                                            </p>
                                            <span className="text-xs text-gray-400">rev/min</span>
                                        </div>
                                    )}
                                    {vehicleStats.stats.refrigerante_celsius !== undefined && (
                                        <div className="bg-white px-4 py-4 dark:bg-gray-900">
                                            <div className="flex items-center gap-1.5 text-xs text-gray-500">
                                                <Thermometer className="size-3" />
                                                Refrigerante
                                            </div>
                                            <p className={cn(
                                                'mt-2 text-lg font-semibold',
                                                vehicleStats.stats.refrigerante_celsius > 100 ? 'text-red-500' : 'text-gray-900 dark:text-white'
                                            )}>
                                                {vehicleStats.stats.refrigerante_celsius}°C
                                            </p>
                                            <span className={cn(
                                                'text-xs',
                                                vehicleStats.stats.refrigerante_celsius > 100 ? 'text-red-500' : 'text-gray-400'
                                            )}>
                                                {vehicleStats.stats.refrigerante_celsius > 100 ? '⚠️ Alto' : 'Normal'}
                                            </span>
                                        </div>
                                    )}
                                    {vehicleStats.stats.motor_carga_porcentaje !== undefined && (
                                        <div className="bg-white px-4 py-4 dark:bg-gray-900">
                                            <div className="flex items-center gap-1.5 text-xs text-gray-500">
                                                <Gauge className="size-3" />
                                                Carga Motor
                                            </div>
                                            <p className="mt-2 text-lg font-semibold text-gray-900 dark:text-white">
                                                {vehicleStats.stats.motor_carga_porcentaje}%
                                            </p>
                                            <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                                <div
                                                    className="h-full rounded-full bg-indigo-500"
                                                    style={{ width: `${vehicleStats.stats.motor_carga_porcentaje}%` }}
                                                />
                                            </div>
                                        </div>
                                    )}
                                    {vehicleStats.stats.temperatura_ambiente_celsius !== undefined && (
                                        <div className="bg-white px-4 py-4 dark:bg-gray-900">
                                            <div className="flex items-center gap-1.5 text-xs text-gray-500">
                                                <Thermometer className="size-3" />
                                                Temp. Ambiente
                                            </div>
                                            <p className="mt-2 text-lg font-semibold text-gray-900 dark:text-white">
                                                {vehicleStats.stats.temperatura_ambiente_celsius}°C
                                            </p>
                                        </div>
                                    )}
                                    {vehicleStats.stats.tiene_fallas !== undefined && (
                                        <div className={cn(
                                            'px-4 py-4',
                                            vehicleStats.stats.tiene_fallas ? 'bg-red-50 dark:bg-red-950/30' : 'bg-white dark:bg-gray-900'
                                        )}>
                                            <div className="flex items-center gap-1.5 text-xs text-gray-500">
                                                <AlertTriangle className={cn('size-3', vehicleStats.stats.tiene_fallas ? 'text-red-500' : 'text-gray-400')} />
                                                Fallas
                                            </div>
                                            <p className={cn(
                                                'mt-2 text-sm font-bold',
                                                vehicleStats.stats.tiene_fallas ? 'text-red-600' : 'text-green-600'
                                            )}>
                                                {vehicleStats.stats.tiene_fallas ? '⚠️ Detectadas' : '✓ Sin fallas'}
                                            </p>
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Right Column - Events, Trips & Media */}
                    <div className="space-y-6">

                        {/* Safety Events Section */}
                        {safetyEvents && (
                            <div className="overflow-hidden rounded-lg border bg-white shadow-sm dark:bg-gray-900">
                                <div className="border-b bg-gradient-to-r from-red-50 to-orange-50 px-4 py-3 dark:from-red-950/30 dark:to-orange-950/30">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <div className="flex size-8 items-center justify-center rounded-full bg-red-500 text-white">
                                                <Shield className="size-4" />
                                            </div>
                                            <h4 className="font-semibold text-gray-900 dark:text-white">Eventos de Seguridad</h4>
                                        </div>
                                        <div className="rounded-lg bg-red-100 px-3 py-1 text-xs font-semibold text-red-700 dark:bg-red-900/30 dark:text-red-400">
                                            {safetyEvents.totalEvents} {safetyEvents.totalEvents === 1 ? 'evento' : 'eventos'}
                                        </div>
                                    </div>
                                </div>
                                <div className="p-4">
                                    {safetyEvents.totalEvents > 0 ? (
                                        <div className="space-y-4">
                                            <div className="flex items-center justify-between rounded-lg bg-red-50 px-4 py-3 dark:bg-red-950/20">
                                                <div>
                                                    <p className="text-xs text-gray-500">Total de Eventos</p>
                                                    <p className="text-2xl font-bold text-red-600 dark:text-red-400">
                                                        {safetyEvents.totalEvents}
                                                    </p>
                                                </div>
                                                <div className="text-right">
                                                    <p className="text-xs text-gray-500">Rango de Búsqueda</p>
                                                    <p className="text-lg font-semibold text-gray-900 dark:text-white">
                                                        {safetyEvents.searchRangeHours}h
                                                    </p>
                                                </div>
                                            </div>
                                            {safetyEvents.summaryByType && Object.keys(safetyEvents.summaryByType).length > 0 && (
                                                <div>
                                                    <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                        Desglose por Tipo
                                                    </p>
                                                    <div className="space-y-2">
                                                        {Object.entries(safetyEvents.summaryByType).map(([type, count]) => (
                                                            <div
                                                                key={type}
                                                                className="flex items-center justify-between rounded-lg border border-red-100 bg-red-50/50 px-3 py-2 dark:border-red-900/30 dark:bg-red-950/10"
                                                            >
                                                                <span className="text-sm text-gray-700 dark:text-gray-300">{translateEventType(type)}</span>
                                                                <span className="rounded-full bg-red-500 px-2.5 py-0.5 text-xs font-bold text-white">
                                                                    {count}
                                                                </span>
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}
                                            {/* Individual Events List */}
                                            {safetyEvents.events && safetyEvents.events.length > 0 && (
                                                <div>
                                                    <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                        Eventos Recientes
                                                    </p>
                                                    <div className="space-y-2 max-h-96 overflow-y-auto">
                                                        {safetyEvents.events.map((vehicleEvents, vehicleIdx) => (
                                                            vehicleEvents.events && vehicleEvents.events.length > 0 && (
                                                                <div key={vehicleIdx} className="space-y-2">
                                                                    {vehicleEvents.events.slice(0, 10).map((event: any, eventIdx: number) => {
                                                                        const eventId = `${vehicleIdx}-${eventIdx}`;
                                                                        const isExpanded = expandedEvents.has(eventId);
                                                                        const eventTypeIcon = getEventTypeIcon(event.type_description || '');
                                                                        return (
                                                                            <div
                                                                                key={eventIdx}
                                                                                className="overflow-hidden rounded-lg border border-red-100 bg-white shadow-sm dark:border-red-900/30 dark:bg-gray-800"
                                                                            >
                                                                                <button
                                                                                    onClick={() => toggleEvent(eventId)}
                                                                                    className="w-full"
                                                                                >
                                                                                    <div className="flex items-start gap-3 p-3 hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                                                                        <div className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400">
                                                                                            {eventTypeIcon}
                                                                                        </div>
                                                                                        <div className="min-w-0 flex-1 text-left">
                                                                                            <div className="flex items-start justify-between gap-2">
                                                                                                <div className="flex-1">
                                                                                                    <p className="text-sm font-semibold text-gray-900 dark:text-white">
                                                                                                        {event.type_description || 'Evento desconocido'}
                                                                                                    </p>
                                                                                                    <div className="mt-1 flex flex-wrap items-center gap-2">
                                                                                                        {event.driver?.name && (
                                                                                                            <div className="flex items-center gap-1.5 text-xs text-gray-500">
                                                                                                                <User className="size-3" />
                                                                                                                {event.driver.name}
                                                                                                            </div>
                                                                                                        )}
                                                                                                        {event.timestamp && (
                                                                                                            <div className="flex items-center gap-1.5 text-xs text-gray-500">
                                                                                                                <Clock className="size-3" />
                                                                                                                {formatTimestamp(event.timestamp)}
                                                                                                            </div>
                                                                                                        )}
                                                                                                        {event.event_state_description && (
                                                                                                            <span className="inline-block rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                                                                                                {event.event_state_description}
                                                                                                            </span>
                                                                                                        )}
                                                                                                    </div>
                                                                                                </div>
                                                                                                <div className="shrink-0">
                                                                                                    {isExpanded ? (
                                                                                                        <ChevronUp className="size-4 text-gray-400" />
                                                                                                    ) : (
                                                                                                        <ChevronDown className="size-4 text-gray-400" />
                                                                                                    )}
                                                                                                </div>
                                                                                            </div>
                                                                                        </div>
                                                                                    </div>
                                                                                </button>
                                                                                {isExpanded && (
                                                                                    <div className="border-t border-red-100 bg-gray-50/50 p-4 dark:border-red-900/30 dark:bg-gray-900/50">
                                                                                        <div className="space-y-3">
                                                                                            {event.location?.address && (
                                                                                                <div>
                                                                                                    <div className="mb-1 flex items-center gap-1.5 text-xs font-semibold text-gray-500">
                                                                                                        <MapPin className="size-3" />
                                                                                                        Ubicación
                                                                                                    </div>
                                                                                                    <p className="text-sm text-gray-700 dark:text-gray-300">
                                                                                                        {event.location.address}
                                                                                                    </p>
                                                                                                    {event.location.maps_link && (
                                                                                                        <a
                                                                                                            href={event.location.maps_link}
                                                                                                            target="_blank"
                                                                                                            rel="noopener noreferrer"
                                                                                                            className="mt-1 inline-flex items-center gap-1.5 text-xs text-blue-600 hover:text-blue-700 dark:text-blue-400"
                                                                                                        >
                                                                                                            <ExternalLink className="size-3" />
                                                                                                            Ver en Google Maps
                                                                                                        </a>
                                                                                                    )}
                                                                                                </div>
                                                                                            )}
                                                                                            {event.driver?.name && (
                                                                                                <div>
                                                                                                    <div className="mb-1 flex items-center gap-1.5 text-xs font-semibold text-gray-500">
                                                                                                        <User className="size-3" />
                                                                                                        Conductor
                                                                                                    </div>
                                                                                                    <p className="text-sm text-gray-700 dark:text-gray-300">
                                                                                                        {event.driver.name}
                                                                                                    </p>
                                                                                                </div>
                                                                                            )}
                                                                                            {event.timestamp && (
                                                                                                <div>
                                                                                                    <div className="mb-1 flex items-center gap-1.5 text-xs font-semibold text-gray-500">
                                                                                                        <Clock className="size-3" />
                                                                                                        Fecha y Hora
                                                                                                    </div>
                                                                                                    <p className="text-sm text-gray-700 dark:text-gray-300">
                                                                                                        {formatTimestamp(event.timestamp)}
                                                                                                    </p>
                                                                                                </div>
                                                                                            )}
                                                                                            {event.event_state_description && (
                                                                                                <div>
                                                                                                    <div className="mb-1 flex items-center gap-1.5 text-xs font-semibold text-gray-500">
                                                                                                        <Shield className="size-3" />
                                                                                                        Estado
                                                                                                    </div>
                                                                                                    <span className="inline-block rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                                                                                        {event.event_state_description}
                                                                                                    </span>
                                                                                                </div>
                                                                                            )}
                                                                                            {event.video_url && (
                                                                                                <div>
                                                                                                    <a
                                                                                                        href={event.video_url}
                                                                                                        target="_blank"
                                                                                                        rel="noopener noreferrer"
                                                                                                        className="inline-flex items-center gap-2 rounded-lg bg-red-500 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-red-600"
                                                                                                    >
                                                                                                        <Video className="size-4" />
                                                                                                        Ver Video del Evento
                                                                                                    </a>
                                                                                                </div>
                                                                                            )}
                                                                                        </div>
                                                                                    </div>
                                                                                )}
                                                                            </div>
                                                                        );
                                                                    })}
                                                                </div>
                                                            )
                                                        ))}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    ) : (
                                        <div className="rounded-lg bg-gray-50 px-4 py-6 text-center dark:bg-gray-800">
                                            <Shield className="mx-auto size-8 text-gray-400" />
                                            <p className="mt-2 text-sm text-gray-500">
                                                No se encontraron eventos de seguridad en el período especificado.
                                            </p>
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* Trips Section */}
                        {trips && (
                            <div className="overflow-hidden rounded-lg border bg-white shadow-sm dark:bg-gray-900">
                                <div className="border-b bg-gradient-to-r from-green-50 to-emerald-50 px-4 py-3 dark:from-green-950/30 dark:to-emerald-950/30">
                                    <div className="flex items-center gap-2">
                                        <div className="flex size-8 items-center justify-center rounded-full bg-green-500 text-white">
                                            <Route className="size-4" />
                                        </div>
                                        <h4 className="font-semibold text-gray-900 dark:text-white">Viajes</h4>
                                    </div>
                                </div>
                                <div className="p-4">
                                    {trips.totalTrips > 0 ? (
                                        <div className="space-y-4">
                                            <div className="flex items-center justify-between rounded-lg bg-green-50 px-4 py-3 dark:bg-green-950/20">
                                                <div>
                                                    <p className="text-xs text-gray-500">Total de Viajes</p>
                                                    <p className="text-2xl font-bold text-green-600 dark:text-green-400">
                                                        {trips.totalTrips}
                                                    </p>
                                                </div>
                                                <div className="text-right">
                                                    <p className="text-xs text-gray-500">Rango de Búsqueda</p>
                                                    <p className="text-lg font-semibold text-gray-900 dark:text-white">
                                                        {trips.searchRangeHours}h
                                                    </p>
                                                </div>
                                            </div>
                                            {trips.summaryByStatus && Object.keys(trips.summaryByStatus).length > 0 && (
                                                <div>
                                                    <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                        Desglose por Estado
                                                    </p>
                                                    <div className="space-y-2">
                                                        {Object.entries(trips.summaryByStatus).map(([status, count]) => (
                                                            <div
                                                                key={status}
                                                                className="flex items-center justify-between rounded-lg border border-green-100 bg-green-50/50 px-3 py-2 dark:border-green-900/30 dark:bg-green-950/10"
                                                            >
                                                                <span className="text-sm text-gray-700 dark:text-gray-300">{status}</span>
                                                                <span className="rounded-full bg-green-500 px-2.5 py-0.5 text-xs font-bold text-white">
                                                                    {count}
                                                                </span>
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}
                                            {/* Individual Trips List */}
                                            {trips.trips && trips.trips.length > 0 && (
                                                <div>
                                                    <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                        Viajes Recientes
                                                    </p>
                                                    <div className="space-y-2 max-h-96 overflow-y-auto">
                                                        {trips.trips.map((vehicleTrips, vehicleIdx) => (
                                                            vehicleTrips.trips && vehicleTrips.trips.length > 0 && (
                                                                <div key={vehicleIdx} className="space-y-2">
                                                                    {vehicleTrips.trips.slice(0, 10).map((trip: any, tripIdx: number) => {
                                                                        const tripId = `${vehicleIdx}-${tripIdx}`;
                                                                        const isExpanded = expandedTrips.has(tripId);
                                                                        return (
                                                                            <div
                                                                                key={tripIdx}
                                                                                className="overflow-hidden rounded-lg border border-green-100 bg-white shadow-sm dark:border-green-900/30 dark:bg-gray-800"
                                                                            >
                                                                                <button
                                                                                    onClick={() => toggleTrip(tripId)}
                                                                                    className="w-full"
                                                                                >
                                                                                    <div className="flex items-start gap-3 p-3 hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                                                                        <div className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400">
                                                                                            <Route className="size-4" />
                                                                                        </div>
                                                                                        <div className="min-w-0 flex-1 text-left">
                                                                                            <div className="flex items-start justify-between gap-2">
                                                                                                <div className="flex-1">
                                                                                                    <p className="text-sm font-semibold text-gray-900 dark:text-white">
                                                                                                        Viaje {tripIdx + 1}
                                                                                                    </p>
                                                                                                    <div className="mt-1 flex flex-wrap items-center gap-2">
                                                                                                        {trip.status_description && (
                                                                                                            <span className={cn(
                                                                                                                'inline-block rounded-full px-2 py-0.5 text-xs font-medium',
                                                                                                                trip.status_description === 'Completado' 
                                                                                                                    ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                                                                                                    : trip.status_description === 'En progreso'
                                                                                                                    ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'
                                                                                                                    : 'bg-gray-100 text-gray-700 dark:bg-gray-900/30 dark:text-gray-400'
                                                                                                            )}>
                                                                                                                {trip.status_description}
                                                                                                            </span>
                                                                                                        )}
                                                                                                        {trip.trip_start_time && (
                                                                                                            <div className="flex items-center gap-1.5 text-xs text-gray-500">
                                                                                                                <Clock className="size-3" />
                                                                                                                {formatTimestamp(trip.trip_start_time)}
                                                                                                            </div>
                                                                                                        )}
                                                                                                        {trip.duration_formatted && (
                                                                                                            <div className="flex items-center gap-1.5 text-xs text-gray-500">
                                                                                                                <Timer className="size-3" />
                                                                                                                {trip.duration_formatted}
                                                                                                            </div>
                                                                                                        )}
                                                                                                    </div>
                                                                                                </div>
                                                                                                <div className="shrink-0">
                                                                                                    {isExpanded ? (
                                                                                                        <ChevronUp className="size-4 text-gray-400" />
                                                                                                    ) : (
                                                                                                        <ChevronDown className="size-4 text-gray-400" />
                                                                                                    )}
                                                                                                </div>
                                                                                            </div>
                                                                                        </div>
                                                                                    </div>
                                                                                </button>
                                                                                {isExpanded && (
                                                                                    <div className="border-t border-green-100 bg-gray-50/50 p-4 dark:border-green-900/30 dark:bg-gray-900/50">
                                                                                        <div className="space-y-3">
                                                                                            {trip.status_description && (
                                                                                                <div>
                                                                                                    <div className="mb-1 flex items-center gap-1.5 text-xs font-semibold text-gray-500">
                                                                                                        <Route className="size-3" />
                                                                                                        Estado del Viaje
                                                                                                    </div>
                                                                                                    <span className={cn(
                                                                                                        'inline-block rounded-full px-3 py-1 text-xs font-medium',
                                                                                                        trip.status_description === 'Completado' 
                                                                                                            ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                                                                                            : trip.status_description === 'En progreso'
                                                                                                            ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'
                                                                                                            : 'bg-gray-100 text-gray-700 dark:bg-gray-900/30 dark:text-gray-400'
                                                                                                    )}>
                                                                                                        {trip.status_description}
                                                                                                    </span>
                                                                                                </div>
                                                                                            )}
                                                                                            {trip.trip_start_time && (
                                                                                                <div>
                                                                                                    <div className="mb-1 flex items-center gap-1.5 text-xs font-semibold text-gray-500">
                                                                                                        <Clock className="size-3" />
                                                                                                        Inicio
                                                                                                    </div>
                                                                                                    <p className="text-sm text-gray-700 dark:text-gray-300">
                                                                                                        {formatTimestamp(trip.trip_start_time)}
                                                                                                    </p>
                                                                                                </div>
                                                                                            )}
                                                                                            {trip.trip_end_time && (
                                                                                                <div>
                                                                                                    <div className="mb-1 flex items-center gap-1.5 text-xs font-semibold text-gray-500">
                                                                                                        <Clock className="size-3" />
                                                                                                        Fin
                                                                                                    </div>
                                                                                                    <p className="text-sm text-gray-700 dark:text-gray-300">
                                                                                                        {formatTimestamp(trip.trip_end_time)}
                                                                                                    </p>
                                                                                                </div>
                                                                                            )}
                                                                                            {trip.duration_formatted && (
                                                                                                <div>
                                                                                                    <div className="mb-1 flex items-center gap-1.5 text-xs font-semibold text-gray-500">
                                                                                                        <Timer className="size-3" />
                                                                                                        Duración
                                                                                                    </div>
                                                                                                    <p className="text-sm font-semibold text-gray-700 dark:text-gray-300">
                                                                                                        {trip.duration_formatted}
                                                                                                    </p>
                                                                                                </div>
                                                                                            )}
                                                                                            {trip.start_location && (
                                                                                                <div>
                                                                                                    <div className="mb-1 flex items-center gap-1.5 text-xs font-semibold text-gray-500">
                                                                                                        <MapPin className="size-3" />
                                                                                                        Punto de Inicio
                                                                                                    </div>
                                                                                                    <p className="text-sm text-gray-700 dark:text-gray-300">
                                                                                                        {trip.start_location.address || trip.start_location.point_of_interest || 'Ubicación desconocida'}
                                                                                                    </p>
                                                                                                    {trip.start_location.maps_link && (
                                                                                                        <a
                                                                                                            href={trip.start_location.maps_link}
                                                                                                            target="_blank"
                                                                                                            rel="noopener noreferrer"
                                                                                                            className="mt-1 inline-flex items-center gap-1.5 text-xs text-blue-600 hover:text-blue-700 dark:text-blue-400"
                                                                                                        >
                                                                                                            <ExternalLink className="size-3" />
                                                                                                            Ver en Google Maps
                                                                                                        </a>
                                                                                                    )}
                                                                                                </div>
                                                                                            )}
                                                                                            {trip.end_location && (
                                                                                                <div>
                                                                                                    <div className="mb-1 flex items-center gap-1.5 text-xs font-semibold text-gray-500">
                                                                                                        <MapPin className="size-3" />
                                                                                                        Punto de Fin
                                                                                                    </div>
                                                                                                    <p className="text-sm text-gray-700 dark:text-gray-300">
                                                                                                        {trip.end_location.address || trip.end_location.point_of_interest || 'Ubicación desconocida'}
                                                                                                    </p>
                                                                                                    {trip.end_location.maps_link && (
                                                                                                        <a
                                                                                                            href={trip.end_location.maps_link}
                                                                                                            target="_blank"
                                                                                                            rel="noopener noreferrer"
                                                                                                            className="mt-1 inline-flex items-center gap-1.5 text-xs text-blue-600 hover:text-blue-700 dark:text-blue-400"
                                                                                                        >
                                                                                                            <ExternalLink className="size-3" />
                                                                                                            Ver en Google Maps
                                                                                                        </a>
                                                                                                    )}
                                                                                                </div>
                                                                                            )}
                                                                                        </div>
                                                                                    </div>
                                                                                )}
                                                                            </div>
                                                                        );
                                                                    })}
                                                                </div>
                                                            )
                                                        ))}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    ) : (
                                        <div className="rounded-lg bg-gray-50 px-4 py-6 text-center dark:bg-gray-800">
                                            <Route className="mx-auto size-8 text-gray-400" />
                                            <p className="mt-2 text-sm text-gray-500">
                                                No se encontraron viajes en el período especificado.
                                            </p>
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                </div>

                {/* Dashcam Media Section - Full Width */}
                {dashcamMedia && (
                    <div className="mt-6 overflow-hidden rounded-lg border bg-white shadow-sm dark:bg-gray-900">
                        <div className="border-b bg-gradient-to-r from-purple-50 to-pink-50 px-4 py-3 dark:from-purple-950/30 dark:to-pink-950/30">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <div className="flex size-8 items-center justify-center rounded-full bg-purple-500 text-white">
                                        <Video className="size-4" />
                                    </div>
                                    <h4 className="font-semibold text-gray-900 dark:text-white">Dashcam Media</h4>
                                </div>
                                <div className="rounded-lg bg-purple-100 px-3 py-1 text-xs font-semibold text-purple-700 dark:bg-purple-900/30 dark:text-purple-400">
                                    {dashcamMedia.totalImages} {dashcamMedia.totalImages === 1 ? 'imagen' : 'imágenes'}
                                </div>
                            </div>
                        </div>
                        {dashcamMedia.totalImages > 0 && (
                            <div className="border-b bg-white dark:bg-gray-900">
                                <div className="flex gap-1 px-4 pt-3">
                                    <button
                                        onClick={() => setDashcamTab('all')}
                                        className={cn(
                                            'rounded-t-lg px-4 py-2 text-sm font-medium transition-colors',
                                            dashcamTab === 'all'
                                                ? 'bg-purple-500 text-white'
                                                : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-gray-700'
                                        )}
                                    >
                                        Todo ({dashcamMedia.images.length})
                                    </button>
                                    <button
                                        onClick={() => setDashcamTab('dashcamRoadFacing')}
                                        className={cn(
                                            'rounded-t-lg px-4 py-2 text-sm font-medium transition-colors',
                                            dashcamTab === 'dashcamRoadFacing'
                                                ? 'bg-purple-500 text-white'
                                                : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-gray-700'
                                        )}
                                    >
                                        🚗 Carretera ({dashcamMedia.images.filter(img => img.type === 'dashcamRoadFacing').length})
                                    </button>
                                    <button
                                        onClick={() => setDashcamTab('dashcamDriverFacing')}
                                        className={cn(
                                            'rounded-t-lg px-4 py-2 text-sm font-medium transition-colors',
                                            dashcamTab === 'dashcamDriverFacing'
                                                ? 'bg-purple-500 text-white'
                                                : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-gray-700'
                                        )}
                                    >
                                        👤 Conductor ({dashcamMedia.images.filter(img => img.type === 'dashcamDriverFacing').length})
                                    </button>
                                </div>
                            </div>
                        )}
                        <div className="p-4">
                            {dashcamMedia.totalImages > 0 ? (
                                <div className="space-y-3">
                                    {filteredDashcamImages().length > 0 ? (
                                        <>
                                            <p className="text-sm text-gray-600 dark:text-gray-400">
                                                Mostrando <strong className="text-gray-900 dark:text-white">{filteredDashcamImages().length}</strong> {filteredDashcamImages().length === 1 ? 'imagen' : 'imágenes'}
                                            </p>
                                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                                                {filteredDashcamImages().map((image, index) => (
                                                    <div
                                                        key={image.id}
                                                        className="group relative aspect-video cursor-pointer overflow-hidden rounded-lg bg-gray-100 shadow-sm transition-transform hover:scale-105 dark:bg-gray-800"
                                                        onClick={() => setSelectedImageIndex(index)}
                                                    >
                                                        <img
                                                            src={image.url}
                                                            alt={image.typeDescription}
                                                            className="h-full w-full object-cover"
                                                            loading="lazy"
                                                        />
                                                        <div className="absolute inset-0 bg-gradient-to-t from-black/60 via-black/0 to-black/0 opacity-0 transition-opacity group-hover:opacity-100">
                                                            <div className="absolute bottom-0 left-0 right-0 px-2 py-2">
                                                                <p className="text-xs font-medium text-white truncate">
                                                                    {image.typeDescription}
                                                                </p>
                                                                <p className="text-xs text-white/80">
                                                                    {formatTimestamp(image.timestamp)}
                                                                </p>
                                                            </div>
                                                        </div>
                                                        <div className="absolute top-2 right-2 rounded-full bg-black/50 px-1.5 py-0.5">
                                                            <span className="text-xs text-white">
                                                                {image.type === 'dashcamRoadFacing' ? '🚗' : '👤'}
                                                            </span>
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        </>
                                    ) : (
                                        <div className="rounded-lg bg-gray-50 px-4 py-6 text-center dark:bg-gray-800">
                                            <Video className="mx-auto size-8 text-gray-400" />
                                            <p className="mt-2 text-sm text-gray-500">
                                                No hay imágenes de este tipo en el período especificado.
                                            </p>
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <div className="rounded-lg bg-gray-50 px-4 py-6 text-center dark:bg-gray-800">
                                    <Video className="mx-auto size-8 text-gray-400" />
                                    <p className="mt-2 text-sm text-gray-500">
                                        No se encontraron imágenes o videos en el período especificado.
                                    </p>
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {/* Notes Section - Full Width */}
                {summary.notes.length > 0 && (
                    <div className="mt-6 overflow-hidden rounded-lg border border-yellow-200 bg-gradient-to-r from-yellow-50 to-amber-50 p-4 shadow-sm dark:border-yellow-900/30 dark:from-yellow-950/20 dark:to-amber-950/20">
                        <div className="flex items-center gap-2 mb-3">
                            <AlertTriangle className="size-5 text-yellow-600 dark:text-yellow-400" />
                            <h4 className="text-sm font-semibold text-yellow-800 dark:text-yellow-400">Notas Adicionales</h4>
                        </div>
                        <ul className="space-y-2">
                            {summary.notes.map((note, idx) => (
                                <li key={idx} className="flex items-start gap-2 text-sm text-yellow-700 dark:text-yellow-300">
                                    <span className="mt-0.5 text-yellow-500">•</span>
                                    <span>{note}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>

            {/* Lightbox Modal for Images */}
            {selectedImageIndex !== null && filteredDashcamImages()[selectedImageIndex] && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/90 p-4"
                    onClick={() => setSelectedImageIndex(null)}
                    onKeyDown={handleKeyDown}
                    tabIndex={0}
                >
                    {/* Close button */}
                    <button
                        className="absolute right-4 top-4 rounded-full bg-white/10 p-2 text-white transition-colors hover:bg-white/20 z-10"
                        onClick={(e) => {
                            e.stopPropagation();
                            setSelectedImageIndex(null);
                        }}
                    >
                        <X className="size-6" />
                    </button>

                    {/* Navigation - Previous */}
                    {selectedImageIndex > 0 && (
                        <button
                            className="absolute left-4 rounded-full bg-white/10 p-3 text-white transition-colors hover:bg-white/20 z-10"
                            onClick={(e) => {
                                e.stopPropagation();
                                handlePrevImage();
                            }}
                        >
                            <ChevronLeft className="size-6" />
                        </button>
                    )}

                    {/* Main Image */}
                    <div
                        className="relative max-h-[85vh] max-w-[90vw]"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <img
                            src={filteredDashcamImages()[selectedImageIndex].url}
                            alt={filteredDashcamImages()[selectedImageIndex].typeDescription}
                            className="max-h-[85vh] max-w-[90vw] rounded-lg object-contain shadow-2xl"
                        />
                        {/* Image info overlay */}
                        <div className="absolute inset-x-0 bottom-0 rounded-b-lg bg-gradient-to-t from-black/80 to-transparent p-4">
                            <div className="flex items-center justify-between">
                                <div>
                                    <div className="flex items-center gap-2 text-white">
                                        {filteredDashcamImages()[selectedImageIndex].type === 'dashcamRoadFacing' ? (
                                            <Car className="size-4" />
                                        ) : (
                                            <User className="size-4" />
                                        )}
                                        <span className="font-medium">
                                            {filteredDashcamImages()[selectedImageIndex].typeDescription}
                                        </span>
                                    </div>
                                    <div className="mt-1 flex items-center gap-1 text-sm text-gray-300">
                                        <Clock className="size-3" />
                                        {formatTimestamp(filteredDashcamImages()[selectedImageIndex].timestamp)}
                                    </div>
                                </div>
                                <a
                                    href={filteredDashcamImages()[selectedImageIndex].url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="flex items-center gap-1.5 rounded-lg bg-white/20 px-3 py-2 text-sm font-medium text-white transition-colors hover:bg-white/30"
                                    onClick={(e) => e.stopPropagation()}
                                >
                                    <ExternalLink className="size-4" />
                                    Abrir
                                </a>
                            </div>
                            {/* Image counter */}
                            <div className="mt-2 text-center text-sm text-gray-400">
                                {selectedImageIndex + 1} / {filteredDashcamImages().length}
                            </div>
                        </div>
                    </div>

                    {/* Navigation - Next */}
                    {selectedImageIndex < filteredDashcamImages().length - 1 && (
                        <button
                            className="absolute right-4 rounded-full bg-white/10 p-3 text-white transition-colors hover:bg-white/20 z-10"
                            onClick={(e) => {
                                e.stopPropagation();
                                handleNextImage();
                            }}
                        >
                            <ChevronRight className="size-6" />
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}

