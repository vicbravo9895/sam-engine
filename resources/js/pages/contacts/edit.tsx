import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
    ArrowLeft,
    Building2,
    Headphones,
    Mail,
    MessageSquare,
    Phone,
    Save,
    ShieldAlert,
    Siren,
    Truck,
    User,
    Users,
} from 'lucide-react';

interface Contact {
    id: number;
    name: string;
    role: string | null;
    type: string;
    phone: string | null;
    phone_whatsapp: string | null;
    email: string | null;
    entity_type: string | null;
    entity_id: string | null;
    is_default: boolean;
    priority: number;
    is_active: boolean;
    notes: string | null;
}

interface ContactTypes {
    [key: string]: string;
}

interface EditProps {
    contact: Contact;
    types: ContactTypes;
}

const typeIcons: Record<string, React.ElementType> = {
    operator: User,
    monitoring_team: Headphones,
    supervisor: ShieldAlert,
    emergency: Siren,
    dispatch: Building2,
};

const typeDescriptions: Record<string, string> = {
    operator: 'Conductor u operador del vehículo. Recibe notificaciones personalizadas según el vehículo asignado.',
    monitoring_team: 'Equipo central de monitoreo. Recibe todas las alertas para seguimiento y escalación.',
    supervisor: 'Supervisor de zona o turno. Recibe escalaciones y alertas críticas.',
    emergency: 'Contacto de emergencia. Solo se notifica en casos críticos confirmados.',
    dispatch: 'Centro de despacho. Recibe información para coordinación logística.',
};

