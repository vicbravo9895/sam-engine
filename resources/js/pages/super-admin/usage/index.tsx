import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { BarChart3, Download, Building2, Info } from 'lucide-react';

interface CompanyUsage {
    company_id: number;
    company_name: string;
    alerts_processed: number;
    alerts_revalidated: number;
    ai_tokens: number;
    notifications_sms: number;
    notifications_whatsapp: number;
    notifications_call: number;
    copilot_messages: number;
    copilot_tokens: number;
}

interface DailySummary {
    date: string;
    alerts: number;
    ai_tokens: number;
    notifications: number;
    copilot: number;
}

interface Props {
    usage: CompanyUsage[];
    dailySummaries?: DailySummary[];
    period: string;
    from: string;
    to: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Super Admin', href: '/super-admin' },
    { title: 'Usage', href: '/super-admin/usage' },
];

function formatNumber(n: number): string {
    if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`;
    if (n >= 1_000) return `${(n / 1_000).toFixed(1)}K`;
    return n.toLocaleString();
}

function DailyUsageBarChart({ data }: { data: DailySummary[] }) {
    if (!data || data.length === 0) return null;

    const maxAlerts = Math.max(1, ...data.map((d) => d.alerts));
    const maxTokens = Math.max(1, ...data.map((d) => d.ai_tokens));
    const maxNotifications = Math.max(1, ...data.map((d) => d.notifications));
    const maxCopilot = Math.max(1, ...data.map((d) => d.copilot));

    return (
        <div className="space-y-4">
            <h4 className="text-sm font-medium">Uso diario (sparklines)</h4>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <p className="mb-2 text-xs text-muted-foreground">Alertas</p>
                    <div className="flex h-12 items-end gap-0.5">
                        {data.map((d, i) => (
                            <div
                                key={d.date}
                                title={`${d.date}: ${d.alerts}`}
                                className="min-w-[4px] flex-1 rounded-t bg-blue-500/80 transition-opacity hover:opacity-100"
                                style={{ height: `${Math.max(4, (d.alerts / maxAlerts) * 100)}%` }}
                            />
                        ))}
                    </div>
                </div>
                <div>
                    <p className="mb-2 text-xs text-muted-foreground">AI Tokens</p>
                    <div className="flex h-12 items-end gap-0.5">
                        {data.map((d) => (
                            <div
                                key={d.date}
                                title={`${d.date}: ${formatNumber(d.ai_tokens)}`}
                                className="min-w-[4px] flex-1 rounded-t bg-purple-500/80 transition-opacity hover:opacity-100"
                                style={{ height: `${Math.max(4, (d.ai_tokens / maxTokens) * 100)}%` }}
                            />
                        ))}
                    </div>
                </div>
                <div>
                    <p className="mb-2 text-xs text-muted-foreground">Notificaciones</p>
                    <div className="flex h-12 items-end gap-0.5">
                        {data.map((d) => (
                            <div
                                key={d.date}
                                title={`${d.date}: ${d.notifications}`}
                                className="min-w-[4px] flex-1 rounded-t bg-green-500/80 transition-opacity hover:opacity-100"
                                style={{ height: `${Math.max(4, (d.notifications / maxNotifications) * 100)}%` }}
                            />
                        ))}
                    </div>
                </div>
                <div>
                    <p className="mb-2 text-xs text-muted-foreground">Copilot</p>
                    <div className="flex h-12 items-end gap-0.5">
                        {data.map((d) => (
                            <div
                                key={d.date}
                                title={`${d.date}: ${d.copilot}`}
                                className="min-w-[4px] flex-1 rounded-t bg-amber-500/80 transition-opacity hover:opacity-100"
                                style={{ height: `${Math.max(4, (d.copilot / maxCopilot) * 100)}%` }}
                            />
                        ))}
                    </div>
                </div>
            </div>
            <p className="text-xs text-muted-foreground">
                Fechas: {data[0]?.date ?? '—'} a {data[data.length - 1]?.date ?? '—'}
            </p>
        </div>
    );
}

export default function UsageIndex({ usage, dailySummaries = [], period, from, to }: Props) {
    const totals = usage.reduce(
        (acc, row) => ({
            alerts: acc.alerts + row.alerts_processed + row.alerts_revalidated,
            ai_tokens: acc.ai_tokens + row.ai_tokens,
            notifications:
                acc.notifications +
                row.notifications_sms +
                row.notifications_whatsapp +
                row.notifications_call,
            copilot: acc.copilot + row.copilot_messages,
        }),
        { alerts: 0, ai_tokens: 0, notifications: 0, copilot: 0 },
    );

    const metricCards = [
        {
            label: 'Alertas procesadas',
            value: formatNumber(totals.alerts),
            unit: 'Procesamiento + revalidaciones',
        },
        {
            label: 'AI Tokens',
            value: formatNumber(totals.ai_tokens),
            unit: 'Tokens consumidos por pipeline y copilot',
        },
        {
            label: 'Notificaciones',
            value: formatNumber(totals.notifications),
            unit: 'SMS + WhatsApp + Llamadas',
        },
        {
            label: 'Mensajes Copilot',
            value: formatNumber(totals.copilot),
            unit: 'Consultas interactivas',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Usage Dashboard" />

            <div className="mx-auto max-w-7xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="font-display text-2xl font-bold tracking-tight">Usage Dashboard</h1>
                        <p className="text-muted-foreground text-sm">
                            {from} — {to}
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <Select
                            value={period}
                            onValueChange={(val) =>
                                router.get('/super-admin/usage', { period: val }, { preserveState: true })
                            }
                        >
                            <SelectTrigger className="w-[160px]">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="mtd">Mes actual</SelectItem>
                                <SelectItem value="last30">Últimos 30 días</SelectItem>
                            </SelectContent>
                        </Select>
                        <Button variant="outline" size="sm" asChild>
                            <a href={`/super-admin/usage/export?period=${period}`} download>
                                <Download className="mr-2 h-4 w-4" />
                                Export CSV
                            </a>
                        </Button>
                    </div>
                </div>

                {/* Metric cards with financial feel */}
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                    {metricCards.map((card) => (
                        <div
                            key={card.label}
                            className="rounded-lg border border-gray-200 p-5 dark:border-gray-800"
                        >
                            <p className="text-sm text-muted-foreground">{card.label}</p>
                            <p className="text-3xl font-semibold tracking-tight mt-1">{card.value}</p>
                            <p className="text-xs text-muted-foreground mt-1">{card.unit}</p>
                        </div>
                    ))}
                </div>

                {/* Daily usage visualization */}
                {dailySummaries.length > 0 ? (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <BarChart3 className="h-5 w-5" />
                                Tendencia diaria
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <DailyUsageBarChart data={dailySummaries} />
                        </CardContent>
                    </Card>
                ) : null}

                {/* Per-company table */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <BarChart3 className="h-5 w-5" />
                            Uso por empresa
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {usage.length === 0 ? (
                            <p className="text-muted-foreground py-8 text-center text-sm">
                                No hay datos de uso para este periodo.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-gray-100 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">
                                            <th className="px-3 py-3">Empresa</th>
                                            <th className="px-3 py-3 text-right">Alertas</th>
                                            <th className="px-3 py-3 text-right">AI Tokens</th>
                                            <th className="px-3 py-3 text-right">SMS</th>
                                            <th className="px-3 py-3 text-right">WhatsApp</th>
                                            <th className="px-3 py-3 text-right">Llamadas</th>
                                            <th className="px-3 py-3 text-right">Copilot</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {usage.map((row) => (
                                            <tr
                                                key={row.company_id}
                                                className="border-b border-gray-100 transition-colors hover:bg-muted/50"
                                            >
                                                <td className="px-3 py-2">
                                                    <Link
                                                        href={`/super-admin/usage/${row.company_id}`}
                                                        className="text-primary flex items-center gap-1.5 hover:underline"
                                                    >
                                                        <Building2 className="h-3.5 w-3.5" />
                                                        {row.company_name}
                                                    </Link>
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    {formatNumber(row.alerts_processed + row.alerts_revalidated)}
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    {formatNumber(row.ai_tokens)}
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    {formatNumber(row.notifications_sms)}
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    {formatNumber(row.notifications_whatsapp)}
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    {formatNumber(row.notifications_call)}
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    {formatNumber(row.copilot_messages)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Cost definitions */}
                <div className="rounded-xl border border-gray-200 bg-muted/30 p-5 shadow-sm dark:border-gray-800">
                    <h3 className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
                        <Info className="h-4 w-4" />
                        Definiciones de métricas
                    </h3>
                    <ul className="mt-3 space-y-1.5 text-xs text-muted-foreground">
                        <li>
                            <strong>Alertas procesadas:</strong> Alertas recibidas desde Samsara que pasan por el
                            pipeline AI (triage, investigator, mensaje final) más las revalidaciones para alertas
                            en monitoreo.
                        </li>
                        <li>
                            <strong>AI Tokens:</strong> Tokens consumidos por los modelos OpenAI (GPT-4o, GPT-4o-mini)
                            tanto en el pipeline de alertas como en el Copilot interactivo.
                        </li>
                        <li>
                            <strong>Notificaciones:</strong> Mensajes SMS, WhatsApp y llamadas de voz enviadas vía
                            Twilio como resultado de las decisiones del agente de notificación.
                        </li>
                        <li>
                            <strong>Mensajes Copilot:</strong> Consultas interactivas al FleetAgent para obtener
                            información de flota, vehículos, viajes, eventos de seguridad, etc.
                        </li>
                    </ul>
                </div>
            </div>
        </AppLayout>
    );
}
