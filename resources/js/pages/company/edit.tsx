import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    Check,
    Eye,
    EyeOff,
    Key,
    MapPin,
    Save,
    Shield,
    Trash2,
    Upload,
} from 'lucide-react';
import { useState } from 'react';

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
    created_at: string;
}

interface Props {
    company: CompanyData;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Empresa', href: '/company' },
];

export default function CompanyEdit() {
    const { company } = usePage().props as Props;
    const [showApiKey, setShowApiKey] = useState(false);

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
    });

    // Form for Samsara API key
    const samsaraForm = useForm({
        samsara_api_key: '',
    });

    const handleCompanySubmit = (e: React.FormEvent) => {
        e.preventDefault();
        // Use put directly - Inertia handles the method spoofing
        companyForm.put('/company');
    };

    const handleSamsaraSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        samsaraForm.put('/company/samsara-key', {
            onSuccess: () => {
                samsaraForm.reset();
                setShowApiKey(false);
            },
        });
    };

    const handleRemoveSamsaraKey = () => {
        if (confirm('¿Estás seguro de eliminar la API key de Samsara? Esto desconectará la integración.')) {
            samsaraForm.delete('/company/samsara-key');
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Configuración de Empresa" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4 sm:gap-6 sm:p-6">
                {/* Header */}
                <div>
                    <h1 className="text-xl font-bold tracking-tight sm:text-2xl">
                        Configuración de Empresa
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Administra la información y configuración de tu empresa
                    </p>
                </div>

                <div className="mx-auto w-full max-w-3xl space-y-6">
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
                                        Datos básicos de tu empresa
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
                                            onChange={(e) =>
                                                companyForm.setData('name', e.target.value)
                                            }
                                            className={
                                                companyForm.errors.name ? 'border-destructive' : ''
                                            }
                                        />
                                        {companyForm.errors.name && (
                                            <p className="text-destructive text-sm">
                                                {companyForm.errors.name}
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="legal_name">Razón social</Label>
                                        <Input
                                            id="legal_name"
                                            value={companyForm.data.legal_name}
                                            onChange={(e) =>
                                                companyForm.setData('legal_name', e.target.value)
                                            }
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
                                            onChange={(e) =>
                                                companyForm.setData('tax_id', e.target.value)
                                            }
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="email">Correo electrónico</Label>
                                        <Input
                                            id="email"
                                            type="email"
                                            value={companyForm.data.email}
                                            onChange={(e) =>
                                                companyForm.setData('email', e.target.value)
                                            }
                                        />
                                    </div>
                                </div>

                                {/* Phone */}
                                <div className="space-y-2">
                                    <Label htmlFor="phone">Teléfono</Label>
                                    <Input
                                        id="phone"
                                        value={companyForm.data.phone}
                                        onChange={(e) =>
                                            companyForm.setData('phone', e.target.value)
                                        }
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
                                        onChange={(e) =>
                                            companyForm.setData('address', e.target.value)
                                        }
                                        placeholder="Calle, número, colonia"
                                    />
                                </div>

                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="space-y-2">
                                        <Label htmlFor="city">Ciudad</Label>
                                        <Input
                                            id="city"
                                            value={companyForm.data.city}
                                            onChange={(e) =>
                                                companyForm.setData('city', e.target.value)
                                            }
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="state">Estado</Label>
                                        <Input
                                            id="state"
                                            value={companyForm.data.state}
                                            onChange={(e) =>
                                                companyForm.setData('state', e.target.value)
                                            }
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="postal_code">Código Postal</Label>
                                        <Input
                                            id="postal_code"
                                            value={companyForm.data.postal_code}
                                            onChange={(e) =>
                                                companyForm.setData('postal_code', e.target.value)
                                            }
                                        />
                                    </div>
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
                                        Conecta tu cuenta de Samsara para acceder a los datos de tu
                                        flota
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
                                                <p className="font-medium text-emerald-600">
                                                    Conectado
                                                </p>
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
                                                <p className="font-medium text-amber-600">
                                                    No configurado
                                                </p>
                                                <p className="text-muted-foreground text-sm">
                                                    Agrega tu API key para conectar con Samsara
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
                                            onChange={(e) =>
                                                samsaraForm.setData('samsara_api_key', e.target.value)
                                            }
                                            placeholder="samsara_api_XXXXXXXXXXXXXXXXXX"
                                            className={
                                                samsaraForm.errors.samsara_api_key
                                                    ? 'border-destructive pr-10'
                                                    : 'pr-10'
                                            }
                                        />
                                        <button
                                            type="button"
                                            onClick={() => setShowApiKey(!showApiKey)}
                                            className="text-muted-foreground hover:text-foreground absolute right-3 top-1/2 -translate-y-1/2"
                                        >
                                            {showApiKey ? (
                                                <EyeOff className="size-4" />
                                            ) : (
                                                <Eye className="size-4" />
                                            )}
                                        </button>
                                    </div>
                                    {samsaraForm.errors.samsara_api_key && (
                                        <p className="text-destructive text-sm">
                                            {samsaraForm.errors.samsara_api_key}
                                        </p>
                                    )}
                                    <p className="text-muted-foreground text-sm">
                                        Puedes obtener tu API key desde el{' '}
                                        <a
                                            href="https://cloud.samsara.com/o/settings/api"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="text-primary hover:underline"
                                        >
                                            panel de Samsara
                                        </a>
                                    </p>
                                </div>

                                <div className="flex justify-end">
                                    <Button
                                        type="submit"
                                        disabled={
                                            samsaraForm.processing || !samsaraForm.data.samsara_api_key
                                        }
                                    >
                                        <Shield className="mr-2 size-4" />
                                        {company.has_samsara_key ? 'Actualizar API Key' : 'Guardar API Key'}
                                    </Button>
                                </div>
                            </form>

                            {/* Security Note */}
                            <div className="bg-muted/50 rounded-lg p-4">
                                <div className="flex items-start gap-3">
                                    <Shield className="text-muted-foreground mt-0.5 size-5" />
                                    <div>
                                        <p className="text-sm font-medium">Seguridad</p>
                                        <p className="text-muted-foreground text-sm">
                                            Tu API key se almacena de forma encriptada y nunca se
                                            muestra después de guardarla. Solo tú tienes acceso a los
                                            datos de tu flota.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}

