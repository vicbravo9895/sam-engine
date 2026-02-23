import { useEffect, useState } from 'react';

interface LeafletComponents {
    MapContainer: React.ComponentType<any> | null;
    TileLayer: React.ComponentType<any> | null;
    Marker: React.ComponentType<any> | null;
    Popup: React.ComponentType<any> | null;
    ready: boolean;
}

let leafletPromise: Promise<void> | null = null;
let cachedComponents: Omit<LeafletComponents, 'ready'> | null = null;

function loadLeaflet(): Promise<void> {
    if (!leafletPromise) {
        leafletPromise = Promise.all([
            import('react-leaflet'),
            import('leaflet/dist/leaflet.css'),
            import('leaflet'),
        ]).then(([mod, , L]) => {
            // @ts-ignore
            delete L.Icon.Default.prototype._getIconUrl;
            L.Icon.Default.mergeOptions({
                iconRetinaUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-icon-2x.png',
                iconUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-icon.png',
                shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-shadow.png',
            });
            cachedComponents = {
                MapContainer: mod.MapContainer,
                TileLayer: mod.TileLayer,
                Marker: mod.Marker,
                Popup: mod.Popup,
            };
        });
    }
    return leafletPromise;
}

/**
 * Lazy-loads Leaflet + react-leaflet with module-level caching.
 * First call triggers the import; subsequent calls return the cached modules instantly.
 */
export function useLeaflet(enabled = true): LeafletComponents {
    const [ready, setReady] = useState(!!cachedComponents);

    useEffect(() => {
        if (!enabled || cachedComponents) {
            if (cachedComponents) setReady(true);
            return;
        }
        loadLeaflet().then(() => setReady(true));
    }, [enabled]);

    if (!ready || !cachedComponents) {
        return { MapContainer: null, TileLayer: null, Marker: null, Popup: null, ready: false };
    }

    return { ...cachedComponents, ready: true };
}
