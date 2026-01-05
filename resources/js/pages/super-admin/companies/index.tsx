import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Building2,
    ChevronLeft,
    ChevronRight,
    Edit,
    Key,
    Plus,
    Search,
    Trash2,
    Users,
    Truck,
} from 'lucide-react';
import { useState } from 'react';

interface CompanyData {
    id: number;
    name: string;
    email: string | null;
    is_active: boolean;
    created_at: string;
    users_count: number;
    vehicles_count: number;
}

interface PaginatedCompanies {
    data: CompanyData[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

interface Props {
    companies: PaginatedCompanies;
    filters: {
        search?: string;
        is_active?: string;
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Super Admin', href: '/super-admin' },
    { title: 'Empresas', href: '/super-admin/companies' },
];

export default function CompaniesIndex() {
    const { companies, filters } = usePage<{ props: Props }>().props as unknown as Props;
    const [search, setSearch] = useState(filters.search || '');

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/super-admin/companies', { ...filters, search }, { preserveState: true });
    };

    const handleFilterChange = (key: string, value: string) => {
        const filterValue = value === 'all' ? undefined : value;
        router.get('/super-admin/companies', { ...filters, [key]: filterValue }, { preserveState: true });
    };

    const clearFilters = () => {
        setSearch('');
        router.get('/super-admin/companies', {}, { preserveState: true });
    };

    const handleDelete = (company: CompanyData) => {
        if (confirm(`¿Estás seguro de eliminar la empresa "${company.name}"? Esta acción no se puede deshacer.`)) {
            router.delete(`/super-admin/companies/${company.id}`);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Empresas - Super Admin" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4 sm:gap-6 sm:p-6">
                {/* Header */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-xl font-bold tracking-tight sm:text-2xl">Empresas</h1>
                        <p className="text-muted-foreground text-sm">
                            Gestiona todas las empresas del sistema
                        </p>
                    </div>
                    <Link href="/super-admin/companies/create">
                        <Button className="w-full sm:w-auto">
                            <Plus className="mr-2 size-4" />
                            Nueva Empresa
                        </Button>
                    </Link>
                </div>

                {/* Filters */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">Filtros</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-col gap-4 sm:flex-row">
                            <form onSubmit={handleSearch} className="flex flex-1 gap-2">
                                <div className="relative flex-1">
                                    <Search className="text-muted-foreground absolute left-3 top-1/2 size-4 -translate-y-1/2" />
                                    <Input
                                        placeholder="Buscar por nombre o email..."
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        className="pl-10"
                                    />
                                </div>
                                <Button type="submit" variant="secondary">
                                    Buscar
                                </Button>
                            </form>
                            <Select
                                value={filters.is_active ?? 'all'}
                                onValueChange={(value) => handleFilterChange('is_active', value)}
                            >
                                <SelectTrigger className="w-full sm:w-[150px]">
                                    <SelectValue placeholder="Cualquier estado" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Cualquier estado</SelectItem>
                                    <SelectItem value="1">Activas</SelectItem>
                                    <SelectItem value="0">Inactivas</SelectItem>
                                </SelectContent>
                            </Select>
                            {(filters.search || filters.is_active) && (
                                <Button variant="ghost" onClick={clearFilters}>
                                    Limpiar
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Companies Table */}
                <Card>
                    <CardHeader>
                        <CardDescription>
                            {companies.total} empresa{companies.total !== 1 ? 's' : ''} encontrada{companies.total !== 1 ? 's' : ''}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Empresa</TableHead>
                                        <TableHead className="hidden sm:table-cell">Email</TableHead>
                                        <TableHead>
                                            <div className="flex items-center gap-1">
                                                <Users className="size-4" />
                                                <span className="hidden sm:inline">Usuarios</span>
                                            </div>
                                        </TableHead>
                                        <TableHead>
                                            <div className="flex items-center gap-1">
                                                <Truck className="size-4" />
                                                <span className="hidden sm:inline">Vehículos</span>
                                            </div>
                                        </TableHead>
                                        <TableHead>Estado</TableHead>
                                        <TableHead className="text-right">Acciones</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {companies.data.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={6} className="py-12 text-center">
                                                <Building2 className="text-muted-foreground mx-auto mb-3 size-8" />
                                                <p className="text-muted-foreground">
                                                    No se encontraron empresas
                                                </p>
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        companies.data.map((company) => (
                                            <TableRow key={company.id}>
                                                <TableCell>
                                                    <div className="flex items-center gap-3">
                                                        <div className="bg-primary/10 flex size-10 items-center justify-center rounded-full">
                                                            <Building2 className="text-primary size-5" />
                                                        </div>
                                                        <span className="font-medium">{company.name}</span>
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-muted-foreground hidden sm:table-cell">
                                                    {company.email || '-'}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="outline" className="gap-1">
                                                        <Users className="size-3" />
                                                        {company.users_count}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="outline" className="gap-1">
                                                        <Truck className="size-3" />
                                                        {company.vehicles_count}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant={company.is_active ? 'default' : 'secondary'}>
                                                        {company.is_active ? 'Activa' : 'Inactiva'}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex justify-end gap-2">
                                                        <Link href={`/super-admin/companies/${company.id}/edit`}>
                                                            <Button variant="ghost" size="sm">
                                                                <Edit className="size-4" />
                                                            </Button>
                                                        </Link>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() => handleDelete(company)}
                                                            className="text-destructive hover:text-destructive"
                                                        >
                                                            <Trash2 className="size-4" />
                                                        </Button>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>

                        {/* Pagination */}
                        {companies.last_page > 1 && (
                            <div className="flex items-center justify-between border-t px-4 py-3">
                                <p className="text-muted-foreground text-sm">
                                    Página {companies.current_page} de {companies.last_page}
                                </p>
                                <div className="flex gap-1">
                                    {companies.links.map((link, index) => {
                                        if (index === 0) {
                                            return (
                                                <Button
                                                    key="prev"
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={!link.url}
                                                    onClick={() => link.url && router.get(link.url)}
                                                >
                                                    <ChevronLeft className="size-4" />
                                                </Button>
                                            );
                                        }
                                        if (index === companies.links.length - 1) {
                                            return (
                                                <Button
                                                    key="next"
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={!link.url}
                                                    onClick={() => link.url && router.get(link.url)}
                                                >
                                                    <ChevronRight className="size-4" />
                                                </Button>
                                            );
                                        }
                                        return null;
                                    })}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

