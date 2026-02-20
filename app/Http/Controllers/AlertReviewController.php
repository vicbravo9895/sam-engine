<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessAlertJob;
use App\Models\Alert;
use App\Models\AlertActivity;
use App\Models\AlertComment;
use App\Models\NotificationAck;
use App\Models\User;
use App\Services\AttentionEngine;
use App\Services\DomainEventEmitter;
use Laravel\Pennant\Feature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AlertReviewController extends Controller
{
    /**
     * PATCH /api/alerts/{alert}/status
     */
    public function updateStatus(Request $request, Alert $alert): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([
                Alert::HUMAN_STATUS_PENDING,
                Alert::HUMAN_STATUS_REVIEWED,
                Alert::HUMAN_STATUS_FLAGGED,
                Alert::HUMAN_STATUS_RESOLVED,
                Alert::HUMAN_STATUS_FALSE_POSITIVE,
            ])],
        ]);

        $user = Auth::user();
        $previousStatus = $alert->human_status;
        $alert->setHumanStatus($validated['status'], $user->id);

        DomainEventEmitter::emit(
            companyId: $alert->company_id,
            entityType: 'alert',
            entityId: (string) $alert->id,
            eventType: 'alert.human_reviewed',
            payload: [
                'previous_status' => $previousStatus,
                'new_status' => $validated['status'],
            ],
            actorType: 'user',
            actorId: (string) $user->id,
        );

        return response()->json([
            'success' => true,
            'message' => 'Estado actualizado correctamente',
            'data' => [
                'id' => $alert->id,
                'human_status' => $alert->human_status,
                'human_status_label' => $this->humanStatusLabel($alert->human_status),
                'reviewed_by' => $user->name,
                'reviewed_at' => $alert->reviewed_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/alerts/{alert}/comments
     */
    public function addComment(Request $request, Alert $alert): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
        ]);

        $user = Auth::user();
        $comment = $alert->addComment($user->id, $validated['content']);

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
     * GET /api/alerts/{alert}/comments
     */
    public function getComments(Alert $alert): JsonResponse
    {
        $comments = $alert->comments()
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AlertComment $comment) => [
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
     * GET /api/alerts/{alert}/activities
     */
    public function getActivities(Alert $alert): JsonResponse
    {
        $activities = $alert->activities()
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (AlertActivity $activity) => [
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
     * GET /api/alerts/{alert}/review
     */
    public function getReviewSummary(Alert $alert): JsonResponse
    {
        $alert->load(['reviewedBy:id,name', 'comments.user:id,name']);

        return response()->json([
            'success' => true,
            'data' => [
                'human_status' => $alert->human_status,
                'human_status_label' => $this->humanStatusLabel($alert->human_status),
                'reviewed_by' => $alert->reviewedBy ? [
                    'id' => $alert->reviewedBy->id,
                    'name' => $alert->reviewedBy->name,
                ] : null,
                'reviewed_at' => $alert->reviewed_at?->toIso8601String(),
                'reviewed_at_human' => $alert->reviewed_at?->diffForHumans(),
                'needs_attention' => $alert->needs_attention,
                'urgency_level' => $alert->getHumanUrgencyLevel(),
                'comments_count' => $alert->comments->count(),
                'recent_comments' => $alert->comments->take(3)->map(fn ($c) => [
                    'id' => $c->id,
                    'content' => $c->content,
                    'user' => $c->user ? ['id' => $c->user->id, 'name' => $c->user->name] : null,
                    'created_at_human' => $c->created_at->diffForHumans(),
                ])->values(),
            ],
        ]);
    }

    /**
     * POST /api/alerts/{alert}/ack
     */
    public function acknowledge(Request $request, Alert $alert): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $company = $alert->company;

        if (!$company || !Feature::for($company)->active('notifications-v2')) {
            return response()->json([
                'success' => false,
                'message' => 'Acknowledgement no disponible para esta empresa',
            ], 422);
        }

        $existing = NotificationAck::where('alert_id', $alert->id)
            ->where('ack_type', NotificationAck::TYPE_UI)
            ->where('ack_by_user_id', $user->id)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => true,
                'message' => 'Ya confirmaste esta alerta',
                'data' => [
                    'ack_id' => $existing->id,
                    'acked_at' => $existing->created_at->toIso8601String(),
                ],
            ]);
        }

        $ack = NotificationAck::create([
            'alert_id' => $alert->id,
            'company_id' => $alert->company_id,
            'ack_type' => NotificationAck::TYPE_UI,
            'ack_by_user_id' => $user->id,
            'ack_payload' => [
                'source' => 'web_ui',
                'user_agent' => $request->userAgent(),
            ],
            'created_at' => now(),
        ]);

        DomainEventEmitter::emit(
            companyId: $alert->company_id,
            entityType: 'notification',
            entityId: (string) $alert->id,
            eventType: 'notification.acked',
            payload: [
                'ack_type' => 'ui',
                'ack_id' => $ack->id,
                'user_id' => $user->id,
                'user_name' => $user->name,
            ],
            actorType: 'user',
            actorId: (string) $user->id,
            correlationId: (string) $alert->id,
        );

        if (Feature::for($company)->active('attention-engine-v1')) {
            app(AttentionEngine::class)->acknowledge($alert, $user);
        }

        AlertActivity::logHumanAction(
            $alert->id,
            $alert->company_id,
            $user->id,
            'notification_acked_via_ui',
            ['user_name' => $user->name]
        );

        $alert->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Alerta confirmada',
            'data' => [
                'ack_id' => $ack->id,
                'acked_at' => $ack->created_at->toIso8601String(),
                'acked_by' => $user->name,
                'attention_state' => $alert->attention_state,
            ],
        ]);
    }

    /**
     * POST /api/alerts/{alert}/assign
     */
    public function assign(Request $request, Alert $alert): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $company = $alert->company;

        if (!$company || !Feature::for($company)->active('attention-engine-v1')) {
            return response()->json([
                'success' => false,
                'message' => 'Attention Engine no disponible para esta empresa',
            ], 422);
        }

        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
        ]);

        if (empty($validated['user_id']) && empty($validated['contact_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Se requiere user_id o contact_id',
            ], 422);
        }

        app(AttentionEngine::class)->assignOwner(
            alert: $alert,
            userId: $validated['user_id'] ?? null,
            contactId: $validated['contact_id'] ?? null,
            assignedBy: $user,
        );

        $alert->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Responsable asignado',
            'data' => [
                'owner_user_id' => $alert->owner_user_id,
                'owner_contact_id' => $alert->owner_contact_id,
                'assigned_by' => $user->name,
            ],
        ]);
    }

    /**
     * POST /api/alerts/{alert}/close-attention
     */
    public function closeAttention(Request $request, Alert $alert): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $company = $alert->company;

        if (!$company || !Feature::for($company)->active('attention-engine-v1')) {
            return response()->json([
                'success' => false,
                'message' => 'Attention Engine no disponible para esta empresa',
            ], 422);
        }

        if ($alert->attention_state === Alert::ATTENTION_CLOSED) {
            return response()->json([
                'success' => true,
                'message' => 'La atención ya fue cerrada',
            ]);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        app(AttentionEngine::class)->closeAttention($alert, $user, $validated['reason']);

        $alert->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Atención cerrada',
            'data' => [
                'attention_state' => Alert::ATTENTION_CLOSED,
                'resolved_at' => $alert->resolved_at?->toIso8601String(),
                'closed_by' => $user->name,
            ],
        ]);
    }

    /**
     * Label para human_status.
     */
    private function humanStatusLabel(string $status): string
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
     * Label para action de actividad.
     */
    private function actionLabel(string $action): string
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
     * Icono para action de actividad.
     */
    private function actionIcon(string $action): string
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

    /**
     * POST /api/alerts/{alert}/reprocess
     */
    public function reprocess(Request $request, Alert $alert): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if (!$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para reprocesar alertas',
            ], 403);
        }

        Log::info('Admin reprocessing alert', [
            'alert_id' => $alert->id,
            'user_id' => $user->id,
            'user_email' => $user->email,
            'previous_status' => $alert->ai_status,
        ]);

        $previousStatus = $alert->ai_status;
        $previousVerdict = $alert->verdict;

        $alert->update([
            'ai_status' => Alert::STATUS_PENDING,
            'ai_message' => null,
            'verdict' => null,
            'likelihood' => null,
            'confidence' => null,
            'reasoning' => null,
            'notification_decision_payload' => null,
            'notification_execution' => null,
        ]);

        $ai = $alert->ai;
        if ($ai) {
            $ai->update([
                'ai_assessment' => null,
                'ai_actions' => null,
                'ai_error' => null,
                'alert_context' => null,
                'investigation_count' => 0,
                'investigation_history' => null,
                'last_investigation_at' => null,
                'next_check_minutes' => null,
            ]);
        }

        AlertActivity::logHumanAction(
            $alert->id,
            $alert->company_id,
            $user->id,
            'reprocessed_by_admin',
            [
                'previous_status' => $previousStatus,
                'previous_verdict' => $previousVerdict,
                'reason' => 'Manual reprocess requested by admin',
            ]
        );

        ProcessAlertJob::dispatch($alert);

        return response()->json([
            'success' => true,
            'message' => 'La alerta ha sido encolada para reprocesamiento',
            'data' => [
                'id' => $alert->id,
                'ai_status' => $alert->ai_status,
                'ai_status_label' => 'Pendiente',
                'queued_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
