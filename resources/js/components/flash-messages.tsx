import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { toast, Toaster } from 'sonner';

interface FlashData {
    success?: string;
    error?: string;
}

export function FlashMessages() {
    const [theme, setTheme] = useState<'light' | 'dark'>('light');
    const lastFlashRef = useRef<string | null>(null);

    // Detect theme from document class
    useEffect(() => {
        const updateTheme = () => {
            const isDark = document.documentElement.classList.contains('dark');
            setTheme(isDark ? 'dark' : 'light');
        };

        updateTheme();

        // Watch for theme changes
        const observer = new MutationObserver(updateTheme);
        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        });

        return () => observer.disconnect();
    }, []);

    // Listen to Inertia navigation events to get flash messages
    useEffect(() => {
        const showFlash = (flash: FlashData | undefined) => {
            if (!flash) return;

            // Create a unique key for this flash to avoid duplicates
            const flashKey = JSON.stringify(flash);
            if (flashKey === lastFlashRef.current) return;
            lastFlashRef.current = flashKey;

            if (flash.success) {
                toast.success(flash.success);
            }
            if (flash.error) {
                toast.error(flash.error);
            }
        };

        // Check initial flash messages from the page
        const checkInitialFlash = () => {
            try {
                const pageElement = document.getElementById('app');
                if (pageElement) {
                    const dataPage = pageElement.getAttribute('data-page');
                    if (dataPage) {
                        const pageData = JSON.parse(dataPage);
                        showFlash(pageData.props?.flash);
                    }
                }
            } catch {
                // Ignore parsing errors
            }
        };

        // Check on mount
        checkInitialFlash();

        // Listen to Inertia navigation success events
        const removeListener = router.on('success', (event) => {
            const flash = event.detail.page.props.flash as FlashData | undefined;
            showFlash(flash);
        });

        return () => {
            removeListener();
        };
    }, []);

    return (
        <Toaster
            theme={theme}
            position="top-right"
            richColors
            closeButton
            toastOptions={{
                duration: 5000,
            }}
        />
    );
}

