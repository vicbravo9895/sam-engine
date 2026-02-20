import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarSeparator,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Activity, AlertTriangle, BarChart3, Bell, Bot, Building2, Contact, FileText, Flag, Handshake, LayoutGrid, Network, Radio, Settings, Shield, Sparkles, Truck, User, Users } from 'lucide-react';
import AppLogo from './app-logo';

const operationsNavItems: NavItem[] = [
    {
        title: 'Centro de Comando',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Alertas',
        href: '/samsara/alerts',
        icon: AlertTriangle,
    },
    {
        title: 'Incidentes',
        href: '/incidents',
        icon: Activity,
    },
    {
        title: 'Notificaciones',
        href: '/notifications',
        icon: Bell,
    },
    {
        title: 'Copilot',
        href: '/copilot',
        icon: Sparkles,
    },
];

const monitoringNavItems: NavItem[] = [
    {
        title: 'Vehículos',
        href: '/fleet-report',
        icon: Truck,
    },
    {
        title: 'Conductores',
        href: '/drivers',
        icon: User,
    },
    {
        title: 'Señales',
        href: '/safety-signals',
        icon: Radio,
    },
    {
        title: 'Analítica',
        href: '/analytics',
        icon: BarChart3,
    },
];

const footerNavItems: NavItem[] = [];

export function AppSidebar() {
    const { auth } = usePage<SharedData>().props;
    const userRole = auth?.user?.role as string | undefined;
    const isSuperAdmin = userRole === 'super_admin';
    const isAdmin = userRole === 'admin';
    const isManager = userRole === 'manager';
    const canManageUsers = isAdmin || isManager;

    const adminNavItems: NavItem[] = [];
    
    if (canManageUsers) {
        adminNavItems.push({
            title: 'Usuarios',
            href: '/users',
            icon: Users,
        });
    }

    adminNavItems.push({
        title: 'Contactos',
        href: '/contacts',
        icon: Contact,
    });
    
    if (isAdmin) {
        adminNavItems.push({
            title: 'Empresa',
            href: '/company',
            icon: Settings,
        });
        adminNavItems.push({
            title: 'Reglas de Detección',
            href: '/company/detection-rules',
            icon: Network,
        });
        adminNavItems.push({
            title: 'Configuración AI',
            href: '/company/ai-settings',
            icon: Bot,
        });
    }

    if (isSuperAdmin) {
        adminNavItems.push({
            title: 'Deals',
            href: '/super-admin/deals',
            icon: Handshake,
        });
        adminNavItems.push({
            title: 'Uso y Facturación',
            href: '/super-admin/usage',
            icon: BarChart3,
        });
        adminNavItems.push({
            title: 'Auditoría',
            href: '/super-admin/audit',
            icon: FileText,
        });
        adminNavItems.push({
            title: 'Feature Flags',
            href: '/super-admin/feature-flags',
            icon: Flag,
        });
    }

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={operationsNavItems} label="Operaciones" />
                <NavMain items={monitoringNavItems} label="Monitoreo" />
                {adminNavItems.length > 0 && (
                    <>
                        <SidebarSeparator className="my-1" />
                        <NavMain items={adminNavItems} label="Administración" />
                    </>
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
