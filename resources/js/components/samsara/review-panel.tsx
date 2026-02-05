import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import {
    type EventActivity,
    type EventComment,
    type HumanStatus,
    HUMAN_STATUS_OPTIONS,
} from '@/types/samsara';
import { router } from '@inertiajs/react';
import {
    AlertCircle,
    Camera,
    CheckCircle2,
    Clock,
    Cpu,
    Eye,
    Flag,
    Loader2,
    MessageSquare,
    RefreshCw,
    Search,
    Send,
    Slash,
    Sparkles,
    ToggleRight,
    User,
    Wrench,
    XCircle,
    Zap,
} from 'lucide-react';
import { type LucideIcon } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

// Types for AI Timeline
interface ToolUsage {
    tool_name?: string;
    status_label?: string;
    duration_ms?: number;
}

interface AITimelineStep {
    step: number;
    name: string;
    title: string;
    description: string;
    started_at?: string | null;
    completed_at?: string | null;
    duration_ms?: number | null;
    summary: string;
    tools_used: ToolUsage[];
}

interface ReviewPanelProps {
    eventId: number;
    currentStatus: HumanStatus;
    onStatusChange?: (newStatus: HumanStatus) => void;
    aiTimeline?: AITimelineStep[];
    aiTotalDuration?: number;
    aiTotalTools?: number;
}

const statusStyles: Record<HumanStatus, string> = {
    pending: 'bg-slate-100 text-slate-800 dark:bg-slate-500/20 dark:text-slate-200 border-slate-300',
    reviewed: 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-200 border-blue-300',
    flagged: 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200 border-amber-300',
    resolved: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200 border-emerald-300',
    false_positive: 'bg-slate-100 text-slate-600 dark:bg-slate-500/20 dark:text-slate-300 border-slate-300',
};

const getStatusIcon = (status: HumanStatus): LucideIcon => {
    switch (status) {
        case 'reviewed':
            return Eye;
        case 'flagged':
            return Flag;
        case 'resolved':
            return CheckCircle2;
        case 'false_positive':
            return Slash;
        default:
            return Clock;
    }
};

const getActivityIcon = (iconName: string): LucideIcon => {
    switch (iconName) {
        case 'cpu':
            return Cpu;
        case 'check-circle':
        case 'check-circle-2':
            return CheckCircle2;
        case 'x-circle':
            return XCircle;
        case 'search':
            return Search;
        case 'refresh-cw':
            return RefreshCw;
        case 'eye':
            return Eye;
        case 'toggle-right':
            return ToggleRight;
        case 'message-square':
            return MessageSquare;
        case 'slash':
            return Slash;
        case 'flag':
            return Flag;
        case 'zap':
            return Zap;
        case 'sparkles':
            return Sparkles;
        case 'camera':
            return Camera;
        case 'wrench':
            return Wrench;
        default:
            return AlertCircle;
    }
};

const getAgentIcon = (agentName: string): LucideIcon => {
    switch (agentName) {
        case 'ingestion_agent':
            return Zap;
        case 'panic_investigator':
            return Search;
        case 'final_agent':
            return MessageSquare;
        case 'notification_decision_agent':
            return Sparkles;
        default:
            return Cpu;
    }
};

const formatDuration = (ms?: number | null): string => {
    if (!ms) return '';
    if (ms < 1000) return `${ms}ms`;
    return `${(ms / 1000).toFixed(1)}s`;
};

