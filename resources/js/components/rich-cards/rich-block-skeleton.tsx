import { cn } from '@/lib/utils';
import {
    Activity,
    BarChart3,
    Gauge,
    Loader2,
    MapPin,
    Route,
    Shield,
    Truck,
    Video,
} from 'lucide-react';

export type RichBlockType = 'location' | 'vehicleStats' | 'dashcamMedia' | 'safetyEvents' | 'trips' | 'fleetReport' | 'fleetStatus' | 'fleetAnalysis';

interface RichBlockSkeletonProps {
    type: RichBlockType;
}

// Configuration for each block type
const blockConfig: Record<RichBlockType, {
    icon: React.ComponentType<{ className?: string }>;
    label: string;
    description: string;
    gradient: string;
    iconBg: string;
}> = {
    location: {
        icon: MapPin,
        label: 'Ubicación',
        description: 'Obteniendo ubicación del vehículo...',
        gradient: 'from-blue-50/50 to-cyan-50/50 dark:from-blue-950/20 dark:to-cyan-950/20',
        iconBg: 'bg-blue-500',
    },
    vehicleStats: {
        icon: Gauge,
        label: 'Estadísticas',
        description: 'Consultando estadísticas del vehículo...',
        gradient: 'from-slate-50 to-gray-50 dark:from-slate-950/50 dark:to-gray-950/50',
        iconBg: 'bg-gradient-to-br from-indigo-500 to-purple-600',
    },
    dashcamMedia: {
        icon: Video,
        label: 'Dashcam',
        description: 'Cargando imágenes de cámara...',
        gradient: 'from-purple-50/50 to-pink-50/50 dark:from-purple-950/20 dark:to-pink-950/20',
        iconBg: 'bg-purple-500',
    },
    safetyEvents: {
        icon: Shield,
        label: 'Eventos de Seguridad',
        description: 'Analizando eventos de seguridad...',
        gradient: 'from-red-50/50 to-orange-50/50 dark:from-red-950/20 dark:to-orange-950/20',
        iconBg: 'bg-red-500',
    },
    trips: {
        icon: Route,
        label: 'Viajes',
        description: 'Cargando historial de viajes...',
        gradient: 'from-green-50/50 to-emerald-50/50 dark:from-green-950/20 dark:to-emerald-950/20',
        iconBg: 'bg-green-500',
    },
    fleetReport: {
        icon: Truck,
        label: 'Reporte de Flota',
        description: 'Generando reporte completo del vehículo...',
        gradient: 'from-slate-50 to-gray-50 dark:from-slate-950/50 dark:to-gray-950/50',
        iconBg: 'bg-gradient-to-r from-blue-600 to-blue-500',
    },
    fleetStatus: {
        icon: Activity,
        label: 'Estado de la Flota',
        description: 'Consultando estado de los vehículos...',
        gradient: 'from-cyan-50/50 to-blue-50/50 dark:from-cyan-950/20 dark:to-blue-950/20',
        iconBg: 'bg-gradient-to-r from-cyan-600 to-blue-500',
    },
    fleetAnalysis: {
        icon: BarChart3,
        label: 'Análisis de Flota',
        description: 'Ejecutando análisis avanzado con AI...',
        gradient: 'from-violet-50/50 to-fuchsia-50/50 dark:from-violet-950/20 dark:to-fuchsia-950/20',
        iconBg: 'bg-gradient-to-r from-violet-600 to-fuchsia-500',
    },
};

// Animated skeleton line component
function SkeletonLine({ className }: { className?: string }) {
    return (
        <div
            className={cn(
                'animate-pulse rounded bg-gray-200 dark:bg-gray-700',
                className
            )}
        />
    );
}

// Skeleton for Location Card
function LocationSkeleton() {
    return (
        <>
            {/* Map placeholder */}
            <div className="relative h-48 w-full bg-gray-100 dark:bg-gray-800">
                <div className="absolute inset-0 flex items-center justify-center">
                    <div className="flex flex-col items-center gap-2">
                        <MapPin className="size-8 animate-pulse text-gray-300 dark:text-gray-600" />
                        <SkeletonLine className="h-3 w-24" />
                    </div>
                </div>
            </div>
            {/* Footer skeleton */}
            <div className="grid grid-cols-3 divide-x bg-white/70 dark:bg-black/30">
                {[1, 2, 3].map((i) => (
                    <div key={i} className="px-4 py-3 space-y-2">
                        <SkeletonLine className="h-3 w-16" />
                        <SkeletonLine className="h-4 w-full" />
                    </div>
                ))}
            </div>
        </>
    );
}

