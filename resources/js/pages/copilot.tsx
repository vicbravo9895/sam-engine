import {
    index as copilotIndex,
    send,
    show,
} from '@/actions/App/Http/Controllers/CopilotController';
import { MarkdownContent } from '@/components/markdown-content';
import { Button } from '@/components/ui/button';
import CopilotLayout from '@/layouts/copilot-layout';
import { cn } from '@/lib/utils';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Activity,
    Bot,
    Coins,
    Database,
    Loader2,
    Search,
    Send,
    Sparkles,
    Truck,
    User,
} from 'lucide-react';
import { FormEvent, useEffect, useRef, useState } from 'react';

interface Message {
    id: number;
    role: 'user' | 'assistant';
    content: string;
    created_at: string;
}

interface Conversation {
    id: number;
    thread_id: string;
    title: string;
    created_at: string;
    updated_at: string;
    total_tokens?: number;
}

interface CopilotPageProps {
    conversations: Conversation[];
    currentConversation: Conversation | null;
    messages: Message[];
}

export default function Copilot() {
    const { conversations, currentConversation, messages } =
        usePage<{ props: CopilotPageProps }>().props as unknown as CopilotPageProps;

    const [input, setInput] = useState('');
    const [isStreaming, setIsStreaming] = useState(false);
    const [localMessages, setLocalMessages] = useState<Message[]>(messages);
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

    const messagesEndRef = useRef<HTMLDivElement>(null);
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const abortControllerRef = useRef<AbortController | null>(null);
    // Trackear IDs de mensajes que ya fueron animados
    const animatedMessagesRef = useRef<Set<number>>(new Set());
    // Trackear el conteo inicial de mensajes para no animar mensajes existentes al cargar
    const initialMessageCountRef = useRef<number>(messages.length);

    // Sincronizar mensajes cuando cambian desde el servidor
    useEffect(() => {
        // Marcar todos los mensajes del servidor como ya animados (son mensajes históricos)
        messages.forEach((msg) => animatedMessagesRef.current.add(msg.id));
        initialMessageCountRef.current = messages.length;
        setLocalMessages(messages);
        setCurrentThreadId(currentConversation?.thread_id || null);
        setConversationTokens(currentConversation?.total_tokens || 0);
        setSessionTokens(0); // Reset session tokens on conversation change
    }, [messages, currentConversation]);

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

    const handleSubmit = async (e: FormEvent) => {
        e.preventDefault();
        if (!input.trim() || isStreaming) return;

        const userMessage = input.trim();
        setInput('');
        setIsStreaming(true);
        setStreamingContent('');

        // Agregar mensaje del usuario localmente
        const tempUserMessage: Message = {
            id: Date.now(),
            role: 'user',
            content: userMessage,
            created_at: new Date().toISOString(),
        };
        setLocalMessages((prev) => [...prev, tempUserMessage]);

        // Crear AbortController para poder cancelar
        abortControllerRef.current = new AbortController();

        try {
            // Obtener CSRF token del meta tag
            const csrfToken = document.querySelector<HTMLMetaElement>(
                'meta[name="csrf-token"]',
            )?.content;

            if (!csrfToken) {
                throw new Error('CSRF token no encontrado');
            }

            const response = await fetch(send.url(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'text/event-stream',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    message: userMessage,
                    thread_id: currentThreadId,
                }),
                signal: abortControllerRef.current.signal,
            });

            if (!response.ok) {
                throw new Error('Error en la respuesta del servidor');
            }

            const reader = response.body?.getReader();
            const decoder = new TextDecoder();
            let fullContent = '';
            let newThreadId = currentThreadId;

            if (reader) {
                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    const text = decoder.decode(value, { stream: true });
                    const lines = text.split('\n');

                    for (const line of lines) {
                        if (line.startsWith('data: ')) {
                            try {
                                const data = JSON.parse(line.slice(6));

                                if (data.type === 'start') {
                                    newThreadId = data.thread_id;
                                    setCurrentThreadId(data.thread_id);
                                } else if (data.type === 'tool_start') {
                                    setActiveTool({
                                        label: data.label,
                                        icon: data.icon,
                                    });
                                } else if (data.type === 'tool_end') {
                                    setActiveTool(null);
                                } else if (data.type === 'chunk') {
                                    setActiveTool(null); // Limpiar tool cuando empieza el texto
                                    fullContent += data.content;
                                    setStreamingContent(fullContent);
                                } else if (data.type === 'done') {
                                    // Agregar mensaje del asistente cuando termina
                                    const assistantMessage: Message = {
                                        id: Date.now() + 1,
                                        role: 'assistant',
                                        content: fullContent,
                                        created_at: new Date().toISOString(),
                                    };
                                    setLocalMessages((prev) => [
                                        ...prev,
                                        assistantMessage,
                                    ]);
                                    setStreamingContent('');

                                    // Acumular tokens de esta sesión
                                    if (data.tokens && data.tokens.total_tokens > 0) {
                                        setSessionTokens((prev) => prev + data.tokens.total_tokens);
                                        setConversationTokens((prev) => prev + data.tokens.total_tokens);
                                    }

                                    // Si es nueva conversación, redirigir
                                    if (
                                        newThreadId &&
                                        newThreadId !== currentConversation?.thread_id
                                    ) {
                                        router.visit(show.url(newThreadId), {
                                            preserveState: false,
                                        });
                                    }
                                }
                            } catch {
                                // Ignorar líneas que no son JSON válido
                            }
                        }
                    }
                }
            }
        } catch (error) {
            if ((error as Error).name !== 'AbortError') {
                console.error('Error en streaming:', error);
                // Mostrar mensaje de error
                const errorMessage: Message = {
                    id: Date.now() + 1,
                    role: 'assistant',
                    content: 'Lo siento, hubo un error al procesar tu mensaje. Por favor, intenta de nuevo.',
                    created_at: new Date().toISOString(),
                };
                setLocalMessages((prev) => [...prev, errorMessage]);
            }
        } finally {
            setIsStreaming(false);
            setStreamingContent('');
            setActiveTool(null);
            abortControllerRef.current = null;
        }
    };

    // Helper para obtener el icono de la herramienta
    const getToolIcon = (iconName: string) => {
        const icons: Record<string, React.ReactNode> = {
            truck: <Truck className="size-4" />,
            activity: <Activity className="size-4" />,
            database: <Database className="size-4" />,
            search: <Search className="size-4" />,
            loader: <Loader2 className="size-4 animate-spin" />,
        };
        return icons[iconName] || <Loader2 className="size-4 animate-spin" />;
    };

    const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            handleSubmit(e);
        }
    };

    const handleSuggestionClick = (suggestion: string) => {
        setInput(suggestion);
        textareaRef.current?.focus();
    };

    return (
        <CopilotLayout
            conversations={conversations}
            currentThreadId={currentConversation?.thread_id || null}
        >
            <Head title="Copilot" />

            <div className="flex h-full max-h-full min-h-0 flex-1 flex-col overflow-hidden">
                {/* Mensajes - área scrollable */}
                <div className="min-h-0 flex-1 overflow-y-auto">
                    {localMessages.length === 0 && !streamingContent ? (
                        <div className="flex h-full flex-col items-center justify-center px-4 py-8">
                            <div className="from-primary/20 to-primary/5 animate-in fade-in zoom-in mb-4 rounded-full bg-gradient-to-br p-4 duration-500 md:mb-6 md:p-6">
                                <Sparkles className="text-primary size-8 md:size-12" />
                            </div>
                            <h2 className="animate-in fade-in slide-in-from-bottom-4 mb-2 text-xl font-semibold duration-500 md:text-2xl" style={{ animationDelay: '100ms', animationFillMode: 'backwards' }}>
                                ¡Hola! Soy tu Copilot
                            </h2>
                            <p className="text-muted-foreground animate-in fade-in slide-in-from-bottom-4 mb-6 max-w-md px-4 text-center text-sm duration-500 md:mb-8 md:text-base" style={{ animationDelay: '200ms', animationFillMode: 'backwards' }}>
                                Estoy aquí para ayudarte. Pregúntame cualquier cosa
                                sobre tu flota, operaciones o lo que necesites.
                            </p>
                            <div className="grid w-full max-w-2xl gap-2 px-2 md:gap-3 md:px-0 sm:grid-cols-2">
                                {[
                                    '¿Cuál es el estado de mi flota?',
                                    '¿Cómo puedo optimizar mis rutas?',
                                    'Dame un resumen de las operaciones',
                                    '¿Qué vehículos necesitan mantenimiento?',
                                ].map((suggestion, index) => (
                                    <button
                                        type="button"
                                        key={suggestion}
                                        onClick={() => handleSuggestionClick(suggestion)}
                                        className="bg-muted/50 hover:bg-muted animate-in fade-in slide-in-from-bottom-4 active:scale-[0.98] md:hover:scale-[1.02] rounded-xl border px-3 py-2.5 text-left text-sm transition-all duration-200 md:px-4 md:py-3"
                                        style={{ animationDelay: `${300 + index * 75}ms`, animationFillMode: 'backwards' }}
                                    >
                                        {suggestion}
                                    </button>
                                ))}
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
                                                'min-w-0 overflow-hidden rounded-2xl px-3 py-2 transition-shadow duration-200 md:px-4 md:py-3',
                                                message.role === 'user'
                                                    ? 'bg-primary text-primary-foreground max-w-[85%] md:max-w-[70%]'
                                                    : 'bg-muted flex-1 max-w-none',
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
                            {isStreaming && !streamingContent && activeTool && (
                                <div className="animate-in fade-in slide-in-from-left-4 mb-4 flex gap-2 duration-300 md:mb-6 md:gap-4">
                                    <div className="bg-primary/10 animate-in zoom-in flex size-7 flex-shrink-0 items-center justify-center rounded-full duration-300 md:size-8">
                                        <Bot className="text-primary size-4 md:size-5" />
                                    </div>
                                    <div className="bg-muted border-primary/20 animate-in fade-in zoom-in flex items-center gap-2 rounded-2xl border px-3 py-2 duration-200 md:gap-3 md:px-4 md:py-3">
                                        <div className="text-primary flex size-7 items-center justify-center rounded-lg bg-gradient-to-br from-blue-500/20 to-purple-500/20 md:size-8">
                                            {getToolIcon(activeTool.icon)}
                                        </div>
                                        <div className="flex flex-col">
                                            <span className="text-xs font-medium md:text-sm">{activeTool.label}</span>
                                            <div className="mt-1 flex items-center gap-1">
                                                <span className="bg-primary size-1.5 animate-pulse rounded-full"></span>
                                                <span className="bg-primary size-1.5 animate-pulse rounded-full [animation-delay:150ms]"></span>
                                                <span className="bg-primary size-1.5 animate-pulse rounded-full [animation-delay:300ms]"></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {/* Indicador de carga genérico */}
                            {isStreaming && !streamingContent && !activeTool && (
                                <div className="animate-in fade-in slide-in-from-left-4 mb-4 flex gap-2 duration-300 md:mb-6 md:gap-4">
                                    <div className="bg-primary/10 animate-in zoom-in flex size-7 flex-shrink-0 items-center justify-center rounded-full duration-300 md:size-8">
                                        <Bot className="text-primary size-4 animate-pulse md:size-5" />
                                    </div>
                                    <div className="bg-muted rounded-2xl px-3 py-2 md:px-4 md:py-3">
                                        <div className="flex items-center gap-1.5">
                                            <span className="bg-foreground/50 size-2 animate-bounce rounded-full [animation-delay:-0.3s]"></span>
                                            <span className="bg-foreground/50 size-2 animate-bounce rounded-full [animation-delay:-0.15s]"></span>
                                            <span className="bg-foreground/50 size-2 animate-bounce rounded-full"></span>
                                        </div>
                                    </div>
                                </div>
                            )}

                            <div ref={messagesEndRef} />
                        </div>
                    )}
                </div>

                {/* Input - fijo en la parte inferior con safe area para home indicator */}
                <div className="bg-background flex-shrink-0 border-t p-3 pb-safe md:p-4">
                    <form
                        onSubmit={handleSubmit}
                        className="bg-muted/50 mx-auto flex max-w-4xl items-end gap-2 rounded-2xl border p-1.5 md:p-2"
                    >
                        <textarea
                            ref={textareaRef}
                            value={input}
                            onChange={(e) => setInput(e.target.value)}
                            onKeyDown={handleKeyDown}
                            placeholder="Escribe tu mensaje..."
                            className="placeholder:text-muted-foreground max-h-[120px] min-h-[40px] flex-1 resize-none bg-transparent px-2.5 py-2 text-sm outline-none md:max-h-[200px] md:min-h-[44px] md:px-3 md:py-2.5"
                            rows={1}
                            disabled={isStreaming}
                        />
                        <Button
                            type="submit"
                            size="icon"
                            disabled={!input.trim() || isStreaming}
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
        </CopilotLayout>
    );
}
