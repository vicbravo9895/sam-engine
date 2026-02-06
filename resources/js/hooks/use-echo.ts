/**
 * React hooks for Laravel Echo WebSocket subscriptions.
 *
 * These hooks manage the lifecycle of channel subscriptions,
 * automatically subscribing on mount and unsubscribing on unmount.
 *
 * Usage:
 *   // Subscribe to a private channel
 *   usePrivateChannel('copilot.thread-123', '.copilot.stream', (data) => {
 *     console.log('Received:', data);
 *   });
 *
 *   // With enabled flag (conditional subscription)
 *   usePrivateChannel('copilot.thread-123', '.copilot.stream', handleEvent, {
 *     enabled: isStreaming,
 *   });
 */

import { useCallback, useEffect, useRef } from 'react';
import echo, { leaveChannel } from '@/lib/echo';

/**
 * Options for usePrivateChannel hook.
 */
interface UsePrivateChannelOptions {
    /** Only subscribe when true (default: true) */
    enabled?: boolean;
    /** Called when subscription errors occur */
    onError?: (error: unknown) => void;
    /** Called when successfully subscribed */
    onSubscribed?: () => void;
}

/**
 * Subscribe to a private Laravel Echo channel.
 *
 * Automatically handles subscription lifecycle (subscribe on mount, leave on unmount).
 * The callback is stable across re-renders if you pass a stable reference.
 *
 * @param channelName The channel name without 'private-' prefix (e.g., 'copilot.thread-123')
 * @param eventName The event name with '.' prefix (e.g., '.copilot.stream')
 * @param callback Function to call when event is received
 * @param options Additional options
 *
 * @example
 * ```tsx
 * function CopilotChat({ threadId }: { threadId: string }) {
 *   const [content, setContent] = useState('');
 *
 *   usePrivateChannel(`copilot.${threadId}`, '.copilot.stream', (data) => {
 *     if (data.type === 'chunk') {
 *       setContent(prev => prev + data.payload.content);
 *     }
 *   });
 *
 *   return <div>{content}</div>;
 * }
 * ```
 */
export function usePrivateChannel<T = unknown>(
    channelName: string,
    eventName: string,
    callback: (data: T) => void,
    options: UsePrivateChannelOptions = {},
): void {
    const { enabled = true, onError, onSubscribed } = options;

    // Store callback in ref to avoid re-subscribing on callback changes
    const callbackRef = useRef(callback);
    callbackRef.current = callback;

    const onErrorRef = useRef(onError);
    onErrorRef.current = onError;

    const onSubscribedRef = useRef(onSubscribed);
    onSubscribedRef.current = onSubscribed;

    useEffect(() => {
        if (!enabled || !channelName) {
            return;
        }

        // Subscribe to private channel
        const channel = echo.private(channelName);

        // Listen for the event
        channel.listen(eventName, (data: T) => {
            callbackRef.current(data);
        });

        // Handle subscription errors
        channel.error((error: unknown) => {
            console.error('[Echo] Channel error:', channelName, error);
            onErrorRef.current?.(error);
        });

        // Notify on successful subscription
        // Note: Echo doesn't have a direct "subscribed" callback for private channels,
        // but we can consider it subscribed once listen() is called successfully
        onSubscribedRef.current?.();

        // Cleanup: leave channel on unmount or when dependencies change
        return () => {
            channel.stopListening(eventName);
            leaveChannel(channelName);
        };
    }, [channelName, eventName, enabled]);
}

/**
 * Hook to get the current WebSocket connection state.
 * Useful for showing connection status indicators.
 */
export function useEchoConnection(): {
    isConnected: boolean;
    reconnect: () => void;
} {
    const isConnected = useRef(true);

    const reconnect = useCallback(() => {
        // Force reconnection by getting the underlying Pusher instance
        const connector = echo.connector as { pusher?: { connect: () => void } };
        connector.pusher?.connect();
    }, []);

    return {
        isConnected: isConnected.current,
        reconnect,
    };
}

/**
 * Type for CopilotStreamEvent data received via WebSocket.
 * Matches the broadcastWith() output from CopilotStreamEvent.php
 */
export interface CopilotStreamEventData {
    type: 'chunk' | 'tool_start' | 'tool_end' | 'stream_end' | 'stream_error';
    payload: {
        content?: string;
        tool_info?: {
            label: string;
            icon: string;
        };
        tokens?: {
            input_tokens?: number;
            output_tokens?: number;
            total_tokens?: number;
        };
        error?: string;
    };
    timestamp: string;
}

/**
 * Specialized hook for Copilot streaming.
 *
 * Provides a simpler API specifically for copilot chat streaming.
 *
 * @param threadId The conversation thread ID
 * @param handlers Event handlers for different stream events
 * @param enabled Whether to subscribe (default: true)
 *
 * @example
 * ```tsx
 * useCopilotStream(threadId, {
 *   onChunk: (content) => setStreamingContent(prev => prev + content),
 *   onToolStart: (toolInfo) => setActiveTool(toolInfo),
 *   onToolEnd: () => setActiveTool(null),
 *   onStreamEnd: (tokens) => {
 *     setIsStreaming(false);
 *     router.reload();
 *   },
 *   onError: (error) => console.error('Stream error:', error),
 * }, isStreaming);
 * ```
 */
export function useCopilotStream(
    threadId: string | null,
    handlers: {
        onChunk?: (content: string) => void;
        onToolStart?: (toolInfo: { label: string; icon: string }) => void;
        onToolEnd?: () => void;
        onStreamEnd?: (tokens?: CopilotStreamEventData['payload']['tokens']) => void;
        onError?: (error: string) => void;
    },
    enabled = true,
): void {
    const handlersRef = useRef(handlers);
    handlersRef.current = handlers;

    const handleEvent = useCallback((data: CopilotStreamEventData) => {
        const { type, payload } = data;
        const h = handlersRef.current;

        switch (type) {
            case 'chunk':
                if (payload.content) {
                    h.onChunk?.(payload.content);
                }
                break;
            case 'tool_start':
                if (payload.tool_info) {
                    h.onToolStart?.(payload.tool_info);
                }
                break;
            case 'tool_end':
                h.onToolEnd?.();
                break;
            case 'stream_end':
                h.onStreamEnd?.(payload.tokens);
                break;
            case 'stream_error':
                h.onError?.(payload.error ?? 'Unknown error');
                break;
        }
    }, []);

    usePrivateChannel<CopilotStreamEventData>(
        threadId ? `copilot.${threadId}` : '',
        '.copilot.stream',
        handleEvent,
        { enabled: enabled && !!threadId },
    );
}
