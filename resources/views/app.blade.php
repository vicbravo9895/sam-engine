<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Reverb WebSocket config from server (runtime); same build works in any environment --}}
        <script>
            window.__REVERB_CONFIG__ = {
                key: @json(config('broadcasting.connections.reverb.key')),
                host: @json(env('REVERB_HOST', 'localhost')),
                port: {{ (int) (env('REVERB_PORT') ?: 443) }},
                scheme: @json(env('REVERB_SCHEME', 'https')),
            };
        </script>

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <title inertia>{{ config('app.name', 'SAM') }}</title>

        <meta name="description" content="SAM - Sistema Automatizado de Monitoreo. Sistema inteligente de gestión de flotas con IA y procesamiento de alertas.">
        <meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'SAM') }}">
        <meta name="application-name" content="{{ config('app.name', 'SAM') }}">
        <meta name="theme-color" content="#0a0a0a">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

        {{-- Favicon & PWA Icons --}}
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="apple-touch-icon" sizes="152x152" href="/icons/icon-152x152.png">
        <link rel="apple-touch-icon" sizes="180x180" href="/icons/icon-180x180.png">
        <link rel="apple-touch-icon" sizes="167x167" href="/icons/icon-167x167.png">

        {{-- PWA Manifest --}}
        <link rel="manifest" href="/manifest.webmanifest">

        {{-- Apple Splash Screens (generados para diferentes dispositivos) --}}
        <link rel="apple-touch-startup-image" href="/splashscreens/apple-splash-2048-2732.png" media="(device-width: 1024px) and (device-height: 1366px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)">
        <link rel="apple-touch-startup-image" href="/splashscreens/apple-splash-1170-2532.png" media="(device-width: 390px) and (device-height: 844px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)">
        <link rel="apple-touch-startup-image" href="/splashscreens/apple-splash-1284-2778.png" media="(device-width: 428px) and (device-height: 926px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600|manrope:400,500,600,700,800|jetbrains-mono:400,500,600" rel="stylesheet" />

        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia

        {{-- Service Worker Registration --}}
        <script>
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', () => {
                    navigator.serviceWorker.register('/sw.js')
                        .then((registration) => {
                            console.log('[PWA] Service Worker registered:', registration.scope);
                            
                            // Escuchar actualizaciones
                            registration.addEventListener('updatefound', () => {
                                const newWorker = registration.installing;
                                if (newWorker) {
                                    newWorker.addEventListener('statechange', () => {
                                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                                            // Nuevo service worker disponible
                                            window.dispatchEvent(new CustomEvent('pwa-update-available', {
                                                detail: { registration }
                                            }));
                                        }
                                    });
                                }
                            });
                        })
                        .catch((error) => {
                            console.log('[PWA] Service Worker registration failed:', error);
                        });
                });

                // Manejar el evento beforeinstallprompt
                let deferredPrompt;
                window.addEventListener('beforeinstallprompt', (e) => {
                    e.preventDefault();
                    deferredPrompt = e;
                    window.deferredPWAPrompt = e;
                    window.dispatchEvent(new CustomEvent('pwa-installable'));
                });
            }
        </script>
    </body>
</html>
