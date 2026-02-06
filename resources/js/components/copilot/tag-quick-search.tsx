import { cn } from '@/lib/utils';
import {
    Activity,
    ChevronLeft,
    ChevronRight,
    Search,
    Tag,
    Truck,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

// ============================================================================
// Types
// ============================================================================

export interface TagOption {
    id: number;
    samsara_id: string;
    name: string;
    parent_tag_id: string | null;
    vehicle_count: number;
    driver_count: number;
}

interface TagAction {
    id: string;
    label: string;
    icon: React.ComponentType<{ className?: string }>;
    color: string;
    buildQuery: (tagName: string) => string;
}

interface TagQuickSearchProps {
    tags: TagOption[];
    open: boolean;
    onClose: () => void;
    onSelect: (query: string) => void;
    /** Pre-select an action so the user only needs to pick the tag */
    preSelectedAction?: string | null;
}

// ============================================================================
// Actions available per tag
// ============================================================================

const TAG_ACTIONS: TagAction[] = [
    {
        id: 'fleet',
        label: 'Estado de flota',
        icon: Activity,
        color: 'text-blue-500 bg-blue-500/10',
        buildQuery: (name) => `¿Cuál es el estado de la flota del grupo ${name}?`,
    },
    {
        id: 'vehicles',
        label: 'Vehículos del grupo',
        icon: Truck,
        color: 'text-indigo-500 bg-indigo-500/10',
        buildQuery: (name) => `¿Cuáles son los vehículos del grupo ${name}?`,
    },
    {
        id: 'drivers',
        label: 'Conductores del grupo',
        icon: Users,
        color: 'text-amber-500 bg-amber-500/10',
        buildQuery: (name) => `¿Cuáles son los conductores del grupo ${name}?`,
    },
];

// ============================================================================
// Component
// ============================================================================

export function TagQuickSearch({
    tags,
    open,
    onClose,
    onSelect,
    preSelectedAction = null,
}: TagQuickSearchProps) {
    const [search, setSearch] = useState('');
    const [selectedTag, setSelectedTag] = useState<TagOption | null>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const listRef = useRef<HTMLDivElement>(null);
    const panelRef = useRef<HTMLDivElement>(null);

    // Close on click outside
    useEffect(() => {
        if (!open) return;
        const handleMouseDown = (e: MouseEvent) => {
            if (panelRef.current && !panelRef.current.contains(e.target as Node)) {
                onClose();
            }
        };
        document.addEventListener('mousedown', handleMouseDown);
        return () => document.removeEventListener('mousedown', handleMouseDown);
    }, [open, onClose]);

    // Build a map of samsara_id -> name for parent lookup
    const tagNameMap = useMemo(() => {
        const map = new Map<string, string>();
        tags.forEach((t) => map.set(t.samsara_id, t.name));
        return map;
    }, [tags]);

    // Reset state when dialog opens/closes
    useEffect(() => {
        if (open) {
            setSearch('');
            setSelectedTag(null);
            setTimeout(() => inputRef.current?.focus(), 100);
        }
    }, [open]);

    // Close on Escape key
    useEffect(() => {
        if (!open) return;
        const handleKeyDown = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                if (selectedTag) {
                    setSelectedTag(null);
                } else {
                    onClose();
                }
            }
        };
        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [open, selectedTag, onClose]);

    // Filter tags based on search
    const filtered = useMemo(() => {
        if (!search.trim()) return tags;
        const q = search.toLowerCase();
        return tags.filter((t) => t.name?.toLowerCase().includes(q));
    }, [tags, search]);

    // Handle tag click — if preSelectedAction, send directly
    const handleTagClick = (tag: TagOption) => {
        if (preSelectedAction) {
            const action = TAG_ACTIONS.find((a) => a.id === preSelectedAction);
            if (action) {
                onSelect(action.buildQuery(tag.name));
                onClose();
                return;
            }
        }
        setSelectedTag(tag);
    };

    // Handle action click
    const handleActionClick = (action: TagAction) => {
        if (!selectedTag) return;
        onSelect(action.buildQuery(selectedTag.name));
        onClose();
    };

    // Handle back to tag list
    const handleBack = () => {
        setSelectedTag(null);
    };

    // Build tag subtitle with hierarchy and counts
    const getTagSubtitle = (t: TagOption): string => {
        const parts: string[] = [];
        if (t.parent_tag_id) {
            const parentName = tagNameMap.get(t.parent_tag_id);
            if (parentName) parts.push(parentName);
        }
        const counts: string[] = [];
        if (t.vehicle_count > 0) counts.push(`${t.vehicle_count} vehículos`);
        if (t.driver_count > 0) counts.push(`${t.driver_count} conductores`);
        if (counts.length > 0) parts.push(counts.join(', '));
        return parts.join(' · ') || 'Sin elementos';
    };

    if (!open) return null;

    return (
        <div ref={panelRef} className="animate-in fade-in slide-in-from-bottom-2 absolute inset-x-0 bottom-full z-40 mb-0 duration-200">
            <div className="bg-background mx-3 rounded-2xl border shadow-xl md:mx-auto md:max-w-4xl">
                {/* Header */}
                <div className="flex items-center gap-2 border-b px-3 py-2.5">
                    {selectedTag ? (
                        <>
                            <button
                                type="button"
                                onClick={handleBack}
                                className="text-muted-foreground hover:text-foreground -ml-1 rounded-lg p-1 transition-colors"
                            >
                                <ChevronLeft className="size-4" />
                            </button>
                            <Tag className="text-teal-500 size-4" />
                            <span className="text-sm font-semibold">{selectedTag.name}</span>
                            <span className="text-muted-foreground text-xs">
                                {getTagSubtitle(selectedTag)}
                            </span>
                        </>
                    ) : (
                        <>
                            <Search className="text-muted-foreground size-4" />
                            <input
                                ref={inputRef}
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Buscar grupo por nombre..."
                                className="placeholder:text-muted-foreground flex-1 bg-transparent text-sm outline-none"
                            />
                            {search && (
                                <button
                                    type="button"
                                    onClick={() => setSearch('')}
                                    className="text-muted-foreground hover:text-foreground rounded p-0.5 transition-colors"
                                >
                                    <X className="size-3.5" />
                                </button>
                            )}
                        </>
                    )}
                    <button
                        type="button"
                        onClick={onClose}
                        className="text-muted-foreground hover:text-foreground ml-auto rounded-lg p-1 transition-colors"
                    >
                        <X className="size-4" />
                    </button>
                </div>

                {/* Content */}
                {selectedTag ? (
                    /* Action picker */
                    <div className="p-2">
                        <p className="text-muted-foreground mb-2 px-1 text-xs">
                            ¿Qué quieres saber de este grupo?
                        </p>
                        <div className="grid grid-cols-3 gap-1.5">
                            {TAG_ACTIONS.map((action) => {
                                const Icon = action.icon;
                                const [textColor, bgColor] = action.color.split(' ');
                                return (
                                    <button
                                        key={action.id}
                                        type="button"
                                        onClick={() => handleActionClick(action)}
                                        className="hover:bg-muted flex flex-col items-center gap-1.5 rounded-xl border px-3 py-3 text-xs font-medium transition-all hover:shadow-sm"
                                    >
                                        <div className={cn('flex size-8 items-center justify-center rounded-lg', bgColor)}>
                                            <Icon className={cn('size-4', textColor)} />
                                        </div>
                                        {action.label}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                ) : (
                    /* Tag list */
                    <div
                        ref={listRef}
                        className="max-h-[280px] overflow-y-auto overscroll-contain p-1.5"
                    >
                        {filtered.length === 0 ? (
                            <div className="text-muted-foreground flex flex-col items-center gap-2 py-8 text-sm">
                                <Tag className="size-8 opacity-30" />
                                <span>
                                    {search
                                        ? `No se encontraron grupos para "${search}"`
                                        : 'No hay grupos configurados'}
                                </span>
                            </div>
                        ) : (
                            <>
                                <p className="text-muted-foreground mb-1 px-2 text-[10px] font-medium uppercase tracking-wider">
                                    {filtered.length === tags.length
                                        ? `${tags.length} grupos`
                                        : `${filtered.length} de ${tags.length} grupos`}
                                </p>
                                {filtered.map((tag) => (
                                    <button
                                        key={tag.id}
                                        type="button"
                                        onClick={() => handleTagClick(tag)}
                                        className="hover:bg-muted group flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left transition-colors"
                                    >
                                        <div className="bg-muted group-hover:bg-teal-500/10 flex size-8 flex-shrink-0 items-center justify-center rounded-lg transition-colors">
                                            <Tag className="text-muted-foreground group-hover:text-teal-500 size-4 transition-colors" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-1.5">
                                                {tag.parent_tag_id && tagNameMap.get(tag.parent_tag_id) && (
                                                    <>
                                                        <span className="text-muted-foreground truncate text-xs">
                                                            {tagNameMap.get(tag.parent_tag_id)}
                                                        </span>
                                                        <ChevronRight className="text-muted-foreground/50 size-3 flex-shrink-0" />
                                                    </>
                                                )}
                                                <p className="truncate text-sm font-medium">
                                                    {tag.name}
                                                </p>
                                            </div>
                                            <p className="text-muted-foreground truncate text-xs">
                                                {[
                                                    tag.vehicle_count > 0 && `${tag.vehicle_count} vehículos`,
                                                    tag.driver_count > 0 && `${tag.driver_count} conductores`,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ') || 'Sin elementos'}
                                            </p>
                                        </div>
                                    </button>
                                ))}
                            </>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
