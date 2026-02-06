import { cn } from '@/lib/utils';
import {
    AlertCircle,
    AlertTriangle,
    ArrowDown,
    ArrowRight,
    ArrowUp,
    BarChart3,
    CheckCircle2,
    ChevronDown,
    ChevronUp,
    Clock,
    Info,
    Lightbulb,
    Shield,
    Target,
    TrendingDown,
    TrendingUp,
} from 'lucide-react';
import { useState } from 'react';
import Markdown from 'react-markdown';
import remarkGfm from 'remark-gfm';

interface AnalysisMetric {
    key: string;
    label: string;
    value: string | number;
    unit?: string;
    trend?: 'up' | 'down' | 'stable';
    trend_value?: string;
    severity?: 'low' | 'medium' | 'high' | 'critical';
}

interface AnalysisFinding {
    title: string;
    description: string;
    severity: 'low' | 'medium' | 'high' | 'critical';
    category: string;
    evidence?: string[];
}

interface FleetAnalysisCardData {
    analysisType: string;
    title: string;
    summary: string;
    riskLevel: 'low' | 'medium' | 'high' | 'critical';
    metrics: AnalysisMetric[];
    findings: AnalysisFinding[];
    insights: string;
    recommendations: string[];
    dataWindow: {
        days_back?: number;
        end?: string;
        description?: string;
    };
    methodology: string;
}

interface FleetAnalysisCardProps {
    data: FleetAnalysisCardData;
}

const RISK_CONFIG: Record<string, { label: string; color: string; bgColor: string; icon: React.ReactNode }> = {
    low: {
        label: 'Bajo',
        color: 'text-green-700 dark:text-green-400',
        bgColor: 'bg-green-100 dark:bg-green-900/30',
        icon: <CheckCircle2 className="size-4" />,
    },
    medium: {
        label: 'Moderado',
        color: 'text-yellow-700 dark:text-yellow-400',
        bgColor: 'bg-yellow-100 dark:bg-yellow-900/30',
        icon: <AlertTriangle className="size-4" />,
    },
    high: {
        label: 'Alto',
        color: 'text-orange-700 dark:text-orange-400',
        bgColor: 'bg-orange-100 dark:bg-orange-900/30',
        icon: <AlertCircle className="size-4" />,
    },
    critical: {
        label: 'Critico',
        color: 'text-red-700 dark:text-red-400',
        bgColor: 'bg-red-100 dark:bg-red-900/30',
        icon: <AlertCircle className="size-4" />,
    },
};

const SEVERITY_COLORS: Record<string, { border: string; bg: string; text: string; dot: string }> = {
    low: {
        border: 'border-green-200 dark:border-green-800',
        bg: 'bg-green-50 dark:bg-green-950/20',
        text: 'text-green-700 dark:text-green-400',
        dot: 'bg-green-500',
    },
    medium: {
        border: 'border-yellow-200 dark:border-yellow-800',
        bg: 'bg-yellow-50 dark:bg-yellow-950/20',
        text: 'text-yellow-700 dark:text-yellow-400',
        dot: 'bg-yellow-500',
    },
    high: {
        border: 'border-orange-200 dark:border-orange-800',
        bg: 'bg-orange-50 dark:bg-orange-950/20',
        text: 'text-orange-700 dark:text-orange-400',
        dot: 'bg-orange-500',
    },
    critical: {
        border: 'border-red-200 dark:border-red-800',
        bg: 'bg-red-50 dark:bg-red-950/20',
        text: 'text-red-700 dark:text-red-400',
        dot: 'bg-red-500',
    },
};

const ANALYSIS_TYPE_LABELS: Record<string, { label: string; gradient: string }> = {
    driver_risk_profile: {
        label: 'Perfil de Riesgo',
        gradient: 'from-orange-600 to-red-500',
    },
    fleet_safety_overview: {
        label: 'Seguridad de Flota',
        gradient: 'from-red-600 to-rose-500',
    },
    vehicle_health: {
        label: 'Salud del Vehiculo',
        gradient: 'from-emerald-600 to-teal-500',
    },
    operational_efficiency: {
        label: 'Eficiencia Operativa',
        gradient: 'from-blue-600 to-indigo-500',
    },
    anomaly_detection: {
        label: 'Deteccion de Anomalias',
        gradient: 'from-violet-600 to-purple-500',
    },
};

function TrendIcon({ trend }: { trend?: string }) {
    if (trend === 'up') return <TrendingUp className="size-3.5 text-red-500" />;
    if (trend === 'down') return <TrendingDown className="size-3.5 text-green-500" />;
    return <ArrowRight className="size-3.5 text-gray-400" />;
}

