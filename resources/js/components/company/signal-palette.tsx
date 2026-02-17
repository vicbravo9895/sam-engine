import { AlertTriangle, GripVertical } from 'lucide-react';
import type { DragEvent } from 'react';

interface SignalPaletteProps {
    labels: string[];
    labelTranslations: Record<string, string>;
    usedLabels: Set<string>;
}

export function SignalPalette({ labels, labelTranslations, usedLabels }: SignalPaletteProps) {
    const onDragStart = (event: DragEvent, label: string) => {
        event.dataTransfer.setData('application/reactflow-label', label);
        event.dataTransfer.effectAllowed = 'move';
    };

    const available = labels.filter((l) => !usedLabels.has(l));

    return (
        <div className="flex h-full w-56 shrink-0 flex-col border-r">
            <div className="border-b px-3 py-2">
                <h4 className="text-sm font-semibold">Señales disponibles</h4>
                <p className="text-muted-foreground text-xs">
                    Arrastra al canvas para crear regla
                </p>
            </div>
            <div className="flex-1 space-y-1 overflow-y-auto p-2">
                {available.length === 0 && (
                    <p className="text-muted-foreground px-2 py-4 text-center text-xs">
                        Todas las señales están en uso
                    </p>
                )}
                {available.map((label) => (
                    <div
                        key={label}
                        draggable
                        onDragStart={(e) => onDragStart(e, label)}
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
        </div>
    );
}
