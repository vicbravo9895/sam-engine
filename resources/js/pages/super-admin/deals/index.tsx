import { Badge } from '@/components/ui/badge';
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
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    Eye,
    Handshake,
    Search,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';

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
    status: string;
    source: string;
    created_at: string;
}

interface PaginatedDeals {
    data: DealData[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

interface Props {
    deals: PaginatedDeals;
    stats: Record<string, number>;
    countries: string[];
    filters: {
        search?: string;
        status?: string;
        country?: string;
    };
    statuses: Record<string, string>;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Super Admin', href: '/super-admin' },
    { title: 'Deals', href: '/super-admin/deals' },
];

const statusVariant: Record<string, 'default' | 'secondary' | 'outline' | 'destructive'> = {
    new: 'default',
    contacted: 'secondary',
    qualified: 'outline',
    proposal: 'outline',
    won: 'default',
    lost: 'destructive',
};

const statusColors: Record<string, string> = {
    new: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    contacted: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
    qualified: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
    proposal: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300',
    won: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    lost: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
};

function formatDate(dateStr: string) {
    return new Date(dateStr).toLocaleDateString('es-MX', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

export default function DealsIndex() {
    const { deals, stats, countries, filters, statuses } = usePage<{ props: Props }>().props as unknown as Props;
    const [search, setSearch] = useState(filters.search || '');

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/super-admin/deals', { ...filters, search }, { preserveState: true });
    };

    const handleFilterChange = (key: string, value: string) => {
        const filterValue = value === 'all' ? undefined : value;
        router.get('/super-admin/deals', { ...filters, [key]: filterValue }, { preserveState: true });
    };

    const clearFilters = () => {
        setSearch('');
        router.get('/super-admin/deals', {}, { preserveState: true });
    };

    const handleDelete = (deal: DealData) => {
        if (confirm(`¿Estás seguro de eliminar el deal de "${deal.first_name} ${deal.last_name}"?`)) {
            router.delete(`/super-admin/deals/${deal.id}`);
        }
    };

    const hasFilters = filters.search || filters.status || filters.country;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Deals - Super Admin" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4 sm:gap-6 sm:p-6">
                {/* Header */}
                <div>
                    <h1 className="text-xl font-bold tracking-tight sm:text-2xl">Solicitudes de Demo</h1>
                    <p className="text-muted-foreground text-sm">
                        Gestiona y da seguimiento a las solicitudes de demo recibidas
                    </p>
                </div>

                {/* Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
                    {Object.entries(statuses).map(([key, label]) => (
                        <Card
                            key={key}
                            className={`cursor-pointer transition-shadow hover:shadow-md ${filters.status === key ? 'ring-primary ring-2' : ''}`}
                            onClick={() => handleFilterChange('status', filters.status === key ? 'all' : key)}
                        >
                            <CardContent className="p-3 text-center">
                                <p className="text-2xl font-bold">{stats[key] ?? 0}</p>
                                <p className="text-muted-foreground text-xs">{label}</p>
                            </CardContent>
                        </Card>
                    ))}
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
                                        placeholder="Buscar por nombre, email, empresa o teléfono..."
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
                                value={filters.status ?? 'all'}
                                onValueChange={(value) => handleFilterChange('status', value)}
                            >
                                <SelectTrigger className="w-full sm:w-[160px]">
                                    <SelectValue placeholder="Estado" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Todos los estados</SelectItem>
                                    {Object.entries(statuses).map(([key, label]) => (
                                        <SelectItem key={key} value={key}>{label}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.country ?? 'all'}
                                onValueChange={(value) => handleFilterChange('country', value)}
                            >
                                <SelectTrigger className="w-full sm:w-[160px]">
                                    <SelectValue placeholder="País" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Todos los países</SelectItem>
                                    {countries.map((c) => (
                                        <SelectItem key={c} value={c}>{c}</SelectItem>
                                    ))}
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

                {/* Deals Table */}
                <Card>
                    <CardHeader>
                        <CardDescription>
                            {deals.total} solicitud{deals.total !== 1 ? 'es' : ''} encontrada{deals.total !== 1 ? 's' : ''}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Contacto</TableHead>
                                        <TableHead className="hidden md:table-cell">Empresa</TableHead>
                                        <TableHead className="hidden lg:table-cell">Teléfono</TableHead>
                                        <TableHead className="hidden sm:table-cell">Flota</TableHead>
                                        <TableHead className="hidden lg:table-cell">País</TableHead>
                                        <TableHead>Estado</TableHead>
                                        <TableHead className="hidden sm:table-cell">Fecha</TableHead>
                                        <TableHead className="text-right">Acciones</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {deals.data.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={8} className="py-12 text-center">
                                                <Handshake className="text-muted-foreground mx-auto mb-3 size-8" />
                                                <p className="text-muted-foreground">
                                                    No se encontraron solicitudes
                                                </p>
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        deals.data.map((deal) => (
                                            <TableRow key={deal.id}>
                                                <TableCell>
                                                    <div>
                                                        <p className="font-medium">{deal.first_name} {deal.last_name}</p>
                                                        <p className="text-muted-foreground text-xs">{deal.email}</p>
                                                    </div>
                                                </TableCell>
                                                <TableCell className="hidden md:table-cell">
                                                    <div>
                                                        <p className="text-sm">{deal.company_name}</p>
                                                        {deal.position && (
                                                            <p className="text-muted-foreground text-xs">{deal.position}</p>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-muted-foreground hidden text-sm lg:table-cell">
                                                    {deal.phone}
                                                </TableCell>
                                                <TableCell className="hidden sm:table-cell">
                                                    <Badge variant="outline">{deal.fleet_size}</Badge>
                                                </TableCell>
                                                <TableCell className="text-muted-foreground hidden text-sm lg:table-cell">
                                                    {deal.country}
                                                </TableCell>
                                                <TableCell>
                                                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${statusColors[deal.status] || ''}`}>
                                                        {statuses[deal.status] || deal.status}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="text-muted-foreground hidden text-sm sm:table-cell">
                                                    {formatDate(deal.created_at)}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex justify-end gap-1">
                                                        <Link href={`/super-admin/deals/${deal.id}`}>
                                                            <Button variant="ghost" size="sm">
                                                                <Eye className="size-4" />
                                                            </Button>
                                                        </Link>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() => handleDelete(deal)}
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
                        {deals.last_page > 1 && (
                            <div className="flex items-center justify-between border-t px-4 py-3">
                                <p className="text-muted-foreground text-sm">
                                    Página {deals.current_page} de {deals.last_page}
                                </p>
                                <div className="flex gap-1">
                                    {deals.links.map((link, index) => {
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
                                        if (index === deals.links.length - 1) {
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
