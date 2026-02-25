<?php

namespace App\Http\Controllers;

use App\Http\Requests\Alerts\AddCommentRequest;
use App\Http\Requests\Alerts\AssignRequest;
use App\Http\Requests\Alerts\CloseAttentionRequest;
use App\Http\Requests\Alerts\UpdateStatusRequest;
use App\Jobs\ProcessAlertJob;
use App\Models\Alert;
use App\Models\AlertActivity;
use App\Models\AlertComment;
use App\Models\NotificationAck;
use App\Models\User;
use App\Services\AlertDisplayService;
use App\Services\AttentionEngine;
use App\Services\DomainEventEmitter;
use Laravel\Pennant\Feature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AlertReviewController extends Controller
{
    /**
     * PATCH /api/alerts/{alert}/status
     */
    public function updateStatus(UpdateStatusRequest $request, Alert $alert): JsonResponse
    {
        $validated = $request->validated();

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

        $display = app(AlertDisplayService::class);

        return response()->json([
            'success' => true,
            'message' => 'Estado actualizado correctamente',
            'data' => [
                'id' => $alert->id,
                'human_status' => $alert->human_status,
                'human_status_label' => $display->humanStatusLabel($alert->human_status),
                'reviewed_by' => $user->name,
                'reviewed_at' => $alert->reviewed_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/alerts/{alert}/comments
     */
    public function addComment(AddCommentRequest $request, Alert $alert): JsonResponse
    {
        $validated = $request->validated();

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
        $display = app(AlertDisplayService::class);

        $activities = $alert->activities()
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (AlertActivity $activity) => [
                'id' => $activity->id,
                'action' => $activity->action,
                'action_label' => $display->actionLabel($activity->action),
                'action_icon' => $display->actionIcon($activity->action),
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
        $display = app(AlertDisplayService::class);

        return response()->json([
            'success' => true,
            'data' => [
                'human_status' => $alert->human_status,
                'human_status_label' => $display->humanStatusLabel($alert->human_status),
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
    public function assign(AssignRequest $request, Alert $alert): JsonResponse
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

        $validated = $request->validated();

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
    public function closeAttention(CloseAttentionRequest $request, Alert $alert): JsonResponse
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

        $validated = $request->validated();

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
