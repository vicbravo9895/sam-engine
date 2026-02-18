import { CHANNEL_TYPES, RECIPIENT_TYPES } from './flow-nodes';
import {
    AlertTriangle,
    ChevronDown,
    ChevronRight,
    GitMerge,
    GripVertical,
    Search,
} from 'lucide-react';
import { useMemo, useState, type DragEvent } from 'react';

interface SignalPaletteProps {
    labels: string[];
    labelTranslations: Record<string, string>;
    onAddAndNode: () => void;
}

export function SignalPalette({ labels, labelTranslations, onAddAndNode }: SignalPaletteProps) {
    const [search, setSearch] = useState('');
    const [channelsOpen, setChannelsOpen] = useState(true);
    const [recipientsOpen, setRecipientsOpen] = useState(true);

    const filtered = useMemo(() => {
        if (!search.trim()) return labels;
        const q = search.toLowerCase();
        return labels.filter((l) => {
            const display = labelTranslations[l] ?? l;
            return display.toLowerCase().includes(q) || l.toLowerCase().includes(q);
        });
    }, [labels, labelTranslations, search]);

    const onDragStartSignal = (event: DragEvent, label: string) => {
        event.dataTransfer.setData('application/reactflow-label', label);
        event.dataTransfer.effectAllowed = 'move';
    };

    const onDragStartChannel = (event: DragEvent, channel: string) => {
        event.dataTransfer.setData('application/reactflow-channel', channel);
        event.dataTransfer.effectAllowed = 'move';
    };

    const onDragStartRecipient = (event: DragEvent, recipient: string) => {
        event.dataTransfer.setData('application/reactflow-recipient', recipient);
        event.dataTransfer.effectAllowed = 'move';
    };

    const channelEntries = Object.entries(CHANNEL_TYPES) as [string, (typeof CHANNEL_TYPES)[keyof typeof CHANNEL_TYPES]][];
    const recipientEntries = Object.entries(RECIPIENT_TYPES) as [string, (typeof RECIPIENT_TYPES)[keyof typeof RECIPIENT_TYPES]][];

    return (
        <div className="flex h-full w-56 shrink-0 flex-col border-r">
            <div className="space-y-2 border-b px-3 py-2">
                <h4 className="text-sm font-semibold">Señales</h4>
                <div className="relative">
                    <Search className="text-muted-foreground pointer-events-none absolute left-2 top-1/2 size-3.5 -translate-y-1/2" />
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Buscar señal..."
                        className="border-input bg-background placeholder:text-muted-foreground h-8 w-full rounded-md border pl-7 pr-2 text-xs focus:outline-none focus:ring-1 focus:ring-violet-400"
                    />
                </div>
            </div>

            <div className="flex-1 space-y-0 overflow-y-auto">
                {/* Signals */}
                <div className="space-y-1 p-2">
                    {filtered.length === 0 && (
                        <p className="text-muted-foreground px-2 py-4 text-center text-xs">
                            Sin resultados
                        </p>
                    )}
                    {filtered.map((label) => (
                        <div
                            key={label}
                            draggable
                            onDragStart={(e) => onDragStartSignal(e, label)}
                            className="flex cursor-grab items-center gap-2 rounded-md border border-amber-200 bg-amber-50/50 px-2 py-1.5 text-sm transition-colors hover:border-amber-300 hover:bg-amber-100/50 active:cursor-grabbing dark:border-amber-800 dark:bg-amber-950/50 dark:hover:border-amber-700 dark:hover:bg-amber-900/50"
                        >
                            <GripVertical className="text-muted-foreground size-3 shrink-0" />
                            <AlertTriangle className="size-3 shrink-0 text-amber-600 dark:text-amber-400" />
                            <span className="truncate text-xs font-medium">
                                {labelTranslations[label] ?? label}
                            </span>
                        </div>
                    ))}
                </div>

                {/* Channels */}
                <div className="border-t">
                    <button
                        type="button"
                        onClick={() => setChannelsOpen(!channelsOpen)}
                        className="flex w-full items-center gap-1.5 px-3 py-2 text-left text-sm font-semibold hover:bg-muted/50"
                    >
                        {channelsOpen ? (
                            <ChevronDown className="size-3.5" />
                        ) : (
                            <ChevronRight className="size-3.5" />
                        )}
                        Canales
                    </button>
                    {channelsOpen && (
                        <div className="space-y-1 px-2 pb-2">
                            {channelEntries.map(([key, cfg]) => {
                                const Icon = cfg.icon;
                                return (
                                    <div
                                        key={key}
                                        draggable
                                        onDragStart={(e) => onDragStartChannel(e, key)}
                                        className="flex cursor-grab items-center gap-2 rounded-md border border-sky-200 bg-sky-50/50 px-2 py-1.5 text-sm transition-colors hover:border-sky-300 hover:bg-sky-100/50 active:cursor-grabbing dark:border-sky-800 dark:bg-sky-950/50 dark:hover:border-sky-700 dark:hover:bg-sky-900/50"
                                    >
                                        <GripVertical className="text-muted-foreground size-3 shrink-0" />
                                        <Icon className="size-3 shrink-0 text-sky-600 dark:text-sky-400" />
                                        <span className="truncate text-xs font-medium">
                                            {cfg.label}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>

                {/* Recipients */}
                <div className="border-t">
                    <button
                        type="button"
                        onClick={() => setRecipientsOpen(!recipientsOpen)}
                        className="flex w-full items-center gap-1.5 px-3 py-2 text-left text-sm font-semibold hover:bg-muted/50"
                    >
                        {recipientsOpen ? (
                            <ChevronDown className="size-3.5" />
                        ) : (
                            <ChevronRight className="size-3.5" />
                        )}
                        Destinatarios
                    </button>
                    {recipientsOpen && (
                        <div className="space-y-1 px-2 pb-2">
                            {recipientEntries.map(([key, cfg]) => {
                                const Icon = cfg.icon;
                                return (
                                    <div
                                        key={key}
                                        draggable
                                        onDragStart={(e) => onDragStartRecipient(e, key)}
                                        className="flex cursor-grab items-center gap-2 rounded-md border border-teal-200 bg-teal-50/50 px-2 py-1.5 text-sm transition-colors hover:border-teal-300 hover:bg-teal-100/50 active:cursor-grabbing dark:border-teal-800 dark:bg-teal-950/50 dark:hover:border-teal-700 dark:hover:bg-teal-900/50"
                                    >
                                        <GripVertical className="text-muted-foreground size-3 shrink-0" />
                                        <Icon className="size-3 shrink-0 text-teal-600 dark:text-teal-400" />
                                        <span className="truncate text-xs font-medium">
                                            {cfg.label}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
            </div>

            <div className="border-t p-2">
                <button
                    type="button"
                    onClick={onAddAndNode}
                    className="flex w-full items-center justify-center gap-2 rounded-md border border-violet-200 bg-violet-50/50 px-2 py-1.5 text-xs font-medium text-violet-700 transition-colors hover:border-violet-300 hover:bg-violet-100 dark:border-violet-800 dark:bg-violet-950/50 dark:text-violet-300 dark:hover:border-violet-700 dark:hover:bg-violet-900/50"
                >
                    <GitMerge className="size-3.5" />
                    Añadir nodo AND
                </button>
            </div>
        </div>
    );
}
