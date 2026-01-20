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

/**
 * Check if a nav item could match the current URL.
 * Returns true for exact match or if URL is a subpath.
 */
function couldMatch(currentUrl: string, itemHref: string): boolean {
    const resolvedHref = resolveUrl(itemHref);
    const urlPath = currentUrl.split('?')[0];
    
    return urlPath === resolvedHref || urlPath.startsWith(resolvedHref + '/');
}

/**
 * Find the best matching item from a list.
 * Returns the href of the most specific match (longest href that matches).
 */
function findBestMatch(currentUrl: string, items: NavItem[]): string | null {
    const urlPath = currentUrl.split('?')[0];
    let bestMatch: string | null = null;
    let bestLength = -1;
    
    for (const item of items) {
        const resolvedHref = resolveUrl(item.href);
        if (couldMatch(urlPath, item.href) && resolvedHref.length > bestLength) {
            bestMatch = resolvedHref;
            bestLength = resolvedHref.length;
        }
    }
    
    return bestMatch;
}

export function NavMain({ items = [], label = 'Plataforma' }: NavMainProps) {
    const page = usePage();
    const bestMatch = findBestMatch(page.url, items);
    
    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>{label}</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            asChild
                            isActive={resolveUrl(item.href) === bestMatch}
                            tooltip={{ children: item.title }}
                        >
                            <Link href={item.href} prefetch>
                                {item.icon && <item.icon />}
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
