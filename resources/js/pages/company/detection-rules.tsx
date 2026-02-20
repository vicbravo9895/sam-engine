import { DetectionFlow, type Rule } from '@/components/company/detection-flow';
import { CHANNEL_TYPES, RECIPIENT_TYPES } from '@/components/company/flow-nodes';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    BellOff,
    ChevronDown,
    ChevronRight,
    HelpCircle,
    Loader2,
    Network,
    Save,
    Satellite,
    TestTube,
} from 'lucide-react';
import { useCallback, useState } from 'react';

interface StaleVehicleMonitorConfig {
    enabled: boolean;
    threshold_minutes: number;
    channels: string[];
    recipients: string[];
    cooldown_minutes: number;
    inactive_after_days: number;
}

interface Props {
    company: { id: number; name: string };
    safetyStreamNotify: { enabled: boolean; rules: Rule[] };
    staleVehicleMonitor: StaleVehicleMonitorConfig;
    canonicalBehaviorLabels: string[];
    labelTranslations: Record<string, string>;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Empresa', href: '/company' },
    { title: 'Motor de Reglas de Detección', href: '/company/detection-rules' },
];

function ThresholdInput({
    minutes,
    onChange,
}: {
    minutes: number;
    onChange: (minutes: number) => void;
}) {
    const [unit, setUnit] = useState<'minutes' | 'hours'>(minutes >= 60 ? 'hours' : 'minutes');
    const displayValue = unit === 'hours' ? Math.round(minutes / 60) : minutes;

    const handleValueChange = (val: string) => {
        const num = parseInt(val, 10) || 0;
        onChange(unit === 'hours' ? num * 60 : num);
    };

    const handleUnitChange = (newUnit: string) => {
        const u = newUnit as 'minutes' | 'hours';
        setUnit(u);
        if (u === 'hours') {
            onChange(Math.max(60, Math.round(minutes / 60) * 60));
        } else {
            onChange(minutes);
        }
    };

    return (
        <div className="flex gap-2">
            <Input
                type="number"
                min={unit === 'hours' ? 1 : 5}
                max={unit === 'hours' ? 24 : 1440}
                value={displayValue}
                onChange={(e) => handleValueChange(e.target.value)}
                className="w-24"
            />
            <Select value={unit} onValueChange={handleUnitChange}>
                <SelectTrigger className="w-28">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="minutes">Minutos</SelectItem>
                    <SelectItem value="hours">Horas</SelectItem>
                </SelectContent>
            </Select>
        </div>
    );
}

