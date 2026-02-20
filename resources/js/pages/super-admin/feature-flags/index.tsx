import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Flag, Info } from 'lucide-react';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

interface FlagDef {
    label: string;
    description: string;
}

interface CompanyRow {
    id: number;
    name: string;
    is_active: boolean;
}

interface Props {
    flags: Record<string, FlagDef>;
    companies: CompanyRow[];
    matrix: Record<number, Record<string, boolean>>;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Super Admin', href: '/super-admin' },
    { title: 'Feature flags', href: '/super-admin/feature-flags' },
];

export default function FeatureFlagsIndex({ flags, companies, matrix }: Props) {
    const flagKeys = Object.keys(flags);

    function handleToggle(companyId: number, flag: string, active: boolean) {
        router.put(`/super-admin/feature-flags/${companyId}`, { flag, active });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Feature flags" />
            <TooltipProvider>
                <div className="mx-auto max-w-7xl space-y-6 p-6">
                    <div>
                        <h1 className="font-display text-2xl font-bold tracking-tight flex items-center gap-2">
                            <Flag className="h-6 w-6" />
                            Feature flags
                        </h1>
                        <p className="text-muted-foreground text-sm mt-1">
                            Activa o desactiva flags por empresa. Los cambios aplican de inmediato.
                        </p>
                    </div>

                    <Card className="rounded-xl">
                        <CardHeader>
                            <CardTitle className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                                Flags por empresa
                            </CardTitle>
                            <CardDescription>
                                Verde = activo. El valor por defecto (cuando no hay override) está definido en código.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50">
                                            <th className="text-left p-3 font-medium w-48">Empresa</th>
                                            {flagKeys.map((key) => (
                                                <th key={key} className="p-3 font-medium text-center min-w-[140px]">
                                                    <div className="flex items-center justify-center gap-1">
                                                        <span>{flags[key].label}</span>
                                                        <Tooltip>
                                                            <TooltipTrigger asChild>
                                                                <Info className="h-3.5 w-3.5 text-muted-foreground cursor-help" />
                                                            </TooltipTrigger>
                                                            <TooltipContent side="top" className="max-w-xs">
                                                                {flags[key].description}
                                                            </TooltipContent>
                                                        </Tooltip>
                                                    </div>
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {companies.map((company) => (
                                            <tr
                                                key={company.id}
                                                className={`border-b last:border-0 ${!company.is_active ? 'opacity-60' : ''}`}
                                            >
                                                <td className="p-3">
                                                    <div className="flex items-center gap-2">
                                                        <span className="font-medium">{company.name}</span>
                                                        {!company.is_active ? (
                                                            <Badge variant="secondary">Inactiva</Badge>
                                                        ) : null}
                                                    </div>
                                                </td>
                                                {flagKeys.map((flagKey) => {
                                                    const active = matrix[company.id]?.[flagKey] ?? false;
                                                    return (
                                                        <td key={flagKey} className="p-3 text-center">
                                                            <div className="flex justify-center">
                                                                <Switch
                                                                    checked={active}
                                                                    onCheckedChange={(checked) =>
                                                                        handleToggle(company.id, flagKey, checked)
                                                                    }
                                                                />
                                                            </div>
                                                        </td>
                                                    );
                                                })}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            {companies.length === 0 ? (
                                <div className="flex flex-col items-center justify-center py-12">
                                    <Flag className="mb-3 size-16 text-muted-foreground/20" />
                                    <p className="font-display font-semibold text-muted-foreground">
                                        No hay empresas. Crea una en Super Admin → Empresas.
                                    </p>
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>
                </div>
            </TooltipProvider>
        </AppLayout>
    );
}
