import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { Badge } from '@/components/ui/badge';
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
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    Building2,
    Check,
    Eye,
    EyeOff,
    Key,
    MapPin,
    Save,
    Shield,
    Trash2,
    Truck,
    User,
    Users,
} from 'lucide-react';
import { useState } from 'react';

interface UserData {
    id: number;
    name: string;
    email: string;
    role: string;
    is_active: boolean;
    created_at: string;
}

interface CompanyData {
    id: number;
    name: string;
    slug: string;
    legal_name: string | null;
    tax_id: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    city: string | null;
    state: string | null;
    country: string | null;
    postal_code: string | null;
    logo_url: string | null;
    is_active: boolean;
    has_samsara_key: boolean;
    users_count: number;
    vehicles_count: number;
    created_at: string;
}

interface Props {
    company: CompanyData;
    users: UserData[];
}

const roleColors: Record<string, string> = {
    admin: 'bg-rose-500/10 text-rose-600 border-rose-200',
    manager: 'bg-amber-500/10 text-amber-600 border-amber-200',
    user: 'bg-sky-500/10 text-sky-600 border-sky-200',
};

const roleLabels: Record<string, string> = {
    admin: 'Admin',
    manager: 'Manager',
    user: 'Usuario',
};

export default function CompanyEdit() {
    const { company, users } = usePage<{ props: Props }>().props as unknown as Props;
    const [showApiKey, setShowApiKey] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Super Admin', href: '/super-admin' },
        { title: 'Empresas', href: '/super-admin/companies' },
        { title: company.name, href: `/super-admin/companies/${company.id}/edit` },
    ];

    // Form for company info
    const companyForm = useForm({
        name: company.name,
        legal_name: company.legal_name || '',
        tax_id: company.tax_id || '',
        email: company.email || '',
        phone: company.phone || '',
        address: company.address || '',
        city: company.city || '',
        state: company.state || '',
        country: company.country || 'MX',
        postal_code: company.postal_code || '',
        is_active: company.is_active,
    });

    // Form for Samsara API key
    const samsaraForm = useForm({
        samsara_api_key: '',
    });

    const handleCompanySubmit = (e: React.FormEvent) => {
        e.preventDefault();
        companyForm.put(`/super-admin/companies/${company.id}`);
    };

    const handleSamsaraSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        samsaraForm.put(`/super-admin/companies/${company.id}/samsara-key`, {
            onSuccess: () => {
                samsaraForm.reset();
                setShowApiKey(false);
            },
        });
    };

    const handleRemoveSamsaraKey = () => {
        if (confirm('¿Estás seguro de eliminar la API key de Samsara?')) {
            samsaraForm.delete(`/super-admin/companies/${company.id}/samsara-key`);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${company.name} - Super Admin`} />

            <div className="flex h-full flex-1 flex-col gap-4 p-4 sm:gap-6 sm:p-6">
                {/* Header */}
                <div className="flex items-center gap-4">
                    <Link href="/super-admin/companies">
                        <Button variant="ghost" size="icon">
                            <ArrowLeft className="size-5" />
                        </Button>
                    </Link>
                    <div className="flex-1">
                        <div className="flex items-center gap-3">
                            <h1 className="text-xl font-bold tracking-tight sm:text-2xl">
                                {company.name}
                            </h1>
                            <Badge variant={company.is_active ? 'default' : 'secondary'}>
                                {company.is_active ? 'Activa' : 'Inactiva'}
                            </Badge>
                        </div>
                        <div className="text-muted-foreground mt-1 flex items-center gap-4 text-sm">
                            <span className="flex items-center gap-1">
                                <Users className="size-4" />
                                {company.users_count} usuarios
                            </span>
                            <span className="flex items-center gap-1">
                                <Truck className="size-4" />
                                {company.vehicles_count} vehículos
                            </span>
                        </div>
                    </div>
                </div>

                <div className="mx-auto w-full max-w-4xl space-y-6">
                    {/* Company Info Card */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-3">
                                <div className="bg-primary/10 flex size-12 items-center justify-center rounded-full">
                                    <Building2 className="text-primary size-6" />
                                </div>
                                <div>
                                    <CardTitle>Información de la Empresa</CardTitle>
                                    <CardDescription>
                                        Datos básicos de la empresa
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleCompanySubmit} className="space-y-6">
                                {/* Name and Legal Name */}
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="name">Nombre comercial *</Label>
                                        <Input
                                            id="name"
                                            value={companyForm.data.name}
                                            onChange={(e) => companyForm.setData('name', e.target.value)}
                                            className={companyForm.errors.name ? 'border-destructive' : ''}
                                        />
                                        {companyForm.errors.name && (
                                            <p className="text-destructive text-sm">{companyForm.errors.name}</p>
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="legal_name">Razón social</Label>
                                        <Input
                                            id="legal_name"
                                            value={companyForm.data.legal_name}
                                            onChange={(e) => companyForm.setData('legal_name', e.target.value)}
                                        />
                                    </div>
                                </div>

                                {/* Tax ID and Email */}
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="tax_id">RFC</Label>
                                        <Input
                                            id="tax_id"
                                            value={companyForm.data.tax_id}
                                            onChange={(e) => companyForm.setData('tax_id', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="email">Correo electrónico</Label>
                                        <Input
                                            id="email"
                                            type="email"
                                            value={companyForm.data.email}
                                            onChange={(e) => companyForm.setData('email', e.target.value)}
                                        />
                                    </div>
                                </div>

                                {/* Phone */}
                                <div className="space-y-2">
                                    <Label htmlFor="phone">Teléfono</Label>
                                    <Input
                                        id="phone"
                                        value={companyForm.data.phone}
                                        onChange={(e) => companyForm.setData('phone', e.target.value)}
                                    />
                                </div>

                                <Separator />

                                {/* Address Section */}
                                <div className="flex items-center gap-2">
                                    <MapPin className="text-muted-foreground size-4" />
                                    <h3 className="font-medium">Dirección</h3>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="address">Dirección</Label>
                                    <Input
                                        id="address"
                                        value={companyForm.data.address}
                                        onChange={(e) => companyForm.setData('address', e.target.value)}
                                        placeholder="Calle, número, colonia"
                                    />
                                </div>

                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="space-y-2">
                                        <Label htmlFor="city">Ciudad</Label>
                                        <Input
                                            id="city"
                                            value={companyForm.data.city}
                                            onChange={(e) => companyForm.setData('city', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="state">Estado</Label>
                                        <Input
                                            id="state"
                                            value={companyForm.data.state}
                                            onChange={(e) => companyForm.setData('state', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="postal_code">Código Postal</Label>
                                        <Input
                                            id="postal_code"
                                            value={companyForm.data.postal_code}
                                            onChange={(e) => companyForm.setData('postal_code', e.target.value)}
                                        />
                                    </div>
                                </div>

                                <Separator />

                                {/* Status */}
                                <div className="flex items-center justify-between">
                                    <div className="space-y-0.5">
                                        <Label>Estado de la empresa</Label>
                                        <p className="text-muted-foreground text-sm">
                                            Las empresas inactivas no pueden iniciar sesión
                                        </p>
                                    </div>
                                    <Switch
                                        checked={companyForm.data.is_active}
                                        onCheckedChange={(checked) => companyForm.setData('is_active', checked)}
                                    />
                                </div>

                                {/* Submit */}
                                <div className="flex justify-end pt-4">
                                    <Button type="submit" disabled={companyForm.processing}>
                                        <Save className="mr-2 size-4" />
                                        Guardar Cambios
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>

                    {/* Samsara Integration Card */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-3">
                                <div className="flex size-12 items-center justify-center rounded-full bg-orange-500/10">
                                    <Key className="size-6 text-orange-600" />
                                </div>
                                <div>
                                    <CardTitle>Integración Samsara</CardTitle>
                                    <CardDescription>
                                        Conecta con Samsara para acceder a los datos de la flota
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {/* Status */}
                            <div className="flex items-center justify-between rounded-lg border p-4">
                                <div className="flex items-center gap-3">
                                    {company.has_samsara_key ? (
                                        <>
                                            <div className="flex size-10 items-center justify-center rounded-full bg-emerald-500/10">
                                                <Check className="size-5 text-emerald-600" />
                                            </div>
                                            <div>
                                                <p className="font-medium text-emerald-600">Conectado</p>
                                                <p className="text-muted-foreground text-sm">
                                                    La integración con Samsara está activa
                                                </p>
                                            </div>
                                        </>
                                    ) : (
                                        <>
                                            <div className="flex size-10 items-center justify-center rounded-full bg-amber-500/10">
                                                <AlertTriangle className="size-5 text-amber-600" />
                                            </div>
                                            <div>
                                                <p className="font-medium text-amber-600">No configurado</p>
                                                <p className="text-muted-foreground text-sm">
                                                    Agrega una API key para conectar con Samsara
                                                </p>
                                            </div>
                                        </>
                                    )}
                                </div>
                                {company.has_samsara_key && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={handleRemoveSamsaraKey}
                                        className="text-destructive hover:text-destructive"
                                    >
                                        <Trash2 className="mr-2 size-4" />
                                        Eliminar
                                    </Button>
                                )}
                            </div>

                            {/* API Key Form */}
                            <form onSubmit={handleSamsaraSubmit} className="space-y-4">
                                <div className="space-y-2">
                                    <Label htmlFor="samsara_api_key">
                                        {company.has_samsara_key ? 'Nueva API Key' : 'API Key de Samsara'}
                                    </Label>
                                    <div className="relative">
                                        <Input
                                            id="samsara_api_key"
                                            type={showApiKey ? 'text' : 'password'}
                                            value={samsaraForm.data.samsara_api_key}
                                            onChange={(e) => samsaraForm.setData('samsara_api_key', e.target.value)}
                                            placeholder="samsara_api_XXXXXXXXXXXXXXXXXX"
                                            className={samsaraForm.errors.samsara_api_key ? 'border-destructive pr-10' : 'pr-10'}
                                        />
                                        <button
                                            type="button"
                                            onClick={() => setShowApiKey(!showApiKey)}
                                            className="text-muted-foreground hover:text-foreground absolute right-3 top-1/2 -translate-y-1/2"
                                        >
                                            {showApiKey ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                                        </button>
                                    </div>
                                    {samsaraForm.errors.samsara_api_key && (
                                        <p className="text-destructive text-sm">{samsaraForm.errors.samsara_api_key}</p>
                                    )}
                                </div>

                                <div className="flex justify-end">
                                    <Button
                                        type="submit"
                                        disabled={samsaraForm.processing || !samsaraForm.data.samsara_api_key}
                                    >
                                        <Shield className="mr-2 size-4" />
                                        {company.has_samsara_key ? 'Actualizar API Key' : 'Guardar API Key'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>

                    {/* Users Card */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <div className="flex items-center gap-3">
                                <div className="flex size-12 items-center justify-center rounded-full bg-sky-500/10">
                                    <Users className="size-6 text-sky-600" />
                                </div>
                                <div>
                                    <CardTitle>Usuarios de la Empresa</CardTitle>
                                    <CardDescription>
                                        {users.length} usuario{users.length !== 1 ? 's' : ''} registrado{users.length !== 1 ? 's' : ''}
                                    </CardDescription>
                                </div>
                            </div>
                            <Link href={`/super-admin/users/create?company_id=${company.id}`}>
                                <Button variant="outline" size="sm">
                                    Agregar Usuario
                                </Button>
                            </Link>
                        </CardHeader>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Usuario</TableHead>
                                        <TableHead>Email</TableHead>
                                        <TableHead>Rol</TableHead>
                                        <TableHead>Estado</TableHead>
                                        <TableHead className="text-right">Acciones</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {users.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={5} className="py-8 text-center">
                                                <User className="text-muted-foreground mx-auto mb-2 size-6" />
                                                <p className="text-muted-foreground text-sm">
                                                    No hay usuarios en esta empresa
                                                </p>
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        users.map((user) => (
                                            <TableRow key={user.id}>
                                                <TableCell className="font-medium">{user.name}</TableCell>
                                                <TableCell className="text-muted-foreground">{user.email}</TableCell>
                                                <TableCell>
                                                    <Badge variant="outline" className={roleColors[user.role]}>
                                                        {roleLabels[user.role] || user.role}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant={user.is_active ? 'default' : 'secondary'}>
                                                        {user.is_active ? 'Activo' : 'Inactivo'}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Link href={`/super-admin/users/${user.id}/edit`}>
                                                        <Button variant="ghost" size="sm">
                                                            Editar
                                                        </Button>
                                                    </Link>
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}