export default function DetectionRulesPage() {
    const { safetyStreamNotify, staleVehicleMonitor, canonicalBehaviorLabels, labelTranslations } =
        usePage().props as Props;

    const [howItWorksOpen, setHowItWorksOpen] = useState(false);
    const [howToTestOpen, setHowToTestOpen] = useState(false);

    const form = useForm({
        enabled: safetyStreamNotify.enabled,
        rules: safetyStreamNotify.rules,
    });

    const staleForm = useForm<StaleVehicleMonitorConfig>({
        enabled: staleVehicleMonitor.enabled,
        threshold_minutes: staleVehicleMonitor.threshold_minutes,
        channels: staleVehicleMonitor.channels,
        recipients: staleVehicleMonitor.recipients,
        cooldown_minutes: staleVehicleMonitor.cooldown_minutes,
        inactive_after_days: staleVehicleMonitor.inactive_after_days,
    });

    const handleRulesChange = useCallback((newRules: Rule[]) => {
        form.setData('rules', newRules);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put('/company/detection-rules');
    };

    const handleStaleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        staleForm.put('/company/stale-vehicle-monitor');
    };

    const toggleStaleChannel = (channel: string) => {
        const current = staleForm.data.channels;
        if (current.includes(channel)) {
            staleForm.setData('channels', current.filter((c) => c !== channel));
        } else {
            staleForm.setData('channels', [...current, channel]);
        }
    };

    const toggleStaleRecipient = (recipient: string) => {
        const current = staleForm.data.recipients;
        if (current.includes(recipient)) {
            staleForm.setData('recipients', current.filter((r) => r !== recipient));
        } else {
            staleForm.setData('recipients', [...current, recipient]);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Motor de Reglas de Detección" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="font-display text-2xl font-bold tracking-tight sm:text-3xl">
                        Motor de Reglas de Detección
                    </h1>
                    <p className="text-muted-foreground">
                        Define qué señales de seguridad disparan alertas, el pipeline de IA, o
                        notificaciones inmediatas.
                    </p>
                </div>

                {/* Cómo funciona */}
                <Collapsible open={howItWorksOpen} onOpenChange={setHowItWorksOpen}>
                    <Card>
                        <CollapsibleTrigger asChild>
                            <CardHeader className="cursor-pointer select-none rounded-t-xl transition-colors hover:bg-muted/50">
                                <div className="flex items-center gap-2">
                                    {howItWorksOpen ? (
                                        <ChevronDown className="size-4" />
                                    ) : (
                                        <ChevronRight className="size-4" />
                                    )}
                                    <HelpCircle className="text-muted-foreground size-4" />
                                    <CardTitle className="text-base">Cómo funciona</CardTitle>
                                </div>
                            </CardHeader>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <CardContent className="text-muted-foreground space-y-3 pt-0 text-sm">
                                <p>
                                    Cuando un vehículo genera un{' '}
                                    <strong>evento de seguridad</strong> en Samsara (frenado brusco,
                                    colisión, exceso de velocidad, etc.), el sistema recibe una señal con
                                    etiquetas de comportamiento. Las reglas que construyes aquí se evalúan
                                    contra esas etiquetas.
                                </p>

                                <h5 className="font-display text-foreground font-semibold tracking-tight">Construir reglas</h5>
                                <ol className="ml-2 list-inside list-decimal space-y-1">
                                    <li>
                                        <strong>Arrastra señales</strong> desde la paleta izquierda al
                                        canvas. Usa la barra de búsqueda para encontrar la que necesitas.
                                        Cada señal se convierte en un nodo trigger independiente.
                                    </li>
                                    <li>
                                        <strong>Crea un nodo AND</strong> con el botón en la paleta. Cada
                                        AND es una regla.
                                    </li>
                                    <li>
                                        <strong>Conecta triggers a un AND</strong> arrastrando desde el
                                        punto derecho del trigger al punto izquierdo del AND. Puedes
                                        conectar uno o varios triggers al mismo AND (todas las condiciones
                                        deben cumplirse a la vez).
                                    </li>
                                    <li>
                                        <strong>Configura canales y destinatarios</strong> arrastrando nodos
                                        de canal (WhatsApp, SMS, Llamada) y destinatario (Monitoreo,
                                        Supervisor, etc.) desde la paleta y conectándolos al lado derecho
                                        del nodo AND. Esto define a quién y por qué medio se notifica
                                        cuando la regla se activa.
                                    </li>
                                </ol>

                                <h5 className="font-display text-foreground font-semibold tracking-tight">Tipos de acción</h5>
                                <p>
                                    Haz clic en la etiqueta de acción del nodo AND para cambiar entre:
                                </p>
                                <ul className="ml-2 list-inside list-disc space-y-1">
                                    <li>
                                        <strong className="text-violet-600 dark:text-violet-400">
                                            Pipeline IA
                                        </strong>{' '}
                                        — Procesa la alerta con el pipeline de IA antes de notificar.
                                        Ideal para análisis detallado.
                                    </li>
                                    <li>
                                        <strong className="text-amber-600 dark:text-amber-400">
                                            Alerta inmediata
                                        </strong>{' '}
                                        — Notifica de inmediato sin pasar por IA. Para respuesta rápida.
                                    </li>
                                    <li>
                                        <strong className="text-emerald-600 dark:text-emerald-400">
                                            Ambos
                                        </strong>{' '}
                                        — Notifica de inmediato Y ejecuta el pipeline de IA en paralelo.
                                    </li>
                                </ul>

                                <h5 className="font-display text-foreground font-semibold tracking-tight">
                                    Canales y destinatarios
                                </h5>
                                <p>
                                    Si no conectas canales ni destinatarios a una regla, se usará la
                                    configuración por defecto de la matriz de escalación. Si los conectas,
                                    se usarán los que hayas configurado en la regla.
                                </p>
                            </CardContent>
                        </CollapsibleContent>
                    </Card>
                </Collapsible>

                {/* Cómo probar */}
                <Collapsible open={howToTestOpen} onOpenChange={setHowToTestOpen}>
                    <Card>
                        <CollapsibleTrigger asChild>
                            <CardHeader className="cursor-pointer select-none rounded-t-xl transition-colors hover:bg-muted/50">
                                <div className="flex items-center gap-2">
                                    {howToTestOpen ? (
                                        <ChevronDown className="size-4" />
                                    ) : (
                                        <ChevronRight className="size-4" />
                                    )}
                                    <TestTube className="text-muted-foreground size-4" />
                                    <CardTitle className="text-base">
                                        Cómo probar que funciona
                                    </CardTitle>
                                </div>
                            </CardHeader>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <CardContent className="text-muted-foreground space-y-3 pt-0 text-sm">
                                <ol className="ml-2 list-inside list-decimal space-y-2">
                                    <li>
                                        <strong>Revisa los Eventos de Seguridad:</strong>{' '}
                                        <Link
                                            href="/safety-signals"
                                            className="text-primary underline underline-offset-2 hover:no-underline"
                                        >
                                            Eventos de Seguridad
                                        </Link>{' '}
                                        muestra las señales que llegan de Samsara y sus etiquetas.
                                    </li>
                                    <li>
                                        <strong>Configura al menos una regla</strong> con una señal que
                                        sepas que ya aparece en tu flota y guarda.
                                    </li>
                                    <li>
                                        Cuando ocurra un evento real con esa etiqueta, el sistema creará
                                        una alerta en{' '}
                                        <Link
                                            href="/samsara/alerts"
                                            className="text-primary underline underline-offset-2 hover:no-underline"
                                        >
                                            Alertas
                                        </Link>{' '}
                                        y ejecutará la acción configurada (IA, inmediata, o ambas).
                                    </li>
                                </ol>
                                <p>
                                    El daemon que ingesta señales debe estar en ejecución; si no recibes
                                    eventos, revisa que el sync con Samsara esté activo.
                                </p>
                            </CardContent>
                        </CollapsibleContent>
                    </Card>
                </Collapsible>

                {/* Canvas y guardado */}
                <form onSubmit={handleSubmit} className="space-y-4">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0">
                            <div className="flex items-center gap-3">
                                <div className="flex size-10 items-center justify-center rounded-xl bg-violet-500/10">
                                    <Network className="size-5 text-violet-600" />
                                </div>
                                <div>
                                    <CardTitle>Reglas</CardTitle>
                                    <CardDescription>
                                        Arrastra señales, crea nodos AND y conecta para definir reglas.
                                    </CardDescription>
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="text-muted-foreground text-sm">
                                    {form.data.enabled ? 'Activo' : 'Pausado'}
                                </span>
                                <Switch
                                    checked={form.data.enabled}
                                    onCheckedChange={(checked) => form.setData('enabled', checked)}
                                />
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {form.data.enabled ? (
                                <>
                                    <DetectionFlow
                                        rules={form.data.rules}
                                        canonicalLabels={canonicalBehaviorLabels}
                                        labelTranslations={labelTranslations}
                                        onChange={handleRulesChange}
                                        height={560}
                                    />
                                    {form.data.rules.length === 0 ? (
                                        <div className="flex items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950">
                                            <BellOff className="size-4 text-amber-600" />
                                            <p className="text-sm text-amber-800 dark:text-amber-200">
                                                No hay reglas. Arrastra señales al canvas, crea un nodo AND,
                                                conecta los triggers al AND y guarda.
                                            </p>
                                        </div>
                                    ) : null}
                                </>
                            ) : (
                                <div className="flex items-center gap-3 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-900">
                                    <BellOff className="size-5 text-zinc-500" />
                                    <div>
                                        <p className="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                            Motor pausado
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            Las señales no dispararán alertas ni notificaciones. Las reglas
                                            se conservan.
                                        </p>
                                    </div>
                                </div>
                            )}

                            <div className="flex justify-end">
                                <Button type="submit" disabled={form.processing}>
                                    {form.processing ? (
                                        <Loader2 className="mr-2 size-4 animate-spin" />
                                    ) : (
                                        <Save className="mr-2 size-4" />
                                    )}
                                    {form.processing ? 'Guardando...' : 'Guardar reglas'}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </form>

                {/* Stale Vehicle Monitor */}
                <form onSubmit={handleStaleSubmit} className="space-y-4">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0">
                            <div className="flex items-center gap-3">
                                <div className="flex size-10 items-center justify-center rounded-xl bg-rose-500/10">
                                    <Satellite className="size-5 text-rose-600" />
                                </div>
                                <div>
                                    <CardTitle>Monitor de Vehículos sin Reportar</CardTitle>
                                    <CardDescription>
                                        Notifica cuando un vehículo deja de reportar estadísticas por un
                                        tiempo configurable.
                                    </CardDescription>
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="text-muted-foreground text-sm">
                                    {staleForm.data.enabled ? 'Activo' : 'Inactivo'}
                                </span>
                                <Switch
                                    checked={staleForm.data.enabled}
                                    onCheckedChange={(checked) =>
                                        staleForm.setData('enabled', checked)
                                    }
                                />
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            {staleForm.data.enabled ? (
                                <>
                                    <div className="grid gap-6 sm:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label className="uppercase tracking-wider text-xs">
                                                Umbral de inactividad
                                            </Label>
                                            <p className="text-muted-foreground text-xs">
                                                Tiempo sin reportar antes de alertar.
                                            </p>
                                            <ThresholdInput
                                                minutes={staleForm.data.threshold_minutes}
                                                onChange={(val) =>
                                                    staleForm.setData('threshold_minutes', val)
                                                }
                                            />
                                            {staleForm.errors.threshold_minutes ? (
                                                <p className="text-xs text-red-500">
                                                    {staleForm.errors.threshold_minutes}
                                                </p>
                                            ) : null}
                                        </div>

                                        <div className="space-y-2">
                                            <Label className="uppercase tracking-wider text-xs">
                                                Cooldown entre alertas
                                            </Label>
                                            <p className="text-muted-foreground text-xs">
                                                Tiempo mínimo entre re-alertas del mismo vehículo.
                                            </p>
                                            <div className="flex items-center gap-2">
                                                <Input
                                                    type="number"
                                                    min={15}
                                                    max={1440}
                                                    value={staleForm.data.cooldown_minutes}
                                                    onChange={(e) =>
                                                        staleForm.setData(
                                                            'cooldown_minutes',
                                                            parseInt(e.target.value, 10) || 60,
                                                        )
                                                    }
                                                    className="w-24"
                                                />
                                                <span className="text-muted-foreground text-sm">
                                                    minutos
                                                </span>
                                            </div>
                                            {staleForm.errors.cooldown_minutes ? (
                                                <p className="text-xs text-red-500">
                                                    {staleForm.errors.cooldown_minutes}
                                                </p>
                                            ) : null}
                                        </div>

                                        <div className="space-y-2">
                                            <Label className="uppercase tracking-wider text-xs">
                                                Ignorar vehículos inactivos
                                            </Label>
                                            <p className="text-muted-foreground text-xs">
                                                Vehículos sin reportar por más de estos días se consideran inactivos y no generan alertas.
                                            </p>
                                            <div className="flex items-center gap-2">
                                                <Input
                                                    type="number"
                                                    min={1}
                                                    max={365}
                                                    value={staleForm.data.inactive_after_days}
                                                    onChange={(e) =>
                                                        staleForm.setData(
                                                            'inactive_after_days',
                                                            parseInt(e.target.value, 10) || 20,
                                                        )
                                                    }
                                                    className="w-24"
                                                />
                                                <span className="text-muted-foreground text-sm">
                                                    días
                                                </span>
                                            </div>
                                            {staleForm.errors.inactive_after_days ? (
                                                <p className="text-xs text-red-500">
                                                    {staleForm.errors.inactive_after_days}
                                                </p>
                                            ) : null}
                                        </div>
                                    </div>

                                    <div className="grid gap-6 sm:grid-cols-2">
                                        <div className="space-y-3">
                                            <Label className="uppercase tracking-wider text-xs">Canales de notificación</Label>
                                            <p className="text-muted-foreground text-xs">
                                                Por qué medios se envía la alerta.
                                            </p>
                                            <div className="space-y-2">
                                                {(
                                                    Object.entries(CHANNEL_TYPES) as [
                                                        string,
                                                        (typeof CHANNEL_TYPES)[keyof typeof CHANNEL_TYPES],
                                                    ][]
                                                ).map(([key, cfg]) => {
                                                    const Icon = cfg.icon;
                                                    return (
                                                        <label
                                                            key={key}
                                                            className="flex items-center gap-2"
                                                        >
                                                            <Checkbox
                                                                checked={staleForm.data.channels.includes(
                                                                    key,
                                                                )}
                                                                onCheckedChange={() =>
                                                                    toggleStaleChannel(key)
                                                                }
                                                            />
                                                            <Icon className="size-4 text-sky-600" />
                                                            <span className="text-sm">
                                                                {cfg.label}
                                                            </span>
                                                        </label>
                                                    );
                                                })}
                                            </div>
                                            {staleForm.errors.channels ? (
                                                <p className="text-xs text-red-500">
                                                    {staleForm.errors.channels}
                                                </p>
                                            ) : null}
                                        </div>

                                        <div className="space-y-3">
                                            <Label className="uppercase tracking-wider text-xs">Destinatarios</Label>
                                            <p className="text-muted-foreground text-xs">
                                                A quién se envía la alerta.
                                            </p>
                                            <div className="space-y-2">
                                                {(
                                                    Object.entries(RECIPIENT_TYPES) as [
                                                        string,
                                                        (typeof RECIPIENT_TYPES)[keyof typeof RECIPIENT_TYPES],
                                                    ][]
                                                ).map(([key, cfg]) => {
                                                    const Icon = cfg.icon;
                                                    return (
                                                        <label
                                                            key={key}
                                                            className="flex items-center gap-2"
                                                        >
                                                            <Checkbox
                                                                checked={staleForm.data.recipients.includes(
                                                                    key,
                                                                )}
                                                                onCheckedChange={() =>
                                                                    toggleStaleRecipient(key)
                                                                }
                                                            />
                                                            <Icon className="size-4 text-teal-600" />
                                                            <span className="text-sm">
                                                                {cfg.label}
                                                            </span>
                                                        </label>
                                                    );
                                                })}
                                            </div>
                                            {staleForm.errors.recipients && (
                                                <p className="text-xs text-red-500">
                                                    {staleForm.errors.recipients}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                </>
                            ) : (
                                <div className="flex items-center gap-3 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-900">
                                    <Satellite className="size-5 text-zinc-500" />
                                    <div>
                                        <p className="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                            Monitor inactivo
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            No se enviarán alertas por vehículos sin reportar. Activa el
                                            monitor para configurarlo.
                                        </p>
                                    </div>
                                </div>
                            )}

                            <div className="flex justify-end">
                                <Button type="submit" disabled={staleForm.processing}>
                                    {staleForm.processing ? (
                                        <Loader2 className="mr-2 size-4 animate-spin" />
                                    ) : (
                                        <Save className="mr-2 size-4" />
                                    )}
                                    {staleForm.processing ? 'Guardando...' : 'Guardar monitor'}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </form>
            </div>
        </AppLayout>
    );
}
