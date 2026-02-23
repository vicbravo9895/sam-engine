import { FadeIn, SlideUp, StaggerContainer, StaggerItem } from '@/components/motion';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { ReviewPanel } from '@/components/samsara/review-panel';
import { type HumanStatus } from '@/types/samsara';
import type { UnifiedTimelineEntry } from '@/types/samsara';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    Bell,
    Bookmark,
    Camera,
    CheckCircle,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Clock,
    Cpu,
    ImageIcon,
    Loader2,
    Radio,
    Send,
    Shield,
    User,
    XCircle,
} from 'lucide-react';
import { type LucideIcon } from 'lucide-react';
import { formatDistanceToNow } from 'date-fns';
import { es } from 'date-fns/locale';
import { useCallback, useEffect, useState } from 'react';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface PayloadSummaryItem {
    label: string;
    value: string;
}

interface MediaInsight {
    camera?: string;
    analysis?: string;
    analysis_preview?: string;
    url?: string | null;
    download_url?: string | null;
}

interface AssessmentView {
    verdict?: string | null;
    likelihood?: string | null;
    reasoning?: string | null;
    evidence?: { label: string; value: string | Record<string, unknown> }[];
}

interface NotificationDecision {
    should_notify?: boolean;
    channels_to_use?: string[];
    recipients?: { name?: string; phone?: string; type?: string }[];
    reason?: string;
    /** Texto amigable para el usuario (backend reemplaza jerga técnica) */
    reason_display?: string | null;
}

interface NotificationExecution {
    attempted?: boolean;
    results?: { channel?: string; success?: boolean; error?: string }[];
    throttled?: boolean;
}

interface CompanyUser {
    id: number;
    name: string;
}

interface EventPayload {
    id: number;
    severity: string;
    severity_label?: string | null;
    verdict_badge?: { verdict: string; likelihood?: string | null; urgency: string; color: string } | null;
    display_event_type?: string | null;
    ai_assessment?: { confidence?: number | string | null } | null;
    ack_status?: string | null;
    ack_due_at?: string | null;
    acked_at?: string | null;
    resolve_due_at?: string | null;
    attention_state?: string | null;
    owner_user_id?: number | null;
    owner_name?: string | null;
    owner_contact_name?: string | null;
    human_status?: HumanStatus;
    payload_summary: PayloadSummaryItem[];
    media_insights: MediaInsight[];
    raw_payload?: Record<string, unknown> | null;
    ai_assessment_view?: AssessmentView | null;
    recommended_actions?: string[];
    investigation_steps?: string[];
    investigation_metadata?: { count: number; last_check?: string; history?: unknown[] };
    notification_decision?: NotificationDecision | null;
    notification_execution?: NotificationExecution | null;
    notification_channels?: string[] | null;
    unified_timeline?: UnifiedTimelineEntry[];
    timeline?: unknown[];
    ai_message?: string | null;
    ai_actions?: {
        total_duration_ms?: number;
        total_tools_called?: number;
        camera_analysis?: {
            analyses?: Array<{
                input?: string | null;
                camera?: string | null;
                analysis?: string | null;
                scene_description?: string | null;
                samsara_url?: string | null;
                url?: string | null;
                recommendation?: { action?: string; reason?: string };
            }>;
            media_urls?: string[];
        };
    };
}

interface ShowProps {
    event: EventPayload;
    breadcrumbs?: BreadcrumbItem[];
    companyUsers?: CompanyUser[];
}

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const ALERTS_INDEX_URL = '/samsara/alerts';

const severityConfig: Record<string, { dot: string; bar: string; gradient: string; glow: string; text: string }> = {
    critical: {
        dot: 'bg-red-500',
        bar: 'bg-gradient-to-r from-red-500 to-red-400',
        gradient: 'from-red-500/6 via-red-500/3 to-transparent',
        glow: 'shadow-[0_0_20px_rgba(248,113,113,0.15)]',
        text: 'text-red-400',
    },
    warning: {
        dot: 'bg-amber-500',
        bar: 'bg-gradient-to-r from-amber-500 to-amber-400',
        gradient: 'from-amber-500/6 via-amber-500/3 to-transparent',
        glow: 'shadow-[0_0_20px_rgba(251,191,36,0.12)]',
        text: 'text-amber-400',
    },
    info: {
        dot: 'bg-sky-500',
        bar: 'bg-gradient-to-r from-sky-500 to-teal-400',
        gradient: 'from-sky-500/6 via-sky-500/3 to-transparent',
        glow: 'shadow-[0_0_20px_rgba(56,189,248,0.12)]',
        text: 'text-sky-400',
    },
};

const timelineTypeConfig: Record<UnifiedTimelineEntry['type'], { color: string; icon: LucideIcon }> = {
    signal: { color: 'bg-sky-500/90', icon: Radio },
    ai: { color: 'bg-violet-500/90', icon: Cpu },
    notification: { color: 'bg-emerald-500/90', icon: Send },
    notification_status: { color: 'bg-slate-500/80', icon: Clock },
    activity: { color: 'bg-amber-500/90', icon: User },
    domain_event: { color: 'bg-slate-500/80', icon: Bookmark },
};

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function getCsrfHeaders(): Record<string, string> {
    const token =
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.getAttribute('content') ||
        document.querySelector<HTMLMetaElement>('meta[name="X-CSRF-TOKEN"]')?.getAttribute('content') ||
        decodeURIComponent(
            document.cookie
                .split('; ')
                .find((row) => row.startsWith('XSRF-TOKEN='))
                ?.split('=')[1] ?? ''
        );
    return {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': token,
        'X-XSRF-TOKEN': token,
        Accept: 'application/json',
    };
}

