import '../css/app.css';

import { FlashMessages } from '@/components/flash-messages';
import { OfflineIndicator } from '@/components/pwa/offline-indicator';
import { PWAInstallPrompt } from '@/components/pwa/pwa-install-prompt';
import { PWAUpdatePrompt } from '@/components/pwa/pwa-update-prompt';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { initializeTheme } from './hooks/use-appearance';

const appName = import.meta.env.VITE_APP_NAME || 'SAM';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.tsx`,
            import.meta.glob('./pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <StrictMode>
                <OfflineIndicator />
                <PWAUpdatePrompt />
                <App {...props} />
                <PWAInstallPrompt />
                <FlashMessages />
            </StrictMode>,
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