export function ReviewPanel({ 
    eventId, 
    currentStatus, 
    onStatusChange,
    aiTimeline = [],
    aiTotalDuration,
    aiTotalTools,
}: ReviewPanelProps) {
    const [status, setStatus] = useState<HumanStatus>(currentStatus);
    const [comments, setComments] = useState<EventComment[]>([]);
    const [activities, setActivities] = useState<EventActivity[]>([]);
    const [newComment, setNewComment] = useState('');
    const [isLoadingComments, setIsLoadingComments] = useState(true);
    const [isLoadingActivities, setIsLoadingActivities] = useState(true);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [isChangingStatus, setIsChangingStatus] = useState(false);
    const [activeTab, setActiveTab] = useState<'comments' | 'activity'>('comments');

    // Check if we have AI timeline data
    const hasAITimeline = aiTimeline.length > 0;

    // Fetch comments
    const fetchComments = useCallback(async () => {
        try {
            setIsLoadingComments(true);
            const response = await fetch(`/api/events/${eventId}/comments`);
            const data = await response.json();
            setComments(data.data);
        } catch {
            // Error fetching comments
        } finally {
            setIsLoadingComments(false);
        }
    }, [eventId]);

    // Fetch activities
    const fetchActivities = useCallback(async () => {
        try {
            setIsLoadingActivities(true);
            const response = await fetch(`/api/events/${eventId}/activities`);
            const data = await response.json();
            setActivities(data.data);
        } catch {
            // Error fetching activities
        } finally {
            setIsLoadingActivities(false);
        }
    }, [eventId]);

    useEffect(() => {
        fetchComments();
        fetchActivities();
    }, [fetchComments, fetchActivities]);

    // Handle status change
    const handleStatusChange = async (newStatus: HumanStatus) => {
        if (newStatus === status) return;

        try {
            setIsChangingStatus(true);
            const response = await fetch(`/api/events/${eventId}/status`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(
                        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''
                    ),
                },
                credentials: 'include',
                body: JSON.stringify({ status: newStatus }),
            });

            if (!response.ok) {
                throw new Error('Failed to update status');
            }

            setStatus(newStatus);
            onStatusChange?.(newStatus);
            fetchActivities();
            // Refresh page data
            router.reload({ only: ['events', 'stats'] });
        } catch {
            // Error changing status
        } finally {
            setIsChangingStatus(false);
        }
    };

    // Handle comment submit
    const handleSubmitComment = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!newComment.trim()) return;

        try {
            setIsSubmitting(true);
            const response = await fetch(`/api/events/${eventId}/comments`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(
                        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''
                    ),
                },
                credentials: 'include',
                body: JSON.stringify({ content: newComment.trim() }),
            });

            if (!response.ok) {
                throw new Error('Failed to add comment');
            }

            const data = await response.json();
            setComments([data.data, ...comments]);
            setNewComment('');
            fetchActivities();
        } catch {
            // Error submitting comment
        } finally {
            setIsSubmitting(false);
        }
    };

    const StatusIcon = getStatusIcon(status);

    return (
        <div className="space-y-4">
            {/* Current Status */}
            <div className="rounded-lg border bg-muted/30 p-4">
                <div className="flex items-center justify-between mb-3">
                    <div className="flex items-center gap-2">
                        <StatusIcon className="size-4" />
                        <span className="text-sm font-medium">Estado de revisión</span>
                    </div>
                    <Badge className={statusStyles[status]}>
                        {HUMAN_STATUS_OPTIONS.find(o => o.value === status)?.label}
                    </Badge>
                </div>

                {/* Status buttons */}
                <div className="flex flex-wrap gap-2">
                    {HUMAN_STATUS_OPTIONS.filter(o => o.value !== 'pending').map((option) => {
                        const Icon = getStatusIcon(option.value);
                        const isActive = status === option.value;
                        return (
                            <Button
                                key={option.value}
                                variant={isActive ? 'default' : 'outline'}
                                size="sm"
                                onClick={() => handleStatusChange(option.value)}
                                disabled={isChangingStatus}
                                className="gap-1.5"
                            >
                                {isChangingStatus ? (
                                    <Loader2 className="size-3.5 animate-spin" />
                                ) : (
                                    <Icon className="size-3.5" />
                                )}
                                {option.label}
                            </Button>
                        );
                    })}
                </div>
            </div>

            {/* Tabs */}
            <div className="flex border-b">
                <button
                    onClick={() => setActiveTab('comments')}
                    className={`flex items-center gap-2 px-4 py-2 text-sm font-medium border-b-2 transition-colors ${activeTab === 'comments'
                            ? 'border-primary text-primary'
                            : 'border-transparent text-muted-foreground hover:text-foreground'
                        }`}
                >
                    <MessageSquare className="size-4" />
                    Comentarios
                    {comments.length > 0 && (
                        <Badge variant="secondary" className="text-xs px-1.5 py-0">
                            {comments.length}
                        </Badge>
                    )}
                </button>
                <button
                    onClick={() => setActiveTab('activity')}
                    className={`flex items-center gap-2 px-4 py-2 text-sm font-medium border-b-2 transition-colors ${activeTab === 'activity'
                            ? 'border-primary text-primary'
                            : 'border-transparent text-muted-foreground hover:text-foreground'
                        }`}
                >
                    <Clock className="size-4" />
                    Actividad
                </button>
            </div>

            {/* Comments Tab */}
            {activeTab === 'comments' && (
                <div className="space-y-4">
                    {/* Add comment form */}
                    <form onSubmit={handleSubmitComment} className="space-y-2">
                        <Textarea
                            placeholder="Escribe un comentario..."
                            value={newComment}
                            onChange={(e) => setNewComment(e.target.value)}
                            rows={2}
                            className="resize-none"
                        />
                        <div className="flex justify-end">
                            <Button
                                type="submit"
                                size="sm"
                                disabled={!newComment.trim() || isSubmitting}
                                className="gap-2"
                            >
                                {isSubmitting ? (
                                    <Loader2 className="size-4 animate-spin" />
                                ) : (
                                    <Send className="size-4" />
                                )}
                                Enviar
                            </Button>
                        </div>
                    </form>

                    {/* Comments list */}
                    <div className="space-y-3 max-h-64 overflow-y-auto">
                        {isLoadingComments ? (
                            <div className="flex items-center justify-center py-8">
                                <Loader2 className="size-6 animate-spin text-muted-foreground" />
                            </div>
                        ) : comments.length === 0 ? (
                            <div className="text-center py-8 text-muted-foreground">
                                <MessageSquare className="size-8 mx-auto mb-2 opacity-50" />
                                <p className="text-sm">Sin comentarios aún</p>
                            </div>
                        ) : (
                            comments.map((comment) => (
                                <div
                                    key={comment.id}
                                    className="rounded-lg border bg-card p-3 space-y-1"
                                >
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2 text-sm">
                                            <User className="size-3.5 text-muted-foreground" />
                                            <span className="font-medium">
                                                {comment.user?.name ?? 'Usuario'}
                                            </span>
                                        </div>
                                        <span className="text-xs text-muted-foreground">
                                            {comment.created_at_human}
                                        </span>
                                    </div>
                                    <p className="text-sm text-muted-foreground whitespace-pre-wrap">
                                        {comment.content}
                                    </p>
                                </div>
                            ))
                        )}
                    </div>
                </div>
            )}

            {/* Activity Tab */}
            {activeTab === 'activity' && (
                <div className="space-y-4 max-h-[500px] overflow-y-auto">
                    {/* AI Pipeline Timeline */}
                    {hasAITimeline && (
                        <div className="space-y-3">
                            <div className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                                <Zap className="size-4" />
                                <span>Procesamiento AI</span>
                                {aiTotalDuration && (
                                    <Badge variant="outline" className="text-xs">
                                        {formatDuration(aiTotalDuration)}
                                    </Badge>
                                )}
                                {aiTotalTools !== undefined && aiTotalTools > 0 && (
                                    <Badge variant="secondary" className="text-xs">
                                        {aiTotalTools} herramientas
                                    </Badge>
                                )}
                            </div>
                            
                            <div className="relative space-y-2 pl-4 border-l-2 border-primary/20">
                                {aiTimeline.map((step) => {
                                    const StepIcon = getAgentIcon(step.name);
                                    return (
                                        <div
                                            key={`ai-${step.step}-${step.name}`}
                                            className="relative"
                                        >
                                            {/* Connection dot */}
                                            <div className="absolute -left-[21px] top-3 size-2.5 rounded-full bg-primary/60 ring-2 ring-background" />
                                            
                                            <div className="rounded-lg border bg-primary/5 border-primary/20 p-3">
                                                <div className="flex items-start gap-3">
                                                    <div className="rounded-full bg-primary/10 p-1.5 text-primary shrink-0">
                                                        <StepIcon className="size-3.5" />
                                                    </div>
                                                    <div className="flex-1 min-w-0">
                                                        <div className="flex items-center justify-between gap-2">
                                                            <p className="text-sm font-medium">
                                                                {step.title}
                                                            </p>
                                                            {step.duration_ms && (
                                                                <Badge variant="outline" className="text-xs shrink-0">
                                                                    {formatDuration(step.duration_ms)}
                                                                </Badge>
                                                            )}
                                                        </div>
                                                        <p className="text-xs text-muted-foreground mt-0.5">
                                                            {step.description}
                                                        </p>
                                                        {step.summary && step.summary !== 'Sin información generada para este paso.' && (
                                                            <p className="text-xs text-foreground/80 mt-1">
                                                                {step.summary}
                                                            </p>
                                                        )}
                                                        {step.tools_used.length > 0 && (
                                                            <div className="flex flex-wrap gap-1 mt-2">
                                                                {step.tools_used.map((tool, idx) => (
                                                                    <Badge 
                                                                        key={idx} 
                                                                        variant="secondary" 
                                                                        className="text-xs py-0"
                                                                    >
                                                                        <Wrench className="size-2.5 mr-1" />
                                                                        {tool.tool_name}
                                                                    </Badge>
                                                                ))}
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}

                    {/* Human Activities */}
                    {(activities.length > 0 || !hasAITimeline) && (
                        <div className="space-y-3">
                            {hasAITimeline && activities.length > 0 && (
                                <div className="flex items-center gap-2 text-sm font-medium text-muted-foreground pt-2">
                                    <User className="size-4" />
                                    <span>Actividad humana</span>
                                </div>
                            )}
                            
                            {isLoadingActivities ? (
                                <div className="flex items-center justify-center py-8">
                                    <Loader2 className="size-6 animate-spin text-muted-foreground" />
                                </div>
                            ) : activities.length === 0 && !hasAITimeline ? (
                                <div className="text-center py-8 text-muted-foreground">
                                    <Clock className="size-8 mx-auto mb-2 opacity-50" />
                                    <p className="text-sm">Sin actividad registrada</p>
                                </div>
                            ) : activities.length === 0 ? (
                                <div className="text-center py-4 text-muted-foreground">
                                    <p className="text-sm">Sin actividad humana registrada</p>
                                </div>
                            ) : (
                                <div className="space-y-2">
                                    {activities.map((activity) => {
                                        const Icon = getActivityIcon(activity.action_icon);
                                        return (
                                            <div
                                                key={activity.id}
                                                className={`flex items-start gap-3 rounded-lg border p-3 ${activity.is_ai_action
                                                        ? 'bg-primary/5 border-primary/20'
                                                        : 'bg-card'
                                                    }`}
                                            >
                                                <div
                                                    className={`rounded-full p-1.5 ${activity.is_ai_action
                                                            ? 'bg-primary/10 text-primary'
                                                            : 'bg-muted text-muted-foreground'
                                                        }`}
                                                >
                                                    <Icon className="size-3.5" />
                                                </div>
                                                <div className="flex-1 min-w-0">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <p className="text-sm font-medium truncate">
                                                            {activity.action_label}
                                                        </p>
                                                        <span className="text-xs text-muted-foreground shrink-0">
                                                            {activity.created_at_human}
                                                        </span>
                                                    </div>
                                                    <p className="text-xs text-muted-foreground">
                                                        {activity.is_ai_action
                                                            ? 'Sistema AI'
                                                            : activity.user?.name ?? 'Usuario'}
                                                    </p>
                                                    {activity.metadata && (
                                                        <div className="mt-1 text-xs text-muted-foreground">
                                                            {typeof activity.metadata.old_status === 'string' && 
                                                             typeof activity.metadata.new_status === 'string' && (
                                                                <span>
                                                                    {HUMAN_STATUS_OPTIONS.find(o => o.value === activity.metadata?.old_status)?.label}
                                                                    {' → '}
                                                                    {HUMAN_STATUS_OPTIONS.find(o => o.value === activity.metadata?.new_status)?.label}
                                                                </span>
                                                            )}
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

