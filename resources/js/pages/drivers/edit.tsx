import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowLeft,
    Car,
    MessageSquare,
    Phone,
    Save,
    User,
} from 'lucide-react';

interface Driver {
    id: number;
    samsara_id: string;
    name: string;
    phone: string | null;
    country_code: string | null;
    formatted_phone: string | null;
    formatted_whatsapp: string | null;
    username: string | null;
    license_number: string | null;
    license_state: string | null;
    driver_activation_status: string;
    is_deactivated: boolean;
    assigned_vehicle_name: string | null;
    profile_image_url: string | null;
    timezone: string | null;
    notes: string | null;
    updated_at: string | null;
}

interface CountryCodes {
    [key: string]: string;
}

interface EditProps {
    driver: Driver;
    countryCodes: CountryCodes;
}

export default function DriverEdit({ driver, countryCodes }: EditProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Configuración', href: '/settings' },
        { title: 'Conductores', href: '/drivers' },
        { title: driver.name, href: `/drivers/${driver.id}/edit` },
    ];

    const { data, setData, put, processing, errors } = useForm({
        phone: driver.phone || '',
        country_code: driver.country_code || '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/drivers/${driver.id}`);
    };

    // Extrae los últimos 10 dígitos del número (número nacional)
    const extractNationalNumber = (phone: string): string | null => {
        const digits = phone.replace(/[^0-9]/g, '');
        if (digits.length < 10) return null;
        return digits.slice(-10);
    };

    // Preview de números formateados
    const previewFormattedPhone = () => {
        if (!data.phone || !data.country_code) return null;
        const nationalNumber = extractNationalNumber(data.phone);
        if (!nationalNumber) return null;
        return `+${data.country_code}${nationalNumber}`;
    };

    const previewFormattedWhatsapp = () => {
        if (!data.phone || !data.country_code) return null;
        const nationalNumber = extractNationalNumber(data.phone);
        if (!nationalNumber) return null;
        // México necesita el "1" móvil para WhatsApp
        if (data.country_code === '52') {
            return `+${data.country_code}1${nationalNumber}`;
        }
        return `+${data.country_code}${nationalNumber}`;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Editar ${driver.name}`} />
            <div className="mx-auto max-w-3xl p-4">
                <header className="mb-6">
                    <Button variant="ghost" asChild className="mb-4 gap-2">
                        <Link href="/drivers">
                            <ArrowLeft className="size-4" />
                            Volver a Conductores
                        </Link>
                    </Button>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Editar Conductor
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Configura el número de teléfono y código de país para {driver.name}.
                    </p>
                </header>

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Información del Conductor (Solo lectura, viene de Samsara) */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <User className="size-5" />
                                Información del Conductor
                            </CardTitle>
                            <CardDescription>
                                Esta información se sincroniza automáticamente desde Samsara.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-start gap-4">
                                {driver.profile_image_url ? (
                                    <img 
                                        src={driver.profile_image_url} 
                                        alt={driver.name}
                                        className="size-20 rounded-lg object-cover"
                                    />
                                ) : (
                                    <div className="flex size-20 items-center justify-center rounded-lg bg-muted">
                                        <User className="size-8 text-muted-foreground" />
                                    </div>
                                )}
                                <div className="space-y-2">
                                    <div>
                                        <h3 className="font-medium">{driver.name}</h3>
                                        {driver.username && (
                                            <p className="text-sm text-muted-foreground">@{driver.username}</p>
                                        )}
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Badge variant={driver.is_deactivated ? 'destructive' : 'secondary'}>
                                            {driver.is_deactivated ? 'Inactivo' : 'Activo'}
                                        </Badge>
                                        {driver.license_number && (
                                            <Badge variant="outline">
                                                Licencia: {driver.license_state} {driver.license_number}
                                            </Badge>
                                        )}
                                    </div>
                                    {driver.assigned_vehicle_name && (
                                        <div className="flex items-center gap-1 text-sm text-muted-foreground">
                                            <Car className="size-3" />
                                            <span>Vehículo: {driver.assigned_vehicle_name}</span>
                                        </div>
                                    )}
                                    <p className="text-xs text-muted-foreground">
                                        ID Samsara: {driver.samsara_id}
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Configuración de Teléfono (Editable) */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Phone className="size-5" />
                                Configuración de Teléfono
                            </CardTitle>
                            <CardDescription>
                                Configura el número y código de país para notificaciones SMS, llamadas y WhatsApp.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 md:grid-cols-2">
                                <div>
                                    <Label htmlFor="phone">Número de Teléfono</Label>
                                    <Input
                                        id="phone"
                                        value={data.phone}
                                        onChange={(e) => setData('phone', e.target.value)}
                                        placeholder="8117658890"
                                        className="mt-1 font-mono"
                                    />
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Solo el número nacional, sin código de país.
                                    </p>
                                    {errors.phone && (
                                        <p className="mt-1 text-sm text-destructive">{errors.phone}</p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="country_code">Código de País</Label>
                                    <Select
                                        value={data.country_code || '__none__'}
                                        onValueChange={(value) => setData('country_code', value === '__none__' ? '' : value)}
                                    >
                                        <SelectTrigger className="mt-1">
                                            <SelectValue placeholder="Seleccionar país" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">Sin código de país</SelectItem>
                                            {Object.entries(countryCodes).map(([code, label]) => (
                                                <SelectItem key={code} value={code}>
                                                    {label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.country_code && (
                                        <p className="mt-1 text-sm text-destructive">{errors.country_code}</p>
                                    )}
                                </div>
                            </div>

                            {/* Aviso México */}
                            {data.country_code === '52' && (
                                <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950/20">
                                    <div className="flex items-start gap-2">
                                        <AlertCircle className="size-4 text-amber-500 mt-0.5" />
                                        <div className="text-sm">
                                            <p className="font-medium text-amber-700 dark:text-amber-400">
                                                Números de México
                                            </p>
                                            <p className="text-amber-600 dark:text-amber-500">
                                                Para WhatsApp, el sistema agrega automáticamente el "1" móvil después del código de país.
                                                SMS y llamadas funcionan sin este dígito adicional.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {/* Preview de números */}
                            {data.phone && (
                                <div className="rounded-lg border bg-muted/50 p-4 space-y-3">
                                    <h4 className="text-sm font-medium">Vista previa de números formateados:</h4>
                                    <div className="grid gap-3 md:grid-cols-2">
                                        <div className="space-y-1">
                                            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                                <Phone className="size-3" />
                                                <span>SMS / Llamadas:</span>
                                            </div>
                                            <code className="block rounded bg-background px-2 py-1 text-sm">
                                                {previewFormattedPhone() || 'No disponible'}
                                            </code>
                                        </div>
                                        <div className="space-y-1">
                                            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                                <MessageSquare className="size-3 text-green-500" />
                                                <span>WhatsApp:</span>
                                            </div>
                                            <code className="block rounded bg-background px-2 py-1 text-sm">
                                                {previewFormattedWhatsapp() || 'No disponible'}
                                            </code>
                                        </div>
                                    </div>
                                    {!data.country_code && (
                                        <p className="text-xs text-amber-600">
                                            Selecciona un código de país para generar números formateados correctamente.
                                        </p>
                                    )}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Actions */}
                    <div className="flex items-center justify-end gap-4">
                        <Button variant="outline" type="button" asChild>
                            <Link href="/drivers">Cancelar</Link>
                        </Button>
                        <Button type="submit" disabled={processing} className="gap-2">
                            <Save className="size-4" />
                            {processing ? 'Guardando...' : 'Guardar Cambios'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
