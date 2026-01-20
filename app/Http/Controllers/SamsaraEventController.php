<?php

namespace App\Http\Controllers;

use App\Models\SamsaraEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SamsaraEventController extends Controller
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
        ];

        $query = SamsaraEvent::query();
        
        // Filter by company_id if user has a company (multi-tenant isolation)
        if ($user->company_id) {
            $query->forCompany($user->company_id);
        }
        
        $query->orderByDesc('occurred_at')
            ->orderByDesc('created_at');

        if ($filters['search'] !== '') {
            $term = mb_strtolower($filters['search']);
            $query->where(function ($inner) use ($term) {
                $like = "%{$term}%";
                $inner
                    ->orWhereRaw('LOWER(samsara_event_id) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(vehicle_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(driver_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(event_type) LIKE ?', [$like]);
            });
        }

        if ($filters['severity'] !== '') {
            $query->where('severity', $filters['severity']);
        }

        if ($filters['status'] !== '') {
            $query->where('ai_status', $filters['status']);
        }

        if ($filters['event_type'] !== '') {
            $query->where('event_type', $filters['event_type']);
        }

        if ($filters['date_from'] !== '') {
            $this->applyDateFilter($query, '>=', $filters['date_from']);
        }

        if ($filters['date_to'] !== '') {
            $this->applyDateFilter($query, '<=', $filters['date_to']);
        }

        $events = $query
            ->paginate(12)
            ->withQueryString()
            ->through(function (SamsaraEvent $event) {
                $alertType = $event->raw_payload['alertType'] ?? $event->event_type;
                $assessment = $this->formatAssessment($event->ai_assessment);

                return [
                    'id' => $event->id,
                    'samsara_event_id' => $event->samsara_event_id,
                    'event_type' => $event->event_type,
                    'event_description' => $event->event_description,
                    'event_title' => $this->alertTypeLabel($alertType),
                    'event_icon' => $this->getEventIcon($alertType),
                    'severity' => $event->severity,
                    'severity_label' => $this->severityLabel($event->severity),
                    'ai_status' => $event->ai_status,
                    'ai_status_label' => $this->statusLabel($event->ai_status),
                    'vehicle_name' => $event->vehicle_name,
                    'driver_name' => $event->driver_name,
                    'occurred_at' => optional($event->occurred_at)?->toIso8601String(),
                    'occurred_at_human' => optional($event->occurred_at)?->diffForHumans(),
                    'created_at' => $event->created_at->toIso8601String(),
                    'ai_message_preview' => is_string($assessment['reasoning'] ?? null) 
                        ? $assessment['reasoning'] 
                        : Str::limit((string) $event->ai_message, 180),
                    'ai_assessment_view' => $assessment,
                    'verdict_summary' => $this->getVerdictSummary($assessment),
                    'investigation_summary' => $this->getInvestigationSummary($event->ai_actions),
                    'has_images' => $this->eventHasImages($event->ai_actions),
                    'investigation_metadata' => $event->ai_status === SamsaraEvent::STATUS_INVESTIGATING
                        ? [
                            'count' => $event->investigation_count,
                            'max_investigations' => SamsaraEvent::getMaxInvestigations(),
                        ]
                        : null,
                    // Human review data
                    'human_status' => $event->human_status,
                    'human_status_label' => $this->humanStatusLabel($event->human_status),
                    'needs_attention' => $event->needsHumanAttention(),
                    'urgency_level' => $event->getHumanUrgencyLevel(),
                ];
            });

        $stats = [
            'total' => SamsaraEvent::count(),
            'critical' => SamsaraEvent::where('severity', SamsaraEvent::SEVERITY_CRITICAL)->count(),
            'investigating' => SamsaraEvent::where('ai_status', SamsaraEvent::STATUS_INVESTIGATING)->count(),
            'completed' => SamsaraEvent::where('ai_status', SamsaraEvent::STATUS_COMPLETED)->count(),
            'failed' => SamsaraEvent::where('ai_status', SamsaraEvent::STATUS_FAILED)->count(),
            // Human review stats
            'needs_attention' => SamsaraEvent::query()->needsHumanAttention()->count(),
            'human_pending' => SamsaraEvent::where('human_status', SamsaraEvent::HUMAN_STATUS_PENDING)->count(),
            'human_reviewed' => SamsaraEvent::whereIn('human_status', [
                SamsaraEvent::HUMAN_STATUS_REVIEWED,
                SamsaraEvent::HUMAN_STATUS_RESOLVED,
                SamsaraEvent::HUMAN_STATUS_FALSE_POSITIVE,
            ])->count(),
        ];

        $eventTypes = SamsaraEvent::query()
            ->whereNotNull('event_type')
            ->distinct('event_type')
            ->orderBy('event_type')
            ->pluck('event_type')
            ->filter()
            ->values();

        $filterOptions = [
            'severities' => [
                ['label' => 'Todas', 'value' => ''],
                ['label' => 'Informativa', 'value' => SamsaraEvent::SEVERITY_INFO],
                ['label' => 'Advertencia', 'value' => SamsaraEvent::SEVERITY_WARNING],
                ['label' => 'Crítica', 'value' => SamsaraEvent::SEVERITY_CRITICAL],
            ],
            'statuses' => [
                ['label' => 'Todos', 'value' => ''],
                ['label' => 'Pendiente', 'value' => SamsaraEvent::STATUS_PENDING],
                ['label' => 'En proceso', 'value' => SamsaraEvent::STATUS_PROCESSING],
                ['label' => 'Investigando', 'value' => SamsaraEvent::STATUS_INVESTIGATING],
                ['label' => 'Completado', 'value' => SamsaraEvent::STATUS_COMPLETED],
                ['label' => 'Falló', 'value' => SamsaraEvent::STATUS_FAILED],
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

    public function show(Request $request, SamsaraEvent $samsaraEvent): Response
    {
        $user = $request->user();
        
        // Ensure event belongs to user's company (multi-tenant isolation)
        if ($user->company_id && $samsaraEvent->company_id !== $user->company_id) {
            abort(404);
        }
        
        $rawPayload = $samsaraEvent->raw_payload ?? [];
        $aiActions = $this->normalizeAiActions($samsaraEvent->ai_actions);
        $assessment = $samsaraEvent->ai_assessment ?? null;
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

        // Extract media insights with persisted image URLs
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

        $payloadSummary = collect([
            [
                'label' => 'Tipo de alerta',
                'value' => $this->alertTypeLabel($rawPayload['alertType'] ?? $samsaraEvent->event_type),
            ],
            [
                'label' => 'Ubicación aproximada',
                'value' => data_get($rawPayload, 'location.label')
                    ?? data_get($rawPayload, 'vehicle.location.name')
                    ?? data_get($rawPayload, 'vehicle.lastKnownLocation'),
            ],
            [
                'label' => 'Hora del evento (UTC)',
                'value' => $rawPayload['eventTime'] ?? optional($samsaraEvent->occurred_at)?->toIso8601String(),
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
        
        // Procesar alert_context para agregar missing_contacts flag
        $alertContext = $samsaraEvent->alert_context;
        if ($alertContext && isset($alertContext['notification_contacts'])) {
            $contacts = $alertContext['notification_contacts'];
            $allEmpty = empty($contacts['operator']['phone'] ?? null) 
                && empty($contacts['monitoring_team']['phone'] ?? null) 
                && empty($contacts['supervisor']['phone'] ?? null);
            $alertContext['notification_contacts']['missing_contacts'] = $allEmpty;
        }
        
        // Traducir behavior_label si existe
        if ($alertContext && !empty($alertContext['behavior_label'])) {
            $alertContext['behavior_label_translated'] = $this->behaviorLabelTranslation($alertContext['behavior_label']);
        }
        
        return Inertia::render('samsara/events/show', [
            'event' => [
                'id' => $samsaraEvent->id,
                'samsara_event_id' => $samsaraEvent->samsara_event_id,
                'event_type' => $samsaraEvent->event_type,
                'event_description' => $samsaraEvent->event_description,
                'display_event_type' => $this->alertTypeLabel($samsaraEvent->event_type),
                'event_icon' => $this->getEventIcon($samsaraEvent->event_type),
                'severity' => $samsaraEvent->severity,
                'severity_label' => $this->severityLabel($samsaraEvent->severity),
                'ai_status' => $samsaraEvent->ai_status,
                'ai_status_label' => $this->statusLabel($samsaraEvent->ai_status),
                'vehicle_name' => $samsaraEvent->vehicle_name,
                'vehicle_id' => $samsaraEvent->vehicle_id,
                'driver_name' => $samsaraEvent->driver_name,
                'driver_id' => $samsaraEvent->driver_id,
                'occurred_at' => optional($samsaraEvent->occurred_at)?->toIso8601String(),
                'ai_assessment' => $samsaraEvent->ai_assessment,
                'ai_assessment_view' => $assessmentView,
                'verdict_badge' => $verdictBadge,
                'ai_message' => $samsaraEvent->ai_message,
                'ai_actions' => $aiActions,
                'raw_payload' => $rawPayload,
                'payload_summary' => $payloadSummary,
                'timeline' => $timeline,
                'media_insights' => $mediaInsights,
                'investigation_metadata' => [
                    'count' => $samsaraEvent->investigation_count,
                    'last_check' => optional($samsaraEvent->last_investigation_at)?->diffForHumans(),
                    'last_check_at' => optional($samsaraEvent->last_investigation_at)?->toIso8601String(),
                    'next_check_minutes' => $samsaraEvent->next_check_minutes,
                    'next_check_available_at' => $samsaraEvent->last_investigation_at && $samsaraEvent->next_check_minutes
                        ? $samsaraEvent->last_investigation_at
                            ->copy()
                            ->addMinutes($samsaraEvent->next_check_minutes)
                            ->toIso8601String()
                        : null,
                    'history' => $samsaraEvent->investigation_history ?? [],
                    'max_investigations' => SamsaraEvent::getMaxInvestigations(),
                ],
                'investigation_actions' => $investigationActions,
                // Campos adicionales del pipeline AI (Problema 0)
                'alert_context' => $alertContext,
                'notification_decision' => $samsaraEvent->notification_decision,
                'notification_execution' => $samsaraEvent->notification_execution,
                'risk_escalation' => $samsaraEvent->risk_escalation ?? ($samsaraEvent->ai_assessment['risk_escalation'] ?? null),
                'proactive_flag' => $samsaraEvent->proactive_flag ?? ($alertContext['proactive_flag'] ?? null),
                'dedupe_key' => $samsaraEvent->dedupe_key ?? ($samsaraEvent->ai_assessment['dedupe_key'] ?? null),
                // Callback status (panic button flow)
                'notification_status' => $samsaraEvent->notification_status,
                'notification_status_label' => $this->notificationStatusLabel($samsaraEvent->notification_status),
                'call_response' => $samsaraEvent->call_response,
                'notification_channels' => $samsaraEvent->notification_channels,
                'notification_sent_at' => $samsaraEvent->notification_sent_at?->toIso8601String(),
                // Human review data
                'human_status' => $samsaraEvent->human_status,
                'human_status_label' => $this->humanStatusLabel($samsaraEvent->human_status),
                'reviewed_by' => $samsaraEvent->reviewedBy ? [
                    'id' => $samsaraEvent->reviewedBy->id,
                    'name' => $samsaraEvent->reviewedBy->name,
                ] : null,
                'reviewed_at' => $samsaraEvent->reviewed_at?->toIso8601String(),
                'reviewed_at_human' => $samsaraEvent->reviewed_at?->diffForHumans(),
                'needs_attention' => $samsaraEvent->needsHumanAttention(),
                'urgency_level' => $samsaraEvent->getHumanUrgencyLevel(),
                'comments_count' => $samsaraEvent->comments()->count(),
            ],
            'breadcrumbs' => [
                [
                    'title' => 'Alertas Samsara',
                    'href' => route('samsara.alerts.index'),
                ],
                [
                    'title' => $this->alertTypeLabel($samsaraEvent->event_type),
                    'href' => route('samsara.alerts.show', $samsaraEvent),
                ],
            ],
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
            SamsaraEvent::SEVERITY_CRITICAL => 'Crítica',
            SamsaraEvent::SEVERITY_WARNING => 'Advertencia',
            default => 'Informativa',
        };
    }

    private function statusLabel(?string $status): string
    {
        return match ($status) {
            SamsaraEvent::STATUS_COMPLETED => 'Completada',
            SamsaraEvent::STATUS_PROCESSING => 'En proceso',
            SamsaraEvent::STATUS_INVESTIGATING => 'Investigando',
            SamsaraEvent::STATUS_FAILED => 'Falló',
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

            // Agregar información de riesgo si existe
            if (!empty($assessment['risk_escalation'])) {
                $details[] = [
                    'label' => 'Nivel de escalación',
                    'value' => ucfirst((string) $assessment['risk_escalation']),
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
            $reason = $data['reason'] ?? 'nivel monitor';

            return [
                'summary' => $shouldNotify 
                    ? "Notificación decidida: {$escalation} via " . implode(', ', $channels)
                    : "Sin notificación necesaria ({$reason})",
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

    private function getEventIcon(?string $type): string
    {
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
            SamsaraEvent::HUMAN_STATUS_PENDING => 'Sin revisar',
            SamsaraEvent::HUMAN_STATUS_REVIEWED => 'Revisado',
            SamsaraEvent::HUMAN_STATUS_FLAGGED => 'Marcado',
            SamsaraEvent::HUMAN_STATUS_RESOLVED => 'Resuelto',
            SamsaraEvent::HUMAN_STATUS_FALSE_POSITIVE => 'Falso positivo',
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
}
