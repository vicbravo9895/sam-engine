import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Bell,
    BellOff,
    Bot,
    Clock,
    Loader2,
    MessageSquare,
    Phone,
    Mail,
    RefreshCw,
    Save,
    Search,
    Settings2,
    Timer,
} from 'lucide-react';

// Available interval options in minutes
const AVAILABLE_INTERVALS = [5, 10, 15, 20, 30, 45, 60, 90, 120] as const;

interface AiConfig {
    investigation_windows: {
        correlation_window_minutes: number;
        media_window_seconds: number;
        safety_events_before_minutes: number;
        safety_events_after_minutes: number;
        vehicle_stats_before_minutes: number;
        vehicle_stats_after_minutes: number;
        camera_media_window_minutes: number;
    };
    monitoring: {
        confidence_threshold: number;
        check_intervals: number[];
        max_revalidations: number;
    };
    escalation_matrix: Record<string, { channels: string[]; recipients: string[] }>;
}

interface NotificationConfig {
    channels_enabled: {
        sms: boolean;
        whatsapp: boolean;
        call: boolean;
        email: boolean;
    };
}

interface Props {
    company: {
        id: number;
        name: string;
    };
    aiConfig: AiConfig;
    notificationConfig: NotificationConfig;
    defaults: {
        ai_config: AiConfig;
        notifications: NotificationConfig;
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Empresa', href: '/company' },
    { title: 'Configuración de AI', href: '/company/ai-settings' },
];

export default function AiSettings() {
    const { aiConfig, notificationConfig, defaults } = usePage().props as Props;

    // Use nested structure for form data
    const form = useForm({
        investigation_windows: {
            correlation_window_minutes: aiConfig.investigation_windows.correlation_window_minutes,
            media_window_seconds: aiConfig.investigation_windows.media_window_seconds,
            safety_events_before_minutes: aiConfig.investigation_windows.safety_events_before_minutes,
            safety_events_after_minutes: aiConfig.investigation_windows.safety_events_after_minutes,
            vehicle_stats_before_minutes: aiConfig.investigation_windows.vehicle_stats_before_minutes,
            vehicle_stats_after_minutes: aiConfig.investigation_windows.vehicle_stats_after_minutes,
            camera_media_window_minutes: aiConfig.investigation_windows.camera_media_window_minutes,
        },
        monitoring: {
            confidence_threshold: aiConfig.monitoring.confidence_threshold,
            check_intervals: aiConfig.monitoring.check_intervals,
            max_revalidations: aiConfig.monitoring.max_revalidations,
        },
        channels_enabled: {
            sms: notificationConfig.channels_enabled.sms,
            whatsapp: notificationConfig.channels_enabled.whatsapp,
            call: notificationConfig.channels_enabled.call,
            email: notificationConfig.channels_enabled.email,
        },
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put('/company/ai-settings');
    };

    const handleReset = () => {
        if (confirm('¿Estás seguro de restablecer toda la configuración a valores predeterminados?')) {
            router.post('/company/ai-settings/reset');
        }
    };

    // Helper to update nested investigation_windows
    const setInvestigationWindow = (key: keyof typeof form.data.investigation_windows, value: number) => {
        form.setData('investigation_windows', {
            ...form.data.investigation_windows,
            [key]: value,
        });
    };

    // Helper to update nested monitoring
    const setMonitoring = (key: keyof typeof form.data.monitoring, value: number | number[]) => {
        form.setData('monitoring', {
            ...form.data.monitoring,
            [key]: value,
        });
    };

    // Helper to update nested channels_enabled
    const setChannelEnabled = (key: keyof typeof form.data.channels_enabled, value: boolean) => {
        form.setData('channels_enabled', {
            ...form.data.channels_enabled,
            [key]: value,
        });
    };

    // Helper to toggle interval in the list
    const toggleInterval = (interval: number) => {
        const currentIntervals = form.data.monitoring.check_intervals;
        const newIntervals = currentIntervals.includes(interval)
            ? currentIntervals.filter((i) => i !== interval)
            : [...currentIntervals, interval].sort((a, b) => a - b);
        
        // Don't allow empty intervals
        if (newIntervals.length > 0) {
            setMonitoring('check_intervals', newIntervals);
        }
    };

    const isModified = (
        section: 'investigation_windows' | 'monitoring' | 'channels_enabled',
        key: string
    ): boolean => {
        if (section === 'investigation_windows') {
            const current = form.data.investigation_windows[key as keyof typeof form.data.investigation_windows];
            const defaultVal = defaults.ai_config.investigation_windows[key as keyof typeof defaults.ai_config.investigation_windows];
            return current !== defaultVal;
        }
        if (section === 'monitoring') {
            const current = form.data.monitoring[key as keyof typeof form.data.monitoring];
            const defaultVal = defaults.ai_config.monitoring[key as keyof typeof defaults.ai_config.monitoring];
            if (Array.isArray(current) && Array.isArray(defaultVal)) {
                return JSON.stringify(current) !== JSON.stringify(defaultVal);
            }
            return current !== defaultVal;
        }
        if (section === 'channels_enabled') {
            const current = form.data.channels_enabled[key as keyof typeof form.data.channels_enabled];
            const defaultVal = defaults.notifications.channels_enabled[key as keyof typeof defaults.notifications.channels_enabled];
            return current !== defaultVal;
        }
        return false;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Configuración de AI" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4 sm:gap-6 sm:p-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-bold tracking-tight sm:text-2xl">
                            Configuración de AI
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Personaliza el comportamiento del sistema de análisis de alertas
                        </p>
                    </div>
                    <Link href="/company">
                        <Button variant="outline" size="sm">
                            <Settings2 className="mr-2 size-4" />
                            Configuración General
                        </Button>
                    </Link>
                </div>

                <form onSubmit={handleSubmit} className="mx-auto w-full max-w-3xl space-y-6">
                    {/* Investigation Windows Card */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-3">
                                <div className="bg-primary/10 flex size-12 items-center justify-center rounded-full">
                                    <Search className="text-primary size-6" />
                                </div>
                                <div>
                                    <CardTitle>Ventanas de Investigación</CardTitle>
                                    <CardDescription>
                                        Define cuánto tiempo antes y después del evento buscar información
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            {/* Safety Events */}
                            <div>
                                <h4 className="mb-3 flex items-center gap-2 font-medium">
                                    <Clock className="size-4" />
                                    Eventos de Seguridad
                                </h4>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="safety_before" className="flex items-center justify-between">
                                            <span>Minutos antes</span>
                                            {isModified('investigation_windows', 'safety_events_before_minutes') && (
                                                <span className="text-xs text-amber-600">Modificado</span>
                                            )}
                                        </Label>
                                        <Input
                                            id="safety_before"
                                            type="number"
                                            min={5}
                                            max={120}
                                            value={form.data.investigation_windows.safety_events_before_minutes}
                                            onChange={(e) =>
                                                setInvestigationWindow('safety_events_before_minutes', parseInt(e.target.value) || 0)
                                            }
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            Default: {defaults.ai_config.investigation_windows.safety_events_before_minutes} min
                                        </p>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="safety_after" className="flex items-center justify-between">
                                            <span>Minutos después</span>
                                            {isModified('investigation_windows', 'safety_events_after_minutes') && (
                                                <span className="text-xs text-amber-600">Modificado</span>
                                            )}
                                        </Label>
                                        <Input
                                            id="safety_after"
                                            type="number"
                                            min={1}
                                            max={60}
                                            value={form.data.investigation_windows.safety_events_after_minutes}
                                            onChange={(e) =>
                                                setInvestigationWindow('safety_events_after_minutes', parseInt(e.target.value) || 0)
                                            }
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            Default: {defaults.ai_config.investigation_windows.safety_events_after_minutes} min
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <Separator />

                            {/* Vehicle Stats */}
                            <div>
                                <h4 className="mb-3 flex items-center gap-2 font-medium">
                                    <Timer className="size-4" />
                                    Estadísticas del Vehículo
                                </h4>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="stats_before" className="flex items-center justify-between">
                                            <span>Minutos antes</span>
                                            {isModified('investigation_windows', 'vehicle_stats_before_minutes') && (
                                                <span className="text-xs text-amber-600">Modificado</span>
                                            )}
                                        </Label>
                                        <Input
                                            id="stats_before"
                                            type="number"
                                            min={1}
                                            max={30}
                                            value={form.data.investigation_windows.vehicle_stats_before_minutes}
                                            onChange={(e) =>
                                                setInvestigationWindow('vehicle_stats_before_minutes', parseInt(e.target.value) || 0)
                                            }
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            Default: {defaults.ai_config.investigation_windows.vehicle_stats_before_minutes} min
                                        </p>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="stats_after" className="flex items-center justify-between">
                                            <span>Minutos después</span>
                                            {isModified('investigation_windows', 'vehicle_stats_after_minutes') && (
                                                <span className="text-xs text-amber-600">Modificado</span>
                                            )}
                                        </Label>
                                        <Input
                                            id="stats_after"
                                            type="number"
                                            min={1}
                                            max={15}
                                            value={form.data.investigation_windows.vehicle_stats_after_minutes}
                                            onChange={(e) =>
                                                setInvestigationWindow('vehicle_stats_after_minutes', parseInt(e.target.value) || 0)
                                            }
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            Default: {defaults.ai_config.investigation_windows.vehicle_stats_after_minutes} min
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <Separator />

                            {/* Camera Media */}
                            <div>
                                <h4 className="mb-3 font-medium">Imágenes de Cámara</h4>
                                <div className="space-y-2">
                                    <Label htmlFor="camera_window" className="flex items-center justify-between">
                                        <span>Ventana de tiempo (minutos)</span>
                                        {isModified('investigation_windows', 'camera_media_window_minutes') && (
                                            <span className="text-xs text-amber-600">Modificado</span>
                                        )}
                                    </Label>
                                    <Input
                                        id="camera_window"
                                        type="number"
                                        min={1}
                                        max={10}
                                        className="max-w-[200px]"
                                        value={form.data.investigation_windows.camera_media_window_minutes}
                                        onChange={(e) =>
                                            setInvestigationWindow('camera_media_window_minutes', parseInt(e.target.value) || 0)
                                        }
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        Busca imágenes ± este tiempo del evento. Default: {defaults.ai_config.investigation_windows.camera_media_window_minutes} min
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Monitoring Card */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-3">
                                <div className="flex size-12 items-center justify-center rounded-full bg-blue-500/10">
                                    <Bot className="size-6 text-blue-600" />
                                </div>
                                <div>
                                    <CardTitle>Monitoreo y Revalidación</CardTitle>
                                    <CardDescription>
                                        Configura cuándo el sistema debe continuar monitoreando un evento
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="confidence" className="flex items-center justify-between">
                                        <span>Umbral de confianza</span>
                                        {isModified('monitoring', 'confidence_threshold') && (
                                            <span className="text-xs text-amber-600">Modificado</span>
                                        )}
                                    </Label>
                                    <Input
                                        id="confidence"
                                        type="number"
                                        step={0.05}
                                        min={0.5}
                                        max={0.99}
                                        value={form.data.monitoring.confidence_threshold}
                                        onChange={(e) =>
                                            setMonitoring('confidence_threshold', parseFloat(e.target.value) || 0)
                                        }
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        Si la confianza es menor a este valor, el evento se monitorea. Default: {defaults.ai_config.monitoring.confidence_threshold}
                                    </p>
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="max_reval" className="flex items-center justify-between">
                                        <span>Máximo de revalidaciones</span>
                                        {isModified('monitoring', 'max_revalidations') && (
                                            <span className="text-xs text-amber-600">Modificado</span>
                                        )}
                                    </Label>
                                    <Input
                                        id="max_reval"
                                        type="number"
                                        min={1}
                                        max={20}
                                        value={form.data.monitoring.max_revalidations}
                                        onChange={(e) =>
                                            setMonitoring('max_revalidations', parseInt(e.target.value) || 0)
                                        }
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        Número máximo de revalidaciones antes de cerrar. Default: {defaults.ai_config.monitoring.max_revalidations}
                                    </p>
                                </div>
                            </div>

                            <Separator />

                            {/* Check Intervals */}
                            <div className="space-y-3">
                                <Label className="flex items-center justify-between">
                                    <span>Intervalos de revalidación</span>
                                    {isModified('monitoring', 'check_intervals') && (
                                        <span className="text-xs text-amber-600">Modificado</span>
                                    )}
                                </Label>
                                <p className="text-muted-foreground text-sm">
                                    Selecciona los intervalos disponibles para la revalidación de alertas.
                                    El sistema elegirá según la severidad del evento.
                                </p>
                                <div className="flex flex-wrap gap-3">
                                    {AVAILABLE_INTERVALS.map((interval) => {
                                        const isSelected = form.data.monitoring.check_intervals.includes(interval);
                                        const isDefault = defaults.ai_config.monitoring.check_intervals.includes(interval);
                                        const isLastSelected = isSelected && form.data.monitoring.check_intervals.length === 1;
                                        
                                        return (
                                            <label
                                                key={interval}
                                                className={`flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 transition-colors ${
                                                    isSelected
                                                        ? 'border-primary bg-primary/5'
                                                        : 'border-border hover:border-primary/50'
                                                } ${isLastSelected ? 'cursor-not-allowed opacity-60' : ''}`}
                                            >
                                                <Checkbox
                                                    checked={isSelected}
                                                    onCheckedChange={() => toggleInterval(interval)}
                                                    disabled={isLastSelected}
                                                />
                                                <span className="text-sm font-medium">{interval} min</span>
                                                {isDefault && !isSelected && (
                                                    <span className="text-muted-foreground text-xs">(default)</span>
                                                )}
                                            </label>
                                        );
                                    })}
                                </div>
                                <p className="text-muted-foreground text-xs">
                                    Seleccionados: {form.data.monitoring.check_intervals.join(', ')} min.
                                    Default: {defaults.ai_config.monitoring.check_intervals.join(', ')} min.
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Notification Channels Card */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-3">
                                <div className="flex size-12 items-center justify-center rounded-full bg-emerald-500/10">
                                    <Bell className="size-6 text-emerald-600" />
                                </div>
                                <div>
                                    <CardTitle>Canales de Notificación</CardTitle>
                                    <CardDescription>
                                        Habilita o deshabilita canales de notificación para tu empresa
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <p className="text-muted-foreground text-sm">
                                La AI siempre emitirá su recomendación de qué canales usar según la severidad del evento.
                                Aquí puedes desactivar canales que tu empresa no quiere usar.
                            </p>

                            <div className="space-y-4">
                                {/* SMS */}
                                <div className="flex items-center justify-between rounded-lg border p-4">
                                    <div className="flex items-center gap-3">
                                        <MessageSquare className="text-muted-foreground size-5" />
                                        <div>
                                            <p className="font-medium">SMS</p>
                                            <p className="text-muted-foreground text-sm">
                                                Mensajes de texto a los contactos
                                            </p>
                                        </div>
                                    </div>
                                    <Switch
                                        checked={form.data.channels_enabled.sms}
                                        onCheckedChange={(checked) => setChannelEnabled('sms', checked)}
                                    />
                                </div>

                                {/* WhatsApp */}
                                <div className="flex items-center justify-between rounded-lg border p-4">
                                    <div className="flex items-center gap-3">
                                        <MessageSquare className="text-muted-foreground size-5" />
                                        <div>
                                            <p className="font-medium">WhatsApp</p>
                                            <p className="text-muted-foreground text-sm">
                                                Mensajes via WhatsApp Business
                                            </p>
                                        </div>
                                    </div>
                                    <Switch
                                        checked={form.data.channels_enabled.whatsapp}
                                        onCheckedChange={(checked) => setChannelEnabled('whatsapp', checked)}
                                    />
                                </div>

                                {/* Call */}
                                <div className="flex items-center justify-between rounded-lg border p-4">
                                    <div className="flex items-center gap-3">
                                        <Phone className="text-muted-foreground size-5" />
                                        <div>
                                            <p className="font-medium">Llamadas de voz</p>
                                            <p className="text-muted-foreground text-sm">
                                                Llamadas automáticas para emergencias
                                            </p>
                                        </div>
                                    </div>
                                    <Switch
                                        checked={form.data.channels_enabled.call}
                                        onCheckedChange={(checked) => setChannelEnabled('call', checked)}
                                    />
                                </div>

                                {/* Email (coming soon) */}
                                <div className="flex items-center justify-between rounded-lg border p-4 opacity-60">
                                    <div className="flex items-center gap-3">
                                        <Mail className="text-muted-foreground size-5" />
                                        <div>
                                            <p className="font-medium">
                                                Correo electrónico
                                                <span className="ml-2 rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-800 dark:bg-amber-900 dark:text-amber-200">
                                                    Próximamente
                                                </span>
                                            </p>
                                            <p className="text-muted-foreground text-sm">
                                                Notificaciones por email
                                            </p>
                                        </div>
                                    </div>
                                    <Switch
                                        checked={form.data.channels_enabled.email}
                                        onCheckedChange={(checked) => setChannelEnabled('email', checked)}
                                        disabled
                                    />
                                </div>
                            </div>

                            {/* Warning if all disabled */}
                            {!form.data.channels_enabled.sms &&
                                !form.data.channels_enabled.whatsapp &&
                                !form.data.channels_enabled.call && (
                                    <div className="flex items-center gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950">
                                        <BellOff className="size-5 text-amber-600" />
                                        <p className="text-sm text-amber-800 dark:text-amber-200">
                                            <strong>Atención:</strong> No tienes ningún canal habilitado.
                                            El sistema no podrá enviar notificaciones de alertas.
                                        </p>
                                    </div>
                                )}
                        </CardContent>
                    </Card>

                    {/* Motor de Reglas de Detección — enlace a página dedicada */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between gap-3">
                                <div className="flex items-center gap-3">
                                    <div className="flex size-12 items-center justify-center rounded-full bg-violet-500/10">
                                        <Bot className="size-6 text-violet-600" />
                                    </div>
                                    <div>
                                        <CardTitle>Motor de Reglas de Detección</CardTitle>
                                        <CardDescription>
                                            Configura qué señales de seguridad disparan alertas y el pipeline de IA
                                        </CardDescription>
                                    </div>
                                </div>
                                <Link href="/company/detection-rules">
                                    <Button variant="outline" size="sm">
                                        Configurar reglas
                                    </Button>
                                </Link>
                            </div>
                        </CardHeader>
                    </Card>

                    {/* Actions */}
                    <div className="flex items-center justify-between">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={handleReset}
                            disabled={form.processing}
                        >
                            <RefreshCw className="mr-2 size-4" />
                            Restablecer a Predeterminados
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? (
                                <Loader2 className="mr-2 size-4 animate-spin" />
                            ) : (
                                <Save className="mr-2 size-4" />
                            )}
                            {form.processing ? 'Guardando...' : 'Guardar Cambios'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