// Skeleton for Vehicle Stats Card
function VehicleStatsSkeleton() {
    return (
        <div className="grid grid-cols-2 gap-px bg-gray-200 dark:bg-gray-700 sm:grid-cols-4">
            {[1, 2, 3, 4].map((i) => (
                <div key={i} className="flex flex-col bg-white p-3 dark:bg-gray-900 space-y-2">
                    <SkeletonLine className="h-3 w-16" />
                    <SkeletonLine className="h-6 w-20" />
                    <SkeletonLine className="h-2 w-12" />
                </div>
            ))}
        </div>
    );
}

// Skeleton for Safety Events Card
function SafetyEventsSkeleton() {
    return (
        <div className="p-4 space-y-4">
            <div className="flex items-center justify-between rounded-lg bg-red-50/50 px-4 py-3 dark:bg-red-950/20">
                <div className="space-y-2">
                    <SkeletonLine className="h-3 w-20" />
                    <SkeletonLine className="h-8 w-12" />
                </div>
                <div className="space-y-2 text-right">
                    <SkeletonLine className="h-3 w-24 ml-auto" />
                    <SkeletonLine className="h-6 w-8 ml-auto" />
                </div>
            </div>
            <div className="space-y-2">
                {[1, 2].map((i) => (
                    <div key={i} className="flex items-center justify-between rounded-lg border px-3 py-2">
                        <SkeletonLine className="h-4 w-32" />
                        <SkeletonLine className="h-5 w-8 rounded-full" />
                    </div>
                ))}
            </div>
        </div>
    );
}

