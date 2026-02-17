import { Handle, Position, type NodeProps } from '@xyflow/react';
import { AlertTriangle, Bell, GitMerge, X } from 'lucide-react';

export interface TriggerNodeData {
    label: string;
    displayLabel: string;
    onDelete?: (id: string) => void;
    [key: string]: unknown;
}

export interface AndNodeData {
    ruleId: string;
    onDelete?: (id: string) => void;
    [key: string]: unknown;
}

export interface NotifyNodeData {
    label?: string;
    [key: string]: unknown;
}

export function TriggerNode({ id, data }: NodeProps) {
    const nodeData = data as TriggerNodeData;

    return (
        <div className="group relative flex items-center gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 shadow-sm transition-shadow hover:shadow-md dark:border-amber-700 dark:bg-amber-950">
            <AlertTriangle className="size-4 shrink-0 text-amber-600 dark:text-amber-400" />
            <span className="max-w-40 truncate text-sm font-medium text-amber-900 dark:text-amber-100">
                {nodeData.displayLabel}
            </span>
            {nodeData.onDelete && (
                <button
                    type="button"
                    onClick={(e) => {
                        e.stopPropagation();
                        nodeData.onDelete!(id);
                    }}
                    className="ml-1 rounded p-0.5 text-amber-500 opacity-0 transition-opacity hover:bg-amber-200 hover:text-amber-700 group-hover:opacity-100 dark:hover:bg-amber-800 dark:hover:text-amber-300"
                >
                    <X className="size-3" />
                </button>
            )}
            <Handle
                type="source"
                position={Position.Right}
                className="!size-2.5 !border-amber-400 !bg-amber-500"
            />
        </div>
    );
}

export function AndNode({ id, data }: NodeProps) {
    const nodeData = data as AndNodeData;

    return (
        <div className="group relative flex items-center gap-2 rounded-lg border border-violet-300 bg-violet-50 px-3 py-2 shadow-sm transition-shadow hover:shadow-md dark:border-violet-700 dark:bg-violet-950">
            <Handle
                type="target"
                position={Position.Left}
                isConnectable
                className="!size-2.5 !border-violet-400 !bg-violet-500"
            />
            <GitMerge className="size-4 shrink-0 text-violet-600 dark:text-violet-400" />
            <span className="text-xs font-semibold tracking-wider text-violet-700 dark:text-violet-300">
                AND
            </span>
            {nodeData.onDelete && (
                <button
                    type="button"
                    onClick={(e) => {
                        e.stopPropagation();
                        nodeData.onDelete!(id);
                    }}
                    className="ml-1 rounded p-0.5 text-violet-500 opacity-0 transition-opacity hover:bg-violet-200 hover:text-violet-700 group-hover:opacity-100 dark:hover:bg-violet-800 dark:hover:text-violet-300"
                >
                    <X className="size-3" />
                </button>
            )}
            <Handle
                type="source"
                position={Position.Right}
                className="!size-2.5 !border-violet-400 !bg-violet-500"
            />
        </div>
    );
}

export function NotifyNode({ data }: NodeProps) {
    const nodeData = data as NotifyNodeData;

    return (
        <div className="flex items-center gap-2 rounded-lg border-2 border-emerald-400 bg-emerald-50 px-4 py-3 shadow-md dark:border-emerald-600 dark:bg-emerald-950">
            <Handle
                type="target"
                position={Position.Left}
                isConnectable
                className="!size-3 !border-emerald-400 !bg-emerald-500"
            />
            <Bell className="size-5 text-emerald-600 dark:text-emerald-400" />
            <div>
                <p className="text-sm font-semibold text-emerald-900 dark:text-emerald-100">
                    {nodeData.label ?? 'Notificación'}
                </p>
                <p className="text-xs text-emerald-600 dark:text-emerald-400">
                    Pipeline IA + Notificar
                </p>
            </div>
        </div>
    );
}

export const nodeTypes = {
    trigger: TriggerNode,
    and: AndNode,
    notify: NotifyNode,
} as const;
