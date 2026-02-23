<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\AlertAi;
use App\Models\DomainEvent;
use App\Models\Signal;
use App\Models\User;
use App\Services\DomainEventEmitter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AlertController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        
        $filters = [
            'search' => trim((string) $request->input('search', '')),
            'severity' => (string) $request->input('severity', ''),
            'status' => (string) $request->input('status', ''),
            'event_type' => (string) $request->input('event_type', ''),
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
            'attention' => (string) $request->input('attention', ''),
        ];

        $query = Alert::query()->with(['signal', 'ownerUser', 'ai']);

        if ($user->company_id) {
            $query->forCompany($user->company_id);
        }
        
        $query->orderByDesc('occurred_at')
            ->orderByDesc('created_at');

        if ($filters['search'] !== '') {
            $term = mb_strtolower($filters['search']);
            $query->whereHas('signal', function ($sq) use ($term) {
                $like = "%{$term}%";
                $sq->where(function ($inner) use ($like) {
                    $inner->whereRaw('LOWER(samsara_event_id) LIKE ?', [$like])
                          ->orWhereRaw('LOWER(vehicle_name) LIKE ?', [$like])
                          ->orWhereRaw('LOWER(driver_name) LIKE ?', [$like])
                          ->orWhereRaw('LOWER(event_type) LIKE ?', [$like]);
                });
            });
        }

        if ($filters['severity'] !== '') {
            $query->where('severity', $filters['severity']);
        }

        if ($filters['status'] !== '') {
            $query->where('ai_status', $filters['status']);
        }

        if ($filters['event_type'] !== '') {
            $query->whereHas('signal', fn ($sq) => $sq->where('event_type', $filters['event_type']));
        }

        if ($filters['date_from'] !== '') {
            $this->applyDateFilter($query, '>=', $filters['date_from']);
        }

        if ($filters['date_to'] !== '') {
            $this->applyDateFilter($query, '<=', $filters['date_to']);
        }

        if ($filters['attention'] === 'actionable') {
            $query->where(function ($q) {
                $q->where('human_status', Alert::HUMAN_STATUS_PENDING)
                    ->where(function ($inner) {
                        $inner->whereIn('ai_status', ['failed', 'investigating'])
                              ->orWhere('severity', Alert::SEVERITY_CRITICAL)
                              ->orWhereIn('risk_escalation', [Alert::RISK_CALL, Alert::RISK_EMERGENCY]);
                    });
            });
        } elseif ($filters['attention'] === 'pending') {
            $query->humanPending();
        }

        $events = $query
            ->paginate(12)
            ->withQueryString()
            ->through(fn (Alert $alert) => $this->formatAlertListItem($alert));

        $companyScope = fn ($q) => $user->company_id ? $q->forCompany($user->company_id) : $q;

        $stats = [
            'total' => Alert::query()->tap($companyScope)->count(),
            'critical' => Alert::query()->tap($companyScope)->critical()->count(),
            'investigating' => Alert::query()->tap($companyScope)->investigating()->count(),
            'completed' => Alert::query()->tap($companyScope)->completed()->count(),
            'failed' => Alert::query()->tap($companyScope)->failed()->count(),
            'needs_attention' => Alert::query()->tap($companyScope)->needsAttention()->count(),
            'human_pending' => Alert::query()->tap($companyScope)->humanPending()->count(),
            'human_reviewed' => Alert::query()->tap($companyScope)->whereIn('human_status', [
                Alert::HUMAN_STATUS_REVIEWED,
                Alert::HUMAN_STATUS_RESOLVED,
                Alert::HUMAN_STATUS_FALSE_POSITIVE,
            ])->count(),
        ];

        $eventTypes = Signal::query()
            ->when($user->company_id, fn ($q) => $q->forCompany($user->company_id))
            ->whereNotNull('event_type')
            ->distinct('event_type')
            ->orderBy('event_type')
            ->pluck('event_type')
            ->filter()
            ->values();

        $filterOptions = [
            'severities' => [
                ['label' => 'Todas', 'value' => ''],
                ['label' => 'Informativa', 'value' => 'info'],
                ['label' => 'Advertencia', 'value' => 'warning'],
                ['label' => 'Crítica', 'value' => 'critical'],
            ],
            'statuses' => [
                ['label' => 'Todos', 'value' => ''],
                ['label' => 'Pendiente', 'value' => 'pending'],
                ['label' => 'En proceso', 'value' => 'processing'],
                ['label' => 'Investigando', 'value' => 'investigating'],
                ['label' => 'Completado', 'value' => 'completed'],
                ['label' => 'Falló', 'value' => 'failed'],
            ],
            'event_types' => $eventTypes
                ->map(fn($type) => [
                    'label' => $this->alertTypeLabel($type),
                    'value' => $type,
                ])
                ->values()
                ->all(),
        ];

        return Inertia::render('samsara/events/index', [
            'events' => $events,
            'filters' => $filters,
            'filterOptions' => $filterOptions,
            'stats' => $stats,
        ]);
    }

    /**
     * T4 — Attention Center: lista única "Requieren atención" ordenada por prioridad.
     */
    public function workbenchAttention(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $companyId = $request->input('company_id') ?? $user->company_id;
        if (!$companyId) {
            return response()->json(['error' => 'company_id required'], 400);
        }
        if ($user->company_id && (int) $user->company_id !== (int) $companyId) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $perPage = max(1, min(50, (int) $request->input('per_page', 15)));
        $query = Alert::query()
            ->with(['signal', 'ownerUser'])
            ->forCompany((int) $companyId)
            ->needsAttention()
            ->orderByAttentionPriority();

        $events = $query->paginate($perPage)->withQueryString()->through(fn (Alert $alert) => $this->formatAlertListItem($alert));

        return response()->json([
            'data' => $events->items(),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
                'from' => $events->firstItem(),
                'to' => $events->lastItem(),
            ],
            'links' => [
                'first' => $events->url(1),
                'last' => $events->url($events->lastPage()),
                'prev' => $events->previousPageUrl(),
                'next' => $events->nextPageUrl(),
            ],
        ]);
    }

    /**
     * V2: Format an Alert for list views (index and workbench/attention).
     */
    protected function formatAlertListItem(Alert $alert): array
    {
        $signal = $alert->signal;
        $eventType = $signal?->event_type;
        $rawPayload = $signal?->raw_payload ?? [];
        $alertType = $rawPayload['alertType'] ?? $eventType;

        $ai = $alert->relationLoaded('ai') ? $alert->ai : null;
        $aiAssessment = $ai?->ai_assessment;
        $aiActions = $ai?->ai_actions;
        // Fallback: use normalized verdict/likelihood on alerts when ai_assessment is missing (e.g. old data)
        if (empty($aiAssessment) && ($alert->verdict !== null || $alert->likelihood !== null)) {
            $aiAssessment = array_filter([
                'verdict' => $alert->verdict,
                'likelihood' => $alert->likelihood,
            ]);
        }
        $assessment = $this->formatAssessment($aiAssessment);

        $displayEventType = $signal?->event_description ?: $this->alertTypeLabel($alertType);
        return [
            'id' => $alert->id,
            'signal_id' => $alert->signal_id,
            'samsara_event_id' => $signal?->samsara_event_id,
            'event_type' => $eventType,
            'event_description' => $signal?->event_description,
            'event_title' => $displayEventType,
            'event_icon' => $this->getEventIcon($alertType, $signal?->event_description),
            'severity' => $alert->severity,
            'severity_label' => $this->severityLabel($alert->severity),
            'ai_status' => $alert->ai_status,
            'ai_status_label' => $this->statusLabel($alert->ai_status),
            'vehicle_name' => $signal?->vehicle_name,
            'driver_name' => $signal?->driver_name,
            'occurred_at' => optional($alert->occurred_at)?->toIso8601String(),
            'occurred_at_human' => optional($alert->occurred_at)?->diffForHumans(),
            'created_at' => $alert->created_at->toIso8601String(),
            'ai_message_preview' => is_string($assessment['reasoning'] ?? null)
                ? $assessment['reasoning']
                : Str::limit((string) $alert->ai_message, 180),
            'ai_assessment_view' => $assessment,
            'verdict_summary' => $this->getVerdictSummary($assessment),
            'investigation_summary' => $this->getInvestigationSummary($aiActions),
            'has_images' => $this->eventHasImages($aiActions),
            'investigation_metadata' => $alert->ai_status === 'investigating'
                ? [
                    'count' => $ai?->investigation_count ?? 0,
                    'max_investigations' => Alert::getMaxInvestigations(),
                ]
                : null,
            'human_status' => $alert->human_status,
            'human_status_label' => $this->humanStatusLabel($alert->human_status ?? 'pending'),
            'needs_attention' => $alert->needs_attention,
            'urgency_level' => $alert->getHumanUrgencyLevel(),
            'attention_state' => $alert->attention_state,
            'ack_status' => $alert->ack_status,
            'ack_due_at' => $alert->ack_due_at?->toIso8601String(),
            'owner_name' => $alert->ownerUser?->name,
            'escalation_level' => $alert->escalation_level,
            'escalation_count' => $alert->escalation_count,
        ];
    }

    public function show(Request $request, Alert $alert): Response
    {
        $user = $request->user();
        
        if ($user->company_id && $alert->company_id !== $user->company_id) {
            abort(404);
        }

        $alert->load([
            'signal',
            'ai',
            'metrics',
            'comments.user',
            'activities',
            'notificationResults.deliveryEvents',
            'reviewedBy',
            'ownerUser',
            'ownerContact',
        ]);

        $signal = $alert->signal;
        $ai = $alert->ai;

        $rawPayload = $signal?->raw_payload ?? [];
        $aiActions = $this->normalizeAiActions($ai?->ai_actions);
        $assessment = $ai?->ai_assessment ?? null;
        $assessmentView = $this->formatAssessment($assessment);

        $timelineCollection = collect($aiActions['agents'])
            ->map(function ($agent, $index) {
                $agentKey = $agent['name'] ?? 'pipeline';
                $metadata = $this->agentMetadata($agentKey);

                $tools = collect($agent['tools_used'] ?? [])
                    ->map(function ($tool) {
                        $toolName = $tool['tool_name'] ?? '';

                        return [
                            'tool_name' => $this->toolLabel($toolName),
                            'raw_tool_name' => $toolName,
                            'called_at' => $tool['called_at'] ?? null,
                            'duration_ms' => $tool['duration_ms'] ?? null,
                            'status' => $tool['status'] ?? null,
                            'status_label' => $this->toolStatusLabel($tool['status'] ?? null),
                            'result_summary' => $this->summarizeToolResult($toolName, $tool),
                            'details' => $tool['details'] ?? null,
                            'media_urls' => $tool['media_urls'] ?? [], // Preserve persisted image URLs
                        ];
                    })
                    ->values()
                    ->all();

                $outputSummary = $this->summarizeAgentOutput($agentKey, $agent['output_summary'] ?? null);

                return [
                    'step' => $index + 1,
                    'name' => $agentKey,
                    'title' => $metadata['title'],
                    'description' => $metadata['description'],
                    'started_at' => $agent['started_at'] ?? null,
                    'completed_at' => $agent['completed_at'] ?? null,
                    'duration_ms' => $agent['duration_ms'] ?? null,
                    'summary' => $outputSummary['summary'],
                    'summary_details' => $outputSummary['details'],
                    'tools_used' => $tools,
                ];
            });

        $timeline = $timelineCollection->values()->all();

        // Extract media insights with persisted image URLs (from timeline tools or preloaded camera_analysis)
        $mediaInsights = $timelineCollection
            ->flatMap(fn($agent) => collect($agent['tools_used']))
            ->filter(fn($tool) => ($tool['raw_tool_name'] ?? '') === 'get_camera_media')
            ->flatMap(function ($tool) {
                $mediaUrls = $tool['media_urls'] ?? [];
                $analyses = $tool['details']['analyses'] ?? [];

                // If we have persisted URLs but no analyses, create entries from URLs
                if (!empty($mediaUrls) && empty($analyses)) {
                    return collect($mediaUrls)->map(fn($url, $idx) => [
                        'camera' => 'Cámara ' . ($idx + 1),
                        'analysis' => null,
                        'analysis_preview' => null,
                        'url' => $url,
                        'download_url' => $url,
                    ]);
                }

                // Combine analyses with their corresponding persisted URLs
                return collect($analyses)->map(function ($analysis, $idx) use ($mediaUrls) {
                    $persistedUrl = $mediaUrls[$idx] ?? null;
                    return [
                        'camera' => $this->cameraLabel($analysis['camera'] ?? $analysis['input'] ?? null),
                        'analysis' => $analysis['analysis_preview'] ?? $analysis['analysis'] ?? null,
                        'analysis_preview' => $analysis['analysis_preview'] ?? $analysis['analysis'] ?? null,
                        'url' => $persistedUrl ?? $analysis['url'] ?? null,
                        'download_url' => $persistedUrl ?? $analysis['download_url'] ?? $analysis['url'] ?? null,
                    ];
                });
            })
            ->values()
            ->all();

        // When pipeline uses preloaded camera data (no get_camera_media in timeline), build media_insights from camera_analysis
        if (empty($mediaInsights) && !empty($aiActions['camera_analysis'])) {
            $mediaInsights = $this->mediaInsightsFromCameraAnalysis($aiActions['camera_analysis']);
        }

        $eventType = $signal?->event_type;
        $eventDescription = $signal?->event_description;
        $displayEventType = $eventDescription ?: $this->alertTypeLabel($rawPayload['alertType'] ?? $eventType);

        $payloadSummary = collect([
            [
                'label' => 'Tipo de alerta',
                'value' => $displayEventType,
            ],
            [
                'label' => 'Ubicación aproximada',
                'value' => data_get($rawPayload, 'location.label')
                    ?? data_get($rawPayload, 'vehicle.location.name')
                    ?? data_get($rawPayload, 'vehicle.lastKnownLocation'),
            ],
            [
                'label' => 'Hora del evento (UTC)',
                'value' => $rawPayload['eventTime'] ?? optional($alert->occurred_at)?->toIso8601String(),
            ],
            [
                'label' => 'Cámara analizada',
                'value' => $this->cameraLabel(data_get($rawPayload, 'camera.name')),
            ],
        ])
            ->filter(fn($item) => filled($item['value']))
            ->values()
            ->all();

        $investigationActions = $this->categorizeTools($timelineCollection);
        $verdictBadge = $this->getVerdictBadge($assessmentView);

        $aiActions['agents'] = $timeline;

        $alertContext = $ai?->alert_context;
        if ($alertContext && isset($alertContext['notification_contacts'])) {
            $contacts = $alertContext['notification_contacts'];
            $allEmpty = empty($contacts['operator']['phone'] ?? null) 
                && empty($contacts['monitoring_team']['phone'] ?? null) 
                && empty($contacts['supervisor']['phone'] ?? null);
            $alertContext['notification_contacts']['missing_contacts'] = $allEmpty;
        }

        if ($alertContext && !empty($alertContext['behavior_label'])) {
            $alertContext['behavior_label_translated'] = $this->behaviorLabelTranslation($alertContext['behavior_label']);
        }

        $unifiedTimeline = $this->buildUnifiedTimeline($alert);

        return Inertia::render('samsara/events/show', [
            'event' => [
                'id' => $alert->id,
                'signal_id' => $alert->signal_id,
                'samsara_event_id' => $signal?->samsara_event_id,
                'event_type' => $eventType,
                'event_description' => $eventDescription,
                'display_event_type' => $displayEventType,
                'event_icon' => $this->getEventIcon($eventType, $eventDescription),
                'severity' => $alert->severity,
                'severity_label' => $this->severityLabel($alert->severity),
                'ai_status' => $alert->ai_status,
                'ai_status_label' => $this->statusLabel($alert->ai_status),
                'vehicle_name' => $signal?->vehicle_name,
                'vehicle_id' => $signal?->vehicle_id,
                'driver_name' => $signal?->driver_name,
                'driver_id' => $signal?->driver_id,
                'occurred_at' => optional($alert->occurred_at)?->toIso8601String(),
                'ai_assessment' => $assessment,
                'ai_assessment_view' => $assessmentView,
                'verdict_badge' => $verdictBadge,
                'ai_message' => $alert->ai_message,
                'ai_actions' => $aiActions,
                'raw_payload' => $rawPayload,
                'payload_summary' => $payloadSummary,
                'timeline' => $timeline,
                'unified_timeline' => $unifiedTimeline,
                'media_insights' => $mediaInsights,
                'investigation_metadata' => [
                    'count' => $ai?->investigation_count ?? 0,
                    'last_check' => optional($ai?->last_investigation_at)?->diffForHumans(),
                    'last_check_at' => optional($ai?->last_investigation_at)?->toIso8601String(),
                    'next_check_minutes' => $ai?->next_check_minutes,
                    'next_check_available_at' => $ai?->last_investigation_at && $ai?->next_check_minutes
                        ? $ai->last_investigation_at
                            ->copy()
                            ->addMinutes($ai->next_check_minutes)
                            ->toIso8601String()
                        : null,
                    'history' => $ai?->investigation_history ?? [],
                    'max_investigations' => Alert::getMaxInvestigations(),
                ],
                'investigation_actions' => $investigationActions,
                'alert_context' => $alertContext,
                'notification_decision' => $this->notificationDecisionWithDisplayReason($alert->notification_decision_payload),
                'notification_execution' => $alert->notification_execution,
                'risk_escalation' => $alert->risk_escalation ?? ($assessment['risk_escalation'] ?? null),
                'proactive_flag' => $alert->proactive_flag ?? ($alertContext['proactive_flag'] ?? null),
                'dedupe_key' => $alert->dedupe_key ?? ($assessment['dedupe_key'] ?? null),
                'recommended_actions' => $alert->getRecommendedActionsArray(),
                'investigation_steps' => $alert->getInvestigationStepsArray(),
                'notification_status' => $alert->notification_status,
                'notification_status_label' => $this->notificationStatusLabel($alert->notification_status),
                'call_response' => $alert->call_response,
                'notification_channels' => $alert->notification_channels,
                'notification_sent_at' => $alert->notification_sent_at?->toIso8601String(),
                'human_status' => $alert->human_status ?? 'pending',
                'human_status_label' => $this->humanStatusLabel($alert->human_status ?? 'pending'),
                'reviewed_by' => $alert->reviewedBy ? [
                    'id' => $alert->reviewedBy->id,
                    'name' => $alert->reviewedBy->name,
                ] : null,
                'reviewed_at' => $alert->reviewed_at?->toIso8601String(),
                'reviewed_at_human' => $alert->reviewed_at?->diffForHumans(),
                'needs_attention' => $alert->needs_attention,
                'urgency_level' => $alert->getHumanUrgencyLevel(),
                'comments_count' => $alert->comments->count(),
                'attention_state' => $alert->attention_state,
                'ack_status' => $alert->ack_status,
                'ack_due_at' => $alert->ack_due_at?->toIso8601String(),
                'acked_at' => $alert->acked_at?->toIso8601String(),
                'resolve_due_at' => $alert->resolve_due_at?->toIso8601String(),
                'resolved_at' => $alert->resolved_at?->toIso8601String(),
                'owner_user_id' => $alert->owner_user_id,
                'owner_name' => $alert->ownerUser?->name,
                'owner_contact_name' => $alert->ownerContact?->name,
                'escalation_level' => $alert->escalation_level,
                'escalation_count' => $alert->escalation_count,
            ],
            'breadcrumbs' => [
                [
                    'title' => 'Alertas Samsara',
                    'href' => route('samsara.alerts.index'),
                ],
                [
                    'title' => $this->alertTypeLabel($eventType),
                    'href' => route('samsara.alerts.show', $alert),
                ],
            ],
            'companyUsers' => $user->company_id
                ? User::where('company_id', $user->company_id)
                    ->select('id', 'name')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
                    ->values()
                : [],
        ]);
    }

    private function applyDateFilter($query, string $operator, string $value): void
    {
        try {
            $date = Carbon::parse($value);
            $query->where('occurred_at', $operator, $operator === '>=' ? $date->startOfDay() : $date->endOfDay());
        } catch (\Throwable $e) {
            // Ignorar fechas inválidas
        }
    }

    private function normalizeAiActions(?array $actions): array
    {
        $actions ??= [];

        $normalized = [
            'agents' => collect($actions['agents'] ?? [])->map(function ($agent) {
                // Support both 'tools' (new format) and 'tools_used' (old format)
                $rawTools = $agent['tools'] ?? $agent['tools_used'] ?? [];

                $agent['tools_used'] = collect($rawTools)->map(function ($tool) {
                    // Normalize tool structure: support both 'name' and 'tool_name'
                    return [
                        'tool_name' => $tool['name'] ?? $tool['tool_name'] ?? null,
                        'duration_ms' => $tool['duration_ms'] ?? null,
                        'status' => $tool['status'] ?? 'success',
                        'called_at' => $tool['called_at'] ?? null,
                        'result_summary' => $tool['summary'] ?? $tool['result_summary'] ?? null,
                        'details' => $tool['details'] ?? null,
                        'media_urls' => $tool['media_urls'] ?? [], // Preserve media_urls
                    ];
                })->values()->all();

                return $agent;
            })->values()->all(),
            'total_duration_ms' => $actions['total_duration_ms'] ?? 0,
            'total_tools_called' => $actions['total_tools_called'] ?? 0,
        ];
        
        // Preserve camera_analysis if it exists (contains local URLs after persistence)
        if (!empty($actions['camera_analysis'])) {
            $normalized['camera_analysis'] = $actions['camera_analysis'];
        }
        
        return $normalized;
    }

    private function formatAssessment(?array $assessment): ?array
    {
        if (!is_array($assessment) || empty($assessment)) {
            return null;
        }

        // Normalizar reasoning a string
        $reasoning = $assessment['reasoning'] ?? null;
        if (is_array($reasoning)) {
            $reasoning = json_encode($reasoning, JSON_UNESCAPED_UNICODE);
        }

        return [
            'verdict' => $this->assessmentVerdictLabel($this->extractStringValue($assessment['verdict'] ?? null)),
            'likelihood' => $this->assessmentLikelihoodLabel($this->extractStringValue($assessment['likelihood'] ?? null)),
            'reasoning' => $reasoning,
            'evidence' => $this->mapAssessmentEvidence($assessment['supporting_evidence'] ?? []),
        ];
    }

    private function alertTypeLabel(?string $type): string
    {
        $normalized = Str::of((string) $type)->lower()->value();

        return match ($normalized) {
            'panic_button', 'panicbutton' => 'Botón de pánico',
            'alertincident' => 'Incidente reportado',
            'safety_event' => 'Evento de seguridad',
            default => $type ? Str::headline($type) : 'Alerta de Samsara',
        };
    }

    private function severityLabel(?string $severity): string
    {
        return match ($severity) {
            Alert::SEVERITY_CRITICAL => 'Crítica',
            Alert::SEVERITY_WARNING => 'Advertencia',
            default => 'Informativa',
        };
    }

    private function statusLabel(?string $status): string
    {
        return match ($status) {
            Alert::STATUS_COMPLETED => 'Completada',
            Alert::STATUS_PROCESSING => 'En proceso',
            Alert::STATUS_INVESTIGATING => 'Investigando',
            Alert::STATUS_FAILED => 'Falló',
            default => 'Pendiente',
        };
    }

    private function agentMetadata(string $name): array
    {
        $dictionary = [
            // Nuevos nombres de agentes (contrato actualizado)
            'triage_agent' => [
                'title' => 'Triaje de alerta',
                'description' => 'Clasificando el tipo de alerta y extrayendo datos clave.',
            ],
            'investigator_agent' => [
                'title' => 'Investigación técnica',
                'description' => 'Analizando evidencia con herramientas de Samsara y Vision AI.',
            ],
            'final_agent' => [
                'title' => 'Mensaje operativo',
                'description' => 'Preparando la comunicación para el equipo de monitoreo.',
            ],
            'notification_decision_agent' => [
                'title' => 'Decisión de notificación',
                'description' => 'Determinando canales y destinatarios según nivel de riesgo.',
            ],
            // Aliases legacy para compatibilidad
            'ingestion_agent' => [
                'title' => 'Triaje de alerta',
                'description' => 'Clasificando el tipo de alerta y extrayendo datos clave.',
            ],
            'panic_investigator' => [
                'title' => 'Investigación técnica',
                'description' => 'Analizando evidencia con herramientas de Samsara y Vision AI.',
            ],
        ];

        return $dictionary[$name] ?? [
            'title' => 'Paso del pipeline',
            'description' => 'Ejecución automática del flujo de AI.',
        ];
    }

    private function toolLabel(?string $name): string
    {
        return match ($name) {
            'get_vehicle_stats' => 'Estadísticas del vehículo',
            'get_vehicle_info' => 'Ficha del vehículo',
            'get_driver_assignment' => 'Conductor asignado',
            'get_camera_media' => 'Material de cámaras',
            'get_safety_events' => 'Eventos de seguridad',
            default => $name ? Str::headline($name) : 'Tool',
        };
    }

    private function toolStatusLabel(?string $status): string
    {
        return match ($status) {
            'success' => 'Completada',
            'error' => 'Con error',
            default => 'Sin estado',
        };
    }

    private function cameraLabel(?string $camera): ?string
    {
        return match ($camera) {
            null, '' => null,
            'dashcamDriverFacing' => 'Cámara interna (conductor)',
            'dashcamRoadFacing' => 'Cámara frontal (carretera)',
            default => Str::headline($camera),
        };
    }

    /**
     * Build media_insights from preloaded camera_analysis (when pipeline did not use get_camera_media tool).
     */
    private function mediaInsightsFromCameraAnalysis(array $cameraAnalysis): array
    {
        $analyses = $cameraAnalysis['analyses'] ?? [];
        $mediaUrls = $cameraAnalysis['media_urls'] ?? [];

        if (empty($analyses) && empty($mediaUrls)) {
            return [];
        }

        // Prefer analyses (one entry per image with optional analysis text)
        if (! empty($analyses)) {
            return collect($analyses)->map(function ($analysis, $idx) use ($mediaUrls) {
                $url = $mediaUrls[$idx] ?? $analysis['samsara_url'] ?? $analysis['url'] ?? null;
                $analysisText = $this->previewFromCameraAnalysisItem($analysis);
                $cameraName = $this->cameraLabel($analysis['input'] ?? $analysis['camera'] ?? null)
                    ?? 'Imagen ' . ($idx + 1);

                return [
                    'camera' => $cameraName,
                    'analysis' => $analysisText,
                    'analysis_preview' => $analysisText ? Str::limit($analysisText, 120) : null,
                    'url' => $url,
                    'download_url' => $url,
                ];
            })->values()->all();
        }

        // Fallback: only URLs, no analyses
        return collect($mediaUrls)->map(fn ($url, $idx) => [
            'camera' => 'Imagen ' . ($idx + 1),
            'analysis' => null,
            'analysis_preview' => null,
            'url' => $url,
            'download_url' => $url,
        ])->values()->all();
    }

    private function previewFromCameraAnalysisItem(array $item): ?string
    {
        if (! empty($item['scene_description'])) {
            return (string) $item['scene_description'];
        }
        if (! empty($item['recommendation']['reason'])) {
            return (string) $item['recommendation']['reason'];
        }
        $raw = $item['analysis'] ?? null;
        if (is_string($raw) && strlen($raw) < 500 && ! str_contains($raw, '"')) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/"scene_description"\s*:\s*"([^"]+)"/', $raw, $m)) {
            return $m[1];
        }
        if (is_string($raw) && preg_match('/"reason"\s*:\s*"([^"]+)"/', $raw, $m)) {
            return $m[1];
        }

        return null;
    }

    private function assessmentVerdictLabel(?string $verdict): string
    {
        return match ($verdict) {
            // Falsos positivos
            'likely_false_positive', 'false_positive' => 'Probable falso positivo',
            'confirmed_false_positive' => 'Falso positivo confirmado',
            
            // Verdaderos positivos
            'likely_true_positive', 'true_positive' => 'Probable verdadero positivo',
            'confirmed_true_positive' => 'Verdadero positivo confirmado',
            
            // Requiere revisión
            'needs_review', 'needs_human_review', 'pending_review' => 'Requiere revisión manual',
            'inconclusive' => 'Resultado inconcluso',
            
            // Sin acción necesaria
            'no_action_needed', 'dismissed' => 'Sin acción necesaria',
            
            // Monitor
            'monitor', 'monitoring' => 'En monitoreo',
            
            // Nuevos verdicts del AI Service (Problema 1)
            'uncertain' => 'En monitoreo - Información insuficiente',
            'real_panic' => 'Pánico real - Emergencia confirmada',
            'risk_detected' => 'Riesgo detectado - Posible manipulación',
            'confirmed_violation' => 'Violación confirmada',
            'resolved' => 'Resuelto - Sin acción necesaria',
            'escalated' => 'Escalado a supervisión',
            
            default => ucfirst((string) ($verdict ?? 'Sin veredicto')),
        };
    }

    private function assessmentLikelihoodLabel(?string $likelihood): string
    {
        return match ($likelihood) {
            'low' => 'Baja',
            'medium' => 'Media',
            'high' => 'Alta',
            default => ucfirst((string) ($likelihood ?? 'Sin dato')),
        };
    }

    /**
     * Etiqueta amigable para nivel de riesgo (risk_escalation).
     * El usuario debe entender: qué implica cada nivel sin jerga técnica.
     */
    private function riskEscalationLabel(?string $level): string
    {
        if (! $level) {
            return 'Sin dato';
        }
        $normalized = Str::of((string) $level)->lower()->value();
        return match ($normalized) {
            'monitor' => 'Solo monitoreo (no requiere notificación)',
            'warn' => 'Advertencia',
            'call' => 'Requiere llamada',
            'emergency' => 'Emergencia',
            default => ucfirst((string) $level),
        };
    }

    /**
     * Traduce la razón técnica de la decisión de notificación a un texto claro para el usuario.
     */
    private function notificationReasonLabel(?string $reason): string
    {
        if (! $reason) {
            return 'Sin detalle';
        }
        $r = Str::of($reason)->lower()->value();
        if (str_contains($r, 'monitor') && (str_contains($r, 'no requiere') || str_contains($r, 'notificacion'))) {
            return 'Riesgo bajo: solo monitoreo. No se envía notificación.';
        }
        if (str_contains($r, 'nivel monitor') || $r === 'nivel monitor') {
            return 'Riesgo bajo: solo monitoreo. No se envía notificación.';
        }
        if (str_contains($r, 'sin contactos') || str_contains($r, 'no hay contactos')) {
            return 'No hay contactos configurados para notificar.';
        }
        if (str_contains($r, 'falso positivo') || str_contains($r, 'false positive')) {
            return 'Probable falso positivo: solo monitoreo.';
        }
        if (str_contains($r, 'escalacion') && str_contains($r, 'notificar')) {
            return $reason;
        }
        return $reason;
    }

    /**
     * Añade reason_display (texto amigable) al payload de decisión de notificación.
     */
    private function notificationDecisionWithDisplayReason(?array $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }
        $payload['reason_display'] = $this->notificationReasonLabel($payload['reason'] ?? null);

        return $payload;
    }

    private function mapAssessmentEvidence(array $evidence): array
    {
        $labels = [
            // New short keys from AI assessment
            'vehicle' => 'Datos del vehículo',
            'info' => 'Información general',
            'safety' => 'Eventos de seguridad',
            'camera' => 'Análisis de cámaras',
            // Legacy keys
            'vehicle_stats_summary' => 'Resumen de estadísticas del vehículo',
            'vehicle_info_summary' => 'Ficha del vehículo',
            'safety_events_summary' => 'Eventos de seguridad detectados',
            'camera_summary' => 'Hallazgos de cámaras',
        ];

        // Keys que contienen objetos complejos y deben ser omitidos o procesados especialmente
        $complexKeys = [
            'payload_driver',
            'assignment_driver', 
            'data_consistency',
            'camera',
        ];

        $items = [];
        foreach ($evidence as $key => $value) {
            if (!filled($value)) {
                continue;
            }

            // Omitir keys complejas que tienen objetos anidados
            if (in_array($key, $complexKeys)) {
                // Procesar camera especialmente para extraer visual_summary
                if ($key === 'camera' && is_array($value)) {
                    $visualSummary = $value['visual_summary'] ?? null;
                    if (filled($visualSummary)) {
                        $items[] = [
                            'label' => 'Análisis de cámaras',
                            'value' => $this->normalizeEvidenceValue($visualSummary),
                        ];
                    }
                }
                continue;
            }

            $items[] = [
                'label' => $labels[$key] ?? Str::headline((string) $key),
                'value' => $this->normalizeEvidenceValue($value),
            ];
        }

        return $items;
    }

    /**
     * Extrae un valor string de un campo que puede ser string u objeto {id, name}.
     */
    private function extractStringValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            // Si tiene 'name', usar ese valor
            if (isset($value['name'])) {
                return (string) $value['name'];
            }
            // Si solo tiene 'id', usar ese
            if (isset($value['id'])) {
                return (string) $value['id'];
            }
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }

        return '';
    }

    /**
     * Normaliza un valor de evidencia a string para renderizar en React.
     * Convierte objetos y arrays a strings legibles.
     */
    private function normalizeEvidenceValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            // Si es un objeto con 'name', usar el name
            if (isset($value['name'])) {
                return (string) $value['name'];
            }
            
            // Si es un objeto con 'id' y 'name', formatear
            if (isset($value['id']) && isset($value['name'])) {
                return (string) $value['name'];
            }

            // Si es un array de strings, unir con comas
            if (array_is_list($value)) {
                $stringItems = array_filter($value, fn($v) => is_string($v) || is_numeric($v));
                if (count($stringItems) === count($value)) {
                    return implode(', ', $value);
                }
            }

            // Fallback: convertir a JSON legible
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: 'Datos complejos';
        }

        if (is_null($value)) {
            return 'Sin información';
        }

        return (string) $value;
    }

    private function summarizeAgentOutput(string $agentName, ?string $raw): array
    {
        if (!filled($raw)) {
            return [
                'summary' => 'Sin información generada para este paso.',
                'details' => [],
            ];
        }

        $raw = trim((string) $raw);
        $data = $this->decodeAgentOutputJson($raw);

        if (!is_array($data)) {
            return match ($agentName) {
                'final_agent' => [
                    'summary' => $this->finalAgentSummary($raw),
                    'details' => [],
                ],
                default => [
                    'summary' => 'Resumen no disponible para este paso.',
                    'details' => [],
                ],
            };
        }

        // Triage agent (nuevo nombre) o ingestion_agent (legacy)
        if (in_array($agentName, ['triage_agent', 'ingestion_agent'])) {
            $details = [];
            if (!empty($data['alert_type'])) {
                $details[] = [
                    'label' => 'Tipo detectado',
                    'value' => $this->alertTypeLabel($this->extractStringValue($data['alert_type'])),
                ];
            }
            if (!empty($data['alert_kind'])) {
                $details[] = [
                    'label' => 'Categoría',
                    'value' => ucfirst($this->extractStringValue($data['alert_kind'])),
                ];
            }
            if (!empty($data['vehicle_name'])) {
                $details[] = ['label' => 'Unidad', 'value' => $this->extractStringValue($data['vehicle_name'])];
            }
            
            $driverName = $this->extractStringValue($data['driver_name'] ?? null);
            $details[] = [
                'label' => 'Operador',
                'value' => (filled($driverName) && $driverName !== 'unknown')
                    ? $driverName
                    : 'No identificado',
            ];
            
            if (!empty($data['severity_level'])) {
                $details[] = [
                    'label' => 'Severidad',
                    'value' => ucfirst($this->extractStringValue($data['severity_level'])),
                ];
            }
            if (!empty($data['proactive_flag'])) {
                $details[] = [
                    'label' => 'Tipo',
                    'value' => 'Alerta proactiva',
                ];
            }

            return [
                'summary' => 'Datos clave identificados a partir del webhook.',
                'details' => $details,
            ];
        }

        // Investigator agent (nuevo nombre) o panic_investigator (legacy)
        if (in_array($agentName, ['investigator_agent', 'panic_investigator'])) {
            // Nuevo formato: datos directos en el root
            $assessment = $data['panic_assessment'] ?? $data;
            $verdict = $this->assessmentVerdictLabel($assessment['verdict'] ?? null);
            $likelihood = $this->assessmentLikelihoodLabel($assessment['likelihood'] ?? null);
            $details = $this->mapAssessmentEvidence($assessment['supporting_evidence'] ?? []);

            // Agregar información de riesgo si existe (etiqueta amigable para el usuario)
            if (!empty($assessment['risk_escalation'])) {
                $details[] = [
                    'label' => 'Nivel de riesgo',
                    'value' => $this->riskEscalationLabel($assessment['risk_escalation']),
                ];
            }

            return [
                'summary' => "Veredicto: {$verdict}. Probabilidad: {$likelihood}. " .
                    ($assessment['reasoning'] ?? ''),
                'details' => $details,
            ];
        }

        if ($agentName === 'final_agent') {
            return [
                'summary' => $data['message'] ?? $raw,
                'details' => [],
            ];
        }

        // Notification decision agent
        if ($agentName === 'notification_decision_agent') {
            $shouldNotify = $data['should_notify'] ?? false;
            $escalation = $data['escalation_level'] ?? 'none';
            $channels = $data['channels_to_use'] ?? [];
            $reasonRaw = $data['reason'] ?? 'nivel monitor';
            $reasonFriendly = $this->notificationReasonLabel($reasonRaw);

            return [
                'summary' => $shouldNotify
                    ? 'Se enviará notificación por ' . implode(', ', $channels)
                    : 'No se envía notificación. ' . $reasonFriendly,
                'details' => [],
            ];
        }

        return [
            'summary' => 'Resultado registrado por la AI.',
            'details' => [],
        ];
    }

    private function finalAgentSummary(string $message): string
    {
        $message = trim($message);

        if ($message === '') {
            return 'Mensaje final no disponible.';
        }

        $lines = preg_split('/\r?\n/', $message);
        $headline = array_shift($lines);
        $body = implode(' ', $lines);

        return trim(($headline ? "{$headline}. " : '') . $body);
    }

    private function summarizeToolResult(string $toolName, array $tool): string
    {
        $status = $tool['status'] ?? null;
        $base = match ($toolName) {
            'get_vehicle_stats' => 'Consultó estadísticas recientes del vehículo.',
            'get_vehicle_info' => 'Revisó la ficha técnica del vehículo.',
            'get_driver_assignment' => 'Buscó asignaciones de conductor cercanas al evento.',
            'get_camera_media' => 'Descargó material de cámaras para análisis visual.',
            'get_safety_events' => 'Revisó eventos de seguridad en la ventana de tiempo del evento.',
            default => 'Ejecución de tool.',
        };

        if ($toolName === 'get_camera_media' && isset($tool['details']['analyses'])) {
            $count = count($tool['details']['analyses']);
            if ($count > 0) {
                $base .= " Analizó {$count} imagen" . ($count > 1 ? 'es' : '');
            }
        }

        if ($status === 'error') {
            return $base . ' No se obtuvo respuesta: ' . ($tool['result_summary'] ?? 'error no especificado.');
        }

        return $base;
    }

    private function decodeAgentOutputJson(string $raw): ?array
    {
        $attempts = [$raw];

        if (str_contains($raw, '"raw_payload"')) {
            $candidate = Str::before($raw, '"raw_payload"');
            $candidate = rtrim($candidate, ", \n\r\t");
            $attempts[] = $candidate . "\n}";
        }

        if (str_contains($raw, '"supporting_evidence"')) {
            $candidate = Str::before($raw, '"supporting_evidence"');
            $candidate = rtrim($candidate, ", \n\r\t{");
            $attempts[] = $candidate . "\n  }\n}";
        }

        foreach ($attempts as $candidate) {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function getEventIcon(?string $type, ?string $eventDescription = null): string
    {
        // When Samsara sends AlertIncident, the real subtype is in event_description (e.g. "Botón de pánico").
        if ($eventDescription && stripos($eventDescription, 'pánico') !== false) {
            return 'alert-circle';
        }

        $normalized = Str::of((string) $type)->lower()->value();

        return match ($normalized) {
            'panic_button', 'panicbutton' => 'alert-circle',
            'alertincident' => 'alert-triangle',
            'safety_event' => 'shield-alert',
            default => 'bell',
        };
    }

    private function getVerdictSummary(?array $assessment): ?array
    {
        if (!$assessment || !isset($assessment['verdict'])) {
            return null;
        }

        $verdict = $assessment['verdict'];
        $likelihood = $assessment['likelihood'] ?? null;

        $urgency = $this->determineVerdictUrgency($verdict);

        return [
            'verdict' => $verdict,
            'likelihood' => $likelihood,
            'urgency' => $urgency,
        ];
    }

    /**
     * Determina la urgencia basándose en el veredicto traducido.
     */
    private function determineVerdictUrgency(string $verdict): string
    {
        // Urgencia alta - requiere atención inmediata
        $highUrgency = [
            'Probable verdadero positivo',
            'Verdadero positivo confirmado',
            // Nuevos verdicts de alta urgencia (Problema 2)
            'Pánico real - Emergencia confirmada',
            'Violación confirmada',
            'Escalado a supervisión',
        ];

        // Urgencia media - requiere revisión
        $mediumUrgency = [
            'Requiere revisión manual',
            'Resultado inconcluso',
            'En monitoreo',
            // Nuevos verdicts de urgencia media (Problema 2)
            'En monitoreo - Información insuficiente',
            'Riesgo detectado - Posible manipulación',
        ];

        // Urgencia baja - sin acción necesaria
        // Todo lo demás se considera baja urgencia (incluyendo 'Resuelto - Sin acción necesaria')

        if (in_array($verdict, $highUrgency, true)) {
            return 'high';
        }

        if (in_array($verdict, $mediumUrgency, true)) {
            return 'medium';
        }

        return 'low';
    }

    private function getInvestigationSummary(?array $aiActions): array
    {
        if (!is_array($aiActions) || empty($aiActions['agents'])) {
            return [];
        }

        $summary = [];
        $agents = $aiActions['agents'] ?? [];

        foreach ($agents as $agent) {
            $tools = $agent['tools_used'] ?? [];
            foreach ($tools as $tool) {
                $toolName = $tool['tool_name'] ?? '';
                $status = $tool['status'] ?? null;

                if ($status === 'success') {
                    $category = match ($toolName) {
                        'get_vehicle_stats' => 'vehicle_data',
                        'get_vehicle_info' => 'vehicle_data',
                        'get_driver_assignment' => 'driver_data',
                        'get_camera_media' => 'visual_analysis',
                        default => 'other',
                    };

                    if (!isset($summary[$category])) {
                        $summary[$category] = [
                            'label' => $this->getCategoryLabel($category),
                            'items' => [],
                        ];
                    }

                    $summary[$category]['items'][] = $this->toolLabel($toolName);
                }
            }
        }

        return array_values($summary);
    }

    private function getCategoryLabel(string $category): string
    {
        return match ($category) {
            'vehicle_data' => 'Datos del vehículo',
            'driver_data' => 'Información del conductor',
            'visual_analysis' => 'Análisis visual',
            default => 'Otros datos',
        };
    }

    private function categorizeTools($timelineCollection): array
    {
        $categories = [
            'vehicle_data' => [
                'label' => 'Datos del Vehículo',
                'icon' => 'truck',
                'items' => [],
            ],
            'driver_data' => [
                'label' => 'Información del Conductor',
                'icon' => 'user',
                'items' => [],
            ],
            'visual_analysis' => [
                'label' => 'Análisis Visual',
                'icon' => 'camera',
                'items' => [],
            ],
            'safety_data' => [
                'label' => 'Eventos de Seguridad',
                'icon' => 'shield-alert',
                'items' => [],
            ],
        ];

        $timelineCollection
            ->flatMap(fn($agent) => collect($agent['tools_used']))
            ->filter(fn($tool) => ($tool['status_label'] ?? '') === 'Completada')
            ->each(function ($tool) use (&$categories) {
                $toolName = $tool['raw_tool_name'] ?? $tool['tool_name'] ?? '';
                $category = match ($toolName) {
                    'get_vehicle_stats', 'get_vehicle_info' => 'vehicle_data',
                    'get_driver_assignment' => 'driver_data',
                    'get_camera_media' => 'visual_analysis',
                    'get_safety_events' => 'safety_data',
                    default => null,
                };

                if ($category && isset($categories[$category])) {
                    $categories[$category]['items'][] = [
                        'name' => $tool['tool_name'] ?? 'Herramienta',
                        'summary' => $tool['result_summary'] ?? '',
                        'details' => $tool['details'] ?? null,
                    ];
                }
            });

        return array_values(array_filter($categories, fn($cat) => !empty($cat['items'])));
    }

    private function getVerdictBadge(?array $assessment): array
    {
        if (!$assessment) {
            return [
                'verdict' => 'Sin evaluación',
                'likelihood' => null,
                'urgency' => 'unknown',
                'color' => 'slate',
            ];
        }

        $verdict = $assessment['verdict'] ?? 'Sin veredicto';
        $likelihood = $assessment['likelihood'] ?? null;

        $urgency = $this->determineVerdictUrgency($verdict);

        $color = match ($urgency) {
            'high' => 'red',
            'medium' => 'amber',
            'low' => 'emerald',
            default => 'slate',
        };

        return [
            'verdict' => $verdict,
            'likelihood' => $likelihood,
            'urgency' => $urgency,
            'color' => $color,
        ];
    }

    private function eventHasImages(?array $aiActions): bool
    {
        if (!is_array($aiActions) || empty($aiActions['agents'])) {
            return false;
        }

        foreach ($aiActions['agents'] as $agent) {
            $tools = $agent['tools'] ?? $agent['tools_used'] ?? [];
            foreach ($tools as $tool) {
                $mediaUrls = $tool['media_urls'] ?? [];
                if (!empty($mediaUrls)) {
                    return true;
                }
            }
        }

        return false;
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
     * Label para notification_status (callback de pánico).
     */
    private function notificationStatusLabel(?string $status): ?string
    {
        if (!$status) {
            return null;
        }
        
        return match ($status) {
            'panic_confirmed' => 'Pánico Confirmado',
            'false_alarm' => 'Falsa Alarma',
            'operator_no_response' => 'Sin Respuesta',
            'escalated' => 'Escalado',
            'pending' => 'Pendiente',
            'sent' => 'Enviado',
            'failed' => 'Fallido',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    /**
     * Traduce behavior_label del AI al español (Problema 4).
     */
    private function behaviorLabelTranslation(?string $label): string
    {
        return match ($label) {
            'passenger' => 'Detección de pasajero',
            'panic' => 'Botón de pánico',
            'distraction' => 'Distracción del conductor',
            'drowsiness' => 'Somnolencia detectada',
            'phone_use' => 'Uso de teléfono',
            'seatbelt' => 'Cinturón de seguridad',
            'smoking' => 'Fumar detectado',
            'obstruction' => 'Obstrucción de cámara',
            'harsh_braking' => 'Frenado brusco',
            'harsh_acceleration' => 'Aceleración brusca',
            'harsh_cornering' => 'Giro brusco',
            'speeding' => 'Exceso de velocidad',
            'collision' => 'Colisión detectada',
            'rollover' => 'Volcadura detectada',
            default => $label ? Str::headline($label) : 'Sin clasificar',
        };
    }

    /**
     * Get analytics data for the events dashboard.
     */
    public function analytics(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $days = (int) $request->input('days', 30);
        $startDate = now()->subDays($days)->startOfDay();
        $companyId = $user->company_id;

        $alertScope = fn ($q) => $q->when($companyId, fn($q2) => $q2->forCompany($companyId))
            ->where('occurred_at', '>=', $startDate);

        $signalScope = fn ($q) => $q->when($companyId, fn($q2) => $q2->forCompany($companyId))
            ->where('occurred_at', '>=', $startDate);
        
        $eventsByType = Signal::query()->tap($signalScope)
            ->selectRaw('event_description, COUNT(*) as count')
            ->groupBy('event_description')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'label' => $row->event_description ?? 'Sin descripción',
                'value' => $row->count,
            ]);
        
        $topVehicles = Signal::query()->tap($signalScope)
            ->whereNotNull('vehicle_name')
            ->selectRaw('vehicle_id, vehicle_name, COUNT(*) as count')
            ->groupBy('vehicle_id', 'vehicle_name')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'id' => $row->vehicle_id,
                'name' => $row->vehicle_name,
                'count' => $row->count,
            ]);
        
        $topDrivers = Signal::query()->tap($signalScope)
            ->whereNotNull('driver_name')
            ->where('driver_name', '!=', '')
            ->selectRaw('driver_id, driver_name, COUNT(*) as count')
            ->groupBy('driver_id', 'driver_name')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'id' => $row->driver_id,
                'name' => $row->driver_name,
                'count' => $row->count,
            ]);
        
        $eventsBySeverity = Alert::query()->tap($alertScope)
            ->selectRaw('severity, COUNT(*) as count')
            ->groupBy('severity')
            ->get()
            ->mapWithKeys(fn($row) => [$row->severity => $row->count]);
        
        $eventsByVerdict = Alert::query()->tap($alertScope)
            ->whereNotNull('verdict')
            ->selectRaw('verdict, COUNT(*) as count')
            ->groupBy('verdict')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'verdict' => $row->verdict,
                'label' => $this->assessmentVerdictLabel($row->verdict),
                'count' => $row->count,
            ]);
        
        $eventsByDay = Alert::query()->tap($alertScope)
            ->selectRaw("DATE(occurred_at) as date, COUNT(*) as count")
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn($row) => [
                'date' => $row->date,
                'count' => $row->count,
            ]);
        
        $eventsByHour = Alert::query()->tap($alertScope)
            ->selectRaw("EXTRACT(HOUR FROM occurred_at) as hour, COUNT(*) as count")
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->map(fn($row) => [
                'hour' => (int) $row->hour,
                'count' => $row->count,
            ]);
        
        $totalEvents = Alert::query()->tap($alertScope)->count();
        
        $falsePositiveCount = Alert::query()->tap($alertScope)
            ->where(function ($q) {
                $q->where('verdict', 'likely_false_positive')
                  ->orWhere('verdict', 'no_action_needed')
                  ->orWhere('human_status', Alert::HUMAN_STATUS_FALSE_POSITIVE);
            })
            ->count();
        
        $realAlertCount = Alert::query()->tap($alertScope)
            ->whereIn('verdict', ['real_panic', 'confirmed_violation', 'risk_detected'])
            ->count();
        
        return response()->json([
            'period_days' => $days,
            'period_start' => $startDate->toIso8601String(),
            'summary' => [
                'total_events' => $totalEvents,
                'false_positives' => $falsePositiveCount,
                'false_positive_rate' => $totalEvents > 0 
                    ? round(($falsePositiveCount / $totalEvents) * 100, 1) 
                    : 0,
                'real_alerts' => $realAlertCount,
                'real_alert_rate' => $totalEvents > 0 
                    ? round(($realAlertCount / $totalEvents) * 100, 1) 
                    : 0,
            ],
            'events_by_type' => $eventsByType,
            'top_vehicles' => $topVehicles,
            'top_drivers' => $topDrivers,
            'events_by_severity' => $eventsBySeverity,
            'events_by_verdict' => $eventsByVerdict,
            'events_by_day' => $eventsByDay,
            'events_by_hour' => $eventsByHour,
        ]);
    }

    /**
     * Build a unified chronological timeline merging all event sources.
     */
    private function buildUnifiedTimeline(Alert $alert): array
    {
        $entries = collect();

        $signal = $alert->signal;
        $signalEventLabel = $signal?->event_description ?: $this->alertTypeLabel($signal?->event_type);
        $entries->push([
            'type' => 'signal',
            'icon' => 'radio',
            'title' => 'Señal recibida',
            'description' => $signalEventLabel . ' — ' . ($signal?->vehicle_name ?? 'Vehículo desconocido'),
            'timestamp' => $alert->created_at->toIso8601String(),
            'actor' => 'system',
        ]);

        if ($alert->metrics?->ai_started_at) {
            $entries->push([
                'type' => 'ai',
                'icon' => 'cpu',
                'title' => 'Análisis iniciado',
                'description' => null,
                'timestamp' => $alert->metrics->ai_started_at->toIso8601String(),
                'actor' => 'system',
            ]);
        }

        if ($alert->metrics?->ai_finished_at) {
            $latency = $alert->metrics->pipeline_latency_ms
                ? number_format($alert->metrics->pipeline_latency_ms) . 'ms'
                : null;
            $entries->push([
                'type' => 'ai',
                'icon' => 'check-circle',
                'title' => 'Análisis completado',
                'description' => $latency ? "Latencia: {$latency}" : null,
                'timestamp' => $alert->metrics->ai_finished_at->toIso8601String(),
                'actor' => 'system',
            ]);
        }

        foreach ($alert->notificationResults ?? collect() as $nr) {
            $errorLabel = $nr->success ? null : ($this->twilioErrorToSpanish($nr->error) ?? $nr->error ?? 'Error desconocido');
            $description = $nr->to_number . ($errorLabel !== null ? ' (fallido: ' . $errorLabel . ')' : '');
            $entries->push([
                'type' => 'notification',
                'icon' => 'send',
                'title' => 'Notificación enviada — ' . strtoupper($nr->channel),
                'description' => $description,
                'timestamp' => ($nr->timestamp_utc ?? $nr->created_at)->toIso8601String(),
                'actor' => 'system',
            ]);

            foreach ($nr->deliveryEvents ?? collect() as $de) {
                $description = $de->error_message
                    ? ($this->twilioErrorToSpanish($de->error_message) ?? $de->error_message)
                    : null;
                $entries->push([
                    'type' => 'notification_status',
                    'icon' => $de->isTerminal() ? ($de->status === 'delivered' || $de->status === 'read' ? 'check' : 'x') : 'clock',
                    'title' => $this->deliveryEventStatusLabel($de->status),
                    'description' => $description,
                    'timestamp' => $de->received_at->toIso8601String(),
                    'actor' => 'twilio',
                ]);
            }
        }

        foreach ($alert->activities ?? collect() as $activity) {
            $entries->push([
                'type' => 'activity',
                'icon' => 'user',
                'title' => $this->activityActionLabel($activity->action),
                'description' => $this->activityDescription($activity->action, $activity->metadata),
                'timestamp' => $activity->created_at->toIso8601String(),
                'actor' => $activity->user_id ? ('user:' . $activity->user_id) : 'system',
            ]);
        }

        try {
            $domainEvents = DomainEvent::forEntity('alert', (string) $alert->id)
                ->forCompany($alert->company_id)
                ->chronological()
                ->limit(100)
                ->get();

            // Skip domain events that duplicate an activity we already show (assign, close, ack, escalate).
            $domainEventTypesSkippedWhenActivityExists = [
                'alert.assigned',
                'alert.attention_closed',
                'alert.attention_acked',
                'alert.attention_escalated',
            ];

            foreach ($domainEvents as $de) {
                if (in_array($de->event_type, $domainEventTypesSkippedWhenActivityExists, true)) {
                    continue;
                }
                $entries->push([
                    'type' => 'domain_event',
                    'icon' => 'bookmark',
                    'title' => $this->domainEventTitleLabel($de->event_type),
                    'description' => $this->domainEventDescription($de->event_type, $de->payload),
                    'timestamp' => $de->occurred_at->toIso8601String(),
                    'actor' => $de->actor_type ? ($de->actor_type . ':' . $de->actor_id) : 'system',
                ]);
            }
        } catch (\Throwable $e) {
            Log::debug('buildUnifiedTimeline: domain events fetch failed', ['error' => $e->getMessage()]);
        }

        $sorted = $entries->sortBy('timestamp')->values();

        return $this->collapseConsecutiveTimelineDuplicates($sorted->all());
    }

    /**
     * Agrupa entradas consecutivas con mismo tipo y título en una sola (ej. "Alerta escalada (3 veces)").
     */
    private function collapseConsecutiveTimelineDuplicates(array $entries): array
    {
        if ($entries === []) {
            return [];
        }
        $out = [];
        $current = null;
        $count = 0;
        foreach ($entries as $entry) {
            $key = ($entry['type'] ?? '') . '|' . ($entry['title'] ?? '');
            if ($current !== null && $current === $key) {
                $count++;
                continue;
            }
            if ($current !== null) {
                $last = &$out[array_key_last($out)];
                if ($count > 1) {
                    $last['title'] = $last['title'] . ' (' . $count . ' veces)';
                }
            }
            $current = $key;
            $count = 1;
            $out[] = $entry;
        }
        if ($current !== null && $count > 1) {
            $out[array_key_last($out)]['title'] = $out[array_key_last($out)]['title'] . ' (' . $count . ' veces)';
        }
        return $out;
    }

    private function activityActionLabel(string $action): string
    {
        return match ($action) {
            'human_status_changed' => 'Estado actualizado',
            'comment_added' => 'Comentario agregado',
            'attention_acked' => 'Alerta reconocida',
            'attention_assigned' => 'Propietario asignado',
            'attention_escalated' => 'Alerta escalada',
            'attention_closed' => 'Atención cerrada',
            'notification_acked_via_ui' => 'Notificación confirmada',
            'notification_acked_via_reply' => 'Notificación confirmada por respuesta',
            'reprocessed' => 'Reprocesada',
            'reprocessed_by_admin' => 'Reprocesada por administrador',
            'ai_processing_started' => 'Análisis iniciado',
            'ai_completed' => 'Análisis completado',
            'ai_failed' => 'Error en el análisis',
            'ai_investigating' => 'En revisión por la IA',
            'ai_revalidated' => 'Revisión de seguimiento',
            'human_reviewed' => 'Revisado por una persona',
            'marked_false_positive' => 'Marcado como falso positivo',
            'marked_resolved' => 'Marcado como resuelto',
            'marked_flagged' => 'Marcado para seguimiento',
            'alert_escalated' => 'Alerta escalada',
            'escalated' => 'Alerta escalada',
            default => 'Evento del sistema',
        };
    }

    private function deliveryEventStatusLabel(string $status): string
    {
        return match (strtolower($status)) {
            'delivered' => 'Mensaje entregado',
            'read' => 'Mensaje leído',
            'sent' => 'Mensaje enviado',
            'failed', 'undelivered' => 'No se pudo entregar',
            default => 'Estado: ' . $status,
        };
    }

    /**
     * Traduce mensajes de error de Twilio al español para la línea de tiempo.
     */
    private function twilioErrorToSpanish(?string $error): ?string
    {
        if ($error === null || $error === '') {
            return null;
        }
        $e = strtolower($error);
        if (str_contains($e, 'account not authorized to call') || str_contains($e, 'not authorized to call')) {
            return 'Cuenta no autorizada para llamar a este número. Revisa los permisos de llamadas internacionales en Twilio.';
        }
        if (str_contains($e, 'international permissions') || str_contains($e, 'geo-permissions')) {
            return 'Permisos de llamadas internacionales no habilitados en Twilio.';
        }
        if (str_contains($e, 'invalid phone number') || str_contains($e, 'invalid parameter')) {
            return 'Número de teléfono no válido.';
        }
        if (str_contains($e, 'unable to create record') || str_contains($e, 'resource not found')) {
            return 'No se pudo completar la operación (recurso no encontrado).';
        }
        if (str_contains($e, 'authenticate') || str_contains($e, 'authentication')) {
            return 'Error de autenticación con Twilio.';
        }
        if (str_contains($e, 'busy') || str_contains($e, 'no-answer')) {
            return 'Llamada no contestada o ocupado.';
        }
        return null;
    }

    /**
     * Human-readable description for activity metadata (timeline).
     */
    private function activityDescription(string $action, ?array $metadata): ?string
    {
        if (! is_array($metadata) || empty($metadata)) {
            return null;
        }

        return match ($action) {
            'attention_assigned' => $this->formatAttentionAssignedDescription($metadata),
            'attention_closed' => $this->formatAttentionClosedDescription($metadata),
            'attention_acked' => isset($metadata['user_name']) ? "Reconocida por {$metadata['user_name']}" : null,
            'human_status_changed' => $this->formatHumanStatusChangedDescription($metadata),
            'comment_added' => isset($metadata['comment_id']) ? 'Comentario agregado' : null,
            'notification_acked_via_ui' => isset($metadata['user_name']) ? "Confirmada por {$metadata['user_name']}" : null,
            'notification_acked_via_reply' => 'Confirmada por respuesta del destinatario',
            'reprocessed_by_admin' => isset($metadata['user_name']) ? "Reprocesada por {$metadata['user_name']}" : null,
            default => null,
        };
    }

    private function formatAttentionAssignedDescription(array $m): ?string
    {
        $by = $m['assigned_by_name'] ?? null;
        $ownerId = $m['owner_user_id'] ?? null;
        if ($by !== null && $ownerId !== null) {
            $owner = User::find($ownerId);
            $ownerName = $owner?->name ?? "Usuario #{$ownerId}";
            return "Asignado a {$ownerName} por {$by}";
        }
        if ($by !== null) {
            return "Asignado por {$by}";
        }
        return null;
    }

    private function formatAttentionClosedDescription(array $m): ?string
    {
        $user = $m['user_name'] ?? null;
        $reason = $m['reason'] ?? null;
        if ($user !== null && $reason !== null) {
            return "Cerrada por {$user}: {$reason}";
        }
        if ($user !== null) {
            return "Cerrada por {$user}";
        }
        if ($reason !== null) {
            return $reason;
        }
        return null;
    }

    private function formatHumanStatusChangedDescription(array $m): ?string
    {
        $old = $m['old_status'] ?? null;
        $new = $m['new_status'] ?? null;
        if ($old !== null && $new !== null) {
            return $this->humanStatusLabel($old) . ' → ' . $this->humanStatusLabel($new);
        }
        return null;
    }

    private function domainEventTitleLabel(string $eventType): string
    {
        return match ($eventType) {
            'alert.assigned' => 'Propietario asignado',
            'alert.attention_closed' => 'Atención cerrada',
            'alert.attention_acked' => 'Alerta reconocida',
            'alert.attention_escalated' => 'Alerta escalada',
            'alert.acked' => 'Alerta reconocida',
            'alert.processing_started' => 'Análisis iniciado',
            'alert.investigating' => 'En revisión por la IA',
            'alert.attention_initialized' => 'En seguimiento',
            'alert.completed' => 'Análisis completado',
            'alert.failed' => 'Error en el análisis',
            'alert.revalidation_started' => 'Revisión de seguimiento',
            'alert.revalidation_completed' => 'Revisión de seguimiento completada',
            'alert.human_reviewed' => 'Revisado por una persona',
            default => 'Evento del sistema',
        };
    }

    /**
     * Human-readable description for domain event payload (timeline).
     */
    private function domainEventDescription(string $eventType, ?array $payload): ?string
    {
        if (! is_array($payload) || empty($payload)) {
            return null;
        }

        return match ($eventType) {
            'alert.assigned' => $this->formatAttentionAssignedDescription($payload),
            'alert.attention_closed' => $this->formatAttentionClosedDescription(
                ['user_name' => null, 'reason' => $payload['reason'] ?? null]
            ) ?: (isset($payload['reason']) ? (string) $payload['reason'] : null),
            default => null,
        };
    }
}
