import { useCallback, useEffect, useState } from 'react';

interface BeforeInstallPromptEvent extends Event {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

interface PWAState {
    isInstallable: boolean;
    isInstalled: boolean;
    isOnline: boolean;
    hasUpdate: boolean;
    isUpdating: boolean;
    isIOS: boolean;
    isStandalone: boolean;
}

interface UsePWAReturn extends PWAState {
    installApp: () => Promise<boolean>;
    updateApp: () => void;
    dismissInstall: () => void;
}

declare global {
    interface Window {
        deferredPWAPrompt?: BeforeInstallPromptEvent;
    }
}

export function usePWA(): UsePWAReturn {
    const [state, setState] = useState<PWAState>({
        isInstallable: false,
        isInstalled: false,
        isOnline: typeof navigator !== 'undefined' ? navigator.onLine : true,
        hasUpdate: false,
        isUpdating: false,
        isIOS: false,
        isStandalone: false,
    });

    const [deferredPrompt, setDeferredPrompt] = useState<BeforeInstallPromptEvent | null>(null);
    const [waitingWorker, setWaitingWorker] = useState<ServiceWorker | null>(null);

    useEffect(() => {
        if (typeof window === 'undefined') return;

        // Detectar iOS
        const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !(window as { MSStream?: unknown }).MSStream;

        // Detectar si está instalada
        const isStandalone =
            window.matchMedia('(display-mode: standalone)').matches ||
            (navigator as { standalone?: boolean }).standalone === true;

        setState((prev) => ({
            ...prev,
            isIOS,
            isStandalone,
            isInstalled: isStandalone,
        }));

        // Verificar si ya hay un prompt guardado
        if (window.deferredPWAPrompt) {
            setDeferredPrompt(window.deferredPWAPrompt);
            setState((prev) => ({ ...prev, isInstallable: true }));
        }

        // Escuchar evento de instalación disponible
        const handleInstallable = () => {
            if (window.deferredPWAPrompt) {
                setDeferredPrompt(window.deferredPWAPrompt);
                setState((prev) => ({ ...prev, isInstallable: true }));
            }
        };

        // Escuchar evento de actualización disponible
        const handleUpdateAvailable = (event: CustomEvent<{ registration: ServiceWorkerRegistration }>) => {
            const reg = event.detail.registration;
            if (reg?.waiting) {
                setWaitingWorker(reg.waiting);
                setState((prev) => ({ ...prev, hasUpdate: true }));
            }
        };

        // Detectar cambios de conexión
        const handleOnline = () => {
            setState((prev) => ({ ...prev, isOnline: true }));
        };

        const handleOffline = () => {
            setState((prev) => ({ ...prev, isOnline: false }));
        };

        window.addEventListener('pwa-installable', handleInstallable);
        window.addEventListener('pwa-update-available', handleUpdateAvailable as EventListener);
        window.addEventListener('online', handleOnline);
        window.addEventListener('offline', handleOffline);

        // Verificar si ya hay un SW waiting al cargar
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.ready.then((registration) => {
                if (registration.waiting) {
                    setWaitingWorker(registration.waiting);
                    setState((prev) => ({ ...prev, hasUpdate: true }));
                }

                // Escuchar por nuevas actualizaciones
                registration.addEventListener('updatefound', () => {
                    const newWorker = registration.installing;
                    if (newWorker) {
                        newWorker.addEventListener('statechange', () => {
                            if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                                setWaitingWorker(newWorker);
                                setState((prev) => ({ ...prev, hasUpdate: true }));
                            }
                        });
                    }
                });
            });
        }

        return () => {
            window.removeEventListener('pwa-installable', handleInstallable);
            window.removeEventListener('pwa-update-available', handleUpdateAvailable as EventListener);
            window.removeEventListener('online', handleOnline);
            window.removeEventListener('offline', handleOffline);
        };
    }, []);

    const installApp = useCallback(async (): Promise<boolean> => {
        if (!deferredPrompt) {
            console.log('[PWA] No install prompt available');
            return false;
        }

        try {
            await deferredPrompt.prompt();
            const choiceResult = await deferredPrompt.userChoice;

            if (choiceResult.outcome === 'accepted') {
                console.log('[PWA] User accepted the install prompt');
                setState((prev) => ({ ...prev, isInstallable: false, isInstalled: true }));
                setDeferredPrompt(null);
                window.deferredPWAPrompt = undefined;
                return true;
            } else {
                console.log('[PWA] User dismissed the install prompt');
                return false;
            }
        } catch (error) {
            console.error('[PWA] Error during installation:', error);
            return false;
        }
    }, [deferredPrompt]);

    const updateApp = useCallback(() => {
        setState((prev) => ({ ...prev, isUpdating: true }));

        // Si tenemos un waiting worker guardado, usarlo
        if (waitingWorker) {
            console.log('[PWA] Sending SKIP_WAITING to waiting worker');
            waitingWorker.postMessage({ type: 'SKIP_WAITING' });
        } else {
            // Intentar obtener el waiting worker directamente
            console.log('[PWA] No cached waiting worker, checking registration...');
            navigator.serviceWorker.ready.then((registration) => {
                if (registration.waiting) {
                    console.log('[PWA] Found waiting worker in registration');
                    registration.waiting.postMessage({ type: 'SKIP_WAITING' });
                } else {
                    // No hay waiting worker - forzar recarga para obtener nueva versión
                    console.log('[PWA] No waiting worker found, forcing reload');
                    window.location.reload();
                }
            });
        }

        // Escuchar cuando el nuevo SW tome control y recargar
        const handleControllerChange = () => {
            console.log('[PWA] Controller changed, reloading...');
            window.location.reload();
        };

        navigator.serviceWorker.addEventListener('controllerchange', handleControllerChange);

        // Timeout de seguridad: si no recarga en 3 segundos, forzar recarga
        setTimeout(() => {
            console.log('[PWA] Timeout reached, forcing reload');
            window.location.reload();
        }, 3000);
    }, [waitingWorker]);

    const dismissInstall = useCallback(() => {
        setState((prev) => ({ ...prev, isInstallable: false }));
        setDeferredPrompt(null);
        window.deferredPWAPrompt = undefined;
    }, []);

    return {
        ...state,
        installApp,
        updateApp,
        dismissInstall,
    };
}
