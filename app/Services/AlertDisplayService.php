<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\AlertActivity;

class AlertDisplayService
{
    /**
     * Human-readable label for human_status.
     */
    public function humanStatusLabel(string $status): string
    {
        return match ($status) {
            Alert::HUMAN_STATUS_PENDING => 'Sin revisar',
            Alert::HUMAN_STATUS_REVIEWED => 'Revisado',
            Alert::HUMAN_STATUS_FLAGGED => 'Marcado',
            Alert::HUMAN_STATUS_RESOLVED => 'Resuelto',
            Alert::HUMAN_STATUS_FALSE_POSITIVE => 'Falso positivo',
            default => 'Desconocido',
        };
    }

    /**
     * Human-readable label for activity action.
     */
    public function actionLabel(string $action): string
    {
        return match ($action) {
            AlertActivity::ACTION_AI_PROCESSING_STARTED => 'AI inició procesamiento',
            AlertActivity::ACTION_AI_COMPLETED => 'AI completó análisis',
            AlertActivity::ACTION_AI_FAILED => 'AI falló',
            AlertActivity::ACTION_AI_INVESTIGATING => 'AI en investigación',
            AlertActivity::ACTION_AI_REVALIDATED => 'AI revalidó',
            AlertActivity::ACTION_HUMAN_REVIEWED => 'Revisado por humano',
            AlertActivity::ACTION_HUMAN_STATUS_CHANGED => 'Estado cambiado',
            AlertActivity::ACTION_COMMENT_ADDED => 'Comentario agregado',
            AlertActivity::ACTION_MARKED_FALSE_POSITIVE => 'Marcado como falso positivo',
            AlertActivity::ACTION_MARKED_RESOLVED => 'Marcado como resuelto',
            AlertActivity::ACTION_MARKED_FLAGGED => 'Marcado para seguimiento',
            default => 'Actividad',
        };
    }

    /**
     * Icon name for activity action.
     */
    public function actionIcon(string $action): string
    {
        return match ($action) {
            AlertActivity::ACTION_AI_PROCESSING_STARTED => 'cpu',
            AlertActivity::ACTION_AI_COMPLETED => 'check-circle',
            AlertActivity::ACTION_AI_FAILED => 'x-circle',
            AlertActivity::ACTION_AI_INVESTIGATING => 'search',
            AlertActivity::ACTION_AI_REVALIDATED => 'refresh-cw',
            AlertActivity::ACTION_HUMAN_REVIEWED => 'eye',
            AlertActivity::ACTION_HUMAN_STATUS_CHANGED => 'toggle-right',
            AlertActivity::ACTION_COMMENT_ADDED => 'message-square',
            AlertActivity::ACTION_MARKED_FALSE_POSITIVE => 'slash',
            AlertActivity::ACTION_MARKED_RESOLVED => 'check-circle-2',
            AlertActivity::ACTION_MARKED_FLAGGED => 'flag',
            default => 'activity',
        };
    }
}
