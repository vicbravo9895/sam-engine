import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
    ArrowLeft,
    Building2,
    Eye,
    EyeOff,
    Save,
    User,
} from 'lucide-react';
import { useState } from 'react';

interface CompanyData {
    id: number;
    name: string;
}

interface Props {
    companies: CompanyData[];
    selectedCompanyId?: string;
    roles: Record<string, string>;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Super Admin', href: '/super-admin' },
    { title: 'Usuarios', href: '/super-admin/users' },
    { title: 'Nuevo Usuario', href: '/super-admin/users/create' },
];

export default function UserCreate() {
    const { companies, selectedCompanyId, roles } = usePage<{ props: Props }>().props as unknown as Props;
    const [showPassword, setShowPassword] = useState(false);

    const form = useForm({
        company_id: selectedCompanyId || '',
        name: '',
        email: '',
        password: '',
        role: 'user',
        is_active: true,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/super-admin/users');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Nuevo Usuario - Super Admin" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4 sm:gap-6 sm:p-6">
                {/* Header */}
                <div className="flex items-center gap-4">
                    <Link href="/super-admin/users">
                        <Button variant="ghost" size="icon">
                            <ArrowLeft className="size-5" />
                        </Button>
                    </Link>
                    <div>
                        <h1 className="text-xl font-bold tracking-tight sm:text-2xl">
                            Nuevo Usuario
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Crea un nuevo usuario en cualquier empresa
                        </p>
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="mx-auto w-full max-w-2xl space-y-6">
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-3">
                                <div className="bg-primary/10 flex size-12 items-center justify-center rounded-full">
                                    <User className="text-primary size-6" />
                                </div>
                                <div>
                                    <CardTitle>Información del Usuario</CardTitle>
                                    <CardDescription>
                                        Datos del nuevo usuario
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            {/* Company */}
                            <div className="space-y-2">
                                <Label htmlFor="company_id">Empresa *</Label>
                                <Select
                                    value={form.data.company_id}
                                    onValueChange={(value) => form.setData('company_id', value)}
                                >
                                    <SelectTrigger className={form.errors.company_id ? 'border-destructive' : ''}>
                                        <SelectValue placeholder="Selecciona una empresa" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {companies.map((company) => (
                                            <SelectItem key={company.id} value={company.id.toString()}>
                                                <div className="flex items-center gap-2">
                                                    <Building2 className="size-4" />
                                                    {company.name}
                                                </div>
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.company_id && (
                                    <p className="text-destructive text-sm">{form.errors.company_id}</p>
                                )}
                            </div>

                            {/* Name */}
                            <div className="space-y-2">
                                <Label htmlFor="name">Nombre *</Label>
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

                            {/* Email */}
                            <div className="space-y-2">
                                <Label htmlFor="email">Correo electrónico *</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                    className={form.errors.email ? 'border-destructive' : ''}
                                />
                                {form.errors.email && (
                                    <p className="text-destructive text-sm">{form.errors.email}</p>
                                )}
                            </div>

                            {/* Password */}
                            <div className="space-y-2">
                                <Label htmlFor="password">Contraseña *</Label>
                                <div className="relative">
                                    <Input
                                        id="password"
                                        type={showPassword ? 'text' : 'password'}
                                        value={form.data.password}
                                        onChange={(e) => form.setData('password', e.target.value)}
                                        className={form.errors.password ? 'border-destructive pr-10' : 'pr-10'}
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowPassword(!showPassword)}
                                        className="text-muted-foreground hover:text-foreground absolute right-3 top-1/2 -translate-y-1/2"
                                    >
                                        {showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                                    </button>
                                </div>
                                {form.errors.password && (
                                    <p className="text-destructive text-sm">{form.errors.password}</p>
                                )}
                            </div>

                            {/* Role */}
                            <div className="space-y-2">
                                <Label htmlFor="role">Rol *</Label>
                                <Select
                                    value={form.data.role}
                                    onValueChange={(value) => form.setData('role', value)}
                                >
                                    <SelectTrigger className={form.errors.role ? 'border-destructive' : ''}>
                                        <SelectValue placeholder="Selecciona un rol" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(roles).map(([key, label]) => (
                                            <SelectItem key={key} value={key}>
                                                {label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.role && (
                                    <p className="text-destructive text-sm">{form.errors.role}</p>
                                )}
                            </div>

                            {/* Status */}
                            <div className="flex items-center justify-between">
                                <div className="space-y-0.5">
                                    <Label>Estado del usuario</Label>
                                    <p className="text-muted-foreground text-sm">
                                        Los usuarios inactivos no pueden iniciar sesión
                                    </p>
                                </div>
                                <Switch
                                    checked={form.data.is_active}
                                    onCheckedChange={(checked) => form.setData('is_active', checked)}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Submit */}
                    <div className="flex justify-end gap-3">
                        <Link href="/super-admin/users">
                            <Button variant="outline" type="button">
                                Cancelar
                            </Button>
                        </Link>
                        <Button type="submit" disabled={form.processing}>
                            <Save className="mr-2 size-4" />
                            Crear Usuario
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}

