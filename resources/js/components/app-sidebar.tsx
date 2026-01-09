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
import { AlertTriangle, BookOpen, Building2, Folder, LayoutGrid, Shield, Sparkles, Truck, Users } from 'lucide-react';
import AppLogo from './app-logo';

const SAMSARA_ALERTS_URL = '/samsara/alerts';
const FLEET_REPORT_URL = '/fleet-report';
const CONTACTS_URL = '/contacts';
const COPILOT_URL = '/copilot';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Copilot',
        href: COPILOT_URL,
        icon: Sparkles,
    },
    {
        title: 'Alertas Samsara',
        href: SAMSARA_ALERTS_URL,
        icon: AlertTriangle,
    },
    {
        title: 'Reporte de Flota',
        href: FLEET_REPORT_URL,
        icon: Truck,
    },
    {
        title: 'Contactos',
        href: CONTACTS_URL,
        icon: Users,
    },
];

const superAdminNavItems: NavItem[] = [
    {
        title: 'Panel Admin',
        href: '/super-admin',
        icon: Shield,
    },
    {
        title: 'Empresas',
        href: '/super-admin/companies',
        icon: Building2,
    },
    {
        title: 'Todos los Usuarios',
        href: '/super-admin/users',
        icon: Users,
    },
];

const footerNavItems: NavItem[] = [
];

export function AppSidebar() {
    const { auth } = usePage<SharedData>().props;
    const userRole = auth?.user?.role as string | undefined;
    const isSuperAdmin = userRole === 'super_admin';
    
    // Build nav items based on user role
    const navItems: NavItem[] = [...mainNavItems];
    
    // Add management items for admins and managers (not super_admin, they use separate section)
    if (userRole === 'admin' || userRole === 'manager') {
        navItems.push({
            title: 'Usuarios',
            href: '/users',
            icon: Users,
        });
    }
    
    // Add company settings for admins only (not super_admin)
    if (userRole === 'admin') {
        navItems.push({
            title: 'Empresa',
            href: '/company',
            icon: Building2,
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
                <NavMain items={navItems} />
                
                {/* Super Admin Section */}
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
