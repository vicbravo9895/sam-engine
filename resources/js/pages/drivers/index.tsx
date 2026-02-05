import { Badge } from '@/components/ui/badge';
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    Car,
    Check,
    Edit,
    Filter,
    MessageSquare,
    Phone,
    Search,
    User,
    Users,
    X,
    AlertCircle,
    Globe,
} from 'lucide-react';
import { useState } from 'react';

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
    updated_at: string | null;
}

interface PaginatedDrivers {
    data: Driver[];
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

interface CountryCodes {
    [key: string]: string;
}

interface IndexProps {
    drivers: PaginatedDrivers;
    filters: {
        search?: string;
        status?: string;
        has_phone?: string;
    };
    countryCodes: CountryCodes;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Configuración', href: '/settings' },
    { title: 'Conductores', href: '/drivers' },
];

export default function DriversIndex({ drivers, filters, countryCodes }: IndexProps) {
    const [searchTerm, setSearchTerm] = useState(filters.search || '');
    const [statusFilter, setStatusFilter] = useState(filters.status || '');
    const [hasPhoneFilter, setHasPhoneFilter] = useState(filters.has_phone || '');
    const [selectedDrivers, setSelectedDrivers] = useState<number[]>([]);
    const [bulkCountryCodeModal, setBulkCountryCodeModal] = useState(false);
    const [bulkCountryCode, setBulkCountryCode] = useState('52');

    const handleSearch = () => {
        router.get('/drivers', {
            search: searchTerm || undefined,
            status: statusFilter || undefined,
            has_phone: hasPhoneFilter || undefined,
        }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleReset = () => {
        setSearchTerm('');
        setStatusFilter('');
        setHasPhoneFilter('');
        router.get('/drivers', {}, { preserveState: true });
    };

    const handleSelectDriver = (driverId: number) => {
        setSelectedDrivers(prev => 
            prev.includes(driverId) 
                ? prev.filter(id => id !== driverId)
                : [...prev, driverId]
        );
    };

    const handleSelectAll = () => {
        if (selectedDrivers.length === drivers.data.length) {
            setSelectedDrivers([]);
        } else {
            setSelectedDrivers(drivers.data.map(d => d.id));
        }
    };

    const handleBulkUpdate = () => {
        router.post('/drivers/bulk-country-code', {
            driver_ids: selectedDrivers,
            country_code: bulkCountryCode,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setBulkCountryCodeModal(false);
                setSelectedDrivers([]);
            },
        });
    };

    const driversWithPhone = drivers.data.filter(d => d.phone).length;
    const driversWithCountryCode = drivers.data.filter(d => d.country_code).length;
    const activeDrivers = drivers.data.filter(d => !d.is_deactivated).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Conductores" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <header className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                    <div>
                        <p className="text-sm font-medium text-muted-foreground">
                            Configuración
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Conductores
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Configura los números de teléfono y códigos de país para notificaciones.
                        </p>
                    </div>
                    {selectedDrivers.length > 0 && (
                        <Button 
                            onClick={() => setBulkCountryCodeModal(true)} 
                            className="gap-2"
                        >
                            <Globe className="size-4" />
                            Asignar Código País ({selectedDrivers.length})
                        </Button>
                    )}
                </header>

                {/* Stats */}
                <section className="grid gap-4 md:grid-cols-4">
                    <Card className="bg-gradient-to-b from-background to-muted/30">
                        <CardHeader className="pb-2">
                            <div className="flex items-center gap-2">
                                <Users className="size-4 text-muted-foreground" />
                                <CardDescription>Total</CardDescription>
                            </div>
                            <CardTitle className="text-3xl">{drivers.total}</CardTitle>
                        </CardHeader>
                    </Card>
                    <Card className="bg-gradient-to-b from-background to-muted/30">
                        <CardHeader className="pb-2">
                            <div className="flex items-center gap-2">
                                <Check className="size-4 text-emerald-500" />
                                <CardDescription>Activos</CardDescription>
                            </div>
                            <CardTitle className="text-3xl">{activeDrivers}</CardTitle>
                        </CardHeader>
                    </Card>
                    <Card className="bg-gradient-to-b from-background to-muted/30">
                        <CardHeader className="pb-2">
                            <div className="flex items-center gap-2">
                                <Phone className="size-4 text-blue-500" />
                                <CardDescription>Con Teléfono</CardDescription>
                            </div>
                            <CardTitle className="text-3xl">{driversWithPhone}</CardTitle>
                        </CardHeader>
                    </Card>
                    <Card className="bg-gradient-to-b from-background to-muted/30">
                        <CardHeader className="pb-2">
                            <div className="flex items-center gap-2">
                                <Globe className="size-4 text-purple-500" />
                                <CardDescription>Con Código País</CardDescription>
                            </div>
                            <CardTitle className="text-3xl">{driversWithCountryCode}</CardTitle>
                        </CardHeader>
                    </Card>
                </section>

                {/* Info sobre México */}
                <Card className="border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/20">
                    <CardHeader className="flex flex-row items-start gap-3 pb-2">
                        <AlertCircle className="size-5 text-amber-500" />
                        <div className="space-y-1">
                            <CardTitle className="text-sm font-medium">Importante: Números de México</CardTitle>
                            <CardDescription className="text-xs">
                                WhatsApp en México requiere un formato especial: <code className="rounded bg-muted px-1">+521XXXXXXXXXX</code> (con el "1" después del código de país).
                                El sistema lo agrega automáticamente para WhatsApp cuando seleccionas México (+52) como código de país.
                                SMS y llamadas funcionan con <code className="rounded bg-muted px-1">+52XXXXXXXXXX</code>.
                            </CardDescription>
                        </div>
                    </CardHeader>
                </Card>

                {/* Filters */}
                <Card>
                    <CardHeader className="flex flex-row items-center gap-3 border-b pb-4">
                        <div className="rounded-full bg-primary/10 p-2 text-primary">
                            <Filter className="size-4" />
                        </div>
                        <div>
                            <CardTitle className="text-sm">Filtros</CardTitle>
                            <CardDescription className="text-xs">
                                Busca y filtra conductores
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
                                        placeholder="Nombre, teléfono, usuario, licencia..."
                                        className="pl-10"
                                    />
                                </div>
                            </div>
                            <div>
                                <Label>Estado</Label>
                                <Select 
                                    value={statusFilter || '__all__'} 
                                    onValueChange={(v) => setStatusFilter(v === '__all__' ? '' : v)}
                                >
                                    <SelectTrigger className="mt-1">
                                        <SelectValue placeholder="Todos" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all__">Todos</SelectItem>
                                        <SelectItem value="active">Activos</SelectItem>
                                        <SelectItem value="inactive">Inactivos</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Teléfono</Label>
                                <Select 
                                    value={hasPhoneFilter || '__all__'} 
                                    onValueChange={(v) => setHasPhoneFilter(v === '__all__' ? '' : v)}
                                >
                                    <SelectTrigger className="mt-1">
                                        <SelectValue placeholder="Todos" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all__">Todos</SelectItem>
                                        <SelectItem value="1">Con teléfono</SelectItem>
                                        <SelectItem value="0">Sin teléfono</SelectItem>
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

                {/* Drivers Table */}
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-12">
                                        <Checkbox
                                            checked={selectedDrivers.length === drivers.data.length && drivers.data.length > 0}
                                            onCheckedChange={handleSelectAll}
                                        />
                                    </TableHead>
                                    <TableHead>Conductor</TableHead>
                                    <TableHead>Teléfono</TableHead>
                                    <TableHead>Código País</TableHead>
                                    <TableHead>Formateado</TableHead>
                                    <TableHead>WhatsApp</TableHead>
                                    <TableHead>Vehículo</TableHead>
                                    <TableHead>Estado</TableHead>
                                    <TableHead className="w-20"></TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {drivers.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={9} className="text-center py-8 text-muted-foreground">
                                            <Users className="mx-auto mb-2 size-8 opacity-50" />
                                            <p>No hay conductores</p>
                                            <p className="text-sm">Los conductores se sincronizan desde Samsara.</p>
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    drivers.data.map((driver) => (
                                        <TableRow key={driver.id}>
                                            <TableCell>
                                                <Checkbox
                                                    checked={selectedDrivers.includes(driver.id)}
                                                    onCheckedChange={() => handleSelectDriver(driver.id)}
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-3">
                                                    {driver.profile_image_url ? (
                                                        <img 
                                                            src={driver.profile_image_url} 
                                                            alt={driver.name}
                                                            className="size-8 rounded-full object-cover"
                                                        />
                                                    ) : (
                                                        <div className="flex size-8 items-center justify-center rounded-full bg-muted">
                                                            <User className="size-4 text-muted-foreground" />
                                                        </div>
                                                    )}
                                                    <div>
                                                        <div className="font-medium">{driver.name}</div>
                                                        {driver.username && (
                                                            <div className="text-xs text-muted-foreground">@{driver.username}</div>
                                                        )}
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {driver.phone ? (
                                                    <div className="flex items-center gap-1">
                                                        <Phone className="size-3 text-muted-foreground" />
                                                        <span className="font-mono text-sm">{driver.phone}</span>
                                                    </div>
                                                ) : (
                                                    <span className="text-muted-foreground text-sm">-</span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {driver.country_code ? (
                                                    <Badge variant="secondary">+{driver.country_code}</Badge>
                                                ) : driver.phone ? (
                                                    <Badge variant="outline" className="border-amber-300 text-amber-600">
                                                        Sin código
                                                    </Badge>
                                                ) : (
                                                    <span className="text-muted-foreground text-sm">-</span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {driver.formatted_phone ? (
                                                    <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                                                        {driver.formatted_phone}
                                                    </code>
                                                ) : (
                                                    <span className="text-muted-foreground text-sm">-</span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {driver.formatted_whatsapp ? (
                                                    <div className="flex items-center gap-1">
                                                        <MessageSquare className="size-3 text-green-500" />
                                                        <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                                                            {driver.formatted_whatsapp}
                                                        </code>
                                                    </div>
                                                ) : (
                                                    <span className="text-muted-foreground text-sm">-</span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {driver.assigned_vehicle_name ? (
                                                    <div className="flex items-center gap-1">
                                                        <Car className="size-3 text-muted-foreground" />
                                                        <span className="text-sm">{driver.assigned_vehicle_name}</span>
                                                    </div>
                                                ) : (
                                                    <span className="text-muted-foreground text-sm">-</span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {driver.is_deactivated ? (
                                                    <Badge variant="outline" className="border-red-300 text-red-600">
                                                        <X className="mr-1 size-3" />
                                                        Inactivo
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="outline" className="border-emerald-300 text-emerald-600">
                                                        <Check className="mr-1 size-3" />
                                                        Activo
                                                    </Badge>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <Button variant="ghost" size="icon" asChild>
                                                    <Link href={`/drivers/${driver.id}/edit`}>
                                                        <Edit className="size-4" />
                                                    </Link>
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {drivers.last_page > 1 && (
                    <nav className="flex items-center justify-center gap-2">
                        {drivers.links.map((link, index) => (
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

            {/* Bulk Country Code Modal */}
            <Dialog open={bulkCountryCodeModal} onOpenChange={setBulkCountryCodeModal}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Asignar Código de País</DialogTitle>
                        <DialogDescription>
                            Asigna el mismo código de país a los {selectedDrivers.length} conductores seleccionados.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="py-4">
                        <Label htmlFor="bulk_country_code">Código de País</Label>
                        <Select value={bulkCountryCode} onValueChange={setBulkCountryCode}>
                            <SelectTrigger className="mt-1">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {Object.entries(countryCodes).map(([code, label]) => (
                                    <SelectItem key={code} value={code}>
                                        {label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {bulkCountryCode === '52' && (
                            <p className="mt-2 text-xs text-amber-600">
                                Para WhatsApp, el sistema agregará automáticamente el "1" móvil después del código de país.
                            </p>
                        )}
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setBulkCountryCodeModal(false)}>
                            Cancelar
                        </Button>
                        <Button onClick={handleBulkUpdate}>
                            Asignar a {selectedDrivers.length} conductores
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
