import { Handle, Position, type NodeProps } from '@xyflow/react';
import {
    AlertTriangle,
    Bell,
    Bot,
    GitMerge,
    MessageSquare,
    Phone,
    Shield,
    Smartphone,
    Siren,
    Truck,
    Users,
    UserCircle,
    X,
    Zap,
} from 'lucide-react';

export const ACTION_TYPES = {
    ai_pipeline: { label: 'Pipeline IA', icon: Bot, color: 'violet' },
    notify_immediate: { label: 'Alerta inmediata', icon: Zap, color: 'amber' },
    both: { label: 'Ambos', icon: Bell, color: 'emerald' },
} as const;

export type ActionType = keyof typeof ACTION_TYPES;

export const CHANNEL_TYPES = {
    whatsapp: { label: 'WhatsApp', icon: MessageSquare },
    sms: { label: 'SMS', icon: Smartphone },
    call: { label: 'Llamada', icon: Phone },
} as const;

export type ChannelType = keyof typeof CHANNEL_TYPES;

export const RECIPIENT_TYPES = {
    monitoring_team: { label: 'Equipo de monitoreo', icon: Users },
    supervisor: { label: 'Supervisor', icon: Shield },
    operator: { label: 'Operador', icon: UserCircle },
    emergency: { label: 'Emergencia', icon: Siren },
    dispatch: { label: 'Despacho', icon: Truck },
} as const;

export type RecipientType = keyof typeof RECIPIENT_TYPES;

export interface TriggerNodeData {
    label: string;
    displayLabel: string;
    onDelete?: (id: string) => void;
    [key: string]: unknown;
}

export interface AndNodeData {
    ruleId: string;
    action: ActionType;
    onDelete?: (id: string) => void;
    onCycleAction?: (id: string) => void;
    [key: string]: unknown;
}

export interface ChannelNodeData {
    channel: ChannelType;
    displayLabel: string;
    onDelete?: (id: string) => void;
    [key: string]: unknown;
}

export interface RecipientNodeData {
    recipientType: RecipientType;
    displayLabel: string;
    onDelete?: (id: string) => void;
    [key: string]: unknown;
}

const ACTION_ORDER: ActionType[] = ['ai_pipeline', 'notify_immediate', 'both'];

