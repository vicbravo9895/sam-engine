import { cn } from '@/lib/utils';
import { ExternalLink, MapPin, Navigation, Timer } from 'lucide-react';
import { useEffect, useState } from 'react';

interface LocationCardProps {
    data: {
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
    };
}

export function LocationCard({ data }: LocationCardProps) {
    const [MapComponent, setMapComponent] = useState<React.ComponentType<any> | null>(null);
    const [TileLayerComponent, setTileLayerComponent] = useState<React.ComponentType<any> | null>(null);
    const [MarkerComponent, setMarkerComponent] = useState<React.ComponentType<any> | null>(null);
    const [PopupComponent, setPopupComponent] = useState<React.ComponentType<any> | null>(null);

    // Dynamically import Leaflet components (SSR safe)
    useEffect(() => {
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
    }, []);

    const formatTimestamp = (timestamp: string) => {
        const date = new Date(timestamp);
        return date.toLocaleString('es-MX', {
            hour: '2-digit',
            minute: '2-digit',
            day: '2-digit',
            month: 'short',
        });
    };

    const getSpeedColor = (speed: number) => {
        if (speed === 0) return 'text-gray-500';
        if (speed < 30) return 'text-yellow-500';
        if (speed < 80) return 'text-green-500';
        return 'text-red-500';
    };

    return (
        <div className="my-3 overflow-hidden rounded-xl border bg-gradient-to-br from-blue-50/50 to-cyan-50/50 dark:from-blue-950/20 dark:to-cyan-950/20">
            {/* Header */}
            <div className="flex items-center justify-between border-b bg-white/50 px-4 py-3 dark:bg-black/20">
                <div className="flex items-center gap-3">
                    <div className="flex size-10 items-center justify-center rounded-full bg-blue-500 text-white">
                        <MapPin className="size-5" />
                    </div>
                    <div>
                        <h3 className="font-semibold text-gray-900 dark:text-white">
                            {data.vehicleName}
                        </h3>
                        <p className="text-xs text-gray-500">
                            {data.make} {data.model} {data.licensePlate && `• ${data.licensePlate}`}
                        </p>
                    </div>
                </div>
                <a
                    href={data.mapsLink}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex items-center gap-1.5 rounded-lg bg-blue-500 px-3 py-1.5 text-sm font-medium text-white transition-colors hover:bg-blue-600"
                >
                    <ExternalLink className="size-4" />
                    Google Maps
                </a>
            </div>

            {/* Map */}
            <div className="relative h-48 w-full bg-gray-100 dark:bg-gray-800">
                {MapComponent && TileLayerComponent && MarkerComponent && PopupComponent ? (
                    <MapComponent
                        center={[data.lat, data.lng]}
                        zoom={15}
                        style={{ height: '100%', width: '100%' }}
                        scrollWheelZoom={false}
                    >
                        <TileLayerComponent
                            attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
                            url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                        />
                        <MarkerComponent position={[data.lat, data.lng]}>
                            <PopupComponent>
                                <strong>{data.vehicleName}</strong>
                                <br />
                                {data.locationName}
                            </PopupComponent>
                        </MarkerComponent>
                    </MapComponent>
                ) : (
                    <div className="flex h-full items-center justify-center">
                        <div className="text-gray-400">Cargando mapa...</div>
                    </div>
                )}
            </div>

            {/* Info Footer */}
            <div className="grid grid-cols-3 divide-x bg-white/70 dark:bg-black/30">
                {/* Location */}
                <div className="px-4 py-3">
                    <div className="flex items-center gap-1.5 text-xs text-gray-500">
                        <MapPin className="size-3" />
                        Ubicación
                    </div>
                    <p className="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                        {data.locationName}
                    </p>
                    {data.isGeofence && (
                        <span className="mt-1 inline-block rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-700 dark:bg-green-900/30 dark:text-green-400">
                            📍 Geofence
                        </span>
                    )}
                </div>

                {/* Speed */}
                <div className="px-4 py-3">
                    <div className="flex items-center gap-1.5 text-xs text-gray-500">
                        <Navigation className="size-3" />
                        Velocidad
                    </div>
                    <p className={cn('mt-1 text-lg font-bold', getSpeedColor(data.speedKmh))}>
                        {data.speedKmh} km/h
                    </p>
                </div>

                {/* Time */}
                <div className="px-4 py-3">
                    <div className="flex items-center gap-1.5 text-xs text-gray-500">
                        <Timer className="size-3" />
                        Actualizado
                    </div>
                    <p className="mt-1 text-sm font-medium text-gray-900 dark:text-white">
                        {formatTimestamp(data.timestamp)}
                    </p>
                </div>
            </div>
        </div>
    );
}

