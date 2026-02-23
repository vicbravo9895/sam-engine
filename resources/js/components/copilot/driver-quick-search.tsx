import { cn } from '@/lib/utils';
import {
    BarChart3,
    ChevronLeft,
    Search,
    ShieldAlert,
    Truck,
    User,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

// ============================================================================
// Types
// ============================================================================

export interface DriverOption {
    id: number;
    name: string;
    phone: string | null;
    license_number: string | null;
    status: string;
    assigned_vehicle_name: string | null;
}

interface DriverAction {
    id: string;
    label: string;
    icon: React.ComponentType<{ className?: string }>;
    color: string;
    buildQuery: (driverName: string) => string;
}

interface DriverQuickSearchProps {
    drivers: DriverOption[];
    open: boolean;
    onClose: () => void;
    onSelect: (query: string) => void;
    /** Pre-select an action so the user only needs to pick the driver */
    preSelectedAction?: string | null;
}

// ============================================================================
// Actions available per driver
// ============================================================================

const DRIVER_ACTIONS: DriverAction[] = [
    {
        id: 'info',
        label: 'Información',
        icon: User,
        color: 'text-blue-500 bg-blue-500/10',
        buildQuery: (name) => `Dame la información completa del conductor ${name}`,
    },
    {
        id: 'vehicle',
        label: 'Vehículo asignado',
        icon: Truck,
        color: 'text-indigo-500 bg-indigo-500/10',
        buildQuery: (name) => `¿Cuál es el estado del vehículo asignado al conductor ${name}?`,
    },
    {
        id: 'safety',
        label: 'Eventos de seguridad',
        icon: ShieldAlert,
        color: 'text-red-500 bg-red-500/10',
        buildQuery: (name) => `¿Qué eventos de seguridad están relacionados con el conductor ${name}?`,
    },
    {
        id: 'riskAnalysis',
        label: 'Análisis de riesgo',
        icon: BarChart3,
        color: 'text-violet-500 bg-violet-500/10',
        buildQuery: (name) => `Analiza el perfil de riesgo del conductor ${name} de los últimos 7 días`,
    },
];

// ============================================================================
// Component
// ============================================================================

export function DriverQuickSearch({
    drivers,
    open,
    onClose,
    onSelect,
    preSelectedAction = null,
}: DriverQuickSearchProps) {
    const [search, setSearch] = useState('');
    const [selectedDriver, setSelectedDriver] = useState<DriverOption | null>(null);
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

    // Reset state when dialog opens/closes
    useEffect(() => {
        if (open) {
            setSearch('');
            setSelectedDriver(null);
            setTimeout(() => inputRef.current?.focus(), 100);
        }
    }, [open]);

    // Close on Escape key
    useEffect(() => {
        if (!open) return;
        const handleKeyDown = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                if (selectedDriver) {
                    setSelectedDriver(null);
                } else {
                    onClose();
                }
            }
        };
        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [open, selectedDriver, onClose]);

    // Filter drivers based on search
    const filtered = useMemo(() => {
        if (!search.trim()) return drivers;
        const q = search.toLowerCase();
        return drivers.filter(
            (d) =>
                d.name?.toLowerCase().includes(q) ||
                d.phone?.toLowerCase().includes(q) ||
                d.license_number?.toLowerCase().includes(q),
        );
    }, [drivers, search]);

    // Handle driver click — if preSelectedAction, send directly
    const handleDriverClick = (driver: DriverOption) => {
        if (preSelectedAction) {
            const action = DRIVER_ACTIONS.find((a) => a.id === preSelectedAction);
            if (action) {
                onSelect(action.buildQuery(driver.name));
                onClose();
                return;
            }
        }
        setSelectedDriver(driver);
    };

    // Handle action click
    const handleActionClick = (action: DriverAction) => {
        if (!selectedDriver) return;
        onSelect(action.buildQuery(selectedDriver.name));
        onClose();
    };

    // Handle back to driver list
    const handleBack = () => {
        setSelectedDriver(null);
    };

    // Build driver subtitle
    const getDriverSubtitle = (d: DriverOption): string => {
        const parts: string[] = [];
        if (d.phone) parts.push(d.phone);
        if (d.assigned_vehicle_name) parts.push(d.assigned_vehicle_name);
        return parts.join(' · ') || 'Sin información adicional';
    };

    if (!open) return null;

    return (
        <div ref={panelRef} className="animate-in fade-in slide-in-from-bottom-2 absolute inset-x-0 bottom-full z-40 mb-0 overscroll-contain duration-200" role="dialog" aria-label="Seleccionar conductor">
            <div className="bg-background mx-3 rounded-2xl border shadow-xl md:mx-auto md:max-w-4xl">
                {/* Header */}
                <div className="flex items-center gap-2 border-b px-3 py-2.5">
                    {selectedDriver ? (
                        <>
                            <button
                                type="button"
                                onClick={handleBack}
                                className="text-muted-foreground hover:text-foreground -ml-1 rounded-lg p-1 transition-colors"
                                aria-label="Volver a la lista de conductores"
                            >
                                <ChevronLeft className="size-4" aria-hidden />
                            </button>
                            <User className="text-amber-500 size-4" />
                            <span className="text-sm font-semibold">{selectedDriver.name}</span>
                            <span className="text-muted-foreground text-xs">
                                {getDriverSubtitle(selectedDriver)}
                            </span>
                        </>
                    ) : (
                        <>
                            <Search className="text-muted-foreground size-4" />
                            <input
                                ref={inputRef}
                                type="search"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Buscar conductor por nombre, teléfono o licencia…"
                                className="placeholder:text-muted-foreground flex-1 bg-transparent text-sm outline-none"
                                aria-label="Buscar conductor"
                                autoComplete="off"
                            />
                            {search && (
                                <button
                                    type="button"
                                    onClick={() => setSearch('')}
                                    className="text-muted-foreground hover:text-foreground rounded p-0.5 transition-colors"
                                    aria-label="Borrar búsqueda"
                                >
                                    <X className="size-3.5" aria-hidden />
                                </button>
                            )}
                        </>
                    )}
                    <button
                        type="button"
                        onClick={onClose}
                        className="text-muted-foreground hover:text-foreground ml-auto rounded-lg p-1 transition-colors"
                        aria-label="Cerrar"
                    >
                        <X className="size-4" aria-hidden />
                    </button>
                </div>

                {/* Content */}
                {selectedDriver ? (
                    /* Action picker */
                    <div className="p-2">
                        <p className="text-muted-foreground mb-2 px-1 text-xs">
                            ¿Qué quieres saber de este conductor?
                        </p>
                        <div className="grid grid-cols-3 gap-1.5">
                            {DRIVER_ACTIONS.map((action) => {
                                const Icon = action.icon;
                                const [textColor, bgColor] = action.color.split(' ');
                                return (
                                    <button
                                        key={action.id}
                                        type="button"
                                        onClick={() => handleActionClick(action)}
                                        className="hover:bg-muted flex flex-col items-center gap-1.5 rounded-xl border px-3 py-3 text-xs font-medium transition-[background-color,box-shadow] duration-200 hover:shadow-sm"
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
                    /* Driver list */
                    <div
                        ref={listRef}
                        className="max-h-[280px] overflow-y-auto overscroll-contain p-1.5"
                    >
                        {filtered.length === 0 ? (
                            <div className="text-muted-foreground flex flex-col items-center gap-2 py-8 text-sm">
                                <User className="size-8 opacity-30" />
                                <span>
                                    {search
                                        ? `No se encontraron conductores para "${search}"`
                                        : 'No hay conductores activos'}
                                </span>
                            </div>
                        ) : (
                            <>
                                <p className="text-muted-foreground mb-1 px-2 text-[10px] font-medium uppercase tracking-wider">
                                    {filtered.length === drivers.length
                                        ? `${drivers.length} conductores`
                                        : `${filtered.length} de ${drivers.length} conductores`}
                                </p>
                                {filtered.map((driver) => (
                                    <button
                                        key={driver.id}
                                        type="button"
                                        onClick={() => handleDriverClick(driver)}
                                        className="hover:bg-muted group flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left transition-colors"
                                    >
                                        <div className="bg-muted group-hover:bg-amber-500/10 flex size-8 flex-shrink-0 items-center justify-center rounded-lg transition-colors">
                                            <User className="text-muted-foreground group-hover:text-amber-500 size-4 transition-colors" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <p className="truncate text-sm font-medium">
                                                    {driver.name}
                                                </p>
                                                <span className="flex-shrink-0 rounded-full bg-green-500/10 px-1.5 py-0.5 text-[10px] font-medium text-green-600">
                                                    activo
                                                </span>
                                            </div>
                                            <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                                {driver.phone && <span>{driver.phone}</span>}
                                                {driver.phone && driver.assigned_vehicle_name && (
                                                    <span className="text-muted-foreground/40">·</span>
                                                )}
                                                {driver.assigned_vehicle_name && (
                                                    <span className="flex items-center gap-0.5">
                                                        <Truck className="size-3" />
                                                        {driver.assigned_vehicle_name}
                                                    </span>
                                                )}
                                            </div>
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
