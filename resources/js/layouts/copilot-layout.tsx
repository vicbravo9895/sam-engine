import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { CopilotHeader } from '@/components/copilot-header';
import { CopilotSidebar } from '@/components/copilot-sidebar';
import { type PropsWithChildren } from 'react';

interface Conversation {
    id: number;
    thread_id: string;
    title: string;
    created_at: string;
    updated_at: string;
}

interface CopilotLayoutProps {
    conversations: Conversation[];
    currentThreadId: string | null;
}

export default function CopilotLayout({
    children,
    conversations,
    currentThreadId,
}: PropsWithChildren<CopilotLayoutProps>) {
    return (
        <AppShell variant="sidebar">
            <CopilotSidebar
                conversations={conversations}
                currentThreadId={currentThreadId}
            />
            <AppContent variant="sidebar" className="flex h-svh max-h-svh flex-col overflow-hidden">
                <CopilotHeader />
                <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
                    {children}
                </div>
            </AppContent>
        </AppShell>
    );
}
