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
    Plus,
    Search,
    Trash2,
    User,
    UserCheck,
    UserX,
} from 'lucide-react';
import { useState } from 'react';

interface CompanyData {
    id: number;
    name: string;
}

interface UserData {
    id: number;
    name: string;
    email: string;
    role: string;
    is_active: boolean;
    created_at: string;
    company?: CompanyData;
}

interface PaginatedUsers {
    data: UserData[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

interface Props {
    users: PaginatedUsers;
    companies: CompanyData[];
    filters: {
        search?: string;
        company_id?: string;
        role?: string;
        is_active?: string;
    };
    roles: Record<string, string>;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Super Admin', href: '/super-admin' },
    { title: 'Usuarios', href: '/super-admin/users' },
];

const roleColors: Record<string, string> = {
    admin: 'bg-rose-500/10 text-rose-600 border-rose-200',
    manager: 'bg-amber-500/10 text-amber-600 border-amber-200',
    user: 'bg-sky-500/10 text-sky-600 border-sky-200',
};

export default function UsersIndex() {
    const { users, companies, filters, roles } = usePage<{ props: Props }>().props as unknown as Props;
    const [search, setSearch] = useState(filters.search || '');

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/super-admin/users', { ...filters, search }, { preserveState: true });
    };

    const handleFilterChange = (key: string, value: string) => {
        const filterValue = value === 'all' ? undefined : value;
        router.get('/super-admin/users', { ...filters, [key]: filterValue }, { preserveState: true });
    };

    const clearFilters = () => {
        setSearch('');
        router.get('/super-admin/users', {}, { preserveState: true });
    };

    const handleDelete = (user: UserData) => {
        if (confirm(`¿Estás seguro de eliminar al usuario "${user.name}"?`)) {
            router.delete(`/super-admin/users/${user.id}`);
        }
    };

    const hasFilters = filters.search || filters.company_id || filters.role || filters.is_active;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Usuarios - Super Admin" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4 sm:gap-6 sm:p-6">
                {/* Header */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-xl font-bold tracking-tight sm:text-2xl">Usuarios</h1>
                        <p className="text-muted-foreground text-sm">
                            Gestiona todos los usuarios del sistema
                        </p>
                    </div>
                    <Link href="/super-admin/users/create">
                        <Button className="w-full sm:w-auto">
                            <Plus className="mr-2 size-4" />
                            Nuevo Usuario
                        </Button>
                    </Link>
                </div>

                {/* Filters */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">Filtros</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-col gap-4 lg:flex-row">
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
                                value={filters.company_id || 'all'}
                                onValueChange={(value) => handleFilterChange('company_id', value)}
                            >
                                <SelectTrigger className="w-full lg:w-[200px]">
                                    <SelectValue placeholder="Todas las empresas" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Todas las empresas</SelectItem>
                                    {companies.map((company) => (
                                        <SelectItem key={company.id} value={company.id.toString()}>
                                            {company.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.role || 'all'}
                                onValueChange={(value) => handleFilterChange('role', value)}
                            >
                                <SelectTrigger className="w-full lg:w-[150px]">
                                    <SelectValue placeholder="Todos los roles" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Todos los roles</SelectItem>
                                    {Object.entries(roles).map(([key, label]) => (
                                        <SelectItem key={key} value={key}>
                                            {label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.is_active ?? 'all'}
                                onValueChange={(value) => handleFilterChange('is_active', value)}
                            >
                                <SelectTrigger className="w-full lg:w-[130px]">
                                    <SelectValue placeholder="Estado" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Todos</SelectItem>
                                    <SelectItem value="1">Activos</SelectItem>
                                    <SelectItem value="0">Inactivos</SelectItem>
                                </SelectContent>
                            </Select>
                            {hasFilters && (
                                <Button variant="ghost" onClick={clearFilters}>
                                    Limpiar
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Users Table */}
                <Card>
                    <CardHeader>
                        <CardDescription>
                            {users.total} usuario{users.total !== 1 ? 's' : ''} encontrado{users.total !== 1 ? 's' : ''}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Usuario</TableHead>
                                        <TableHead className="hidden md:table-cell">Email</TableHead>
                                        <TableHead>Empresa</TableHead>
                                        <TableHead>Rol</TableHead>
                                        <TableHead>Estado</TableHead>
                                        <TableHead className="text-right">Acciones</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {users.data.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={6} className="py-12 text-center">
                                                <User className="text-muted-foreground mx-auto mb-3 size-8" />
                                                <p className="text-muted-foreground">
                                                    No se encontraron usuarios
                                                </p>
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        users.data.map((user) => (
                                            <TableRow key={user.id}>
                                                <TableCell>
                                                    <div className="flex items-center gap-3">
                                                        <div className="bg-primary/10 flex size-10 items-center justify-center rounded-full">
                                                            <User className="text-primary size-5" />
                                                        </div>
                                                        <div>
                                                            <span className="font-medium">{user.name}</span>
                                                            <p className="text-muted-foreground text-xs md:hidden">
                                                                {user.email}
                                                            </p>
                                                        </div>
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-muted-foreground hidden md:table-cell">
                                                    {user.email}
                                                </TableCell>
                                                <TableCell>
                                                    {user.company ? (
                                                        <Link
                                                            href={`/super-admin/companies/${user.company.id}/edit`}
                                                            className="hover:text-primary flex items-center gap-1 text-sm"
                                                        >
                                                            <Building2 className="size-3" />
                                                            {user.company.name}
                                                        </Link>
                                                    ) : (
                                                        <span className="text-muted-foreground text-sm">-</span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="outline" className={roleColors[user.role]}>
                                                        {roles[user.role] || user.role}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    {user.is_active ? (
                                                        <div className="flex items-center gap-1.5 text-emerald-600">
                                                            <UserCheck className="size-4" />
                                                            <span className="hidden text-sm sm:inline">Activo</span>
                                                        </div>
                                                    ) : (
                                                        <div className="text-muted-foreground flex items-center gap-1.5">
                                                            <UserX className="size-4" />
                                                            <span className="hidden text-sm sm:inline">Inactivo</span>
                                                        </div>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex justify-end gap-2">
                                                        <Link href={`/super-admin/users/${user.id}/edit`}>
                                                            <Button variant="ghost" size="sm">
                                                                <Edit className="size-4" />
                                                            </Button>
                                                        </Link>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() => handleDelete(user)}
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
                        {users.last_page > 1 && (
                            <div className="flex items-center justify-between border-t px-4 py-3">
                                <p className="text-muted-foreground text-sm">
                                    Página {users.current_page} de {users.last_page}
                                </p>
                                <div className="flex gap-1">
                                    {users.links.map((link, index) => {
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
                                        if (index === users.links.length - 1) {
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

