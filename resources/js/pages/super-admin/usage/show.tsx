import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { AnimatedCounter, StaggerContainer, StaggerItem } from '@/components/motion';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ArrowLeft, CreditCard } from 'lucide-react';

interface Props {
    company: { id: number; name: string };
    daily: Record<string, Record<string, number>>;
    totals: Record<string, number>;
    period: string;
    from: string;
    to: string;
}

function formatNumber(n: number): string {
    if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`;
    if (n >= 1_000) return `${(n / 1_000).toFixed(1)}K`;
    return Math.round(n).toLocaleString();
}

const METER_LABELS: Record<string, string> = {
    alerts_processed: 'Alertas procesadas',
    alerts_revalidated: 'Revalidaciones',
    ai_tokens: 'AI Tokens',
    notifications_sms: 'SMS',
    notifications_whatsapp: 'WhatsApp',
    notifications_call: 'Llamadas',
    copilot_messages: 'Copilot mensajes',
    copilot_tokens: 'Copilot tokens',
};

export default function UsageShow({ company, daily, totals, period, from, to }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Super Admin', href: '/super-admin' },
        { title: 'Usage', href: '/super-admin/usage' },
        { title: company.name, href: `/super-admin/usage/${company.id}` },
    ];

    const sortedDates = Object.keys(daily).sort();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Usage — ${company.name}`} />

            <div className="mx-auto max-w-7xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <Button variant="ghost" size="sm" onClick={() => router.get('/super-admin/usage')}>
                            <ArrowLeft className="mr-1 h-4 w-4" />
                            Volver
                        </Button>
                        <div>
                            <h1 className="font-display text-2xl font-bold tracking-tight">{company.name}</h1>
                            <p className="text-muted-foreground text-sm">
                                {from} — {to}
                            </p>
                        </div>
                    </div>
                    <Select
                        value={period}
                        onValueChange={(val) =>
                            router.get(`/super-admin/usage/${company.id}`, { period: val }, { preserveState: true })
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
                </div>

                {/* Meter totals */}
                <StaggerContainer className="grid gap-4 md:grid-cols-4">
                    {Object.entries(METER_LABELS).map(([meter, label]) => (
                        <StaggerItem key={meter}>
                            <Card className="relative overflow-hidden rounded-xl shadow-sm">
                                <CreditCard className="absolute -right-2 -top-2 size-24 text-muted-foreground/10" />
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        {label}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="text-2xl font-bold tabular-nums">
                                        <AnimatedCounter
                                            value={totals[meter] ?? 0}
                                            formatter={formatNumber}
                                            className="font-mono text-xs"
                                        />
                                    </div>
                                </CardContent>
                            </Card>
                        </StaggerItem>
                    ))}
                </StaggerContainer>

                {/* Daily breakdown */}
                <Card className="rounded-xl">
                    <CardHeader>
                        <CardTitle className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                            Detalle diario
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {sortedDates.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-12">
                                <CreditCard className="mb-3 size-16 text-muted-foreground/20" />
                                <p className="font-display font-semibold text-muted-foreground">
                                    No hay datos para este periodo.
                                </p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-gray-100 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">
                                            <th className="px-3 py-2">Fecha</th>
                                            {Object.values(METER_LABELS).map((label) => (
                                                <th key={label} className="px-3 py-2 text-right">
                                                    {label}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {sortedDates.map((date) => (
                                            <tr key={date} className="border-b">
                                                <td className="px-3 py-2 font-mono text-xs">{date}</td>
                                                {Object.keys(METER_LABELS).map((meter) => (
                                                    <td key={meter} className="px-3 py-2 text-right font-mono text-xs tabular-nums">
                                                        {formatNumber(daily[date]?.[meter] ?? 0)}
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
