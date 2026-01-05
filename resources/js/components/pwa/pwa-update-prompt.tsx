import { usePWA } from '@/hooks/use-pwa';
import { Loader2, RefreshCw, X } from 'lucide-react';
import { useState } from 'react';

export function PWAUpdatePrompt() {
    const { hasUpdate, updateApp, isUpdating } = usePWA();
    const [isDismissed, setIsDismissed] = useState(false);

    if (!hasUpdate || isDismissed) return null;

    return (
        <div className="animate-in slide-in-from-top-4 fixed left-4 right-4 top-4 z-50 mx-auto max-w-md md:left-auto md:right-4">
            <div className="rounded-2xl border border-emerald-500/20 bg-zinc-900/95 p-4 shadow-2xl backdrop-blur-xl">
                <button
                    onClick={() => setIsDismissed(true)}
                    className="absolute right-3 top-3 rounded-lg p-1.5 text-zinc-400 transition-colors hover:bg-white/10 hover:text-white"
                    aria-label="Cerrar"
                    disabled={isUpdating}
                >
                    <X className="h-4 w-4" />
                </button>

                <div className="flex items-start gap-4">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500/20">
                        {isUpdating ? (
                            <Loader2 className="h-5 w-5 animate-spin text-emerald-400" />
                        ) : (
                            <RefreshCw className="h-5 w-5 text-emerald-400" />
                        )}
                    </div>

                    <div className="min-w-0 flex-1 pr-6">
                        <h3 className="text-base font-semibold text-white">
                            {isUpdating ? 'Actualizando...' : 'Nueva versión disponible'}
                        </h3>
                        <p className="mt-1 text-sm text-zinc-400">
                            {isUpdating
                                ? 'La página se recargará automáticamente.'
                                : 'Hay mejoras y correcciones disponibles.'}
                        </p>
                    </div>
                </div>

                {!isUpdating && (
                    <div className="mt-4 flex gap-2">
                        <button
                            onClick={() => setIsDismissed(true)}
                            className="flex-1 rounded-xl border border-white/10 bg-white/5 px-4 py-2.5 text-sm font-medium text-zinc-300 transition-colors hover:bg-white/10"
                        >
                            Después
                        </button>
                        <button
                            onClick={updateApp}
                            className="flex flex-1 items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-600 px-4 py-2.5 text-sm font-medium text-white shadow-lg transition-all hover:shadow-emerald-500/25"
                        >
                            <RefreshCw className="h-4 w-4" />
                            Actualizar
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}
