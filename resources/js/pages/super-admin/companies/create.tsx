import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Building2,
    Eye,
    EyeOff,
    Key,
    MapPin,
    Save,
    User,
} from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Super Admin', href: '/super-admin' },
    { title: 'Empresas', href: '/super-admin/companies' },
    { title: 'Nueva Empresa', href: '/super-admin/companies/create' },
];

export default function CompanyCreate() {
    const [showApiKey, setShowApiKey] = useState(false);
    const [showPassword, setShowPassword] = useState(false);

    const form = useForm({
        name: '',
        legal_name: '',
        tax_id: '',
        email: '',
        phone: '',
        address: '',
        city: '',
        state: '',
        country: 'MX',
        postal_code: '',
        samsara_api_key: '',
        is_active: true,
        admin_name: '',
        admin_email: '',
        admin_password: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/super-admin/companies');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Nueva Empresa - Super Admin" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4 sm:gap-6 sm:p-6">
                {/* Header */}
                <div className="flex items-center gap-4">
                    <Link href="/super-admin/companies">
                        <Button variant="ghost" size="icon">
                            <ArrowLeft className="size-5" />
                        </Button>
                    </Link>
                    <div>
                        <h1 className="text-xl font-bold tracking-tight sm:text-2xl">
                            Nueva Empresa
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Crea una nueva empresa con su administrador
                        </p>
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="mx-auto w-full max-w-3xl space-y-6">
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
                        <CardContent className="space-y-6">
                            {/* Name and Legal Name */}
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="name">Nombre comercial *</Label>
                                    <Input
                                        id="name"
                                        value={form.data.name}
                                        onChange={(e) => form.setData('name', e.target.value)}
                                        className={form.errors.name ? 'border-destructive' : ''}
                                    />
                                    {form.errors.name && (
                                        <p className="text-destructive text-sm">{form.errors.name}</p>
                                    )}
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="legal_name">Razón social</Label>
                                    <Input
                                        id="legal_name"
                                        value={form.data.legal_name}
                                        onChange={(e) => form.setData('legal_name', e.target.value)}
                                    />
                                </div>
                            </div>

                            {/* Tax ID and Email */}
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="tax_id">RFC</Label>
                                    <Input
                                        id="tax_id"
                                        value={form.data.tax_id}
                                        onChange={(e) => form.setData('tax_id', e.target.value)}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="email">Correo electrónico</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        value={form.data.email}
                                        onChange={(e) => form.setData('email', e.target.value)}
                                    />
                                </div>
                            </div>

                            {/* Phone */}
                            <div className="space-y-2">
                                <Label htmlFor="phone">Teléfono</Label>
                                <Input
                                    id="phone"
                                    value={form.data.phone}
                                    onChange={(e) => form.setData('phone', e.target.value)}
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
                                    value={form.data.address}
                                    onChange={(e) => form.setData('address', e.target.value)}
                                    placeholder="Calle, número, colonia"
                                />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="space-y-2">
                                    <Label htmlFor="city">Ciudad</Label>
                                    <Input
                                        id="city"
                                        value={form.data.city}
                                        onChange={(e) => form.setData('city', e.target.value)}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="state">Estado</Label>
                                    <Input
                                        id="state"
                                        value={form.data.state}
                                        onChange={(e) => form.setData('state', e.target.value)}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="postal_code">Código Postal</Label>
                                    <Input
                                        id="postal_code"
                                        value={form.data.postal_code}
                                        onChange={(e) => form.setData('postal_code', e.target.value)}
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
                                    checked={form.data.is_active}
                                    onCheckedChange={(checked) => form.setData('is_active', checked)}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Samsara Integration */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-3">
                                <div className="flex size-12 items-center justify-center rounded-full bg-orange-500/10">
                                    <Key className="size-6 text-orange-600" />
                                </div>
                                <div>
                                    <CardTitle>Integración Samsara</CardTitle>
                                    <CardDescription>
                                        API key para conectar con Samsara (opcional)
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-2">
                                <Label htmlFor="samsara_api_key">API Key de Samsara</Label>
                                <div className="relative">
                                    <Input
                                        id="samsara_api_key"
                                        type={showApiKey ? 'text' : 'password'}
                                        value={form.data.samsara_api_key}
                                        onChange={(e) => form.setData('samsara_api_key', e.target.value)}
                                        placeholder="samsara_api_XXXXXXXXXXXXXXXXXX"
                                        className="pr-10"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowApiKey(!showApiKey)}
                                        className="text-muted-foreground hover:text-foreground absolute right-3 top-1/2 -translate-y-1/2"
                                    >
                                        {showApiKey ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                                    </button>
                                </div>
                                {form.errors.samsara_api_key && (
                                    <p className="text-destructive text-sm">{form.errors.samsara_api_key}</p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Admin User */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-3">
                                <div className="flex size-12 items-center justify-center rounded-full bg-sky-500/10">
                                    <User className="size-6 text-sky-600" />
                                </div>
                                <div>
                                    <CardTitle>Usuario Administrador</CardTitle>
                                    <CardDescription>
                                        Crea el primer administrador de esta empresa
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="admin_name">Nombre *</Label>
                                <Input
                                    id="admin_name"
                                    value={form.data.admin_name}
                                    onChange={(e) => form.setData('admin_name', e.target.value)}
                                    className={form.errors.admin_name ? 'border-destructive' : ''}
                                />
                                {form.errors.admin_name && (
                                    <p className="text-destructive text-sm">{form.errors.admin_name}</p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="admin_email">Correo electrónico *</Label>
                                <Input
                                    id="admin_email"
                                    type="email"
                                    value={form.data.admin_email}
                                    onChange={(e) => form.setData('admin_email', e.target.value)}
                                    className={form.errors.admin_email ? 'border-destructive' : ''}
                                />
                                {form.errors.admin_email && (
                                    <p className="text-destructive text-sm">{form.errors.admin_email}</p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="admin_password">Contraseña *</Label>
                                <div className="relative">
                                    <Input
                                        id="admin_password"
                                        type={showPassword ? 'text' : 'password'}
                                        value={form.data.admin_password}
                                        onChange={(e) => form.setData('admin_password', e.target.value)}
                                        className={form.errors.admin_password ? 'border-destructive pr-10' : 'pr-10'}
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowPassword(!showPassword)}
                                        className="text-muted-foreground hover:text-foreground absolute right-3 top-1/2 -translate-y-1/2"
                                    >
                                        {showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                                    </button>
                                </div>
                                {form.errors.admin_password && (
                                    <p className="text-destructive text-sm">{form.errors.admin_password}</p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Submit */}
                    <div className="flex justify-end gap-3">
                        <Link href="/super-admin/companies">
                            <Button variant="outline" type="button">
                                Cancelar
                            </Button>
                        </Link>
                        <Button type="submit" disabled={form.processing}>
                            <Save className="mr-2 size-4" />
                            Crear Empresa
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}

