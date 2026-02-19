import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Building2,
    Calendar,
    CheckCircle2,
    Clock,
    Globe,
    Mail,
    MapPin,
    MessageSquare,
    Phone,
    Save,
    Trash2,
    Truck,
    User,
} from 'lucide-react';

interface DealData {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
    company_name: string;
    position: string | null;
    fleet_size: string;
    country: string;
    challenges: string | null;
    status: string;
    internal_notes: string | null;
    source: string;
    contacted_at: string | null;
    qualified_at: string | null;
    closed_at: string | null;
    created_at: string;
    updated_at: string;
}

interface Props {
    deal: DealData;
    statuses: Record<string, string>;
}

const statusColors: Record<string, string> = {
    new: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    contacted: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
    qualified: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
    proposal: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300',
    won: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    lost: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
};

function formatDateTime(dateStr: string | null) {
    if (!dateStr) return null;
    return new Date(dateStr).toLocaleDateString('es-MX', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function DealShow() {
    const { deal, statuses } = usePage<{ props: Props }>().props as unknown as Props;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Super Admin', href: '/super-admin' },
        { title: 'Deals', href: '/super-admin/deals' },
        { title: `${deal.first_name} ${deal.last_name}`, href: `/super-admin/deals/${deal.id}` },
    ];

    const statusForm = useForm({ status: deal.status });
    const notesForm = useForm({ internal_notes: deal.internal_notes || '' });

    const handleStatusChange = (value: string) => {
        statusForm.setData('status', value);
        router.patch(`/super-admin/deals/${deal.id}/status`, { status: value }, {
            preserveScroll: true,
        });
    };

    const handleNotesSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        notesForm.patch(`/super-admin/deals/${deal.id}/notes`, {
            preserveScroll: true,
        });
    };

    const handleDelete = () => {
        if (confirm(`¿Estás seguro de eliminar este deal de "${deal.first_name} ${deal.last_name}"?`)) {
            router.delete(`/super-admin/deals/${deal.id}`);
        }
    };

    const timelineEvents = [
        { label: 'Solicitud recibida', date: deal.created_at, icon: Clock },
        { label: 'Primer contacto', date: deal.contacted_at, icon: Phone },
        { label: 'Calificado', date: deal.qualified_at, icon: CheckCircle2 },
        { label: 'Cerrado', date: deal.closed_at, icon: CheckCircle2 },
    ].filter(e => e.date);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${deal.first_name} ${deal.last_name} - Deals`} />

            <div className="flex h-full flex-1 flex-col gap-4 p-4 sm:gap-6 sm:p-6">
                {/* Header */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-3">
                        <Link href="/super-admin/deals">
                            <Button variant="ghost" size="sm">
                                <ArrowLeft className="mr-1 size-4" />
                                Volver
                            </Button>
                        </Link>
                        <div>
                            <h1 className="text-xl font-bold tracking-tight sm:text-2xl">
                                {deal.first_name} {deal.last_name}
                            </h1>
                            <p className="text-muted-foreground text-sm">{deal.company_name}</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <span className={`inline-flex items-center rounded-full px-3 py-1 text-sm font-medium ${statusColors[deal.status] || ''}`}>
                            {statuses[deal.status] || deal.status}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={handleDelete}
                            className="text-destructive hover:text-destructive"
                        >
                            <Trash2 className="mr-1 size-4" />
                            Eliminar
                        </Button>
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-3 sm:gap-6">
                    {/* Main info */}
                    <div className="flex flex-col gap-4 lg:col-span-2 sm:gap-6">
                        {/* Contact info */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Información de Contacto</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="flex items-start gap-3">
                                        <User className="text-muted-foreground mt-0.5 size-4" />
                                        <div>
                                            <p className="text-muted-foreground text-xs">Nombre completo</p>
                                            <p className="text-sm font-medium">{deal.first_name} {deal.last_name}</p>
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-3">
                                        <Mail className="text-muted-foreground mt-0.5 size-4" />
                                        <div>
                                            <p className="text-muted-foreground text-xs">Email</p>
                                            <a href={`mailto:${deal.email}`} className="text-sm font-medium text-blue-600 hover:underline dark:text-blue-400">
                                                {deal.email}
                                            </a>
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-3">
                                        <Phone className="text-muted-foreground mt-0.5 size-4" />
                                        <div>
                                            <p className="text-muted-foreground text-xs">Teléfono</p>
                                            <a href={`tel:${deal.phone}`} className="text-sm font-medium text-blue-600 hover:underline dark:text-blue-400">
                                                {deal.phone}
                                            </a>
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-3">
                                        <Building2 className="text-muted-foreground mt-0.5 size-4" />
                                        <div>
                                            <p className="text-muted-foreground text-xs">Empresa</p>
                                            <p className="text-sm font-medium">{deal.company_name}</p>
                                        </div>
                                    </div>
                                    {deal.position && (
                                        <div className="flex items-start gap-3">
                                            <User className="text-muted-foreground mt-0.5 size-4" />
                                            <div>
                                                <p className="text-muted-foreground text-xs">Cargo</p>
                                                <p className="text-sm font-medium">{deal.position}</p>
                                            </div>
                                        </div>
                                    )}
                                    <div className="flex items-start gap-3">
                                        <Truck className="text-muted-foreground mt-0.5 size-4" />
                                        <div>
                                            <p className="text-muted-foreground text-xs">Tamaño de flota</p>
                                            <p className="text-sm font-medium">{deal.fleet_size}</p>
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-3">
                                        <Globe className="text-muted-foreground mt-0.5 size-4" />
                                        <div>
                                            <p className="text-muted-foreground text-xs">País</p>
                                            <p className="text-sm font-medium">{deal.country}</p>
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-3">
                                        <MapPin className="text-muted-foreground mt-0.5 size-4" />
                                        <div>
                                            <p className="text-muted-foreground text-xs">Fuente</p>
                                            <Badge variant="outline">{deal.source}</Badge>
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Challenges */}
                        {deal.challenges && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        <div className="flex items-center gap-2">
                                            <MessageSquare className="size-4" />
                                            Desafíos Actuales
                                        </div>
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-muted-foreground whitespace-pre-wrap text-sm">{deal.challenges}</p>
                                </CardContent>
                            </Card>
                        )}

                        {/* Internal Notes */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Notas Internas</CardTitle>
                                <CardDescription>Seguimiento y notas privadas sobre este deal</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <form onSubmit={handleNotesSubmit} className="space-y-3">
                                    <Textarea
                                        value={notesForm.data.internal_notes}
                                        onChange={(e) => notesForm.setData('internal_notes', e.target.value)}
                                        placeholder="Agrega notas sobre el seguimiento, llamadas realizadas, próximos pasos..."
                                        rows={5}
                                    />
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={notesForm.processing}
                                    >
                                        <Save className="mr-1 size-4" />
                                        Guardar Notas
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Sidebar */}
                    <div className="flex flex-col gap-4 sm:gap-6">
                        {/* Status change */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Estado del Deal</CardTitle>
                                <CardDescription>Actualiza el estado de seguimiento</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-3">
                                    <Label>Estado actual</Label>
                                    <Select
                                        value={deal.status}
                                        onValueChange={handleStatusChange}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(statuses).map(([key, label]) => (
                                                <SelectItem key={key} value={key}>{label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Timeline */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-center gap-2">
                                        <Calendar className="size-4" />
                                        Timeline
                                    </div>
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-4">
                                    {timelineEvents.map((event, i) => (
                                        <div key={i} className="flex gap-3">
                                            <div className="relative flex flex-col items-center">
                                                <div className="bg-primary flex size-6 items-center justify-center rounded-full">
                                                    <event.icon className="text-primary-foreground size-3" />
                                                </div>
                                                {i < timelineEvents.length - 1 && (
                                                    <div className="bg-border absolute top-6 h-full w-px" />
                                                )}
                                            </div>
                                            <div className="pb-4">
                                                <p className="text-sm font-medium">{event.label}</p>
                                                <p className="text-muted-foreground text-xs">
                                                    {formatDateTime(event.date)}
                                                </p>
                                            </div>
                                        </div>
                                    ))}
                                    {timelineEvents.length === 0 && (
                                        <p className="text-muted-foreground text-center text-sm">
                                            Sin eventos registrados
                                        </p>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Quick actions */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Acciones Rápidas</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                <Button
                                    variant="outline"
                                    className="w-full justify-start"
                                    asChild
                                >
                                    <a href={`mailto:${deal.email}`}>
                                        <Mail className="mr-2 size-4" />
                                        Enviar Email
                                    </a>
                                </Button>
                                <Button
                                    variant="outline"
                                    className="w-full justify-start"
                                    asChild
                                >
                                    <a href={`tel:${deal.phone}`}>
                                        <Phone className="mr-2 size-4" />
                                        Llamar
                                    </a>
                                </Button>
                                <Button
                                    variant="outline"
                                    className="w-full justify-start"
                                    asChild
                                >
                                    <a
                                        href={`https://wa.me/${deal.phone.replace(/[^0-9+]/g, '')}`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <MessageSquare className="mr-2 size-4" />
                                        WhatsApp
                                    </a>
                                </Button>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
