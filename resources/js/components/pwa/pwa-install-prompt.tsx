import { usePWA } from '@/hooks/use-pwa';
import { Download, Share, X } from 'lucide-react';
import { useEffect, useState } from 'react';

export function PWAInstallPrompt() {
    const { isInstallable, isIOS, isInstalled, installApp, dismissInstall } = usePWA();
    const [isVisible, setIsVisible] = useState(false);
    const [isDismissed, setIsDismissed] = useState(false);

    useEffect(() => {
        // No mostrar si ya está instalada o fue descartada
        if (isInstalled || isDismissed) {
            setIsVisible(false);
            return;
        }

        // Verificar si el usuario ya descartó anteriormente
        const dismissed = localStorage.getItem('pwa-install-dismissed');
        if (dismissed) {
            const dismissedTime = parseInt(dismissed, 10);
            const daysSinceDismissed = (Date.now() - dismissedTime) / (1000 * 60 * 60 * 24);

            // Volver a mostrar después de 7 días
            if (daysSinceDismissed < 7) {
                setIsDismissed(true);
                return;
            }
        }

        // Mostrar el prompt después de un delay
        const showDelay = setTimeout(() => {
            if (isInstallable || isIOS) {
                setIsVisible(true);
            }
        }, 5000); // 5 segundos después de cargar

        return () => clearTimeout(showDelay);
    }, [isInstallable, isIOS, isInstalled, isDismissed]);

    const handleInstall = async () => {
        const success = await installApp();
        if (success) {
            setIsVisible(false);
        }
    };

    const handleDismiss = () => {
        setIsVisible(false);
        setIsDismissed(true);
        dismissInstall();
        localStorage.setItem('pwa-install-dismissed', Date.now().toString());
    };

    if (!isVisible) return null;

    return (
        <div className="animate-in slide-in-from-bottom-4 fixed bottom-4 left-4 right-4 z-50 mx-auto max-w-md md:left-auto md:right-4">
            <div className="rounded-2xl border border-white/10 bg-zinc-900/95 p-4 shadow-2xl backdrop-blur-xl">
                <button
                    onClick={handleDismiss}
                    className="absolute right-3 top-3 rounded-lg p-1.5 text-zinc-400 transition-colors hover:bg-white/10 hover:text-white"
                    aria-label="Cerrar"
                >
                    <X className="h-4 w-4" />
                </button>

                <div className="flex items-start gap-4">
                    <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 shadow-lg">
                        <img src="/logo.png" alt="Fleet Copilot" className="h-7 w-7" />
                    </div>

                    <div className="min-w-0 flex-1 pr-6">
                        <h3 className="text-base font-semibold text-white">Instalar Fleet Copilot</h3>
                        <p className="mt-1 text-sm text-zinc-400">Acceso rápido desde tu pantalla de inicio con mejor rendimiento.</p>
                    </div>
                </div>

                {isIOS ? (
                    <div className="mt-4 rounded-xl bg-white/5 p-3">
                        <p className="flex items-center gap-2 text-sm text-zinc-300">
                            <Share className="h-4 w-4 text-indigo-400" />
                            <span>
                                Toca el botón <strong>Compartir</strong> y luego <strong>&quot;Agregar a pantalla de inicio&quot;</strong>
                            </span>
                        </p>
                    </div>
                ) : (
                    <div className="mt-4 flex gap-2">
                        <button
                            onClick={handleDismiss}
                            className="flex-1 rounded-xl border border-white/10 bg-white/5 px-4 py-2.5 text-sm font-medium text-zinc-300 transition-colors hover:bg-white/10"
                        >
                            Ahora no
                        </button>
                        <button
                            onClick={handleInstall}
                            className="flex flex-1 items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-600 px-4 py-2.5 text-sm font-medium text-white shadow-lg transition-all hover:shadow-indigo-500/25"
                        >
                            <Download className="h-4 w-4" />
                            Instalar
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