export default function ContactEdit({ contact, types }: EditProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Configuración', href: '/settings' },
        { title: 'Contactos', href: '/contacts' },
        { title: contact.name, href: `/contacts/${contact.id}/edit` },
    ];

    const { data, setData, put, processing, errors } = useForm({
        name: contact.name,
        role: contact.role || '',
        type: contact.type,
        phone: contact.phone || '',
        phone_whatsapp: contact.phone_whatsapp || '',
        email: contact.email || '',
        entity_type: contact.entity_type || '',
        entity_id: contact.entity_id || '',
        is_default: contact.is_default,
        priority: contact.priority,
        is_active: contact.is_active,
        notes: contact.notes || '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/contacts/${contact.id}`);
    };

    const Icon = typeIcons[data.type] || Users;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Editar ${contact.name}`} />
            <div className="mx-auto max-w-3xl p-4">
                <header className="mb-6">
                    <Button variant="ghost" asChild className="mb-4 gap-2">
                        <Link href="/contacts">
                            <ArrowLeft className="size-4" />
                            Volver a Contactos
                        </Link>
                    </Button>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Editar Contacto
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Modifica la información de {contact.name}.
                    </p>
                </header>

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Tipo de Contacto */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Icon className="size-5" />
                                Tipo de Contacto
                            </CardTitle>
                            <CardDescription>
                                Selecciona el rol que tendrá este contacto en el sistema de notificaciones.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-4">
                                <div>
                                    <Label htmlFor="type">Tipo *</Label>
                                    <Select
                                        value={data.type}
                                        onValueChange={(value) => setData('type', value)}
                                    >
                                        <SelectTrigger className="mt-1">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(types).map(([key, label]) => {
                                                const TypeIcon = typeIcons[key] || Users;
                                                return (
                                                    <SelectItem key={key} value={key}>
                                                        <div className="flex items-center gap-2">
                                                            <TypeIcon className="size-4" />
                                                            {label}
                                                        </div>
                                                    </SelectItem>
                                                );
                                            })}
                                        </SelectContent>
                                    </Select>
                                    {errors.type && (
                                        <p className="mt-1 text-sm text-destructive">{errors.type}</p>
                                    )}
                                </div>
                                {data.type && (
                                    <p className="rounded-lg bg-muted p-3 text-sm text-muted-foreground">
                                        {typeDescriptions[data.type]}
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Información Personal */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <User className="size-5" />
                                Información del Contacto
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 md:grid-cols-2">
                                <div>
                                    <Label htmlFor="name">Nombre *</Label>
                                    <Input
                                        id="name"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="Juan Pérez"
                                        className="mt-1"
                                    />
                                    {errors.name && (
                                        <p className="mt-1 text-sm text-destructive">{errors.name}</p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="role">Rol o Cargo</Label>
                                    <Input
                                        id="role"
                                        value={data.role}
                                        onChange={(e) => setData('role', e.target.value)}
                                        placeholder="Supervisor de Turno"
                                        className="mt-1"
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Canales de Comunicación */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Phone className="size-5" />
                                Canales de Comunicación
                            </CardTitle>
                            <CardDescription>
                                Configura los números y correos donde se enviarán las notificaciones.
                                Usa formato E.164 para teléfonos (ej: +521234567890).
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 md:grid-cols-2">
                                <div>
                                    <Label htmlFor="phone" className="flex items-center gap-2">
                                        <Phone className="size-3" />
                                        Teléfono (SMS y Llamadas)
                                    </Label>
                                    <Input
                                        id="phone"
                                        value={data.phone}
                                        onChange={(e) => setData('phone', e.target.value)}
                                        placeholder="+521234567890"
                                        className="mt-1"
                                    />
                                    {errors.phone && (
                                        <p className="mt-1 text-sm text-destructive">{errors.phone}</p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="phone_whatsapp" className="flex items-center gap-2">
                                        <MessageSquare className="size-3" />
                                        WhatsApp (si es diferente)
                                    </Label>
                                    <Input
                                        id="phone_whatsapp"
                                        value={data.phone_whatsapp}
                                        onChange={(e) => setData('phone_whatsapp', e.target.value)}
                                        placeholder="+521234567890"
                                        className="mt-1"
                                    />
                                </div>
                            </div>
                            <div>
                                <Label htmlFor="email" className="flex items-center gap-2">
                                    <Mail className="size-3" />
                                    Correo Electrónico
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                    placeholder="contacto@empresa.com"
                                    className="mt-1"
                                />
                                {errors.email && (
                                    <p className="mt-1 text-sm text-destructive">{errors.email}</p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Asociación */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Truck className="size-5" />
                                Asociación (Opcional)
                            </CardTitle>
                            <CardDescription>
                                Asocia este contacto a un vehículo o conductor específico.
                                Si no se asocia, será un contacto global.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 md:grid-cols-2">
                                <div>
                                    <Label htmlFor="entity_type">Tipo de Asociación</Label>
                                    <Select
                                        value={data.entity_type || '__global__'}
                                        onValueChange={(value) => {
                                            const actualValue = value === '__global__' ? '' : value;
                                            setData('entity_type', actualValue);
                                            if (!actualValue) setData('entity_id', '');
                                        }}
                                    >
                                        <SelectTrigger className="mt-1">
                                            <SelectValue placeholder="Global (sin asociación)" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__global__">Global (sin asociación)</SelectItem>
                                            <SelectItem value="vehicle">Vehículo específico</SelectItem>
                                            <SelectItem value="driver">Conductor específico</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                {data.entity_type && (
                                    <div>
                                        <Label htmlFor="entity_id">
                                            ID de {data.entity_type === 'vehicle' ? 'Vehículo' : 'Conductor'} (Samsara)
                                        </Label>
                                        <Input
                                            id="entity_id"
                                            value={data.entity_id}
                                            onChange={(e) => setData('entity_id', e.target.value)}
                                            placeholder="ID de Samsara"
                                            className="mt-1"
                                        />
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Configuración */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Configuración</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 md:grid-cols-2">
                                <div>
                                    <Label htmlFor="priority">Prioridad (0-100)</Label>
                                    <Input
                                        id="priority"
                                        type="number"
                                        min="0"
                                        max="100"
                                        value={data.priority}
                                        onChange={(e) => setData('priority', parseInt(e.target.value) || 0)}
                                        className="mt-1"
                                    />
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Mayor prioridad = se selecciona primero cuando hay múltiples contactos del mismo tipo.
                                    </p>
                                </div>
                                <div className="space-y-4 pt-6">
                                    <div className="flex items-center space-x-2">
                                        <Checkbox
                                            id="is_active"
                                            checked={data.is_active}
                                            onCheckedChange={(checked) => setData('is_active', !!checked)}
                                        />
                                        <Label htmlFor="is_active">
                                            Contacto activo (recibe notificaciones)
                                        </Label>
                                    </div>
                                    <div className="flex items-center space-x-2">
                                        <Checkbox
                                            id="is_default"
                                            checked={data.is_default}
                                            onCheckedChange={(checked) => setData('is_default', !!checked)}
                                        />
                                        <Label htmlFor="is_default">
                                            Contacto predeterminado para su tipo
                                        </Label>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <Label htmlFor="notes">Notas</Label>
                                <textarea
                                    id="notes"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    placeholder="Notas adicionales sobre este contacto..."
                                    className="mt-1 h-24 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Actions */}
                    <div className="flex items-center justify-end gap-4">
                        <Button variant="outline" type="button" asChild>
                            <Link href="/contacts">Cancelar</Link>
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