function formatRelative(iso?: string | null): string {
    if (!iso) return '\u2014';
    try {
        return formatDistanceToNow(new Date(iso), { addSuffix: true, locale: es });
    } catch {
        return iso;
    }
}

function formatTimelineActor(actor: string): string {
    if (actor === 'system') return 'Sistema';
    if (actor === 'twilio') return 'Twilio';
    if (actor.startsWith('user:')) return 'Usuario';
    return actor;
}

function slaColor(acked: boolean, dueAt?: string | null): string {
    if (acked) return 'text-emerald-400';
    if (!dueAt) return 'text-muted-foreground';
    const due = new Date(dueAt).getTime();
    const now = Date.now();
    if (due <= now) return 'text-red-400';
    return 'text-amber-400';
}

function formatConfidence(raw?: number | string | null): string | null {
    if (raw == null) return null;
    if (typeof raw === 'number') return `${Math.round(raw * 100)}%`;
    return String(raw);
}

/** Effective media list: from media_insights or derived from ai_actions.camera_analysis when backend sends preloaded data. */
function getEffectiveMediaInsights(event: EventPayload): MediaInsight[] {
    if (event.media_insights?.length) return event.media_insights;
    const cam = event.ai_actions?.camera_analysis;
    if (!cam?.analyses?.length && !cam?.media_urls?.length) return [];
    const analyses = cam.analyses ?? [];
    const mediaUrls = cam.media_urls ?? [];
    return analyses.length > 0
        ? analyses.map((a, i) => {
              const url = mediaUrls[i] ?? a.samsara_url ?? a.url ?? null;
              let preview: string | null = a.scene_description ?? a.recommendation?.reason ?? null;
              if (!preview && typeof a.analysis === 'string' && a.analysis.length < 300) preview = a.analysis;
              return {
                  camera: a.input ?? a.camera ?? `Imagen ${i + 1}`,
                  analysis: preview ?? undefined,
                  analysis_preview: preview ?? undefined,
                  url: url ?? undefined,
                  download_url: url ?? undefined,
              };
          })
        : mediaUrls.map((url, i) => ({
              camera: `Imagen ${i + 1}`,
              analysis: undefined,
              analysis_preview: undefined,
              url,
              download_url: url,
          }));
}

// ---------------------------------------------------------------------------
// Sub-components
// ---------------------------------------------------------------------------

function MetricChip({ label, value, accent }: { label: string; value: string; accent?: string }) {
    return (
        <div className="flex items-center gap-1.5 rounded-md border border-border/50 bg-muted/25 px-2.5 py-1">
            <span className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground/80">{label}</span>
            <span className={`font-mono text-[11px] font-semibold ${accent ?? 'text-foreground'}`}>{value}</span>
        </div>
    );
}

function AlertHero({ event }: { event: EventPayload }) {
    const sev = severityConfig[event.severity] ?? severityConfig.info;
    const confidence = formatConfidence(event.ai_assessment?.confidence);
    const acked = event.ack_status === 'acked';

    return (
        <FadeIn>
            <div className={`relative overflow-hidden rounded-xl border bg-card ${sev.glow}`}>
                <div className={`absolute inset-0 bg-gradient-to-r ${sev.gradient} pointer-events-none`} />
                <div className={`h-[3px] ${sev.bar}`} />

                <div className="relative px-4 py-4 sm:px-5">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div className="flex items-start gap-3">
                            <Link
                                href={ALERTS_INDEX_URL}
                                className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg border border-border/60 bg-muted/40 text-muted-foreground transition-all hover:bg-muted hover:text-foreground"
                            >
                                <ArrowLeft className="size-3.5" />
                            </Link>
                            <div className="min-w-0">
                                <div className="flex items-center gap-2.5">
                                    <span className="relative flex size-2.5 shrink-0">
                                        <span className={`absolute inline-flex size-full animate-ping rounded-full ${sev.dot} opacity-40`} />
                                        <span className={`relative inline-flex size-2.5 rounded-full ${sev.dot}`} />
                                    </span>
                                    <h1 className="font-display text-lg font-bold tracking-tight sm:text-xl">
                                        {event.display_event_type ?? 'Alerta'}
                                    </h1>
                                    <span className="font-mono text-[11px] text-muted-foreground/60">#{event.id}</span>
                                </div>
                                {event.verdict_badge && (
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {event.verdict_badge.verdict}
                                        {event.verdict_badge.likelihood ? ` \u00b7 ${event.verdict_badge.likelihood}` : ''}
                                    </p>
                                )}
                            </div>
                        </div>

                        <div className="flex shrink-0 items-center gap-2">
                            <Badge variant={event.severity === 'critical' ? 'critical' : event.severity === 'warning' ? 'warning' : 'info'}>
                                {event.severity_label ?? event.severity}
                            </Badge>
                            {event.attention_state === 'closed' && (
                                <Badge variant="secondary" className="border border-border/60">Cerrada</Badge>
                            )}
                        </div>
                    </div>

                    <div className="mt-3.5 flex flex-wrap gap-1.5">
                        {event.display_event_type && (
                            <MetricChip label="Tipo" value={event.display_event_type} />
                        )}
                        {confidence && (
                            <MetricChip label="Confianza" value={confidence} accent={sev.text} />
                        )}
                        <MetricChip
                            label="Confirmación"
                            value={acked ? 'Confirmado' : 'Pendiente'}
                            accent={acked ? 'text-emerald-400' : 'text-muted-foreground'}
                        />
                        <MetricChip
                            label="Responsable"
                            value={event.owner_name ?? event.owner_contact_name ?? 'Sin asignar'}
                        />
                    </div>
                </div>
            </div>
        </FadeIn>
    );
}

