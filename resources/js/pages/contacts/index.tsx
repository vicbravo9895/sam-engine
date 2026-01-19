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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Building2,
    Check,
    Edit,
    Filter,
    Headphones,
    Mail,
    MessageSquare,
    Phone,
    Plus,
    Search,
    ShieldAlert,
    Siren,
    Trash2,
    Truck,
    Users,
    X,
} from 'lucide-react';
import { useState } from 'react';

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
    notification_preferences: Record<string, unknown> | null;
    notes: string | null;
    created_at: string;
    updated_at: string;
}

interface PaginatedContacts {
    data: Contact[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{
        url: string | null;
        label: string;
        active: boolean;
    }>;
}

interface ContactTypes {
    [key: string]: string;
}

interface IndexProps {
    contacts: PaginatedContacts;
    filters: {
        type?: string;
        search?: string;
        entity_type?: string;
    };
    types: ContactTypes;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Configuración', href: '/settings' },
    { title: 'Contactos', href: '/contacts' },
];

// NOTA: Los operadores (conductores) se configuran en /drivers, no aquí.
const typeIcons: Record<string, React.ElementType> = {
    monitoring_team: Headphones,
    supervisor: ShieldAlert,
    emergency: Siren,
    dispatch: Building2,
};

const typeColors: Record<string, string> = {
    monitoring_team: 'bg-purple-100 text-purple-800 dark:bg-purple-500/20 dark:text-purple-200',
    supervisor: 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200',
    emergency: 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-200',
    dispatch: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200',
};

export default function ContactsIndex({ contacts, filters, types }: IndexProps) {
    const [searchTerm, setSearchTerm] = useState(filters.search || '');
    const [typeFilter, setTypeFilter] = useState(filters.type || '');
    const [entityFilter, setEntityFilter] = useState(filters.entity_type || '');
    const [deleteModal, setDeleteModal] = useState<Contact | null>(null);

    const handleSearch = () => {
        router.get('/contacts', {
            search: searchTerm || undefined,
            type: typeFilter || undefined,
            entity_type: entityFilter || undefined,
        }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleReset = () => {
        setSearchTerm('');
        setTypeFilter('');
        setEntityFilter('');
        router.get('/contacts', {}, { preserveState: true });
    };

    const handleToggleActive = (contact: Contact) => {
        router.post(`/contacts/${contact.id}/toggle-active`, {}, {
            preserveScroll: true,
        });
    };

    const handleSetDefault = (contact: Contact) => {
        router.post(`/contacts/${contact.id}/set-default`, {}, {
            preserveScroll: true,
        });
    };

    const handleDelete = () => {
        if (deleteModal) {
            router.delete(`/contacts/${deleteModal.id}`, {
                preserveScroll: true,
                onSuccess: () => setDeleteModal(null),
            });
        }
    };

    const getEntityLabel = (contact: Contact) => {
        if (!contact.entity_type) return 'Global';
        if (contact.entity_type === 'vehicle') return `Vehículo: ${contact.entity_id}`;
        if (contact.entity_type === 'driver') return `Conductor: ${contact.entity_id}`;
        return contact.entity_type;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Contactos de Notificación" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <header className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                    <div>
                        <p className="text-sm font-medium text-muted-foreground">
                            Configuración • Notificaciones
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Contactos de Notificación
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Configura los contactos que recibirán notificaciones de alertas.
                        </p>
                    </div>
                    <Button asChild className="gap-2">
                        <Link href="/contacts/create">
                            <Plus className="size-4" />
                            Nuevo Contacto
                        </Link>
                    </Button>
                </header>

                {/* Stats */}
                <section className="grid gap-4 md:grid-cols-5">
                    <Card className="bg-gradient-to-b from-background to-muted/30">
                        <CardHeader className="pb-2">
                            <div className="flex items-center gap-2">
                                <Users className="size-4 text-muted-foreground" />
                                <CardDescription>Total</CardDescription>
                            </div>
                            <CardTitle className="text-3xl">{contacts.total}</CardTitle>
                        </CardHeader>
                    </Card>
                    {Object.entries(types).map(([key, label]) => {
                        const Icon = typeIcons[key] || Users;
                        const count = contacts.data.filter(c => c.type === key).length;
                        return (
                            <Card key={key} className="bg-gradient-to-b from-background to-muted/30">
                                <CardHeader className="pb-2">
                                    <div className="flex items-center gap-2">
                                        <Icon className="size-4 text-muted-foreground" />
                                        <CardDescription>{label}</CardDescription>
                                    </div>
                                    <CardTitle className="text-3xl">{count}</CardTitle>
                                </CardHeader>
                            </Card>
                        );
                    })}
                </section>

                {/* Filters */}
                <Card>
                    <CardHeader className="flex flex-row items-center gap-3 border-b pb-4">
                        <div className="rounded-full bg-primary/10 p-2 text-primary">
                            <Filter className="size-4" />
                        </div>
                        <div>
                            <CardTitle className="text-sm">Filtros</CardTitle>
                            <CardDescription className="text-xs">
                                Busca y filtra contactos por tipo o asociación
                            </CardDescription>
                        </div>
                    </CardHeader>
                    <CardContent className="pt-4">
                        <div className="grid gap-4 md:grid-cols-4">
                            <div className="md:col-span-2">
                                <Label htmlFor="search">Búsqueda</Label>
                                <div className="relative mt-1">
                                    <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input
                                        id="search"
                                        value={searchTerm}
                                        onChange={(e) => setSearchTerm(e.target.value)}
                                        onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                                        placeholder="Nombre, teléfono, email..."
                                        className="pl-10"
                                    />
                                </div>
                            </div>
                            <div>
                                <Label>Tipo de Contacto</Label>
                                <Select 
                                    value={typeFilter || '__all__'} 
                                    onValueChange={(v) => setTypeFilter(v === '__all__' ? '' : v)}
                                >
                                    <SelectTrigger className="mt-1">
                                        <SelectValue placeholder="Todos los tipos" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all__">Todos los tipos</SelectItem>
                                        {Object.entries(types).map(([key, label]) => (
                                            <SelectItem key={key} value={key}>{label}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Asociación</Label>
                                <Select 
                                    value={entityFilter || '__all__'} 
                                    onValueChange={(v) => setEntityFilter(v === '__all__' ? '' : v)}
                                >
                                    <SelectTrigger className="mt-1">
                                        <SelectValue placeholder="Todas" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all__">Todas</SelectItem>
                                        <SelectItem value="global">Global</SelectItem>
                                        <SelectItem value="vehicle">Vehículo</SelectItem>
                                        <SelectItem value="driver">Conductor</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="flex items-end gap-2 md:col-span-4">
                                <Button onClick={handleSearch}>Aplicar Filtros</Button>
                                <Button variant="outline" onClick={handleReset}>Limpiar</Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Contacts List */}
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    {contacts.data.length === 0 ? (
                        <Card className="col-span-full">
                            <CardContent className="flex flex-col items-center justify-center py-12 text-center text-muted-foreground">
                                <Users className="mb-4 size-12 opacity-50" />
                                <p className="text-lg font-semibold">No hay contactos</p>
                                <p className="text-sm">
                                    {filters.search || filters.type 
                                        ? 'No se encontraron contactos con esos criterios.' 
                                        : 'Agrega tu primer contacto para recibir notificaciones.'}
                                </p>
                                <Button asChild className="mt-4">
                                    <Link href="/contacts/create">
                                        <Plus className="mr-2 size-4" />
                                        Crear Contacto
                                    </Link>
                                </Button>
                            </CardContent>
                        </Card>
                    ) : (
                        contacts.data.map((contact) => {
                            const Icon = typeIcons[contact.type] || Users;
                            return (
                                <Card 
                                    key={contact.id} 
                                    className={`transition-all ${!contact.is_active ? 'opacity-60' : ''}`}
                                >
                                    <CardHeader className="pb-3">
                                        <div className="flex items-start justify-between">
                                            <div className="flex items-center gap-3">
                                                <div className={`rounded-full p-2 ${typeColors[contact.type] || 'bg-slate-100'}`}>
                                                    <Icon className="size-4" />
                                                </div>
                                                <div>
                                                    <CardTitle className="text-base">{contact.name}</CardTitle>
                                                    {contact.role && (
                                                        <CardDescription className="text-xs">
                                                            {contact.role}
                                                        </CardDescription>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="flex gap-1">
                                                {contact.is_default && (
                                                    <Badge variant="secondary" className="text-xs">
                                                        Predeterminado
                                                    </Badge>
                                                )}
                                                {!contact.is_active && (
                                                    <Badge variant="outline" className="text-xs">
                                                        Inactivo
                                                    </Badge>
                                                )}
                                            </div>
                                        </div>
                                    </CardHeader>
                                    <CardContent className="space-y-3">
                                        <div className="flex items-center gap-2">
                                            <Badge className={typeColors[contact.type]}>
                                                {types[contact.type] || contact.type}
                                            </Badge>
                                            <Badge variant="outline" className="text-xs">
                                                {getEntityLabel(contact)}
                                            </Badge>
                                        </div>

                                        <div className="space-y-2 text-sm">
                                            {contact.phone && (
                                                <div className="flex items-center gap-2 text-muted-foreground">
                                                    <Phone className="size-3" />
                                                    <span>{contact.phone}</span>
                                                </div>
                                            )}
                                            {contact.phone_whatsapp && contact.phone_whatsapp !== contact.phone && (
                                                <div className="flex items-center gap-2 text-muted-foreground">
                                                    <MessageSquare className="size-3" />
                                                    <span>{contact.phone_whatsapp}</span>
                                                </div>
                                            )}
                                            {contact.email && (
                                                <div className="flex items-center gap-2 text-muted-foreground">
                                                    <Mail className="size-3" />
                                                    <span className="truncate">{contact.email}</span>
                                                </div>
                                            )}
                                        </div>

                                        {contact.priority > 0 && (
                                            <div className="text-xs text-muted-foreground">
                                                Prioridad: {contact.priority}
                                            </div>
                                        )}

                                        <div className="flex items-center justify-end gap-2 border-t pt-3">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => handleToggleActive(contact)}
                                                className="text-xs"
                                            >
                                                {contact.is_active ? (
                                                    <><X className="mr-1 size-3" /> Desactivar</>
                                                ) : (
                                                    <><Check className="mr-1 size-3" /> Activar</>
                                                )}
                                            </Button>
                                            {!contact.is_default && contact.is_active && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => handleSetDefault(contact)}
                                                    className="text-xs"
                                                >
                                                    <Check className="mr-1 size-3" />
                                                    Predeterminado
                                                </Button>
                                            )}
                                            <Button variant="ghost" size="icon" asChild>
                                                <Link href={`/contacts/${contact.id}/edit`}>
                                                    <Edit className="size-4" />
                                                </Link>
                                            </Button>
                                            <Button 
                                                variant="ghost" 
                                                size="icon"
                                                onClick={() => setDeleteModal(contact)}
                                                className="text-destructive hover:text-destructive"
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Card>
                            );
                        })
                    )}
                </div>

                {/* Pagination */}
                {contacts.last_page > 1 && (
                    <nav className="flex items-center justify-center gap-2">
                        {contacts.links.map((link, index) => (
                            <Button
                                key={index}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url)}
                            >
                                <span dangerouslySetInnerHTML={{ __html: link.label }} />
                            </Button>
                        ))}
                    </nav>
                )}
            </div>

            {/* Delete Confirmation Modal */}
            <Dialog open={deleteModal !== null} onOpenChange={() => setDeleteModal(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>¿Eliminar contacto?</DialogTitle>
                        <DialogDescription>
                            ¿Estás seguro de que deseas eliminar a <strong>{deleteModal?.name}</strong>?
                            Esta acción no se puede deshacer.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeleteModal(null)}>
                            Cancelar
                        </Button>
                        <Button variant="destructive" onClick={handleDelete}>
                            Eliminar
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

