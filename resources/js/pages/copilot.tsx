import {
    send,
    show,
} from '@/actions/App/Http/Controllers/CopilotController';
import { DriverQuickSearch, type DriverOption } from '@/components/copilot/driver-quick-search';
import { TagQuickSearch, type TagOption } from '@/components/copilot/tag-quick-search';
import { VehicleQuickSearch, type VehicleOption } from '@/components/copilot/vehicle-quick-search';
import { MarkdownContent } from '@/components/markdown-content';
import { Button } from '@/components/ui/button';
import { useCopilotStream } from '@/hooks/use-echo';
import CopilotLayout from '@/layouts/copilot-layout';
import { cn } from '@/lib/utils';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    BarChart3,
    Bot,
    Coins,
    Database,
    ExternalLink,
    Lightbulb,
    Loader2,
    Search,
    Send,
    ShieldAlert,
    Sparkles,
    Tag,
    Truck,
    User,
    Users,
} from 'lucide-react';
import { FormEvent, useCallback, useEffect, useRef, useState } from 'react';

interface Message {
    id: number;
    role: 'user' | 'assistant';
    content: string;
    status?: 'pending' | 'streaming' | 'completed' | 'failed';
    created_at: string;
}

interface EventContextPayload {
    event_id: number;
    samsara_event_id?: string | null;
    event_type?: string | null;
    event_description?: string | null;
    severity?: string | null;
    vehicle_id?: string | null;
    vehicle_name?: string | null;
    driver_id?: string | null;
    driver_name?: string | null;
    occurred_at?: string | null;
    location_description?: string | null;
    alert_kind?: string | null;
    ai_status?: string | null;
    verdict?: string | null;
    likelihood?: string | null;
}

interface Conversation {
    id: number;
    thread_id: string;
    title: string;
    created_at: string;
    updated_at: string;
    total_tokens?: number;
    is_streaming?: boolean;
    streaming_content?: string;
    active_tool?: {
        label: string;
        icon: string;
    } | null;
    context_event_id?: number | null;
    context_payload?: EventContextPayload | null;
}

interface CopilotPageProps {
    conversations: Conversation[];
    currentConversation: Conversation | null;
    messages: Message[];
    vehicles: VehicleOption[];
    drivers: DriverOption[];
    tags: TagOption[];
}

// ============================================================================
// Vehicle Picker Integration
// Maps suggestion queries to vehicle picker actions so clicking them opens
// the picker instead of sending a generic message to the LLM.
// ============================================================================

/** Follow-up suggestion queries that should open the vehicle picker */
const VEHICLE_PICKER_QUERIES: Record<string, string | null> = {
    'Ver detalles de un vehículo de esta lista': null,
    'Más detalle de un vehículo específico': null,
    'Imágenes de cámaras de algún vehículo': 'cameras',
    'Cámaras de algún vehículo': 'cameras',
    'Imágenes de dashcam': 'cameras',
};

/** Follow-up suggestion queries that should open the tag picker */
const TAG_PICKER_QUERIES: Record<string, string | null> = {
    'Filtrar por grupo': 'fleet',
};

/** Follow-up suggestion queries that should open the driver picker */
const DRIVER_PICKER_QUERIES: Record<string, string | null> = {};

// ============================================================================
// T5: Event Context Banner
// Shows a compact banner when the copilot session is linked to a specific alert.
// ============================================================================

function EventContextBanner({ context }: { context: EventContextPayload }) {
    const severityColors: Record<string, string> = {
        critical: 'border-red-500/40 bg-red-500/5',
        warning: 'border-amber-500/40 bg-amber-500/5',
        info: 'border-blue-500/40 bg-blue-500/5',
    };

    const severityIcons: Record<string, React.ReactNode> = {
        critical: <ShieldAlert className="size-4 text-red-500" />,
        warning: <AlertTriangle className="size-4 text-amber-500" />,
        info: <AlertTriangle className="size-4 text-blue-500" />,
    };

    const severity = context.severity ?? 'info';
    const colorClass = severityColors[severity] ?? severityColors.info;
    const icon = severityIcons[severity] ?? severityIcons.info;

    return (
        <div className={`flex-shrink-0 border-b px-3 py-2 md:px-6 md:py-2.5 ${colorClass}`}>
            <div className="mx-auto flex max-w-4xl items-center gap-2 md:gap-3">
                <div className="flex-shrink-0">{icon}</div>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                        <span className="text-xs font-medium truncate">
                            {context.event_description ?? context.event_type ?? 'Alerta'}
                        </span>
                        {context.vehicle_name && (
                            <span className="text-muted-foreground text-xs">
                                <Truck className="mr-0.5 inline size-3" />
                                {context.vehicle_name}
                            </span>
                        )}
                        {context.driver_name && (
                            <span className="text-muted-foreground text-xs">
                                <User className="mr-0.5 inline size-3" />
                                {context.driver_name}
                            </span>
                        )}
                    </div>
                </div>
                <a
                    href={`/samsara/alerts/${context.event_id}`}
                    className="text-muted-foreground hover:text-foreground flex-shrink-0 transition-colors"
                    title="Ver detalle de alerta"
                >
                    <ExternalLink className="size-3.5" />
                </a>
            </div>
        </div>
    );
}

// ============================================================================
// Copilot Capabilities — Smart Suggestions & Guided Flows
// Organized by tool capabilities so the UI reflects what the copilot can do.
// Queries are crafted to trigger guided flows (e.g., "¿Cuáles tengo?" so
// the copilot helps the user pick vehicles/tags instead of requiring exact names).
// ============================================================================