export function TriggerNode({ id, data }: NodeProps) {
    const d = data as TriggerNodeData;
    return (
        <div className="group relative flex items-center gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 shadow-sm transition-shadow hover:shadow-md dark:border-amber-700 dark:bg-amber-950">
            <AlertTriangle className="size-4 shrink-0 text-amber-600 dark:text-amber-400" />
            <span className="max-w-40 truncate text-sm font-medium text-amber-900 dark:text-amber-100">
                {d.displayLabel}
            </span>
            {d.onDelete && (
                <button
                    type="button"
                    onClick={(e) => {
                        e.stopPropagation();
                        d.onDelete!(id);
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
    const d = data as AndNodeData;
    const raw = d.action ?? 'ai_pipeline';
    const action: ActionType = raw in ACTION_TYPES ? (raw as ActionType) : 'ai_pipeline';
    const cfg = ACTION_TYPES[action];
    const Icon = cfg.icon;

    const cycle = () => {
        d.onCycleAction?.(id);
    };

    const colorMap = {
        violet: {
            border: 'border-violet-300 dark:border-violet-700',
            bg: 'bg-violet-50 dark:bg-violet-950',
            text: 'text-violet-700 dark:text-violet-300',
            icon: 'text-violet-600 dark:text-violet-400',
            badge: 'bg-violet-100 text-violet-700 dark:bg-violet-900 dark:text-violet-300',
            del: 'text-violet-500 hover:bg-violet-200 hover:text-violet-700 dark:hover:bg-violet-800 dark:hover:text-violet-300',
            handle: '!border-violet-400 !bg-violet-500',
        },
        amber: {
            border: 'border-amber-300 dark:border-amber-700',
            bg: 'bg-amber-50 dark:bg-amber-950',
            text: 'text-amber-700 dark:text-amber-300',
            icon: 'text-amber-600 dark:text-amber-400',
            badge: 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300',
            del: 'text-amber-500 hover:bg-amber-200 hover:text-amber-700 dark:hover:bg-amber-800 dark:hover:text-amber-300',
            handle: '!border-amber-400 !bg-amber-500',
        },
        emerald: {
            border: 'border-emerald-300 dark:border-emerald-700',
            bg: 'bg-emerald-50 dark:bg-emerald-950',
            text: 'text-emerald-700 dark:text-emerald-300',
            icon: 'text-emerald-600 dark:text-emerald-400',
            badge: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-300',
            del: 'text-emerald-500 hover:bg-emerald-200 hover:text-emerald-700 dark:hover:bg-emerald-800 dark:hover:text-emerald-300',
            handle: '!border-emerald-400 !bg-emerald-500',
        },
    } as const;

    const c = colorMap[cfg.color];

    return (
        <div
            className={`group relative flex items-center gap-2 rounded-lg border ${c.border} ${c.bg} px-3 py-2 shadow-sm transition-shadow hover:shadow-md`}
        >
            <Handle
                type="target"
                position={Position.Left}
                isConnectable
                className={`!size-2.5 ${c.handle}`}
            />
            <GitMerge className={`size-4 shrink-0 ${c.icon}`} />
            <span className={`text-xs font-semibold tracking-wider ${c.text}`}>AND</span>

            <button
                type="button"
                onClick={(e) => {
                    e.stopPropagation();
                    cycle();
                }}
                title="Cambiar acción"
                className={`flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-medium transition-colors ${c.badge} hover:opacity-80`}
            >
                <Icon className="size-3" />
                {cfg.label}
            </button>

            {d.onDelete && (
                <button
                    type="button"
                    onClick={(e) => {
                        e.stopPropagation();
                        d.onDelete!(id);
                    }}
                    className={`ml-0.5 rounded p-0.5 opacity-0 transition-opacity group-hover:opacity-100 ${c.del}`}
                >
                    <X className="size-3" />
                </button>
            )}
            <Handle
                type="source"
                position={Position.Right}
                isConnectable
                className={`!size-2.5 ${c.handle}`}
            />
        </div>
    );
}

export function ChannelNode({ id, data }: NodeProps) {
    const d = data as ChannelNodeData;
    const channel = d.channel in CHANNEL_TYPES ? d.channel : 'whatsapp';
    const cfg = CHANNEL_TYPES[channel];
    const Icon = cfg.icon;

    return (
        <div className="group relative flex items-center gap-2 rounded-lg border border-sky-300 bg-sky-50 px-3 py-2 shadow-sm transition-shadow hover:shadow-md dark:border-sky-700 dark:bg-sky-950">
            <Handle
                type="target"
                position={Position.Left}
                isConnectable
                className="!size-2.5 !border-sky-400 !bg-sky-500"
            />
            <Icon className="size-4 shrink-0 text-sky-600 dark:text-sky-400" />
            <span className="max-w-32 truncate text-sm font-medium text-sky-900 dark:text-sky-100">
                {d.displayLabel}
            </span>
            {d.onDelete && (
                <button
                    type="button"
                    onClick={(e) => {
                        e.stopPropagation();
                        d.onDelete!(id);
                    }}
                    className="ml-1 rounded p-0.5 text-sky-500 opacity-0 transition-opacity hover:bg-sky-200 hover:text-sky-700 group-hover:opacity-100 dark:hover:bg-sky-800 dark:hover:text-sky-300"
                >
                    <X className="size-3" />
                </button>
            )}
        </div>
    );
}

export function RecipientNode({ id, data }: NodeProps) {
    const d = data as RecipientNodeData;
    const rType = d.recipientType in RECIPIENT_TYPES ? d.recipientType : 'monitoring_team';
    const cfg = RECIPIENT_TYPES[rType];
    const Icon = cfg.icon;

    return (
        <div className="group relative flex items-center gap-2 rounded-lg border border-teal-300 bg-teal-50 px-3 py-2 shadow-sm transition-shadow hover:shadow-md dark:border-teal-700 dark:bg-teal-950">
            <Handle
                type="target"
                position={Position.Left}
                isConnectable
                className="!size-2.5 !border-teal-400 !bg-teal-500"
            />
            <Icon className="size-4 shrink-0 text-teal-600 dark:text-teal-400" />
            <span className="max-w-36 truncate text-sm font-medium text-teal-900 dark:text-teal-100">
                {d.displayLabel}
            </span>
            {d.onDelete && (
                <button
                    type="button"
                    onClick={(e) => {
                        e.stopPropagation();
                        d.onDelete!(id);
                    }}
                    className="ml-1 rounded p-0.5 text-teal-500 opacity-0 transition-opacity hover:bg-teal-200 hover:text-teal-700 group-hover:opacity-100 dark:hover:bg-teal-800 dark:hover:text-teal-300"
                >
                    <X className="size-3" />
                </button>
            )}
        </div>
    );
}

export function nextAction(current: ActionType): ActionType {
    const idx = ACTION_ORDER.indexOf(current);
    return ACTION_ORDER[(idx + 1) % ACTION_ORDER.length];
}

export const nodeTypes = {
    trigger: TriggerNode,
    and: AndNode,
    channel: ChannelNode,
    recipient: RecipientNode,
} as const;
