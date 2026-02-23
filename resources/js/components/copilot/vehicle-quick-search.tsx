import { cn } from '@/lib/utils';
import {
    Activity,
    BarChart3,
    Camera,
    ChevronLeft,
    FileText,
    HeartPulse,
    MapPin,
    Route,
    Search,
    ShieldAlert,
    Truck,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

// ============================================================================
// Types
// ============================================================================

export interface VehicleOption {
    id: number;
    name: string;
    license_plate: string | null;
    make: string | null;
    model: string | null;
    year: number | null;
}

interface VehicleAction {
    id: string;
    label: string;
    icon: React.ComponentType<{ className?: string }>;
    color: string;
    buildQuery: (vehicleName: string) => string;
}

interface VehicleQuickSearchProps {
    vehicles: VehicleOption[];
    open: boolean;
    onClose: () => void;
    onSelect: (query: string) => void;
    /** Pre-select an action so the user only needs to pick the vehicle */
    preSelectedAction?: string | null;
}

// ============================================================================
// Actions available per vehicle
// ============================================================================

const VEHICLE_ACTIONS: VehicleAction[] = [
    {
        id: 'locate',
        label: 'Ubicar',
        icon: MapPin,
        color: 'text-blue-500 bg-blue-500/10',
        buildQuery: (name) => `¿Dónde se encuentra ${name} en este momento?`,
    },
    {
        id: 'stats',
        label: 'Estadísticas',
        icon: Activity,
        color: 'text-indigo-500 bg-indigo-500/10',
        buildQuery: (name) => `Muéstrame las estadísticas en tiempo real de ${name}`,
    },
    {
        id: 'trips',
        label: 'Viajes',
        icon: Route,
        color: 'text-green-500 bg-green-500/10',
        buildQuery: (name) => `¿Cuáles son los viajes recientes de ${name}?`,
    },
    {
        id: 'cameras',
        label: 'Cámaras',
        icon: Camera,
        color: 'text-purple-500 bg-purple-500/10',
        buildQuery: (name) => `Muéstrame las imágenes de dashcam de ${name}`,
    },
    {
        id: 'safety',
        label: 'Seguridad',
        icon: ShieldAlert,
        color: 'text-red-500 bg-red-500/10',
        buildQuery: (name) => `¿Qué eventos de seguridad tiene ${name}?`,
    },
    {
        id: 'report',
        label: 'Reporte completo',
        icon: FileText,
        color: 'text-amber-500 bg-amber-500/10',
        buildQuery: (name) => `Genera un reporte completo de ${name}: ubicación, estadísticas, viajes recientes, eventos de seguridad y cámaras`,
    },
    {
        id: 'healthAnalysis',
        label: 'Análisis de salud',
        icon: HeartPulse,
        color: 'text-emerald-500 bg-emerald-500/10',
        buildQuery: (name) => `Ejecuta un análisis de salud del vehículo ${name}`,
    },
    {
        id: 'efficiencyAnalysis',
        label: 'Eficiencia operativa',
        icon: BarChart3,
        color: 'text-violet-500 bg-violet-500/10',
        buildQuery: (name) => `Analiza la eficiencia operativa de ${name} de los últimos 7 días`,
    },
];

// ============================================================================
// Component
// ============================================================================

export function VehicleQuickSearch({
    vehicles,
    open,
    onClose,
    onSelect,
    preSelectedAction = null,
}: VehicleQuickSearchProps) {
    const [search, setSearch] = useState('');
    const [selectedVehicle, setSelectedVehicle] = useState<VehicleOption | null>(null);
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
            setSelectedVehicle(null);
            // Focus the search input when opened
            setTimeout(() => inputRef.current?.focus(), 100);
        }
    }, [open]);

    // Close on Escape key
    useEffect(() => {
        if (!open) return;
        const handleKeyDown = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                if (selectedVehicle) {
                    setSelectedVehicle(null);
                } else {
                    onClose();
                }
            }
        };
        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [open, selectedVehicle, onClose]);

    // Filter vehicles based on search
    const filtered = useMemo(() => {
        if (!search.trim()) return vehicles;
        const q = search.toLowerCase();
        return vehicles.filter(
            (v) =>
                v.name?.toLowerCase().includes(q) ||
                v.license_plate?.toLowerCase().includes(q) ||
                v.make?.toLowerCase().includes(q) ||
                v.model?.toLowerCase().includes(q),
        );
    }, [vehicles, search]);

    // Handle vehicle click — if preSelectedAction, send directly
    const handleVehicleClick = (vehicle: VehicleOption) => {
        if (preSelectedAction) {
            const action = VEHICLE_ACTIONS.find((a) => a.id === preSelectedAction);
            if (action) {
                onSelect(action.buildQuery(vehicle.name));
                onClose();
                return;
            }
        }
        setSelectedVehicle(vehicle);
    };

    // Handle action click
    const handleActionClick = (action: VehicleAction) => {
        if (!selectedVehicle) return;
        onSelect(action.buildQuery(selectedVehicle.name));
        onClose();
    };

    // Handle back to vehicle list
    const handleBack = () => {
        setSelectedVehicle(null);
    };

    // Build vehicle subtitle
    const getVehicleSubtitle = (v: VehicleOption): string => {
        const parts: string[] = [];
        if (v.license_plate) parts.push(`Placa: ${v.license_plate}`);
        const makeModel = [v.make, v.model].filter(Boolean).join(' ');
        if (makeModel) parts.push(makeModel);
        if (v.year) parts.push(`(${v.year})`);
        return parts.join(' - ') || 'Sin información adicional';
    };

    if (!open) return null;

    return (
        <div ref={panelRef} className="animate-in fade-in slide-in-from-bottom-2 absolute inset-x-0 bottom-full z-40 mb-0 overscroll-contain duration-200" role="dialog" aria-label="Seleccionar vehículo">
            <div className="bg-background mx-3 rounded-2xl border shadow-xl md:mx-auto md:max-w-4xl">
                {/* Header */}
                <div className="flex items-center gap-2 border-b px-3 py-2.5">
                    {selectedVehicle ? (
                        <>
                            <button
                                type="button"
                                onClick={handleBack}
                                className="text-muted-foreground hover:text-foreground -ml-1 rounded-lg p-1 transition-colors"
                                aria-label="Volver a la lista de vehículos"
                            >
                                <ChevronLeft className="size-4" aria-hidden />
                            </button>
                            <Truck className="text-primary size-4" />
                            <span className="text-sm font-semibold">{selectedVehicle.name}</span>
                            <span className="text-muted-foreground text-xs">
                                {getVehicleSubtitle(selectedVehicle)}
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
                                placeholder="Buscar vehículo por nombre, placa o modelo…"
                                className="placeholder:text-muted-foreground flex-1 bg-transparent text-sm outline-none"
                                aria-label="Buscar vehículo"
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
                {selectedVehicle ? (
                    /* Action picker */
                    <div className="p-2">
                        <p className="text-muted-foreground mb-2 px-1 text-xs">
                            ¿Qué quieres saber de este vehículo?
                        </p>
                        <div className="grid grid-cols-2 gap-1.5 sm:grid-cols-3 lg:grid-cols-6">
                            {VEHICLE_ACTIONS.map((action) => {
                                const Icon = action.icon;
                                const [textColor, bgColor] = action.color.split(' ');
                                return (
                                    <button
                                        key={action.id}
                                        type="button"
                                        onClick={() => handleActionClick(action)}
                                        className={cn(
                                            'hover:bg-muted flex flex-col items-center gap-1.5 rounded-xl border px-3 py-3 text-xs font-medium transition-[background-color,box-shadow] duration-200 hover:shadow-sm',
                                        )}
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
                    /* Vehicle list */
                    <div
                        ref={listRef}
                        className="max-h-[280px] overflow-y-auto overscroll-contain p-1.5"
                    >
                        {filtered.length === 0 ? (
                            <div className="text-muted-foreground flex flex-col items-center gap-2 py-8 text-sm">
                                <Truck className="size-8 opacity-30" />
                                <span>
                                    {search
                                        ? `No se encontraron vehículos para "${search}"`
                                        : 'No hay vehículos registrados'}
                                </span>
                            </div>
                        ) : (
                            <>
                                <p className="text-muted-foreground mb-1 px-2 text-[10px] font-medium uppercase tracking-wider">
                                    {filtered.length === vehicles.length
                                        ? `${vehicles.length} vehículos`
                                        : `${filtered.length} de ${vehicles.length} vehículos`}
                                </p>
                                {filtered.map((vehicle) => (
                                    <button
                                        key={vehicle.id}
                                        type="button"
                                        onClick={() => handleVehicleClick(vehicle)}
                                        className="hover:bg-muted group flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left transition-colors"
                                    >
                                        <div className="bg-muted group-hover:bg-primary/10 flex size-8 flex-shrink-0 items-center justify-center rounded-lg transition-colors">
                                            <Truck className="text-muted-foreground group-hover:text-primary size-4 transition-colors" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">
                                                {vehicle.name}
                                            </p>
                                            <p className="text-muted-foreground truncate text-xs">
                                                {getVehicleSubtitle(vehicle)}
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