interface CopilotSuggestion {
    label: string;
    query: string;
}

interface CopilotCategory {
    icon: React.ComponentType<{ className?: string }>;
    title: string;
    description: string;
    gradient: string;
    iconColor: string;
    suggestions: CopilotSuggestion[];
}

const COPILOT_CATEGORIES: CopilotCategory[] = [
    {
        icon: Activity,
        title: 'Mi Flota',
        description: 'Estado en tiempo real de todos tus vehículos',
        gradient: 'from-blue-500/10 to-cyan-500/10',
        iconColor: 'text-blue-500',
        suggestions: [
            { label: 'Estado actual de mi flota', query: '¿Cuál es el estado actual de mi flota?' },
        ],
    },
    {
        icon: Truck,
        title: 'Vehículo',
        description: 'Ubicación, stats, viajes, cámaras y seguridad',
        gradient: 'from-indigo-500/10 to-purple-500/10',
        iconColor: 'text-indigo-500',
        suggestions: [
            { label: 'Buscar un vehículo', query: '__VEHICLE_PICKER__' },
        ],
    },
    {
        icon: Tag,
        title: 'Grupo',
        description: 'Flota, vehículos y conductores por grupo',
        gradient: 'from-teal-500/10 to-emerald-500/10',
        iconColor: 'text-teal-500',
        suggestions: [
            { label: 'Seleccionar un grupo', query: '__TAG_PICKER__' },
        ],
    },
    {
        icon: Users,
        title: 'Conductor',
        description: 'Info, vehículo asignado y seguridad',
        gradient: 'from-amber-500/10 to-yellow-500/10',
        iconColor: 'text-amber-500',
        suggestions: [
            { label: 'Buscar un conductor', query: '__DRIVER_PICKER__' },
        ],
    },
    {
        icon: BarChart3,
        title: 'Análisis AI',
        description: 'Análisis avanzados con inteligencia artificial',
        gradient: 'from-violet-500/10 to-fuchsia-500/10',
        iconColor: 'text-violet-500',
        suggestions: [
            { label: 'Seguridad de mi flota', query: 'Análisis de seguridad de toda mi flota' },
            { label: 'Riesgo de un conductor', query: '__DRIVER_PICKER_riskAnalysis__' },
            { label: 'Salud de un vehículo', query: '__VEHICLE_PICKER_healthAnalysis__' },
            { label: 'Eficiencia operativa', query: '__VEHICLE_PICKER_efficiencyAnalysis__' },
            { label: 'Detectar anomalías', query: 'Detecta anomalías en mi flota de los últimos 7 días' },
        ],
    },
];

interface QuickAction {
    icon: React.ComponentType<{ className?: string }>;
    label: string;
    query: string;
    color: string;
}

const QUICK_ACTIONS: QuickAction[] = [
    { icon: Activity, label: 'Flota', query: '¿Cuál es el estado actual de mi flota?', color: 'text-blue-500' },
    { icon: Truck, label: 'Vehículo', query: '__VEHICLE_PICKER__', color: 'text-indigo-500' },
    { icon: Tag, label: 'Grupo', query: '__TAG_PICKER__', color: 'text-teal-500' },
    { icon: Users, label: 'Conductor', query: '__DRIVER_PICKER__', color: 'text-amber-500' },
    { icon: BarChart3, label: 'Análisis', query: 'Análisis de seguridad de toda mi flota', color: 'text-violet-500' },
];

/**
 * Returns contextual follow-up suggestions based on the content of the last
 * assistant message. Detects which rich cards were rendered and suggests
 * natural next actions so the user doesn't have to think about what to ask.
 */
function getFollowUpSuggestions(lastMessageContent: string): string[] {
    const content = lastMessageContent;

    if (content.includes(':::fleetStatus')) {
        return [
            'Ver detalles de un vehículo de esta lista',
            'Filtrar por grupo',
            'Imágenes de cámaras de algún vehículo',
        ];
    }

    if (content.includes(':::vehicleStats') || content.includes(':::location')) {
        return [
            'Ver viajes recientes de este vehículo',
            'Imágenes de dashcam de este vehículo',
            '¿Tiene eventos de seguridad recientes?',
        ];
    }

    if (content.includes(':::safetyEvents')) {
        return [
            'Mostrar cámaras del vehículo involucrado',
            '¿Dónde se encuentra el vehículo ahora?',
            'Viajes recientes del vehículo',
        ];
    }

    if (content.includes(':::trips')) {
        return [
            '¿Dónde está el vehículo ahora?',
            'Estadísticas en tiempo real del vehículo',
            'Eventos de seguridad durante estos viajes',
        ];
    }

    if (content.includes(':::dashcamMedia')) {
        return [
            '¿Cuál es el estado actual de este vehículo?',
            '¿Tiene eventos de seguridad recientes?',
            '¿Cuáles son sus viajes de hoy?',
        ];
    }

    if (content.includes(':::fleetReport')) {
        return [
            'Más detalle de un vehículo específico',
            '¿Qué eventos de seguridad hubo?',
            'Cámaras de algún vehículo',
        ];
    }

    if (content.includes(':::fleetAnalysis')) {
        return [
            'Análisis de seguridad de toda mi flota',
            'Detecta anomalías en mi flota',
            '¿Cuál es el estado actual de mi flota?',
        ];
    }

    // Default follow-ups
    return [
        '¿Cuál es el estado de mi flota?',
        'Eventos de seguridad recientes',
        'Imágenes de dashcam',
    ];
}

