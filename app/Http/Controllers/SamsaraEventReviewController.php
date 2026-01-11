<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessSamsaraEventJob;
use App\Models\SamsaraEvent;
use App\Models\SamsaraEventActivity;
use App\Models\SamsaraEventComment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Controlador para revisión humana de eventos de Samsara.
 * 
 * Endpoints para:
 * - Cambiar human_status
 * - Agregar comentarios
 * - Listar comentarios
 * - Listar timeline de actividades
 */
class SamsaraEventReviewController extends Controller
{
    /**
     * Cambiar el human_status de un evento.
     * 
     * PATCH /api/events/{id}/status
     */
    public function updateStatus(Request $request, SamsaraEvent $event): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([
                SamsaraEvent::HUMAN_STATUS_PENDING,
                SamsaraEvent::HUMAN_STATUS_REVIEWED,
                SamsaraEvent::HUMAN_STATUS_FLAGGED,
                SamsaraEvent::HUMAN_STATUS_RESOLVED,
                SamsaraEvent::HUMAN_STATUS_FALSE_POSITIVE,
            ])],
        ]);

        $user = Auth::user();
        $event->setHumanStatus($validated['status'], $user->id);

        return response()->json([
            'success' => true,
            'message' => 'Estado actualizado correctamente',
            'data' => [
                'id' => $event->id,
                'human_status' => $event->human_status,
                'human_status_label' => $this->humanStatusLabel($event->human_status),
                'reviewed_by' => $user->name,
                'reviewed_at' => $event->reviewed_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Agregar un comentario a un evento.
     * 
     * POST /api/events/{id}/comments
     */
    public function addComment(Request $request, SamsaraEvent $event): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
        ]);

        $user = Auth::user();
        $comment = $event->addComment($user->id, $validated['content']);

        return response()->json([
            'success' => true,
            'message' => 'Comentario agregado',
            'data' => [
                'id' => $comment->id,
                'content' => $comment->content,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                ],
                'created_at' => $comment->created_at->toIso8601String(),
                'created_at_human' => $comment->created_at->diffForHumans(),
            ],
        ]);
    }

    /**
     * Listar comentarios de un evento.
     * 
     * GET /api/events/{id}/comments
     */
    public function getComments(SamsaraEvent $event): JsonResponse
    {
        $comments = $event->comments()
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SamsaraEventComment $comment) => [
                'id' => $comment->id,
                'content' => $comment->content,
                'user' => $comment->user ? [
                    'id' => $comment->user->id,
                    'name' => $comment->user->name,
                ] : null,
                'created_at' => $comment->created_at->toIso8601String(),
                'created_at_human' => $comment->created_at->diffForHumans(),
            ]);

        return response()->json([
            'success' => true,
            'data' => $comments,
        ]);
    }

    /**
     * Listar timeline de actividades de un evento.
     * 
     * GET /api/events/{id}/activities
     */
    public function getActivities(SamsaraEvent $event): JsonResponse
    {
        $activities = $event->activities()
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (SamsaraEventActivity $activity) => [
                'id' => $activity->id,
                'action' => $activity->action,
                'action_label' => $this->actionLabel($activity->action),
                'action_icon' => $this->actionIcon($activity->action),
                'is_ai_action' => $activity->isAiAction(),
                'user' => $activity->user ? [
                    'id' => $activity->user->id,
                    'name' => $activity->user->name,
                ] : null,
                'metadata' => $activity->metadata,
                'created_at' => $activity->created_at->toIso8601String(),
                'created_at_human' => $activity->created_at->diffForHumans(),
            ]);

        return response()->json([
            'success' => true,
            'data' => $activities,
        ]);
    }

    /**
     * Obtener resumen de revisión humana para un evento.
     * 
     * GET /api/events/{id}/review
     */
    public function getReviewSummary(SamsaraEvent $event): JsonResponse
    {
        $event->load(['reviewedBy:id,name', 'comments.user:id,name']);

        return response()->json([
            'success' => true,
            'data' => [
                'human_status' => $event->human_status,
                'human_status_label' => $this->humanStatusLabel($event->human_status),
                'reviewed_by' => $event->reviewedBy ? [
                    'id' => $event->reviewedBy->id,
                    'name' => $event->reviewedBy->name,
                ] : null,
                'reviewed_at' => $event->reviewed_at?->toIso8601String(),
                'reviewed_at_human' => $event->reviewed_at?->diffForHumans(),
                'needs_attention' => $event->needsHumanAttention(),
                'urgency_level' => $event->getHumanUrgencyLevel(),
                'comments_count' => $event->comments->count(),
                'recent_comments' => $event->comments->take(3)->map(fn ($c) => [
                    'id' => $c->id,
                    'content' => $c->content,
                    'user' => $c->user ? ['id' => $c->user->id, 'name' => $c->user->name] : null,
                    'created_at_human' => $c->created_at->diffForHumans(),
                ])->values(),
            ],
        ]);
    }

    /**
     * Label para human_status.
     */
    private function humanStatusLabel(string $status): string
    {
        return match ($status) {
            SamsaraEvent::HUMAN_STATUS_PENDING => 'Sin revisar',
            SamsaraEvent::HUMAN_STATUS_REVIEWED => 'Revisado',
            SamsaraEvent::HUMAN_STATUS_FLAGGED => 'Marcado',
            SamsaraEvent::HUMAN_STATUS_RESOLVED => 'Resuelto',
            SamsaraEvent::HUMAN_STATUS_FALSE_POSITIVE => 'Falso positivo',
            default => 'Desconocido',
        };
    }

    /**
     * Label para action de actividad.
     */
    private function actionLabel(string $action): string
    {
        return match ($action) {
            SamsaraEventActivity::ACTION_AI_PROCESSING_STARTED => 'AI inició procesamiento',
            SamsaraEventActivity::ACTION_AI_COMPLETED => 'AI completó análisis',
            SamsaraEventActivity::ACTION_AI_FAILED => 'AI falló',
            SamsaraEventActivity::ACTION_AI_INVESTIGATING => 'AI en investigación',
            SamsaraEventActivity::ACTION_AI_REVALIDATED => 'AI revalidó',
            SamsaraEventActivity::ACTION_HUMAN_REVIEWED => 'Revisado por humano',
            SamsaraEventActivity::ACTION_HUMAN_STATUS_CHANGED => 'Estado cambiado',
            SamsaraEventActivity::ACTION_COMMENT_ADDED => 'Comentario agregado',
            SamsaraEventActivity::ACTION_MARKED_FALSE_POSITIVE => 'Marcado como falso positivo',
            SamsaraEventActivity::ACTION_MARKED_RESOLVED => 'Marcado como resuelto',
            SamsaraEventActivity::ACTION_MARKED_FLAGGED => 'Marcado para seguimiento',
            default => 'Actividad',
        };
    }

    /**
     * Icono para action de actividad.
     */
    private function actionIcon(string $action): string
    {
        return match ($action) {
            SamsaraEventActivity::ACTION_AI_PROCESSING_STARTED => 'cpu',
            SamsaraEventActivity::ACTION_AI_COMPLETED => 'check-circle',
            SamsaraEventActivity::ACTION_AI_FAILED => 'x-circle',
            SamsaraEventActivity::ACTION_AI_INVESTIGATING => 'search',
            SamsaraEventActivity::ACTION_AI_REVALIDATED => 'refresh-cw',
            SamsaraEventActivity::ACTION_HUMAN_REVIEWED => 'eye',
            SamsaraEventActivity::ACTION_HUMAN_STATUS_CHANGED => 'toggle-right',
            SamsaraEventActivity::ACTION_COMMENT_ADDED => 'message-square',
            SamsaraEventActivity::ACTION_MARKED_FALSE_POSITIVE => 'slash',
            SamsaraEventActivity::ACTION_MARKED_RESOLVED => 'check-circle-2',
            SamsaraEventActivity::ACTION_MARKED_FLAGGED => 'flag',
            default => 'activity',
        };
    }

    /**
     * Reprocesar una alerta con AI.
     * 
     * POST /api/events/{id}/reprocess
     * 
     * Solo disponible para super_admin.
     * Resetea el estado del evento y lo encola nuevamente para procesamiento.
     */
    public function reprocess(Request $request, SamsaraEvent $event): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        // Solo super_admin puede reprocesar
        if (!$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para reprocesar alertas',
            ], 403);
        }

        // Log de la acción
        Log::info('Admin reprocessing alert', [
            'event_id' => $event->id,
            'samsara_event_id' => $event->samsara_event_id,
            'user_id' => $user->id,
            'user_email' => $user->email,
            'previous_status' => $event->ai_status,
        ]);

        // Guardar estado anterior para el registro de actividad
        $previousStatus = $event->ai_status;
        $previousAssessment = $event->ai_assessment;

        // Resetear el evento para reprocesamiento
        $event->update([
            'ai_status' => SamsaraEvent::STATUS_PENDING,
            'ai_assessment' => null,
            'ai_message' => null,
            'ai_actions' => null,
            'alert_context' => null,
            'notification_decision' => null,
            'notification_execution' => null,
            'investigation_count' => 0,
            'investigation_history' => null,
            'last_investigation_at' => null,
            'next_check_minutes' => null,
        ]);

        // Registrar actividad
        $event->activities()->create([
            'action' => 'reprocessed_by_admin',
            'user_id' => $user->id,
            'metadata' => [
                'previous_status' => $previousStatus,
                'previous_verdict' => $previousAssessment['verdict'] ?? null,
                'reason' => 'Manual reprocess requested by admin',
            ],
        ]);

        // Encolar el job de procesamiento
        ProcessSamsaraEventJob::dispatch($event);

        Log::info('Alert queued for reprocessing', [
            'event_id' => $event->id,
            'queued_by' => $user->email,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'La alerta ha sido encolada para reprocesamiento',
            'data' => [
                'id' => $event->id,
                'ai_status' => $event->ai_status,
                'ai_status_label' => 'Pendiente',
                'queued_at' => now()->toIso8601String(),
            ],
        ]);
    }
}

