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
        $filters = [
            'search' => trim((string) $request->input('search', '')),
            'severity' => (string) $request->input('severity', ''),
            'status' => (string) $request->input('status', ''),
            'event_type' => (string) $request->input('event_type', ''),
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
        ];

        $query = SamsaraEvent::query()
            ->orderByDesc('occurred_at')
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
                    'ai_message_preview' => Str::limit((string) $event->ai_message, 180),
                    'ai_assessment_view' => $assessment,
                    'verdict_summary' => $this->getVerdictSummary($assessment),
                    'investigation_summary' => $this->getInvestigationSummary($event->ai_actions),
                ];
            });

        $stats = [
            'total' => SamsaraEvent::count(),
            'critical' => SamsaraEvent::where('severity', SamsaraEvent::SEVERITY_CRITICAL)->count(),
            'investigating' => SamsaraEvent::where('ai_status', SamsaraEvent::STATUS_INVESTIGATING)->count(),
            'completed' => SamsaraEvent::where('ai_status', SamsaraEvent::STATUS_COMPLETED)->count(),
            'failed' => SamsaraEvent::where('ai_status', SamsaraEvent::STATUS_FAILED)->count(),
        ];

        $eventTypes = SamsaraEvent::query()
            ->select('event_type')
            ->distinct()
            ->whereNotNull('event_type')
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

    public function show(SamsaraEvent $samsaraEvent): Response
    {
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
                        ];
                    })
                    ->values()
                    ->all();

                $outputSummary = $this->summarizeAgentOutput($agentKey, $agent['output_summary'] ?? null);

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

        $mediaInsights = $timelineCollection
            ->flatMap(fn($agent) => collect($agent['tools_used']))
            ->filter(fn($tool) => ($tool['raw_tool_name'] ?? '') === 'get_camera_media')
            ->flatMap(fn($tool) => collect($tool['details']['analyses'] ?? []))
            ->map(fn($analysis) => [
                'camera' => $this->cameraLabel($analysis['camera'] ?? null),
                'analysis' => $analysis['analysis_preview'] ?? $analysis['analysis'] ?? null,
                'analysis_preview' => $analysis['analysis_preview'] ?? $analysis['analysis'] ?? null,
            ])
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
        return Inertia::render('samsara/events/show', [
            'event' => [
                'id' => $samsaraEvent->id,
                'samsara_event_id' => $samsaraEvent->samsara_event_id,
                'event_type' => $samsaraEvent->event_type,
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
                    'next_check_minutes' => $samsaraEvent->next_check_minutes,
                    'history' => $samsaraEvent->investigation_history ?? [],
                    'max_investigations' => SamsaraEvent::getMaxInvestigations(),
                ],
                'investigation_actions' => $investigationActions,
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

        return [
            'agents' => collect($actions['agents'] ?? [])->map(function ($agent) {
                $agent['tools_used'] = array_values($agent['tools_used'] ?? []);
                return $agent;
            })->values()->all(),
            'total_duration_ms' => $actions['total_duration_ms'] ?? 0,
            'total_tools_called' => $actions['total_tools_called'] ?? 0,
        ];
    }

    private function formatAssessment(?array $assessment): ?array
    {
        if (!is_array($assessment) || empty($assessment)) {
            return null;
        }

        return [
            'verdict' => $this->assessmentVerdictLabel($assessment['verdict'] ?? null),
            'likelihood' => $this->assessmentLikelihoodLabel($assessment['likelihood'] ?? null),
            'reasoning' => $assessment['reasoning'] ?? null,
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
            'ingestion_agent' => [
                'title' => 'Resumen del evento',
                'description' => 'Identificando los datos clave del aviso.',
            ],
            'panic_investigator' => [
                'title' => 'Investigación técnica',
                'description' => 'Consultando historial, sensores y cámaras.',
            ],
            'final_agent' => [
                'title' => 'Mensaje operativo',
                'description' => 'Preparando la comunicación para monitoreo.',
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
            'likely_false_positive' => 'Probable falso positivo',
            'likely_true_positive' => 'Probable verdadero positivo',
            'needs_human_review' => 'Requiere revisión manual',
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
            'vehicle_stats_summary' => 'Resumen de estadísticas del vehículo',
            'vehicle_info_summary' => 'Ficha del vehículo',
            'camera_summary' => 'Hallazgos de cámaras',
        ];

        $items = [];
        foreach ($evidence as $key => $value) {
            if (!filled($value)) {
                continue;
            }

            $items[] = [
                'label' => $labels[$key] ?? Str::headline((string) $key),
                'value' => $value,
            ];
        }

        return $items;
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

        if ($agentName === 'ingestion_agent') {
            $details = [];
            if (!empty($data['alert_type'])) {
                $details[] = [
                    'label' => 'Tipo detectado',
                    'value' => $this->alertTypeLabel($data['alert_type']),
                ];
            }
            if (!empty($data['vehicle_name'])) {
                $details[] = ['label' => 'Unidad', 'value' => $data['vehicle_name']];
            }
            $details[] = [
                'label' => 'Operador',
                'value' => (!empty($data['driver_name']) && $data['driver_name'] !== 'unknown')
                    ? $data['driver_name']
                    : 'No identificado',
            ];
            if (!empty($data['severity_level'])) {
                $details[] = [
                    'label' => 'Severidad',
                    'value' => ucfirst((string) $data['severity_level']),
                ];
            }

            return [
                'summary' => 'Datos clave identificados a partir del webhook.',
                'details' => $details,
            ];
        }

        if ($agentName === 'panic_investigator' && isset($data['panic_assessment'])) {
            $assessment = $data['panic_assessment'];
            $verdict = $this->assessmentVerdictLabel($assessment['verdict'] ?? null);
            $likelihood = $this->assessmentLikelihoodLabel($assessment['likelihood'] ?? null);
            $details = $this->mapAssessmentEvidence($assessment['supporting_evidence'] ?? []);

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

        $urgency = match ($verdict) {
            'Probable verdadero positivo' => 'high',
            'Requiere revisión manual' => 'medium',
            default => 'low',
        };

        return [
            'verdict' => $verdict,
            'likelihood' => $likelihood,
            'urgency' => $urgency,
        ];
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

        $urgency = match ($verdict) {
            'Probable verdadero positivo' => 'high',
            'Requiere revisión manual' => 'medium',
            default => 'low',
        };

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
}
