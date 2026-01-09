import { useEffect, useState } from 'react';

const MOBILE_BREAKPOINT = 768;

/**
 * Hook SSR-safe para detectar si estamos en un dispositivo móvil.
 * Usa useState/useEffect en lugar de useSyncExternalStore para evitar
 * problemas con window.matchMedia en SSR y en la primera carga de PWA.
 */
export function useIsMobile(): boolean {
    // Estado inicial: false en servidor, valor real en cliente
    // Usamos una función para evitar ejecutar matchMedia en SSR
    const [isMobile, setIsMobile] = useState<boolean>(() => {
        // Durante SSR o primera carga, window no está disponible
        if (typeof window === 'undefined') {
            return false;
        }
        return window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT - 1}px)`).matches;
    });

    useEffect(() => {
        // Verificación adicional para SSR
        if (typeof window === 'undefined') {
            return;
        }

        const mql = window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT - 1}px)`);
        
        // Sincronizar estado inicial en caso de que haya discrepancia
        // (importante para PWA donde el estado inicial puede ser diferente)
        setIsMobile(mql.matches);

        // Handler para cambios de media query
        const handleChange = (event: MediaQueryListEvent) => {
            setIsMobile(event.matches);
        };

        // Usar addEventListener (más moderno y compatible)
        mql.addEventListener('change', handleChange);

        return () => {
            mql.removeEventListener('change', handleChange);
        };
    }, []);

    return isMobile;
}
