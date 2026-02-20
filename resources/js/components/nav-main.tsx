import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { resolveUrl } from '@/lib/utils';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';

interface NavMainProps {
    items: NavItem[];
    label?: string;
}

function findBestMatch(currentUrl: string, items: NavItem[]): string | null {
    const urlPath = currentUrl.split('?')[0];
    let bestMatch: string | null = null;
    let bestLength = -1;
    
    for (const item of items) {
        const resolved = resolveUrl(item.href);
        const matches = urlPath === resolved || urlPath.startsWith(resolved + '/');
        if (matches && resolved.length > bestLength) {
            bestMatch = resolved;
            bestLength = resolved.length;
        }
    }
    
    return bestMatch;
}

export function NavMain({ items = [], label = 'Plataforma' }: NavMainProps) {
    const page = usePage();
    const bestMatch = findBestMatch(page.url, items);
    
    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel className="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted-foreground/60">
                {label}
            </SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => {
                    const isActive = resolveUrl(item.href) === bestMatch;
                    return (
                        <SidebarMenuItem key={item.title}>
                            <SidebarMenuButton
                                asChild
                                isActive={isActive}
                                tooltip={{ children: item.title }}
                                className={
                                    isActive
                                        ? 'relative border-l-2 border-sidebar-primary bg-sidebar-accent/80 font-medium text-sidebar-primary'
                                        : 'transition-colors duration-150'
                                }
                            >
                                <Link href={item.href} prefetch>
                                    {item.icon ? <item.icon className="size-4" /> : null}
                                    <span>{item.title}</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}
