import { cn } from '@/lib/utils';
import { Camera, Car, ChevronLeft, ChevronRight, Clock, Download, ExternalLink, User, Video, X } from 'lucide-react';
import { useState } from 'react';

interface DashcamImage {
    id: string;
    type: 'dashcamRoadFacing' | 'dashcamDriverFacing' | 'photo' | 'video';
    typeDescription: string;
    timestamp: string;
    url: string;
    isPersisted: boolean;
}

interface DashcamMediaCardProps {
    data: {
        vehicleId: string;
        vehicleName: string;
        totalImages: number;
        images: DashcamImage[];
    };
}

export function DashcamMediaCard({ data }: DashcamMediaCardProps) {
    const [selectedImageIndex, setSelectedImageIndex] = useState<number | null>(null);
    const [activeTab, setActiveTab] = useState<'all' | 'road' | 'driver'>('all');

    const formatTimestamp = (timestamp: string) => {
        const date = new Date(timestamp);
        return date.toLocaleString('es-MX', {
            hour: '2-digit',
            minute: '2-digit',
            day: '2-digit',
            month: 'short',
            year: 'numeric',
        });
    };

    const getTypeIcon = (type: string) => {
        switch (type) {
            case 'dashcamRoadFacing':
                return <Car className="size-4" />;
            case 'dashcamDriverFacing':
                return <User className="size-4" />;
            case 'video':
                return <Video className="size-4" />;
            default:
                return <Camera className="size-4" />;
        }
    };

    const getTypeColor = (type: string) => {
        switch (type) {
            case 'dashcamRoadFacing':
                return 'bg-blue-500';
            case 'dashcamDriverFacing':
                return 'bg-amber-500';
            case 'video':
                return 'bg-purple-500';
            default:
                return 'bg-gray-500';
        }
    };

    // Filter images based on active tab
    const filteredImages = data.images.filter((img) => {
        if (activeTab === 'all') return true;
        if (activeTab === 'road') return img.type === 'dashcamRoadFacing';
        if (activeTab === 'driver') return img.type === 'dashcamDriverFacing';
        return true;
    });

    // Group images by type for summary
    const roadFacingCount = data.images.filter((img) => img.type === 'dashcamRoadFacing').length;
    const driverFacingCount = data.images.filter((img) => img.type === 'dashcamDriverFacing').length;

    const handlePrevImage = () => {
        if (selectedImageIndex !== null && selectedImageIndex > 0) {
            setSelectedImageIndex(selectedImageIndex - 1);
        }
    };

    const handleNextImage = () => {
        if (selectedImageIndex !== null && selectedImageIndex < filteredImages.length - 1) {
            setSelectedImageIndex(selectedImageIndex + 1);
        }
    };

    const handleKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'ArrowLeft') handlePrevImage();
        if (e.key === 'ArrowRight') handleNextImage();
        if (e.key === 'Escape') setSelectedImageIndex(null);
    };

    return (
        <>
            <div className="my-3 overflow-hidden rounded-xl border bg-gradient-to-br from-slate-50/50 to-zinc-50/50 dark:from-slate-950/20 dark:to-zinc-950/20">
                {/* Header */}
                <div className="flex items-center justify-between border-b bg-white/50 px-4 py-3 dark:bg-black/20">
                    <div className="flex items-center gap-3">
                        <div className="flex size-10 items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white">
                            <Camera className="size-5" />
                        </div>
                        <div>
                            <h3 className="font-semibold text-gray-900 dark:text-white">
                                {data.vehicleName}
                            </h3>
                            <p className="text-xs text-gray-500">
                                {data.totalImages} {data.totalImages === 1 ? 'imagen' : 'imágenes'} de dashcam
                            </p>
                        </div>
                    </div>
                    {/* Type summary badges */}
                    <div className="flex items-center gap-2">
                        {roadFacingCount > 0 && (
                            <div className="flex items-center gap-1.5 rounded-full bg-blue-100 px-2.5 py-1 text-xs font-medium text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">
                                <Car className="size-3" />
                                {roadFacingCount}
                            </div>
                        )}
                        {driverFacingCount > 0 && (
                            <div className="flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                <User className="size-3" />
                                {driverFacingCount}
                            </div>
                        )}
                    </div>
                </div>

                {/* Tab Filter */}
                {(roadFacingCount > 0 && driverFacingCount > 0) && (
                    <div className="flex border-b bg-white/30 dark:bg-black/10">
                        <button
                            onClick={() => setActiveTab('all')}
                            className={cn(
                                'flex-1 px-4 py-2 text-sm font-medium transition-colors',
                                activeTab === 'all'
                                    ? 'border-b-2 border-indigo-500 text-indigo-600 dark:text-indigo-400'
                                    : 'text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'
                            )}
                        >
                            Todas ({data.totalImages})
                        </button>
                        <button
                            onClick={() => setActiveTab('road')}
                            className={cn(
                                'flex-1 px-4 py-2 text-sm font-medium transition-colors flex items-center justify-center gap-1.5',
                                activeTab === 'road'
                                    ? 'border-b-2 border-blue-500 text-blue-600 dark:text-blue-400'
                                    : 'text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'
                            )}
                        >
                            <Car className="size-4" />
                            Carretera ({roadFacingCount})
                        </button>
                        <button
                            onClick={() => setActiveTab('driver')}
                            className={cn(
                                'flex-1 px-4 py-2 text-sm font-medium transition-colors flex items-center justify-center gap-1.5',
                                activeTab === 'driver'
                                    ? 'border-b-2 border-amber-500 text-amber-600 dark:text-amber-400'
                                    : 'text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'
                            )}
                        >
                            <User className="size-4" />
                            Conductor ({driverFacingCount})
                        </button>
                    </div>
                )}

                {/* Image Grid */}
                <div className="grid grid-cols-2 gap-2 p-3 sm:grid-cols-3 md:grid-cols-4">
                    {filteredImages.map((image, index) => (
                        <div
                            key={image.id || index}
                            className="group relative aspect-video cursor-pointer overflow-hidden rounded-lg bg-gray-100 transition-transform hover:scale-[1.02] dark:bg-gray-800"
                            onClick={() => setSelectedImageIndex(index)}
                        >
                            <img
                                src={image.url}
                                alt={image.typeDescription}
                                className="size-full object-cover transition-opacity group-hover:opacity-90"
                                loading="lazy"
                            />
                            {/* Type Badge */}
                            <div
                                className={cn(
                                    'absolute left-2 top-2 flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium text-white shadow-md',
                                    getTypeColor(image.type)
                                )}
                            >
                                {getTypeIcon(image.type)}
                            </div>
                            {/* Timestamp overlay */}
                            <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent p-2">
                                <div className="flex items-center gap-1 text-xs text-white">
                                    <Clock className="size-3" />
                                    {formatTimestamp(image.timestamp)}
                                </div>
                            </div>
                            {/* Persisted indicator */}
                            {image.isPersisted && (
                                <div className="absolute right-2 top-2 rounded-full bg-green-500 p-1 text-white shadow-md" title="Guardado localmente">
                                    <Download className="size-3" />
                                </div>
                            )}
                        </div>
                    ))}
                </div>

                {/* Empty state for filtered results */}
                {filteredImages.length === 0 && (
                    <div className="flex flex-col items-center justify-center py-8 text-gray-500">
                        <Camera className="mb-2 size-8 opacity-50" />
                        <p className="text-sm">No hay imágenes para este filtro</p>
                    </div>
                )}
            </div>

            {/* Lightbox Modal */}
            {selectedImageIndex !== null && filteredImages[selectedImageIndex] && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/90 p-4"
                    onClick={() => setSelectedImageIndex(null)}
                    onKeyDown={handleKeyDown}
                    tabIndex={0}
                >
                    {/* Close button */}
                    <button
                        className="absolute right-4 top-4 rounded-full bg-white/10 p-2 text-white transition-colors hover:bg-white/20"
                        onClick={() => setSelectedImageIndex(null)}
                    >
                        <X className="size-6" />
                    </button>

                    {/* Navigation - Previous */}
                    {selectedImageIndex > 0 && (
                        <button
                            className="absolute left-4 rounded-full bg-white/10 p-3 text-white transition-colors hover:bg-white/20"
                            onClick={(e) => {
                                e.stopPropagation();
                                handlePrevImage();
                            }}
                        >
                            <ChevronLeft className="size-6" />
                        </button>
                    )}

                    {/* Main Image */}
                    <div
                        className="relative max-h-[85vh] max-w-[90vw]"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <img
                            src={filteredImages[selectedImageIndex].url}
                            alt={filteredImages[selectedImageIndex].typeDescription}
                            className="max-h-[85vh] max-w-[90vw] rounded-lg object-contain shadow-2xl"
                        />
                        {/* Image info overlay */}
                        <div className="absolute inset-x-0 bottom-0 rounded-b-lg bg-gradient-to-t from-black/80 to-transparent p-4">
                            <div className="flex items-center justify-between">
                                <div>
                                    <div className="flex items-center gap-2 text-white">
                                        {getTypeIcon(filteredImages[selectedImageIndex].type)}
                                        <span className="font-medium">
                                            {filteredImages[selectedImageIndex].typeDescription}
                                        </span>
                                    </div>
                                    <div className="mt-1 flex items-center gap-1 text-sm text-gray-300">
                                        <Clock className="size-3" />
                                        {formatTimestamp(filteredImages[selectedImageIndex].timestamp)}
                                    </div>
                                </div>
                                <a
                                    href={filteredImages[selectedImageIndex].url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="flex items-center gap-1.5 rounded-lg bg-white/20 px-3 py-2 text-sm font-medium text-white transition-colors hover:bg-white/30"
                                >
                                    <ExternalLink className="size-4" />
                                    Abrir
                                </a>
                            </div>
                            {/* Image counter */}
                            <div className="mt-2 text-center text-sm text-gray-400">
                                {selectedImageIndex + 1} / {filteredImages.length}
                            </div>
                        </div>
                    </div>

                    {/* Navigation - Next */}
                    {selectedImageIndex < filteredImages.length - 1 && (
                        <button
                            className="absolute right-4 rounded-full bg-white/10 p-3 text-white transition-colors hover:bg-white/20"
                            onClick={(e) => {
                                e.stopPropagation();
                                handleNextImage();
                            }}
                        >
                            <ChevronRight className="size-6" />
                        </button>
                    )}
                </div>
            )}
        </>
    );
}