export default function Copilot() {
    const { conversations: serverConversations, currentConversation, messages, vehicles, drivers, tags } =
        usePage<{ props: CopilotPageProps }>().props as unknown as CopilotPageProps;

    const [input, setInput] = useState('');
    const [isStreaming, setIsStreaming] = useState(false);
    const [localMessages, setLocalMessages] = useState<Message[]>(messages);
    const [localConversations, setLocalConversations] = useState<Conversation[]>(serverConversations);
    const [streamingContent, setStreamingContent] = useState('');
    const [currentThreadId, setCurrentThreadId] = useState<string | null>(
        currentConversation?.thread_id || null,
    );
    const [activeTool, setActiveTool] = useState<{
        label: string;
        icon: string;
    } | null>(null);
    const [sessionTokens, setSessionTokens] = useState<number>(0);
    const [conversationTokens, setConversationTokens] = useState<number>(
        currentConversation?.total_tokens || 0
    );
    // Estado para indicar que el componente está listo (hidratado)
    const [isHydrated, setIsHydrated] = useState(false);

    // Vehicle picker state
    const [vehiclePickerOpen, setVehiclePickerOpen] = useState(false);
    const [vehiclePickerAction, setVehiclePickerAction] = useState<string | null>(null);

    // Tag picker state
    const [tagPickerOpen, setTagPickerOpen] = useState(false);
    const [tagPickerAction, setTagPickerAction] = useState<string | null>(null);

    // Driver picker state
    const [driverPickerOpen, setDriverPickerOpen] = useState(false);
    const [driverPickerAction, setDriverPickerAction] = useState<string | null>(null);

    const messagesEndRef = useRef<HTMLDivElement>(null);
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    // Trackear IDs de mensajes que ya fueron animados
    const animatedMessagesRef = useRef<Set<number>>(new Set());
    // Trackear el conteo inicial de mensajes para no animar mensajes existentes al cargar
    const initialMessageCountRef = useRef<number>(messages.length);
    // Contenido acumulado para el stream
    const streamingContentRef = useRef<string>('');
    // Trackear el thread actual para detectar cambios de conversación
    const lastServerThreadIdRef = useRef<string | null>(currentConversation?.thread_id || null);

    // WebSocket stream handlers using the useCopilotStream hook
    const handleStreamChunk = useCallback((content: string) => {
        streamingContentRef.current += content;
        setStreamingContent(streamingContentRef.current);
        setActiveTool(null);
    }, []);

    const handleStreamToolStart = useCallback((toolInfo: { label: string; icon: string }) => {
        setActiveTool(toolInfo);
    }, []);

    const handleStreamToolEnd = useCallback(() => {
        setActiveTool(null);
    }, []);

    const handleStreamEnd = useCallback((tokens?: { total_tokens?: number }) => {
        setIsStreaming(false);
        setStreamingContent('');
        setActiveTool(null);
        
        // Actualizar tokens si los recibimos
        const totalTokens = tokens?.total_tokens;
        if (totalTokens) {
            setConversationTokens(prev => prev + totalTokens);
        }
        
        // Navegar a la conversación (esto recargará los mensajes correctamente)
        if (currentThreadId) {
            router.visit(show.url(currentThreadId), {
                preserveScroll: true,
            });
        } else {
            router.reload({ only: ['messages', 'currentConversation', 'conversations'] });
        }
    }, [currentThreadId]);

    const handleStreamError = useCallback((error: string) => {
        setIsStreaming(false);
        setStreamingContent('');
        setActiveTool(null);
        
        // Mostrar mensaje de error
        const errorMessage: Message = {
            id: Date.now() + 1,
            role: 'assistant',
            content: `Lo siento, hubo un error: ${error}`,
            created_at: new Date().toISOString(),
        };
        setLocalMessages((prev) => [...prev, errorMessage]);
    }, []);

    // Subscribe to WebSocket channel for streaming (replaces SSE/EventSource)
    useCopilotStream(
        currentThreadId,
        {
            onChunk: handleStreamChunk,
            onToolStart: handleStreamToolStart,
            onToolEnd: handleStreamToolEnd,
            onStreamEnd: handleStreamEnd,
            onError: handleStreamError,
        },
        isStreaming,
    );

    // Sincronizar mensajes y conversaciones cuando hay navegación o carga inicial
    useEffect(() => {
        const serverThreadId = currentConversation?.thread_id || null;
        const isConversationChange = serverThreadId !== lastServerThreadIdRef.current;
        
        // Siempre actualizar la lista de conversaciones del sidebar
        setLocalConversations(serverConversations);
        
        // Sincronizar si hay cambio de conversación
        if (isConversationChange) {
            lastServerThreadIdRef.current = serverThreadId;
            
            // Marcar todos los mensajes del servidor como ya animados (son mensajes históricos)
            messages.forEach((msg) => animatedMessagesRef.current.add(msg.id));
            initialMessageCountRef.current = messages.length;
            setLocalMessages(messages);
            setCurrentThreadId(serverThreadId);
            setConversationTokens(currentConversation?.total_tokens || 0);
            setSessionTokens(0);
        }
        
        // Handle streaming state on conversation change or page refresh
        // useCopilotStream hook handles the actual WebSocket subscription automatically
        if (isConversationChange) {
            if (currentConversation?.is_streaming && currentConversation?.thread_id) {
                // Resume streaming state from server
                setIsStreaming(true);
                streamingContentRef.current = currentConversation.streaming_content || '';
                setStreamingContent(streamingContentRef.current);
                setActiveTool(currentConversation.active_tool || null);
            } else {
                // Clear streaming state on conversation change
                setIsStreaming(false);
                setStreamingContent('');
                setActiveTool(null);
            }
        } else if (currentConversation?.is_streaming && !isStreaming) {
            // Handle page refresh with active stream
            setIsStreaming(true);
            streamingContentRef.current = currentConversation.streaming_content || '';
            setStreamingContent(streamingContentRef.current);
            setActiveTool(currentConversation.active_tool || null);
        }
    }, [serverConversations, currentConversation, messages, isStreaming]);
    
    // Sincronizar mensajes del servidor cuando cambian (solo después de navegación via Inertia)
    // Este useEffect NO debe interferir con operaciones locales (envío de mensaje, streaming)
    useEffect(() => {
        // No sincronizar si estamos en streaming o si no hay conversación actual
        if (isStreaming) return;
        
        // Solo sincronizar si el thread actual coincide con el del servidor
        // Esto evita sobrescribir mensajes cuando el usuario acaba de crear una conversación nueva
        const serverThreadId = currentConversation?.thread_id;
        if (!serverThreadId || serverThreadId !== currentThreadId) return;
        
        // Solo sincronizar si hay mensajes del servidor y son genuinamente diferentes
        if (messages.length === 0) return;
        
        const lastServerId = messages[messages.length - 1]?.id;
        const lastLocalId = localMessages[localMessages.length - 1]?.id;
        
        // Solo sincronizar si el último mensaje del servidor es diferente Y es un ID real (no temporal)
        // Los IDs temporales (Date.now()) son muy grandes, los de BD son secuenciales pequeños
        const isTemporaryId = lastLocalId && lastLocalId > 1000000000000;
        
        if (lastServerId && lastServerId !== lastLocalId && !isTemporaryId) {
            messages.forEach((msg) => animatedMessagesRef.current.add(msg.id));
            setLocalMessages(messages);
            setConversationTokens(currentConversation?.total_tokens || 0);
        }
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [messages]);

    // Marcar el componente como hidratado después de montar
    // Esto es importante para PWA donde la primera carga puede tener problemas
    useEffect(() => {
        setIsHydrated(true);
    }, []);

    // Helper para determinar si un mensaje debe animarse
    const shouldAnimateMessage = (messageId: number): boolean => {
        if (animatedMessagesRef.current.has(messageId)) {
            return false;
        }
        // Marcar como animado
        animatedMessagesRef.current.add(messageId);
        return true;
    };

    // Scroll al final cuando hay nuevos mensajes o contenido streaming
    useEffect(() => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [localMessages, streamingContent]);

    // Auto-resize del textarea
    useEffect(() => {
        if (textareaRef.current) {
            textareaRef.current.style.height = 'auto';
            textareaRef.current.style.height = `${Math.min(textareaRef.current.scrollHeight, 200)}px`;
        }
    }, [input]);

    // Helper para obtener CSRF token (con fallback para PWA)
    const getCsrfToken = (): string | null => {
        // Primero intentar del meta tag
        const metaToken = document.querySelector<HTMLMetaElement>(
            'meta[name="csrf-token"]',
        )?.content;
        
        if (metaToken) {
            return metaToken;
        }
        
        // Fallback: buscar en cookies (Laravel también lo guarda ahí)
        const cookieMatch = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
        if (cookieMatch) {
            return decodeURIComponent(cookieMatch[1]);
        }
        
        return null;
    };

    // Core message submission logic — extracted so both form submit and
    // suggestion clicks can trigger it without duplicating code.
    const submitMessage = useCallback(async (messageText: string) => {
        if (!isHydrated) return;
        if (!messageText.trim() || isStreaming) return;

        const userMessage = messageText.trim();
        setInput('');
        setIsStreaming(true);
        setStreamingContent('');
        streamingContentRef.current = '';

        // Agregar mensaje del usuario localmente
        const tempUserMessage: Message = {
            id: Date.now(),
            role: 'user',
            content: userMessage,
            created_at: new Date().toISOString(),
        };
        setLocalMessages((prev) => [...prev, tempUserMessage]);

        try {
            // Obtener CSRF token
            const csrfToken = getCsrfToken();

            if (!csrfToken) {
                // En PWA, si no hay CSRF token, recargar la página para obtener uno nuevo
                console.error('[Copilot] CSRF token not found, reloading page');
                window.location.reload();
                return;
            }

            const response = await fetch(send.url(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-XSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    message: userMessage,
                    thread_id: currentThreadId,
                }),
            });

            // Si el servidor devuelve 419 (CSRF token mismatch), recargar para obtener nuevo token
            if (response.status === 419) {
                console.error('[Copilot] CSRF token expired, reloading page');
                window.location.reload();
                return;
            }

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}));
                throw new Error(errorData.message || 'Error en la respuesta del servidor');
            }

            const data = await response.json();

            // Actualizar thread_id si es nueva conversación
            if (data.thread_id) {
                setCurrentThreadId(data.thread_id);
                // Actualizar ref para evitar que useEffect resetee
                lastServerThreadIdRef.current = data.thread_id;
            }

            // Si es nueva conversación, agregar al sidebar
            if (data.is_new_conversation && data.thread_id) {
                // Agregar la nueva conversación al estado local del sidebar
                const newConversation: Conversation = {
                    id: Date.now(), // ID temporal
                    thread_id: data.thread_id,
                    title: userMessage.slice(0, 50) + (userMessage.length > 50 ? '...' : ''),
                    created_at: new Date().toISOString(),
                    updated_at: new Date().toISOString(),
                };
                setLocalConversations((prev) => [newConversation, ...prev]);

                // NO actualizamos la URL aquí, esperamos a que termine el stream
            }

            // WebSocket subscription is handled automatically by useCopilotStream hook
            // when isStreaming is true and currentThreadId is set
        } catch (error) {
            console.error('[Copilot] Error sending message:', error);
            setIsStreaming(false);
            setStreamingContent('');
            setActiveTool(null);

            // Mostrar mensaje de error más descriptivo
            const errorMessage: Message = {
                id: Date.now() + 1,
                role: 'assistant',
                content: error instanceof Error && error.message !== 'Error en la respuesta del servidor'
                    ? `Lo siento, hubo un error: ${error.message}. Por favor, intenta de nuevo.`
                    : 'Lo siento, hubo un error al procesar tu mensaje. Por favor, intenta de nuevo.',
                created_at: new Date().toISOString(),
            };
            setLocalMessages((prev) => [...prev, errorMessage]);
        }
    }, [isHydrated, isStreaming, currentThreadId]);

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        submitMessage(input);
    };

    // Helper para obtener el icono de la herramienta
    const getToolIcon = (iconName: string) => {
        const icons: Record<string, React.ReactNode> = {
            truck: <Truck className="size-4" />,
            activity: <Activity className="size-4" />,
            database: <Database className="size-4" />,
            search: <Search className="size-4" />,
            loader: <Loader2 className="size-4 animate-spin" />,
            'bar-chart': <BarChart3 className="size-4" />,
        };
        return icons[iconName] || <Loader2 className="size-4 animate-spin" />;
    };

    // Helper para obtener descripción contextual de la herramienta
    const getToolContext = (label: string): { description: string; colorClass: string } => {
        const labelLower = label.toLowerCase();
        
        // Vehicle/Fleet related
        if (labelLower.includes('ubicación') || labelLower.includes('location')) {
            return { description: 'Consultando GPS del vehículo...', colorClass: 'from-blue-500/20 to-cyan-500/20' };
        }
        if (labelLower.includes('vehículo') || labelLower.includes('vehicle') || labelLower.includes('stats')) {
            return { description: 'Obteniendo estadísticas del vehículo...', colorClass: 'from-indigo-500/20 to-purple-500/20' };
        }
        if (labelLower.includes('conductor') || labelLower.includes('driver')) {
            return { description: 'Buscando información del conductor...', colorClass: 'from-green-500/20 to-emerald-500/20' };
        }
        if (labelLower.includes('flota') || labelLower.includes('fleet')) {
            return { description: 'Generando reporte de flota...', colorClass: 'from-blue-600/20 to-blue-400/20' };
        }
        
        // Safety related
        if (labelLower.includes('seguridad') || labelLower.includes('safety') || labelLower.includes('evento')) {
            return { description: 'Analizando eventos de seguridad...', colorClass: 'from-red-500/20 to-orange-500/20' };
        }
        
        // Media related
        if (labelLower.includes('cámara') || labelLower.includes('dashcam') || labelLower.includes('media') || labelLower.includes('imagen')) {
            return { description: 'Procesando imágenes de dashcam...', colorClass: 'from-purple-500/20 to-pink-500/20' };
        }
        
        // Trips related
        if (labelLower.includes('viaje') || labelLower.includes('trip') || labelLower.includes('ruta')) {
            return { description: 'Consultando historial de viajes...', colorClass: 'from-green-500/20 to-teal-500/20' };
        }
        
        // Analysis related
        if (labelLower.includes('analizando datos') || labelLower.includes('análisis') || labelLower.includes('analysis')) {
            return { description: 'Ejecutando análisis avanzado con AI...', colorClass: 'from-violet-500/20 to-fuchsia-500/20' };
        }

        // Notifications
        if (labelLower.includes('notificación') || labelLower.includes('sms') || labelLower.includes('whatsapp') || labelLower.includes('llamada')) {
            return { description: 'Enviando notificación...', colorClass: 'from-amber-500/20 to-yellow-500/20' };
        }
        
        // Default
        return { description: 'Procesando solicitud...', colorClass: 'from-blue-500/20 to-purple-500/20' };
    };

    const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            handleSubmit(e);
        }
    };

    const handleSuggestionClick = (suggestion: string) => {
        // Check for special vehicle picker tokens
        if (suggestion === '__VEHICLE_PICKER__') {
            setVehiclePickerAction(null);
            setVehiclePickerOpen(true);
            return;
        }
        const vehiclePickerMatch = suggestion.match(/^__VEHICLE_PICKER_(\w+)__$/);
        if (vehiclePickerMatch) {
            setVehiclePickerAction(vehiclePickerMatch[1]);
            setVehiclePickerOpen(true);
            return;
        }

        // Check for special tag picker tokens
        if (suggestion === '__TAG_PICKER__') {
            setTagPickerAction(null);
            setTagPickerOpen(true);
            return;
        }
        const tagPickerMatch = suggestion.match(/^__TAG_PICKER_(\w+)__$/);
        if (tagPickerMatch) {
            setTagPickerAction(tagPickerMatch[1]);
            setTagPickerOpen(true);
            return;
        }

        // Check for special driver picker tokens
        if (suggestion === '__DRIVER_PICKER__') {
            setDriverPickerAction(null);
            setDriverPickerOpen(true);
            return;
        }
        const driverPickerMatch = suggestion.match(/^__DRIVER_PICKER_(\w+)__$/);
        if (driverPickerMatch) {
            setDriverPickerAction(driverPickerMatch[1]);
            setDriverPickerOpen(true);
            return;
        }

        // Check if this follow-up should open a picker
        if (suggestion in VEHICLE_PICKER_QUERIES) {
            setVehiclePickerAction(VEHICLE_PICKER_QUERIES[suggestion]);
            setVehiclePickerOpen(true);
            return;
        }
        if (suggestion in TAG_PICKER_QUERIES) {
            setTagPickerAction(TAG_PICKER_QUERIES[suggestion]);
            setTagPickerOpen(true);
            return;
        }
        if (suggestion in DRIVER_PICKER_QUERIES) {
            setDriverPickerAction(DRIVER_PICKER_QUERIES[suggestion]);
            setDriverPickerOpen(true);
            return;
        }

        submitMessage(suggestion);
    };

    const handleVehicleSelect = (query: string) => {
        setVehiclePickerOpen(false);
        setVehiclePickerAction(null);
        submitMessage(query);
    };

    const handleTagSelect = (query: string) => {
        setTagPickerOpen(false);
        setTagPickerAction(null);
        submitMessage(query);
    };

    const handleDriverSelect = (query: string) => {
        setDriverPickerOpen(false);
        setDriverPickerAction(null);
        submitMessage(query);
    };

    return (
        <CopilotLayout
            conversations={localConversations}
            currentThreadId={currentThreadId}
        >
            <Head title="Copilot" />

            <div className="flex h-full max-h-full min-h-0 flex-1 flex-col overflow-hidden">
                {/* T5: Event context banner */}
                {currentConversation?.context_payload && (
                    <EventContextBanner context={currentConversation.context_payload} />
                )}

                {/* Mensajes - área scrollable */}
                <div className="min-h-0 flex-1 overflow-y-auto">
                    {localMessages.length === 0 && !streamingContent ? (
                        <div className="flex h-full flex-col items-center px-4 py-6 overflow-y-auto md:justify-center md:py-8">
                            <div className="w-full max-w-3xl">
                                {/* Hero */}
                                <div className="mb-6 text-center md:mb-8">
                                    <div className="from-primary/20 to-primary/5 animate-in fade-in zoom-in mx-auto mb-4 inline-flex rounded-full bg-gradient-to-br p-4 duration-500 md:p-5">
                                        <Sparkles className="text-primary size-7 md:size-9" />
                                    </div>
                                    <h2 className="animate-in fade-in slide-in-from-bottom-4 mb-2 font-display text-xl font-bold tracking-tight duration-500 md:text-2xl" style={{ animationDelay: '100ms', animationFillMode: 'backwards' }}>
                                        Tu Copilot de Flota
                                    </h2>
                                    <p className="text-muted-foreground animate-in fade-in slide-in-from-bottom-4 mx-auto max-w-lg text-sm duration-500 md:text-base" style={{ animationDelay: '200ms', animationFillMode: 'backwards' }}>
                                        Pregúntame lo que necesites. Te ayudo a explorar tu flota,
                                        revisar seguridad, ubicar vehículos y mucho más — sin
                                        necesidad de saber nombres exactos.
                                    </p>
                                </div>

                                {/* Category cards */}
                                <div
                                    className="animate-in fade-in slide-in-from-bottom-4 flex flex-wrap justify-center gap-3 duration-500"
                                    style={{ animationDelay: '300ms', animationFillMode: 'backwards' }}
                                >
                                    {COPILOT_CATEGORIES.map((category, idx) => {
                                        const Icon = category.icon;
                                        const isLast = idx === COPILOT_CATEGORIES.length - 1;
                                        return (
                                            <div
                                                key={category.title}
                                                className={cn(
                                                    'bg-card group rounded-2xl border p-4 transition-all duration-200 hover:shadow-md',
                                                    isLast
                                                        ? 'w-full'
                                                        : 'w-[calc(50%-0.375rem)] lg:w-[calc(25%-0.5625rem)]',
                                                )}
                                            >
                                                <div className={cn(
                                                    'flex items-center gap-2.5',
                                                    !isLast && 'mb-3',
                                                    isLast && 'mb-3 lg:mb-0',
                                                )}>
                                                    <div
                                                        className={`flex size-8 items-center justify-center rounded-xl bg-gradient-to-br ${category.gradient}`}
                                                    >
                                                        <Icon className={`size-4 ${category.iconColor}`} />
                                                    </div>
                                                    <div className={isLast ? 'flex-1' : ''}>
                                                        <h3 className="text-sm font-semibold leading-tight">
                                                            {category.title}
                                                        </h3>
                                                        <p className="text-muted-foreground text-[10px] leading-tight">
                                                            {category.description}
                                                        </p>
                                                    </div>
                                                </div>
                                                <div className={cn(
                                                    'space-y-0.5',
                                                    isLast && 'lg:flex lg:flex-wrap lg:gap-1 lg:space-y-0',
                                                )}>
                                                    {category.suggestions.map((suggestion) => (
                                                        <button
                                                            key={suggestion.label}
                                                            type="button"
                                                            onClick={() =>
                                                                handleSuggestionClick(suggestion.query)
                                                            }
                                                            className={cn(
                                                                'group/item hover:bg-muted flex items-center gap-2 rounded-lg px-2 py-1.5 text-left text-xs transition-colors',
                                                                isLast ? 'w-full lg:w-auto' : 'w-full',
                                                            )}
                                                        >
                                                            <ArrowRight className="text-muted-foreground/40 group-hover/item:text-primary size-3 flex-shrink-0 transition-colors" />
                                                            <span className="text-muted-foreground group-hover/item:text-foreground transition-colors">
                                                                {suggestion.label}
                                                            </span>
                                                        </button>
                                                    ))}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        </div>
                    ) : (
                        <div className="min-w-0 px-3 py-4 md:px-6 md:py-8 lg:px-8">
                            {localMessages.map((message) => {
                                const isNew = shouldAnimateMessage(message.id);
                                return (
                                    <div
                                        key={message.id}
                                        className={cn(
                                            'mb-4 flex min-w-0 gap-2 md:mb-6 md:gap-4',
                                            message.role === 'user'
                                                ? 'justify-end'
                                                : 'justify-start',
                                            // Solo aplicar animaciones a mensajes nuevos
                                            isNew && 'animate-in fade-in duration-300',
                                            isNew && message.role === 'user' && 'slide-in-from-right-4',
                                            isNew && message.role === 'assistant' && 'slide-in-from-left-4',
                                        )}
                                    >
                                        {message.role === 'assistant' && (
                                            <div className={cn(
                                                'bg-primary/10 flex size-7 flex-shrink-0 items-center justify-center rounded-full md:size-8',
                                                isNew && 'animate-in zoom-in duration-300'
                                            )}>
                                                <Bot className="text-primary size-4 md:size-5" />
                                            </div>
                                        )}

                                        <div
                                            className={cn(
                                                'min-w-0 overflow-hidden rounded-2xl px-3 py-2 transition-all duration-200 md:px-4 md:py-3',
                                                message.role === 'user'
                                                    ? 'bg-primary text-primary-foreground max-w-[85%] shadow-sm md:max-w-[70%]'
                                                    : 'bg-muted/60 border border-border/40 flex-1 max-w-none',
                                            )}
                                        >
                                            {message.role === 'assistant' ? (
                                                <MarkdownContent
                                                    content={message.content}
                                                    className="text-sm"
                                                />
                                            ) : (
                                                <p className="whitespace-pre-wrap text-sm leading-relaxed">
                                                    {message.content}
                                                </p>
                                            )}
                                        </div>

                                        {message.role === 'user' && (
                                            <div className={cn(
                                                'bg-secondary flex size-7 flex-shrink-0 items-center justify-center rounded-full md:size-8',
                                                isNew && 'animate-in zoom-in duration-300'
                                            )}>
                                                <User className="text-secondary-foreground size-4 md:size-5" />
                                            </div>
                                        )}
                                    </div>
                                );
                            })}

                            {/* Mensaje de streaming */}
                            {streamingContent && (
                                <div className="animate-in fade-in slide-in-from-left-4 mb-4 flex min-w-0 gap-2 duration-300 md:mb-6 md:gap-4">
                                    <div className="bg-primary/10 animate-in zoom-in flex size-7 flex-shrink-0 items-center justify-center rounded-full duration-300 md:size-8">
                                        <Bot className="text-primary size-4 md:size-5" />
                                    </div>
                                    <div className="bg-muted min-w-0 flex-1 overflow-hidden rounded-2xl px-3 py-2 shadow-sm md:px-4 md:py-3">
                                        <div className="min-w-0 overflow-hidden text-sm">
                                            <MarkdownContent content={streamingContent} isStreaming={isStreaming} />
                                        </div>
                                    </div>
                                </div>
                            )}

                            {/* Indicador de herramienta activa */}
                            {isStreaming && !streamingContent && activeTool && (() => {
                                const toolContext = getToolContext(activeTool.label);
                                return (
                                    <div className="animate-in fade-in slide-in-from-left-4 mb-4 flex gap-2 duration-300 md:mb-6 md:gap-4">
                                        <div className="bg-primary/10 animate-in zoom-in flex size-7 flex-shrink-0 items-center justify-center rounded-full duration-300 md:size-8">
                                            <Bot className="text-primary size-4 md:size-5" />
                                        </div>
                                        <div className="bg-muted border-primary/20 animate-in fade-in zoom-in flex items-center gap-3 rounded-2xl border px-3 py-2.5 duration-200 md:gap-4 md:px-4 md:py-3">
                                            <div className={`text-primary flex size-9 items-center justify-center rounded-xl bg-gradient-to-br ${toolContext.colorClass} md:size-10`}>
                                                {getToolIcon(activeTool.icon)}
                                            </div>
                                            <div className="flex flex-col gap-1">
                                                <span className="text-xs font-semibold md:text-sm">{activeTool.label}</span>
                                                <span className="text-muted-foreground text-[10px] md:text-xs">{toolContext.description}</span>
                                                <div className="mt-0.5 flex items-center gap-1">
                                                    <span className="bg-primary/70 size-1.5 animate-pulse rounded-full"></span>
                                                    <span className="bg-primary/70 size-1.5 animate-pulse rounded-full [animation-delay:150ms]"></span>
                                                    <span className="bg-primary/70 size-1.5 animate-pulse rounded-full [animation-delay:300ms]"></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                );
                            })()}

                            {/* Indicador de carga genérico */}
                            {isStreaming && !streamingContent && !activeTool && (
                                <div className="animate-in fade-in slide-in-from-left-4 mb-4 flex gap-2 duration-300 md:mb-6 md:gap-4">
                                    <div className="bg-primary/10 animate-in zoom-in flex size-7 flex-shrink-0 items-center justify-center rounded-full duration-300 md:size-8">
                                        <Bot className="text-primary size-4 md:size-5" />
                                    </div>
                                    <div className="bg-muted border-muted-foreground/10 flex items-center gap-3 rounded-2xl border px-3 py-2.5 md:px-4 md:py-3">
                                        <div className="text-primary flex size-9 items-center justify-center rounded-xl bg-gradient-to-br from-primary/10 to-primary/5 md:size-10">
                                            <Sparkles className="size-4 animate-pulse" />
                                        </div>
                                        <div className="flex flex-col gap-1">
                                            <span className="text-xs font-semibold md:text-sm">Pensando...</span>
                                            <span className="text-muted-foreground text-[10px] md:text-xs">Analizando tu solicitud</span>
                                            <div className="mt-0.5 flex items-center gap-1">
                                                <span className="bg-primary/70 size-1.5 animate-pulse rounded-full"></span>
                                                <span className="bg-primary/70 size-1.5 animate-pulse rounded-full [animation-delay:150ms]"></span>
                                                <span className="bg-primary/70 size-1.5 animate-pulse rounded-full [animation-delay:300ms]"></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {/* Follow-up suggestions — contextual chips after the last assistant message */}
                            {!isStreaming && !streamingContent && localMessages.length > 0 &&
                                localMessages[localMessages.length - 1]?.role === 'assistant' && (
                                    <div
                                        className="animate-in fade-in slide-in-from-bottom-2 mb-4 ml-9 duration-500 md:ml-12"
                                        style={{ animationDelay: '200ms', animationFillMode: 'backwards' }}
                                    >
                                        <div className="flex items-start gap-2">
                                            <Lightbulb className="mt-1 size-3.5 flex-shrink-0 text-amber-500" />
                                            <div className="flex flex-wrap gap-1.5">
                                                {getFollowUpSuggestions(
                                                    localMessages[localMessages.length - 1].content,
                                                ).map((suggestion) => (
                                                    <button
                                                        key={suggestion}
                                                        type="button"
                                                        onClick={() => handleSuggestionClick(suggestion)}
                                                        className="border-primary/20 bg-primary/5 text-primary hover:bg-primary/10 rounded-full border px-3 py-1 text-xs transition-colors"
                                                    >
                                                        {suggestion}
                                                    </button>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                )}

                            <div ref={messagesEndRef} />
                        </div>
                    )}
                </div>

                {/* Quick actions + Input area */}
                <div className="bg-background relative flex-shrink-0 border-t pb-safe">
                    {/* Quick Search pickers — float above the input area */}
                    <VehicleQuickSearch
                        vehicles={vehicles ?? []}
                        open={vehiclePickerOpen}
                        onClose={() => {
                            setVehiclePickerOpen(false);
                            setVehiclePickerAction(null);
                        }}
                        onSelect={handleVehicleSelect}
                        preSelectedAction={vehiclePickerAction}
                    />
                    <TagQuickSearch
                        tags={tags ?? []}
                        open={tagPickerOpen}
                        onClose={() => {
                            setTagPickerOpen(false);
                            setTagPickerAction(null);
                        }}
                        onSelect={handleTagSelect}
                        preSelectedAction={tagPickerAction}
                    />
                    <DriverQuickSearch
                        drivers={drivers ?? []}
                        open={driverPickerOpen}
                        onClose={() => {
                            setDriverPickerOpen(false);
                            setDriverPickerAction(null);
                        }}
                        onSelect={handleDriverSelect}
                        preSelectedAction={driverPickerAction}
                    />
                    {/* Quick actions bar — only in active conversations, hidden while streaming */}
                    {localMessages.length > 0 && !isStreaming && (
                        <div className="mx-auto max-w-4xl overflow-x-auto px-3 pt-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                            <div className="flex gap-1.5">
                                {QUICK_ACTIONS.map((action) => {
                                    const ActionIcon = action.icon;
                                    return (
                                        <button
                                            key={action.label}
                                            type="button"
                                            onClick={() => handleSuggestionClick(action.query)}
                                            className="text-muted-foreground hover:bg-muted hover:text-foreground flex items-center gap-1.5 whitespace-nowrap rounded-full border bg-transparent px-2.5 py-1 text-[11px] font-medium transition-colors"
                                        >
                                            <ActionIcon className={`size-3 ${action.color}`} />
                                            {action.label}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    )}

                    {/* Input form */}
                    <div className="p-3 md:p-4">
                        <form
                            onSubmit={handleSubmit}
                            className="bg-muted/50 mx-auto flex max-w-4xl items-end gap-2 rounded-2xl border p-1.5 md:p-2"
                        >
                            <textarea
                                ref={textareaRef}
                                value={input}
                                onChange={(e) => setInput(e.target.value)}
                                onKeyDown={handleKeyDown}
                                placeholder={isHydrated ? "Pregúntame sobre tu flota, vehículos, seguridad..." : "Cargando..."}
                                className="placeholder:text-muted-foreground max-h-[120px] min-h-[40px] flex-1 resize-none bg-transparent px-2.5 py-2 text-sm outline-none md:max-h-[200px] md:min-h-[44px] md:px-3 md:py-2.5"
                                rows={1}
                                disabled={isStreaming || !isHydrated}
                            />
                            <Button
                                type="submit"
                                size="icon"
                                disabled={!input.trim() || isStreaming || !isHydrated}
                                className="size-9 flex-shrink-0 rounded-xl md:size-10"
                            >
                                <Send className="size-4" />
                            </Button>
                        </form>
                        <div className="mt-2 flex flex-col items-center justify-center gap-1 md:flex-row md:gap-4">
                            <p className="text-muted-foreground text-center text-[10px] md:text-xs">
                                Copilot puede cometer errores. Verifica la información importante.
                            </p>
                            {conversationTokens > 0 && (
                                <div className="text-muted-foreground flex items-center gap-1.5 text-[10px] md:text-xs" title={`Sesión: ${sessionTokens.toLocaleString()} tokens`}>
                                    <Coins className="size-3" />
                                    <span>{conversationTokens.toLocaleString()} tokens</span>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </CopilotLayout>
    );
}