// Skeleton for Trips Card
function TripsSkeleton() {
    return (
        <div className="p-4 space-y-4">
            <div className="flex items-center justify-between rounded-lg bg-green-50/50 px-4 py-3 dark:bg-green-950/20">
                <div className="space-y-2">
                    <SkeletonLine className="h-3 w-20" />
                    <SkeletonLine className="h-8 w-12" />
                </div>
                <div className="space-y-2 text-right">
                    <SkeletonLine className="h-3 w-24 ml-auto" />
                    <SkeletonLine className="h-6 w-8 ml-auto" />
                </div>
            </div>
            <div className="space-y-2">
                {[1, 2].map((i) => (
                    <div key={i} className="flex items-center gap-3 rounded-lg border p-3">
                        <SkeletonLine className="size-8 rounded-full" />
                        <div className="flex-1 space-y-2">
                            <SkeletonLine className="h-4 w-24" />
                            <SkeletonLine className="h-3 w-32" />
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

// Skeleton for Dashcam Media Card
function DashcamMediaSkeleton() {
    return (
        <div className="p-4">
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                {[1, 2, 3, 4].map((i) => (
                    <div
                        key={i}
                        className="aspect-video overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-800"
                    >
                        <div className="flex h-full items-center justify-center">
                            <Video className="size-6 animate-pulse text-gray-300 dark:text-gray-600" />
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

// Skeleton for Fleet Report Card (most complex)
function FleetReportSkeleton() {
    return (
        <>
            {/* Summary section */}
            <div className="mt-4 rounded-lg bg-white/10 backdrop-blur-sm p-3">
                <SkeletonLine className="h-3 w-24 bg-blue-200/30 mb-3" />
                <div className="space-y-2">
                    <SkeletonLine className="h-4 w-full bg-blue-200/30" />
                    <SkeletonLine className="h-4 w-3/4 bg-blue-200/30" />
                </div>
            </div>
        </>
    );
}

// Skeleton for Fleet Status Card
function FleetStatusSkeleton() {
    return (
        <div className="p-4 space-y-4">
            {/* Stats summary */}
            <div className="grid grid-cols-3 gap-3">
                {[1, 2, 3].map((i) => (
                    <div key={i} className="rounded-lg bg-gray-100 dark:bg-gray-800 px-4 py-3 space-y-2">
                        <SkeletonLine className="h-8 w-12" />
                        <SkeletonLine className="h-3 w-16" />
                    </div>
                ))}
            </div>
            {/* Table header */}
            <div className="border-b pb-2">
                <SkeletonLine className="h-4 w-full" />
            </div>
            {/* Table rows */}
            <div className="space-y-3">
                {[1, 2, 3, 4].map((i) => (
                    <div key={i} className="flex items-center gap-4 py-2">
                        <SkeletonLine className="size-9 rounded-full" />
                        <div className="flex-1 space-y-2">
                            <SkeletonLine className="h-4 w-32" />
                            <SkeletonLine className="h-3 w-20" />
                        </div>
                        <SkeletonLine className="h-4 w-16" />
                        <SkeletonLine className="h-4 w-24" />
                        <SkeletonLine className="h-4 w-12" />
                    </div>
                ))}
            </div>
        </div>
    );
}

// Skeleton for Fleet Analysis Card
function FleetAnalysisSkeleton() {
    return (
        <div className="p-4 space-y-4">
            {/* Metrics grid */}
            <div>
                <SkeletonLine className="h-3 w-24 mb-3" />
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {[1, 2, 3, 4].map((i) => (
                        <div key={i} className="rounded-lg border bg-white p-3 dark:bg-gray-900 space-y-2">
                            <SkeletonLine className="h-3 w-20" />
                            <SkeletonLine className="h-6 w-16" />
                            <SkeletonLine className="h-3 w-12" />
                        </div>
                    ))}
                </div>
            </div>
            {/* Findings */}
            <div>
                <SkeletonLine className="h-3 w-20 mb-3" />
                <div className="space-y-2">
                    {[1, 2].map((i) => (
                        <div key={i} className="flex items-start gap-3 rounded-lg border p-3">
                            <SkeletonLine className="size-3 rounded-full shrink-0" />
                            <div className="flex-1 space-y-2">
                                <SkeletonLine className="h-4 w-48" />
                                <SkeletonLine className="h-3 w-full" />
                            </div>
                        </div>
                    ))}
                </div>
            </div>
            {/* Insights */}
            <div className="rounded-lg border border-violet-200 bg-violet-50/50 dark:border-violet-800 dark:bg-violet-950/20 p-4 space-y-2">
                <SkeletonLine className="h-4 w-full" />
                <SkeletonLine className="h-4 w-3/4" />
                <SkeletonLine className="h-4 w-5/6" />
            </div>
        </div>
    );
}

export function RichBlockSkeleton({ type }: RichBlockSkeletonProps) {
    const config = blockConfig[type];
    const Icon = config.icon;

    // Special handling for fleet analysis - styled header with gradient
    if (type === 'fleetAnalysis') {
        return (
            <div className="my-3 overflow-hidden rounded-xl border bg-gradient-to-br from-slate-50 to-gray-50 dark:from-slate-950/50 dark:to-gray-950/50 shadow-lg animate-in fade-in duration-300">
                <div className="border-b bg-gradient-to-r from-violet-600 to-fuchsia-500 px-6 py-4 text-white">
                    <div className="flex items-start justify-between">
                        <div className="flex items-center gap-4">
                            <div className="flex size-14 items-center justify-center rounded-full bg-white/20 backdrop-blur-sm">
                                <Loader2 className="size-7 animate-spin" />
                            </div>
                            <div>
                                <SkeletonLine className="h-3 w-24 bg-white/20 mb-2" />
                                <SkeletonLine className="h-6 w-56 bg-white/30" />
                                <SkeletonLine className="h-3 w-32 bg-white/20 mt-2" />
                            </div>
                        </div>
                        <SkeletonLine className="h-10 w-24 rounded-lg bg-white/20" />
                    </div>
                    <div className="mt-4 rounded-lg bg-white/10 backdrop-blur-sm p-3">
                        <SkeletonLine className="h-4 w-full bg-white/20" />
                        <SkeletonLine className="h-4 w-3/4 bg-white/20 mt-2" />
                    </div>
                </div>
                <FleetAnalysisSkeleton />
            </div>
        );
    }

    // Special handling for fleet report - it has a different header style
    if (type === 'fleetReport') {
        return (
            <div className="my-3 overflow-hidden rounded-xl border bg-gradient-to-br from-slate-50 to-gray-50 dark:from-slate-950/50 dark:to-gray-950/50 shadow-lg animate-in fade-in duration-300">
                {/* Fleet Report styled header */}
                <div className="border-b bg-gradient-to-r from-blue-600 to-blue-500 px-6 py-4 text-white">
                    <div className="flex items-start justify-between">
                        <div className="flex items-center gap-4">
                            <div className="flex size-14 items-center justify-center rounded-full bg-white/20 backdrop-blur-sm">
                                <Loader2 className="size-7 animate-spin" />
                            </div>
                            <div>
                                <h2 className="text-xl font-bold">Reporte de Flota</h2>
                                <div className="text-sm text-blue-100 mt-0.5">
                                    <SkeletonLine className="h-4 w-48 bg-blue-200/30" />
                                </div>
                            </div>
                        </div>
                        <SkeletonLine className="h-10 w-24 rounded-lg bg-white/20" />
                    </div>
                    <FleetReportSkeleton />
                </div>

                {/* Content skeleton */}
                <div className="p-6">
                    <div className="grid gap-6 lg:grid-cols-2">
                        <div className="space-y-6">
                            {/* Location skeleton placeholder */}
                            <div className="overflow-hidden rounded-lg border bg-white shadow-sm dark:bg-gray-900">
                                <div className="border-b bg-gradient-to-r from-blue-50 to-cyan-50 px-4 py-3 dark:from-blue-950/30 dark:to-cyan-950/30">
                                    <div className="flex items-center gap-2">
                                        <div className="flex size-8 items-center justify-center rounded-full bg-blue-500 text-white">
                                            <MapPin className="size-4" />
                                        </div>
                                        <SkeletonLine className="h-5 w-32" />
                                    </div>
                                </div>
                                <div className="h-48 bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                    <MapPin className="size-8 animate-pulse text-gray-300" />
                                </div>
                            </div>
                        </div>
                        <div className="space-y-6">
                            {/* Events skeleton placeholder */}
                            <div className="overflow-hidden rounded-lg border bg-white shadow-sm dark:bg-gray-900">
                                <div className="border-b bg-gradient-to-r from-red-50 to-orange-50 px-4 py-3 dark:from-red-950/30 dark:to-orange-950/30">
                                    <div className="flex items-center gap-2">
                                        <div className="flex size-8 items-center justify-center rounded-full bg-red-500 text-white">
                                            <Shield className="size-4" />
                                        </div>
                                        <SkeletonLine className="h-5 w-40" />
                                    </div>
                                </div>
                                <SafetyEventsSkeleton />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    // Standard card skeleton for other types
    return (
        <div className={cn(
            'my-3 overflow-hidden rounded-xl border bg-gradient-to-br animate-in fade-in duration-300',
            config.gradient
        )}>
            {/* Header */}
            <div className="flex items-center justify-between border-b bg-white/50 px-4 py-3 dark:bg-black/20">
                <div className="flex items-center gap-3">
                    <div className={cn(
                        'flex size-10 items-center justify-center rounded-full text-white',
                        config.iconBg
                    )}>
                        <Loader2 className="size-5 animate-spin" />
                    </div>
                    <div className="space-y-1.5">
                        <div className="flex items-center gap-2">
                            <Icon className="size-4 text-gray-400" />
                            <span className="text-sm font-medium text-gray-500">{config.label}</span>
                        </div>
                        <p className="text-xs text-gray-400">{config.description}</p>
                    </div>
                </div>
            </div>

            {/* Type-specific skeleton content */}
            {type === 'location' && <LocationSkeleton />}
            {type === 'vehicleStats' && <VehicleStatsSkeleton />}
            {type === 'safetyEvents' && <SafetyEventsSkeleton />}
            {type === 'trips' && <TripsSkeleton />}
            {type === 'dashcamMedia' && <DashcamMediaSkeleton />}
            {type === 'fleetStatus' && <FleetStatusSkeleton />}
            {type === 'fleetAnalysis' && <FleetAnalysisSkeleton />}
        </div>
    );
}
