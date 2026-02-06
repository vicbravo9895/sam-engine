/**
 * Laravel Echo configuration for WebSocket communication.
 *
 * This sets up a singleton Echo instance configured for Laravel Reverb.
 * Reverb uses the Pusher protocol, so we use pusher-js as the transport.
 *
 * Usage:
 *   import echo from '@/lib/echo';
 *   echo.private('channel-name').listen('.event-name', callback);
 *
 * For React components, prefer using the usePrivateChannel hook from @/hooks/use-echo.
 */

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Runtime config injected by Laravel (app.blade.php); avoids build-time env in production
export interface ReverbConfig {
    key: string;
    host: string;
    port: number;
    scheme: string;
}

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo: Echo<'reverb'>;
        __REVERB_CONFIG__?: ReverbConfig;
    }
}
window.Pusher = Pusher;

function getReverbConfig(): ReverbConfig {
    const fromServer = typeof window !== 'undefined' ? window.__REVERB_CONFIG__ : undefined;
    if (fromServer?.key && fromServer?.host) {
        return fromServer;
    }
    return {
        key: (import.meta.env.VITE_REVERB_APP_KEY as string) ?? '',
        host: (import.meta.env.VITE_REVERB_HOST as string) ?? 'localhost',
        port: Number(import.meta.env.VITE_REVERB_PORT) || 443,
        scheme: (import.meta.env.VITE_REVERB_SCHEME as string) ?? 'https',
    };
}

/**
 * Helper to get CSRF token for channel authorization.
 * Tries meta tag first, then XSRF-TOKEN cookie.
 */
function getCsrfToken(): string {
    // Try meta tag first
    const metaToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
    if (metaToken) return metaToken;

    // Fallback to cookie
    const cookieMatch = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    if (cookieMatch) return decodeURIComponent(cookieMatch[1]);

    return '';
}

/**
 * Laravel Echo instance configured for Reverb WebSocket server.
 *
 * Prefers window.__REVERB_CONFIG__ (injected by Laravel at runtime) so the same
 * build works in production without rebuilding for VITE_* env. Falls back to
 * VITE_REVERB_* when not in a Laravel-rendered page (e.g. dev HMR).
 */
const reverb = getReverbConfig();
const echo = new Echo({
    broadcaster: 'reverb',
    key: reverb.key,
    wsHost: reverb.host,
    wsPort: reverb.port || 80,
    wssPort: reverb.port || 443,
    forceTLS: reverb.scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    // Include CSRF token in authorization requests
    authEndpoint: '/broadcasting/auth',
    auth: {
        headers: {
            'X-CSRF-TOKEN': getCsrfToken(),
        },
    },
});

// Make Echo available globally for debugging
window.Echo = echo;

export default echo;

/**
 * Type definitions for Reverb/Pusher channel events.
 */
export interface ChannelSubscription {
    listen<T = unknown>(event: string, callback: (data: T) => void): ChannelSubscription;
    stopListening(event: string): ChannelSubscription;
    error(callback: (error: unknown) => void): ChannelSubscription;
}

/**
 * Subscribe to a private channel.
 * Wrapper around echo.private() with proper typing.
 */
export function subscribeToPrivateChannel(channelName: string): ChannelSubscription {
    return echo.private(channelName) as unknown as ChannelSubscription;
}

/**
 * Leave a private channel.
 */
export function leaveChannel(channelName: string): void {
    echo.leave(channelName);
}