function MetricSeverityDot({ severity }: { severity?: string }) {
    const colors = SEVERITY_COLORS[severity || 'low'];
    return <span className={cn('size-2 rounded-full', colors?.dot || 'bg-gray-400')} />;
}

export function FleetAnalysisCard({ data }: FleetAnalysisCardProps) {
    const {
        analysisType,
        title,
        summary,
        riskLevel,
        metrics,
        findings,
        insights,
        recommendations,
        dataWindow,
        methodology,
    } = data;

    const [expandedFindings, setExpandedFindings] = useState<Set<number>>(new Set());
    const [showMethodology, setShowMethodology] = useState(false);

    const risk = RISK_CONFIG[riskLevel] || RISK_CONFIG.low;
    const typeConfig = ANALYSIS_TYPE_LABELS[analysisType] || {
        label: 'Analisis',
        gradient: 'from-gray-600 to-gray-500',
    };

    const toggleFinding = (idx: number) => {
        const next = new Set(expandedFindings);
        if (next.has(idx)) {
            next.delete(idx);
        } else {
            next.add(idx);
        }
        setExpandedFindings(next);
    };

    return (
        <div className="my-3 overflow-hidden rounded-xl border bg-gradient-to-br from-slate-50 to-gray-50 shadow-lg dark:from-slate-950/50 dark:to-gray-950/50">
            {/* ============================================================ */}
            {/* HEADER */}
            {/* ============================================================ */}
            <div className={cn('border-b bg-gradient-to-r px-6 py-4 text-white', typeConfig.gradient)}>
                <div className="flex items-start justify-between">
                    <div className="flex items-center gap-4">
                        <div className="flex size-14 items-center justify-center rounded-full bg-white/20 backdrop-blur-sm">
                            <BarChart3 className="size-7" />
                        </div>
                        <div>
                            <p className="text-xs font-medium uppercase tracking-wider text-white/70">
                                {typeConfig.label}
                            </p>
                            <h2 className="text-xl font-bold">{title}</h2>
                            {dataWindow?.description && (
                                <div className="mt-1 flex items-center gap-1.5 text-xs text-white/70">
                                    <Clock className="size-3" />
                                    {dataWindow.description}
                                </div>
                            )}
                        </div>
                    </div>
                    <div className={cn('flex items-center gap-2 rounded-lg px-4 py-2.5 font-semibold shadow-md', risk.bgColor, risk.color)}>
                        {risk.icon}
                        <span className="text-sm">{risk.label}</span>
                    </div>
                </div>

                {/* Summary */}
                <div className="mt-4 rounded-lg bg-white/10 p-3 backdrop-blur-sm">
                    <p className="text-sm text-white/90">{summary}</p>
                </div>
            </div>

            <div className="p-6">
                {/* ============================================================ */}
                {/* METRICS GRID */}
                {/* ============================================================ */}
                {metrics.length > 0 && (
                    <div className="mb-6">
                        <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <Target className="size-4" />
                            Metricas Clave
                        </h3>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                            {metrics.map((metric, idx) => (
                                <div
                                    key={metric.key || idx}
                                    className="rounded-lg border bg-white p-3 shadow-sm dark:bg-gray-900"
                                >
                                    <div className="flex items-center justify-between">
                                        <p className="text-xs text-gray-500 dark:text-gray-400">{metric.label}</p>
                                        {metric.severity && <MetricSeverityDot severity={metric.severity} />}
                                    </div>
                                    <div className="mt-1.5 flex items-baseline gap-1.5">
                                        <span className="text-lg font-bold text-gray-900 dark:text-white">
                                            {typeof metric.value === 'number'
                                                ? metric.value.toLocaleString('es-MX')
                                                : metric.value}
                                        </span>
                                        {metric.unit && (
                                            <span className="text-xs text-gray-400">{metric.unit}</span>
                                        )}
                                    </div>
                                    {(metric.trend || metric.trend_value) && (
                                        <div className="mt-1 flex items-center gap-1">
                                            <TrendIcon trend={metric.trend} />
                                            {metric.trend_value && (
                                                <span
                                                    className={cn(
                                                        'text-xs font-medium',
                                                        metric.trend === 'up'
                                                            ? 'text-red-500'
                                                            : metric.trend === 'down'
                                                              ? 'text-green-500'
                                                              : 'text-gray-400',
                                                    )}
                                                >
                                                    {metric.trend_value}
                                                </span>
                                            )}
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* ============================================================ */}
                {/* FINDINGS */}
                {/* ============================================================ */}
                {findings.length > 0 && (
                    <div className="mb-6">
                        <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <Shield className="size-4" />
                            Hallazgos
                        </h3>
                        <div className="space-y-2">
                            {findings.map((finding, idx) => {
                                const isExpanded = expandedFindings.has(idx);
                                const colors = SEVERITY_COLORS[finding.severity] || SEVERITY_COLORS.low;

                                return (
                                    <div
                                        key={idx}
                                        className={cn(
                                            'overflow-hidden rounded-lg border',
                                            colors.border,
                                        )}
                                    >
                                        <button
                                            onClick={() => toggleFinding(idx)}
                                            className="flex w-full items-start gap-3 p-3 text-left transition-colors hover:bg-gray-50 dark:hover:bg-gray-800/50"
                                        >
                                            <span className={cn('mt-0.5 size-2.5 shrink-0 rounded-full', colors.dot)} />
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-start justify-between gap-2">
                                                    <p className="text-sm font-semibold text-gray-900 dark:text-white">
                                                        {finding.title}
                                                    </p>
                                                    <div className="flex shrink-0 items-center gap-2">
                                                        <span
                                                            className={cn(
                                                                'rounded-full px-2 py-0.5 text-xs font-medium',
                                                                colors.bg,
                                                                colors.text,
                                                            )}
                                                        >
                                                            {finding.category}
                                                        </span>
                                                        {isExpanded ? (
                                                            <ChevronUp className="size-4 text-gray-400" />
                                                        ) : (
                                                            <ChevronDown className="size-4 text-gray-400" />
                                                        )}
                                                    </div>
                                                </div>
                                                {!isExpanded && (
                                                    <p className="mt-1 line-clamp-1 text-xs text-gray-500 dark:text-gray-400">
                                                        {finding.description}
                                                    </p>
                                                )}
                                            </div>
                                        </button>
                                        {isExpanded && (
                                            <div className={cn('border-t p-3', colors.border, colors.bg)}>
                                                <p className="text-sm text-gray-700 dark:text-gray-300">
                                                    {finding.description}
                                                </p>
                                                {finding.evidence && finding.evidence.length > 0 && (
                                                    <div className="mt-3">
                                                        <p className="mb-1 text-xs font-semibold text-gray-500">
                                                            Evidencia:
                                                        </p>
                                                        <ul className="space-y-1">
                                                            {finding.evidence.map((e, eIdx) => (
                                                                <li
                                                                    key={eIdx}
                                                                    className="flex items-start gap-1.5 text-xs text-gray-600 dark:text-gray-400"
                                                                >
                                                                    <span className="mt-1 text-gray-400">-</span>
                                                                    <span>{e}</span>
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}

                {/* ============================================================ */}
                {/* INSIGHTS (LLM) */}
                {/* ============================================================ */}
                {insights && (
                    <div className="mb-6">
                        <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <Lightbulb className="size-4" />
                            Interpretacion AI
                        </h3>
                        <div className="rounded-lg border border-violet-200 bg-gradient-to-r from-violet-50 to-fuchsia-50 p-4 dark:border-violet-800 dark:from-violet-950/20 dark:to-fuchsia-950/20">
                            <div className="prose prose-sm dark:prose-invert max-w-none text-sm text-gray-700 dark:text-gray-300">
                                <Markdown remarkPlugins={[remarkGfm]}>{insights}</Markdown>
                            </div>
                        </div>
                    </div>
                )}

                {/* ============================================================ */}
                {/* RECOMMENDATIONS */}
                {/* ============================================================ */}
                {recommendations.length > 0 && (
                    <div className="mb-6">
                        <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <CheckCircle2 className="size-4" />
                            Recomendaciones
                        </h3>
                        <div className="space-y-2">
                            {recommendations.map((rec, idx) => (
                                <div
                                    key={idx}
                                    className="flex items-start gap-3 rounded-lg border bg-white p-3 shadow-sm dark:bg-gray-900"
                                >
                                    <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">
                                        {idx + 1}
                                    </span>
                                    <p className="text-sm text-gray-700 dark:text-gray-300">{rec}</p>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* ============================================================ */}
                {/* FOOTER - Methodology */}
                {/* ============================================================ */}
                {methodology && (
                    <div className="border-t pt-3">
                        <button
                            onClick={() => setShowMethodology(!showMethodology)}
                            className="flex w-full items-center gap-1.5 text-xs text-gray-400 transition-colors hover:text-gray-600 dark:hover:text-gray-300"
                        >
                            <Info className="size-3" />
                            <span>Metodologia</span>
                            {showMethodology ? (
                                <ChevronUp className="size-3" />
                            ) : (
                                <ChevronDown className="size-3" />
                            )}
                        </button>
                        {showMethodology && (
                            <p className="mt-2 text-xs text-gray-400">{methodology}</p>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
