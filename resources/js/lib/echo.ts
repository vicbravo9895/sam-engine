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

// Make Pusher available globally (required by laravel-echo)
declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo: Echo<'reverb'>;
    }
}
window.Pusher = Pusher;

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
 * Configuration is read from Vite environment variables:
 * - VITE_REVERB_APP_KEY: App key for authentication
 * - VITE_REVERB_HOST: WebSocket server hostname
 * - VITE_REVERB_PORT: WebSocket server port
 * - VITE_REVERB_SCHEME: http or https
 */
const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY as string,
    wsHost: import.meta.env.VITE_REVERB_HOST as string,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT) || 80,
    wssPort: Number(import.meta.env.VITE_REVERB_PORT) || 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
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
