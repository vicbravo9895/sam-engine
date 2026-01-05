import { usePWA } from '@/hooks/use-pwa';
import { WifiOff } from 'lucide-react';

export function OfflineIndicator() {
    const { isOnline } = usePWA();

    if (isOnline) return null;

    return (
        <div className="animate-in slide-in-from-top-2 fixed left-0 right-0 top-0 z-[100] bg-amber-500 py-2 text-center text-sm font-medium text-amber-950">
            <div className="flex items-center justify-center gap-2">
                <WifiOff className="h-4 w-4" />
                <span>Sin conexión a internet</span>
            </div>
        </div>
    );
}