function IntelligencePanel({ event, companyUsers }: { event: EventPayload; companyUsers: CompanyUser[] }) {
    const sev = severityConfig[event.severity] ?? severityConfig.info;
    const verdict = event.verdict_badge;
    const confidence = formatConfidence(event.ai_assessment?.confidence);
    const acked = event.ack_status === 'acked';
    const isClosed = event.attention_state === 'closed';
    const needsAck = event.attention_state === 'needs_attention' && event.ack_status === 'pending';

    const [isAcking, setIsAcking] = useState(false);
    const [isAssigning, setIsAssigning] = useState(false);
    const [isClosing, setIsClosing] = useState(false);
    const [showCloseDialog, setShowCloseDialog] = useState(false);
    const [closeReason, setCloseReason] = useState('');

    const handleAck = useCallback(async () => {
        setIsAcking(true);
        try {
            const res = await fetch(`/api/alerts/${event.id}/ack`, {
                method: 'POST',
                headers: getCsrfHeaders(),
                credentials: 'same-origin',
            });
            if (res.ok) router.reload();
        } finally {
            setIsAcking(false);
        }
    }, [event.id]);

    const handleAssign = useCallback(
        async (userId: string) => {
            setIsAssigning(true);
            try {
                const res = await fetch(`/api/alerts/${event.id}/assign`, {
                    method: 'POST',
                    headers: getCsrfHeaders(),
                    credentials: 'same-origin',
                    body: JSON.stringify({ user_id: Number(userId) }),
                });
                if (res.ok) router.reload();
            } finally {
                setIsAssigning(false);
            }
        },
        [event.id]
    );

    const handleCloseAttention = useCallback(async () => {
        if (!closeReason.trim()) return;
        setIsClosing(true);
        try {
            const res = await fetch(`/api/alerts/${event.id}/close-attention`, {
                method: 'POST',
                headers: getCsrfHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify({ reason: closeReason.trim() }),
            });
            if (res.ok) {
                setShowCloseDialog(false);
                setCloseReason('');
                router.reload();
            }
        } finally {
            setIsClosing(false);
        }
    }, [event.id, closeReason]);

    return (
        <>
            <SlideUp delay={0.05}>
                <div className="overflow-hidden rounded-xl border bg-card">
                    <div className={`h-[3px] ${sev.bar}`} />

                    {/* Severity + Verdict */}
                    <div className="px-4 pt-3.5 pb-3 space-y-2.5">
                        <div className="flex items-center justify-between">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground/80">
                                Severidad
                            </span>
                            <div className="flex items-center gap-1.5">
                                <span className={`size-2 rounded-full ${sev.dot}`} />
                                <span className={`text-sm font-semibold ${sev.text}`}>
                                    {event.severity_label ?? event.severity}
                                </span>
                            </div>
                        </div>
                        {verdict && (
                            <Badge
                                variant="outline"
                                className={`w-full justify-center py-1.5 px-2.5 text-[11px] font-medium whitespace-normal break-words min-w-0 overflow-visible ${
                                    verdict.color === 'red'
                                        ? 'border-red-500/30 bg-red-500/8 text-red-300'
                                        : verdict.color === 'amber'
                                          ? 'border-amber-500/30 bg-amber-500/8 text-amber-300'
                                          : verdict.color === 'emerald'
                                            ? 'border-emerald-500/30 bg-emerald-500/8 text-emerald-300'
                                            : 'border-border bg-muted/30 text-muted-foreground'
                                }`}
                            >
                                {verdict.verdict}
                                {verdict.likelihood ? ` (${verdict.likelihood})` : ''}
                            </Badge>
                        )}
                    </div>

                    <div className="h-px bg-border/50 mx-4" />

                    {/* Key facts + SLA + Owner — consolidated */}
                    <div className="px-4 py-3">
                        <dl className="space-y-2 text-[13px]">
                            {event.display_event_type && (
                                <div className="flex items-center justify-between gap-2">
                                    <dt className="text-muted-foreground">Tipo</dt>
                                    <dd className="text-right font-medium truncate max-w-[60%]">{event.display_event_type}</dd>
                                </div>
                            )}
                            {confidence && (
                                <div className="flex items-center justify-between gap-2">
                                    <dt className="text-muted-foreground">Confianza</dt>
                                    <dd className="text-right">
                                        <span className={`font-mono text-xs font-semibold ${sev.text}`}>{confidence}</span>
                                    </dd>
                                </div>
                            )}
                            <div className="flex items-center justify-between gap-2">
                                <dt className="text-muted-foreground">Confirmación</dt>
                                <dd className={`text-right font-mono text-xs font-semibold ${slaColor(acked, event.ack_due_at)}`}>
                                    {acked ? 'Confirmado' : event.ack_due_at ? formatRelative(event.ack_due_at) : 'Sin SLA'}
                                </dd>
                            </div>
                            {event.resolve_due_at && (
                                <div className="flex items-center justify-between gap-2">
                                    <dt className="text-muted-foreground">Revalidación</dt>
                                    <dd className="text-right font-mono text-xs">{formatRelative(event.resolve_due_at)}</dd>
                                </div>
                            )}
                            <div className="flex items-center justify-between gap-2">
                                <dt className="text-muted-foreground">Responsable</dt>
                                <dd className="text-right font-medium truncate max-w-[55%]">
                                    {event.owner_name ?? event.owner_contact_name ?? (
                                        <span className="text-muted-foreground/50 italic font-normal">Sin asignar</span>
                                    )}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <div className="h-px bg-border/50 mx-4" />

                    {/* Actions */}
                    <div className="px-4 py-3 space-y-2">
                        <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground/80">
                            Acciones
                        </span>
                        <div className="flex flex-col gap-1.5">
                            {needsAck && !isClosed && (
                                <Button
                                    size="sm"
                                    className="w-full gap-2 bg-emerald-600 text-white hover:bg-emerald-500"
                                    onClick={handleAck}
                                    disabled={isAcking}
                                >
                                    {isAcking ? <Loader2 className="size-3.5 animate-spin" /> : <CheckCircle className="size-3.5" />}
                                    Confirmar recepción
                                </Button>
                            )}
                            {companyUsers.length > 0 && !isClosed && (
                                <Select
                                    value={event.owner_user_id?.toString() ?? ''}
                                    onValueChange={handleAssign}
                                    disabled={isAssigning}
                                >
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue placeholder={isAssigning ? 'Asignando...' : 'Asignar responsable'} />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {companyUsers.map((u) => (
                                            <SelectItem key={u.id} value={String(u.id)}>
                                                {u.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            )}
                            <Button size="sm" variant="outline" className="w-full justify-start gap-2 text-xs text-muted-foreground" disabled>
                                <Shield className="size-3" />
                                Escalar (pronto)
                            </Button>
                            {!isClosed && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="w-full justify-start gap-2 text-xs text-muted-foreground/70 hover:text-red-400"
                                    onClick={() => setShowCloseDialog(true)}
                                >
                                    <XCircle className="size-3" />
                                    Cerrar atención
                                </Button>
                            )}
                        </div>
                    </div>
                </div>
            </SlideUp>

            <Dialog open={showCloseDialog} onOpenChange={setShowCloseDialog}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Cerrar atención</DialogTitle>
                        <DialogDescription>
                            Indica el motivo por el que se cierra la atención de esta alerta.
                        </DialogDescription>
                    </DialogHeader>
                    <Textarea
                        placeholder="Motivo del cierre..."
                        value={closeReason}
                        onChange={(e) => setCloseReason(e.target.value)}
                        rows={3}
                    />
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setShowCloseDialog(false)} disabled={isClosing}>
                            Cancelar
                        </Button>
                        <Button onClick={handleCloseAttention} disabled={!closeReason.trim() || isClosing}>
                            {isClosing ? <Loader2 className="size-4 animate-spin" /> : null}
                            Cerrar
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function UnifiedTimeline({ entries }: { entries: UnifiedTimelineEntry[] }) {
    return (
        <StaggerContainer className="relative">
            {entries.map((entry, i) => {
                const config = timelineTypeConfig[entry.type];
                const Icon = config.icon;
                const isLast = i === entries.length - 1;

                return (
                    <StaggerItem key={i}>
                        <div className="group relative flex gap-3 rounded-md py-2.5 pr-2 transition-colors hover:bg-muted/25">
                            {!isLast && (
                                <div className="absolute left-[24px] top-[36px] bottom-0 w-px bg-border/40" />
                            )}

                            <div
                                className={`relative z-10 flex size-7 shrink-0 items-center justify-center rounded-full ${config.color} text-white ring-2 ring-card transition-transform duration-150 group-hover:scale-110`}
                            >
                                <Icon className="size-3" />
                            </div>

                            <div className="flex-1 min-w-0 overflow-hidden pt-px">
                                <p className="font-display text-[13px] font-semibold leading-snug break-words">
                                    {entry.title}
                                </p>
                                {entry.description && (
                                    <p className="mt-0.5 text-[11px] leading-relaxed text-muted-foreground break-words line-clamp-3">
                                        {entry.description}
                                    </p>
                                )}
                                <p className="mt-1 font-mono text-[10px] text-muted-foreground/50">
                                    {formatRelative(entry.timestamp)} &middot; {formatTimelineActor(entry.actor)}
                                </p>
                            </div>
                        </div>
                    </StaggerItem>
                );
            })}
        </StaggerContainer>
    );
}

function FallbackTimeline({ event }: { event: EventPayload }) {
    return (
        <div className="space-y-3 px-3">
            {event.ai_message && (
                <div className="rounded-lg border border-violet-500/20 bg-violet-500/5 p-3">
                    <p className="text-[11px] font-semibold uppercase tracking-widest text-violet-400 mb-2">Mensaje AI</p>
                    <p className="text-sm leading-relaxed whitespace-pre-wrap">{event.ai_message}</p>
                </div>
            )}
            {event.timeline && Array.isArray(event.timeline) && event.timeline.length > 0 && (
                <div className="space-y-3">
                    <p className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Pipeline</p>
                    <ol className="list-none space-y-2">
                        {event.timeline.map((step: unknown, i: number) => {
                            const s = step as { title?: string; name?: string };
                            return (
                                <li key={i} className="flex items-center gap-3 rounded-lg border bg-muted/20 px-3 py-2 text-sm">
                                    <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-violet-500/15 font-mono text-[10px] font-bold text-violet-400">
                                        {i + 1}
                                    </span>
                                    {s.title ?? s.name ?? `Paso ${i + 1}`}
                                </li>
                            );
                        })}
                    </ol>
                </div>
            )}
        </div>
    );
}

// ---------------------------------------------------------------------------
// Media storytelling: strip below hero + lightbox
// ---------------------------------------------------------------------------

function MediaLightbox({
    media,
    currentIndex,
    onClose,
    onPrev,
    onNext,
}: {
    media: MediaInsight[];
    currentIndex: number;
    onClose: () => void;
    onPrev: () => void;
    onNext: () => void;
}) {
    const item = media[currentIndex];
    const src = item?.download_url ?? item?.url ?? null;
    const hasMultiple = media.length > 1;

    useEffect(() => {
        const handleKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
            if (hasMultiple && e.key === 'ArrowLeft') onPrev();
            if (hasMultiple && e.key === 'ArrowRight') onNext();
        };
        window.addEventListener('keydown', handleKey);
        return () => window.removeEventListener('keydown', handleKey);
    }, [onClose, onPrev, onNext, hasMultiple]);

    return (
        <Dialog open onOpenChange={() => onClose()}>
            <DialogContent
                className="max-w-[95vw] w-full max-h-[95vh] p-0 gap-0 border-0 bg-black/95 overflow-hidden"
                onPointerDownOutside={onClose}
                onEscapeKeyDown={onClose}
            >
                <div className="relative flex items-center justify-center min-h-[70vh] w-full">
                    {src ? (
                        <img
                            src={src}
                            alt={item?.camera ?? `Evidencia ${currentIndex + 1}`}
                            className="max-h-[85vh] w-auto object-contain"
                        />
                    ) : (
                        <div className="flex flex-col items-center gap-3 text-white/60">
                            <ImageIcon className="size-14" />
                            <span>Imagen no disponible</span>
                        </div>
                    )}
                    {hasMultiple && (
                        <>
                            <button
                                type="button"
                                onClick={onPrev}
                                className="absolute left-2 top-1/2 -translate-y-1/2 flex size-10 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 transition-colors"
                                aria-label="Anterior"
                            >
                                <ChevronLeft className="size-6" />
                            </button>
                            <button
                                type="button"
                                onClick={onNext}
                                className="absolute right-2 top-1/2 -translate-y-1/2 flex size-10 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 transition-colors"
                                aria-label="Siguiente"
                            >
                                <ChevronRight className="size-6" />
                            </button>
                        </>
                    )}
                </div>
                <div className="px-4 py-3 bg-black/60 border-t border-white/10">
                    <p className="text-sm font-semibold text-white">{item?.camera ?? `Cámara ${currentIndex + 1}`}</p>
                    {item?.analysis && (
                        <p className="mt-1 text-xs text-white/80 leading-relaxed line-clamp-2">{item.analysis}</p>
                    )}
                    {hasMultiple && (
                        <p className="mt-2 text-[11px] text-white/50">
                            {currentIndex + 1} / {media.length}
                        </p>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}

function AlertMediaStrip({ media, onMediaClick }: { media: MediaInsight[]; onMediaClick: (index: number) => void }) {
    if (!media?.length) return null;

    return (
        <SlideUp delay={0.08}>
            <section className="rounded-xl border border-border/60 bg-card overflow-hidden" aria-label="Evidencia visual">
                <div className="flex items-center gap-2 px-4 py-3 border-b border-border/50 bg-muted/20">
                    <div className="flex size-8 items-center justify-center rounded-lg bg-[var(--sam-accent-teal)]/15 text-[var(--sam-accent-teal)]">
                        <Camera className="size-4" />
                    </div>
                    <div>
                        <h2 className="font-display text-sm font-semibold tracking-tight text-foreground">
                            Evidencia visual
                        </h2>
                        <p className="text-[11px] text-muted-foreground">
                            Material de cámaras analizado por el pipeline
                        </p>
                    </div>
                </div>
                <div className="p-4">
                    <StaggerContainer className="flex gap-4 overflow-x-auto pb-2 scrollbar-thin scrollbar-track-transparent scrollbar-thumb-border hover:scrollbar-thumb-border/80">
                        {media.map((m, i) => {
                            const src = m.download_url ?? m.url;
                            return (
                                <StaggerItem key={i}>
                                    <button
                                        type="button"
                                        onClick={() => onMediaClick(i)}
                                        className="group flex-shrink-0 w-[min(280px,85vw)] text-left rounded-lg border border-border/50 bg-muted/10 overflow-hidden transition-all duration-200 hover:border-[var(--sam-accent-teal)]/40 hover:shadow-[var(--sam-shadow-md)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--sam-accent-teal)]/50"
                                    >
                                        <div className="relative aspect-video bg-muted/30">
                                            {src ? (
                                                <img
                                                    src={src}
                                                    alt={m.camera ?? `Evidencia ${i + 1}`}
                                                    className="absolute inset-0 h-full w-full object-cover transition-transform duration-300 group-hover:scale-[1.02]"
                                                />
                                            ) : (
                                                <div className="absolute inset-0 flex items-center justify-center text-muted-foreground">
                                                    <ImageIcon className="size-10" />
                                                </div>
                                            )}
                                            <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent px-3 py-2">
                                                <span className="text-xs font-medium text-white">
                                                    {m.camera ?? `Cámara ${i + 1}`}
                                                </span>
                                            </div>
                                        </div>
                                        {m.analysis_preview ?? m.analysis ? (
                                            <div className="p-3">
                                                <p className="text-[12px] text-muted-foreground line-clamp-2 leading-snug">
                                                    {m.analysis_preview ?? m.analysis}
                                                </p>
                                            </div>
                                        ) : null}
                                    </button>
                                </StaggerItem>
                            );
                        })}
                    </StaggerContainer>
                </div>
            </section>
        </SlideUp>
    );
}

type TabId = 'evidencia' | 'ai' | 'notificaciones' | 'actividad';

const TAB_CONFIG: { id: TabId; label: string; icon: LucideIcon }[] = [
    { id: 'evidencia', label: 'Evidencia', icon: Radio },
    { id: 'ai', label: 'AI', icon: Cpu },
    { id: 'notificaciones', label: 'Notif.', icon: Bell },
    { id: 'actividad', label: 'Actividad', icon: Clock },
];

function DetailPanel({ event, companyUsers }: { event: EventPayload; companyUsers: CompanyUser[] }) {
    const [activeTab, setActiveTab] = useState<TabId>('evidencia');
    const [rawExpanded, setRawExpanded] = useState(false);

    return (
        <SlideUp delay={0.15}>
            <div className="overflow-hidden rounded-xl border bg-card">
                {/* Segmented tab control */}
                <div className="border-b border-border/60 px-3 pt-3 pb-2.5">
                    <div className="flex rounded-md bg-muted/40 p-0.5">
                        {TAB_CONFIG.map((tab) => {
                            const Icon = tab.icon;
                            const isActive = activeTab === tab.id;
                            return (
                                <button
                                    key={tab.id}
                                    type="button"
                                    onClick={() => setActiveTab(tab.id)}
                                    className={`flex flex-1 items-center justify-center gap-1 rounded px-1.5 py-1.5 text-[11px] font-medium transition-all duration-200 ${
                                        isActive
                                            ? 'bg-card text-foreground shadow-sm'
                                            : 'text-muted-foreground hover:text-foreground'
                                    }`}
                                >
                                    <Icon className="size-3" />
                                    <span className="hidden sm:inline">{tab.label}</span>
                                </button>
                            );
                        })}
                    </div>
                </div>

                {/* Tab content */}
                <div className="max-h-[calc(100vh-280px)] overflow-y-auto px-4 py-3">
                    {activeTab === 'evidencia' && (
                        <EvidenceTab
                            event={event}
                            rawExpanded={rawExpanded}
                            onToggleRaw={() => setRawExpanded(!rawExpanded)}
                        />
                    )}
                    {activeTab === 'ai' && <AITab event={event} />}
                    {activeTab === 'notificaciones' && <NotificationsTab event={event} />}
                    {activeTab === 'actividad' && (
                        <ReviewPanel
                            eventId={event.id}
                            currentStatus={event.human_status ?? 'pending'}
                            aiTimeline={
                                (event.timeline ?? []).map((t) => {
                                    const s = t as Record<string, unknown>;
                                    return {
                                        step: (s.step as number) ?? 0,
                                        name: (s.name as string) ?? '',
                                        title: (s.title as string) ?? '',
                                        description: (s.description as string) ?? '',
                                        summary: (s.summary as string) ?? '',
                                        tools_used: (s.tools_used as { tool_name?: string; status_label?: string; duration_ms?: number }[]) ?? [],
                                    };
                                })
                            }
                            aiTotalDuration={event.ai_actions?.total_duration_ms}
                            aiTotalTools={event.ai_actions?.total_tools_called}
                        />
                    )}
                </div>
            </div>
        </SlideUp>
    );
}

function EvidenceTab({
    event,
    rawExpanded,
    onToggleRaw,
}: {
    event: EventPayload;
    rawExpanded: boolean;
    onToggleRaw: () => void;
}) {
    return (
        <div className="space-y-5">
            {event.payload_summary?.length > 0 && (
                <section className="space-y-3">
                    <h4 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground/80">
                        Datos del evento
                    </h4>
                    <ul className="space-y-3">
                        {event.payload_summary.map((item, i) => (
                            <li key={i} className="rounded-lg border border-border/50 bg-muted/10 px-4 py-3">
                                <p className="text-[11px] font-medium text-muted-foreground mb-1.5">{item.label}</p>
                                <p className="text-[13px] leading-relaxed text-foreground">{item.value}</p>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {event.raw_payload && (
                <section>
                    <button
                        type="button"
                        onClick={onToggleRaw}
                        className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground transition-colors"
                    >
                        <ChevronDown className={`size-3 transition-transform duration-200 ${rawExpanded ? 'rotate-180' : ''}`} />
                        Ver payload técnico
                    </button>
                    {rawExpanded && (
                        <pre className="mt-2 rounded-lg border border-border/50 bg-muted/20 p-4 font-mono text-[11px] leading-relaxed overflow-auto max-h-52">
                            {JSON.stringify(event.raw_payload, null, 2)}
                        </pre>
                    )}
                </section>
            )}
        </div>
    );
}

function AITab({ event }: { event: EventPayload }) {
    const view = event.ai_assessment_view;

    return (
        <div className="space-y-4">
            {view && (
                <section>
                    <h4 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground/80 mb-2">
                        Evaluación
                    </h4>
                    <div className="space-y-3">
                        {view.verdict && (
                            <div className="rounded-lg border border-violet-500/20 bg-violet-500/5 p-3">
                                <span className="text-[10px] font-semibold uppercase tracking-widest text-violet-400">Veredicto</span>
                                <p className="mt-1 text-sm font-semibold">{view.verdict}</p>
                            </div>
                        )}
                        {view.likelihood && (
                            <div className="flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">Probabilidad</span>
                                <span className="font-mono font-semibold">{view.likelihood}</span>
                            </div>
                        )}
                        {view.reasoning && (
                            <div className="rounded-lg bg-muted/30 p-3">
                                <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">Razonamiento</span>
                                <p className="mt-1.5 text-sm leading-relaxed text-muted-foreground">{view.reasoning}</p>
                            </div>
                        )}
                        {view.evidence?.length ? (
                            <div className="space-y-3">
                                <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground/80">Evidencia</span>
                                <ul className="space-y-3">
                                    {view.evidence.map((e, i) => {
                                        const valueText = typeof e.value === 'string' ? e.value : JSON.stringify(e.value);
                                        return (
                                            <li key={i} className="rounded-lg border border-border/50 bg-muted/10 px-4 py-3">
                                                <p className="text-[11px] font-medium text-muted-foreground mb-1.5">{e.label}</p>
                                                <p className="text-[13px] leading-relaxed text-foreground">{valueText}</p>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>
                        ) : null}
                    </div>
                </section>
            )}

            {event.recommended_actions?.length ? (
                <section>
                    <h4 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground/80 mb-2">
                        Acciones recomendadas
                    </h4>
                    <ul className="space-y-1.5">
                        {event.recommended_actions.map((a, i) => (
                            <li key={i} className="flex items-start gap-2.5 text-sm">
                                <span className="mt-1.5 size-1.5 shrink-0 rounded-full bg-emerald-500/60" />
                                <span className="leading-relaxed">{a}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}

            {event.investigation_steps?.length ? (
                <section>
                    <h4 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground/80 mb-2">
                        Pasos de investigación
                    </h4>
                    <ol className="space-y-1.5">
                        {event.investigation_steps.map((s, i) => (
                            <li key={i} className="flex items-start gap-2.5 text-sm">
                                <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-muted font-mono text-[10px] font-bold text-muted-foreground">
                                    {i + 1}
                                </span>
                                <span className="leading-relaxed pt-0.5">{s}</span>
                            </li>
                        ))}
                    </ol>
                </section>
            ) : null}

            {event.investigation_metadata && (
                <div className="rounded-lg bg-muted/30 px-3 py-2 font-mono text-xs text-muted-foreground">
                    Revisiones: {event.investigation_metadata.count}
                    {event.investigation_metadata.last_check && ` \u00b7 Última: ${event.investigation_metadata.last_check}`}
                </div>
            )}
        </div>
    );
}

function NotificationsTab({ event }: { event: EventPayload }) {
    return (
        <div className="space-y-4">
            {event.notification_decision && (
                <section>
                    <h4 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground/80 mb-2">
                        Decisión
                    </h4>
                    <div className="space-y-2.5 text-sm">
                        {event.notification_decision.should_notify !== undefined && (
                            <div className="flex items-center gap-2">
                                <span className={`size-2 rounded-full ${event.notification_decision.should_notify ? 'bg-emerald-500' : 'bg-slate-500'}`} />
                                <span>
                                    Notificar: <strong>{event.notification_decision.should_notify ? 'Sí' : 'No'}</strong>
                                </span>
                            </div>
                        )}
                        {event.notification_decision.channels_to_use?.length ? (
                            <div className="flex flex-wrap gap-1.5">
                                {event.notification_decision.channels_to_use.map((ch, i) => (
                                    <Badge key={i} variant="secondary" className="text-[11px]">
                                        {ch}
                                    </Badge>
                                ))}
                            </div>
                        ) : null}
                        {(event.notification_decision.reason_display ?? event.notification_decision.reason) && (
                            <p className="text-xs text-muted-foreground leading-relaxed">
                                {event.notification_decision.reason_display ?? event.notification_decision.reason}
                            </p>
                        )}
                        {event.notification_decision.recipients?.length ? (
                            <div className="space-y-1">
                                <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">Destinatarios</span>
                                <ul className="space-y-1">
                                    {event.notification_decision.recipients.map((r, i) => (
                                        <li key={i} className="flex items-center gap-2 rounded border bg-muted/20 px-2.5 py-1.5 text-xs">
                                            <User className="size-3 text-muted-foreground" />
                                            {r.name ?? r.phone ?? '\u2014'}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ) : null}
                    </div>
                </section>
            )}

            {event.notification_execution?.results?.length ? (
                <section>
                    <h4 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground/80 mb-2">
                        Ejecución
                    </h4>
                    <div className="overflow-hidden rounded-lg border">
                        <table className="w-full text-xs">
                            <thead>
                                <tr className="border-b bg-muted/30">
                                    <th className="text-left px-3 py-2 font-semibold text-muted-foreground">Canal</th>
                                    <th className="text-left px-3 py-2 font-semibold text-muted-foreground">Estado</th>
                                    <th className="text-left px-3 py-2 font-semibold text-muted-foreground">Error</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border/40">
                                {event.notification_execution.results.map((r, i) => (
                                    <tr key={i} className="transition-colors hover:bg-muted/20">
                                        <td className="px-3 py-2 font-medium">{r.channel ?? '\u2014'}</td>
                                        <td className="px-3 py-2">
                                            <span className={`inline-flex items-center gap-1.5 ${r.success ? 'text-emerald-400' : 'text-red-400'}`}>
                                                <span className={`size-1.5 rounded-full ${r.success ? 'bg-emerald-500' : 'bg-red-500'}`} />
                                                {r.success ? 'OK' : 'Fallido'}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2 text-muted-foreground max-w-[140px] truncate">{r.error ?? '\u2014'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            ) : null}

            {event.notification_channels?.length ? (
                <div className="flex flex-wrap gap-1.5">
                    <span className="text-xs text-muted-foreground mr-1">Canales usados:</span>
                    {event.notification_channels.map((ch, i) => (
                        <Badge key={i} variant="outline" className="text-[11px]">
                            {ch}
                        </Badge>
                    ))}
                </div>
            ) : null}
        </div>
    );
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

export default function SamsaraAlertShow({ event, breadcrumbs, companyUsers = [] }: ShowProps) {
    const [lightboxIndex, setLightboxIndex] = useState<number | null>(null);

    const computedBreadcrumbs: BreadcrumbItem[] = breadcrumbs?.length
        ? breadcrumbs
        : [
              { title: 'Alertas Samsara', href: ALERTS_INDEX_URL },
              { title: event.display_event_type ?? 'Alerta', href: '#' },
          ];

    const hasUnifiedTimeline = Array.isArray(event.unified_timeline) && event.unified_timeline.length > 0;
    const mediaInsights = getEffectiveMediaInsights(event);
    const hasMedia = mediaInsights.length > 0;

    const handleMediaPrev = useCallback(() => {
        setLightboxIndex((i) => (i === null ? null : i === 0 ? mediaInsights.length - 1 : i - 1));
    }, [mediaInsights.length]);

    const handleMediaNext = useCallback(() => {
        setLightboxIndex((i) => (i === null ? null : (i + 1) % mediaInsights.length));
    }, [mediaInsights.length]);

    return (
        <AppLayout breadcrumbs={computedBreadcrumbs}>
            <Head title={event.display_event_type ?? 'Alerta Samsara'} />

            <div className="flex flex-1 flex-col gap-4 p-4 sm:p-6">
                <AlertHero event={event} />

                {hasMedia && (
                    <AlertMediaStrip
                        media={mediaInsights}
                        onMediaClick={(i) => setLightboxIndex(i)}
                    />
                )}

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-[240px_1fr_340px]">
                    {/* Left — Intelligence + Actions */}
                    <aside className="lg:sticky lg:top-20 lg:self-start order-2 lg:order-1 min-w-0">
                        <IntelligencePanel event={event} companyUsers={companyUsers} />
                    </aside>

                    {/* Center — Timeline */}
                    <main className="order-1 lg:order-2 min-w-0">
                        <SlideUp delay={0.1}>
                            <div className="overflow-hidden rounded-xl border bg-card">
                                <div className="border-b border-border/60 px-4 py-3">
                                    <h2 className="font-display text-sm font-semibold tracking-tight">
                                        Línea de tiempo
                                    </h2>
                                    <p className="mt-0.5 text-[11px] text-muted-foreground/70">
                                        Eventos en orden cronológico
                                    </p>
                                </div>
                                <div className="px-4 py-1.5">
                                    {hasUnifiedTimeline ? (
                                        <UnifiedTimeline entries={event.unified_timeline!} />
                                    ) : (
                                        <FallbackTimeline event={event} />
                                    )}
                                </div>
                            </div>
                        </SlideUp>
                    </main>

                    {/* Right — Detail Panel */}
                    <aside className="lg:sticky lg:top-20 lg:self-start order-3 min-w-0">
                        <DetailPanel event={event} companyUsers={companyUsers} />
                    </aside>
                </div>
            </div>

            {lightboxIndex !== null && mediaInsights.length > 0 && (
                <MediaLightbox
                    media={mediaInsights}
                    currentIndex={lightboxIndex}
                    onClose={() => setLightboxIndex(null)}
                    onPrev={handleMediaPrev}
                    onNext={handleMediaNext}
                />
            )}
        </AppLayout>
    );
}
