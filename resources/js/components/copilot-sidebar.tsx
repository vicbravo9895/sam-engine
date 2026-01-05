import { ConfirmDialog } from '@/components/confirm-dialog';
import { NavUser } from '@/components/nav-user';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuAction,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import {
    destroy,
    index as copilotIndex,
    show,
} from '@/actions/App/Http/Controllers/CopilotController';
import { dashboard } from '@/routes';
import { resolveUrl } from '@/lib/utils';
import { Link, router, usePage } from '@inertiajs/react';
import {
    LayoutGrid,
    MessageSquarePlus,
    MoreHorizontal,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import AppLogo from './app-logo';

interface Conversation {
    id: number;
    thread_id: string;
    title: string;
    created_at: string;
    updated_at: string;
}

interface CopilotSidebarProps {
    conversations: Conversation[];
    currentThreadId: string | null;
}

export function CopilotSidebar({ conversations, currentThreadId }: CopilotSidebarProps) {
    const [deleteDialog, setDeleteDialog] = useState<{
        open: boolean;
        threadId: string | null;
        title: string;
    }>({ open: false, threadId: null, title: '' });
    const page = usePage();

    const handleNewChat = () => {
        router.visit(copilotIndex.url());
    };

    const openDeleteDialog = (threadId: string, title: string) => {
        setDeleteDialog({ open: true, threadId, title });
    };

    const handleDeleteConversation = () => {
        if (deleteDialog.threadId) {
            router.delete(destroy.url(deleteDialog.threadId), {
                preserveScroll: true,
                onSuccess: () => {
                    setDeleteDialog({ open: false, threadId: null, title: '' });
                },
            });
        }
    };

    return (
        <>
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
                    {/* Navegación principal */}
                    <SidebarGroup className="px-2 py-0">
                        <SidebarMenu>
                            <SidebarMenuItem>
                                <SidebarMenuButton
                                    asChild
                                    isActive={page.url === resolveUrl(dashboard())}
                                    tooltip={{ children: 'Dashboard' }}
                                >
                                    <Link href={dashboard()} prefetch>
                                        <LayoutGrid className="size-4" />
                                        <span>Dashboard</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarGroup>

                    {/* Botón nueva conversación */}
                    <SidebarGroup className="px-2 py-0">
                        <SidebarMenu>
                            <SidebarMenuItem>
                                <SidebarMenuButton
                                    onClick={handleNewChat}
                                    tooltip={{ children: 'Nueva conversación' }}
                                    className="bg-primary text-primary-foreground hover:bg-primary/90 hover:text-primary-foreground"
                                >
                                    <MessageSquarePlus className="size-4" />
                                    <span>Nueva conversación</span>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarGroup>

                    {/* Lista de conversaciones */}
                    <SidebarGroup className="flex-1 overflow-y-auto px-2 py-0">
                        <SidebarGroupContent>
                            <SidebarMenu>
                                {conversations.map((conv) => (
                                    <SidebarMenuItem key={conv.thread_id}>
                                        <SidebarMenuButton
                                            asChild
                                            isActive={currentThreadId === conv.thread_id}
                                            tooltip={{ children: conv.title }}
                                        >
                                            <Link href={show.url(conv.thread_id)}>
                                                <span className="truncate">{conv.title}</span>
                                            </Link>
                                        </SidebarMenuButton>
                                        
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <SidebarMenuAction showOnHover>
                                                    <MoreHorizontal className="size-4" />
                                                    <span className="sr-only">Más opciones</span>
                                                </SidebarMenuAction>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent 
                                                side="right" 
                                                align="start"
                                                className="w-40"
                                            >
                                                <DropdownMenuItem 
                                                    variant="destructive"
                                                    onClick={() => openDeleteDialog(conv.thread_id, conv.title)}
                                                >
                                                    <Trash2 className="size-4" />
                                                    <span>Eliminar</span>
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </SidebarMenuItem>
                                ))}
                            </SidebarMenu>
                        </SidebarGroupContent>
                    </SidebarGroup>
                </SidebarContent>

                <SidebarFooter>
                    <NavUser />
                </SidebarFooter>
            </Sidebar>

            {/* Modal de confirmación de eliminación */}
            <ConfirmDialog
                open={deleteDialog.open}
                onOpenChange={(open) => setDeleteDialog((prev) => ({ ...prev, open }))}
                title="Eliminar conversación"
                description={`¿Estás seguro de que deseas eliminar "${deleteDialog.title}"? Esta acción no se puede deshacer.`}
                confirmLabel="Eliminar"
                cancelLabel="Cancelar"
                variant="destructive"
                onConfirm={handleDeleteConversation}
            />
        </>
    );
}
