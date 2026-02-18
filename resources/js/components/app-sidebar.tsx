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
import { Activity, AlertTriangle, BarChart3, Bot, Building2, Contact, LayoutGrid, Network, Radio, Settings, Shield, Sparkles, Truck, User, Users } from 'lucide-react';
import AppLogo from './app-logo';

// ============================================
// Navigation Groups
// ============================================

// General - Punto de entrada principal
const generalNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

// Centro de Control - Herramientas operativas del día a día
const controlCenterNavItems: NavItem[] = [
    {
        title: 'Alertas',
        href: '/samsara/alerts',
        icon: AlertTriangle,
    },
    {
        title: 'Casos de Seguimiento',
        href: '/incidents',
        icon: Activity,
    },
    {
        title: 'Eventos de Seguridad',
        href: '/safety-signals',
        icon: Radio,
    },
    {
        title: 'Análisis',
        href: '/analytics',
        icon: BarChart3,
    },
    {
        title: 'Copilot',
        href: '/copilot',
        icon: Sparkles,
    },
];

// Flota - Gestión de recursos y vehículos
const fleetNavItems: NavItem[] = [
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
        title: 'Contactos',
        href: '/contacts',
        icon: Contact,
    },
];

// Super Admin - Gestión global del sistema
const superAdminNavItems: NavItem[] = [
    {
        title: 'Panel',
        href: '/super-admin',
        icon: Shield,
    },
    {
        title: 'Empresas',
        href: '/super-admin/companies',
        icon: Building2,
    },
    {
        title: 'Usuarios',
        href: '/super-admin/users',
        icon: Users,
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

    // Build admin nav items dynamically based on role
    const adminNavItems: NavItem[] = [];
    
    if (canManageUsers) {
        adminNavItems.push({
            title: 'Usuarios',
            href: '/users',
            icon: Users,
        });
    }
    
    if (isAdmin) {
        adminNavItems.push({
            title: 'Empresa',
            href: '/company',
            icon: Settings,
        });
        adminNavItems.push({
            title: 'Configuración AI',
            href: '/company/ai-settings',
            icon: Bot,
        });
        adminNavItems.push({
            title: 'Motor de Reglas de Detección',
            href: '/company/detection-rules',
            icon: Network,
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
                {/* General */}
                <NavMain items={generalNavItems} label="General" />
                
                {/* Centro de Control - Operaciones diarias */}
                <NavMain items={controlCenterNavItems} label="Centro de Control" />
                
                {/* Flota - Gestión de recursos */}
                <NavMain items={fleetNavItems} label="Flota" />
                
                {/* Administración - Solo para admin/manager */}
                {adminNavItems.length > 0 && (
                    <NavMain items={adminNavItems} label="Administración" />
                )}
                
                {/* Super Admin - Gestión global */}
                {isSuperAdmin && (
                    <>
                        <SidebarSeparator className="my-2" />
                        <NavMain items={superAdminNavItems} label="Super Admin" />
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
