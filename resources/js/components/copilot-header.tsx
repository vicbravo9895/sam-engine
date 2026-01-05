import { SidebarTrigger } from '@/components/ui/sidebar';
import { Sparkles } from 'lucide-react';

export function CopilotHeader() {
    return (
        <header className="bg-background flex h-14 shrink-0 items-center gap-3 border-b px-4 md:px-6">
            <SidebarTrigger className="-ml-2" />
            <div className="flex items-center gap-2">
                <div className="from-primary/20 to-primary/5 flex size-7 items-center justify-center rounded-full bg-gradient-to-br">
                    <Sparkles className="text-primary size-4" />
                </div>
                <span className="text-sm font-medium">Copilot</span>
            </div>
        </header>
    );
}

