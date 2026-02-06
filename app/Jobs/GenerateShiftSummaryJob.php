<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\SamsaraEvent;
use App\Models\ShiftSummary;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Genera un resumen de turno automático para una empresa usando LLM.
 * 
 * Recopila métricas del periodo, genera un resumen narrativo en español,
 * y opcionalmente notifica por email/WhatsApp.
 */
class GenerateShiftSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 120;

    public function __construct(
        public int $companyId,
        public ?string $shiftLabel = null,
        public ?Carbon $periodStart = null,
        public ?Carbon $periodEnd = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $company = Company::find($this->companyId);
        if (!$company) {
            Log::warning('Shift summary: company not found', ['company_id' => $this->companyId]);
            return;
        }

        // Default to last 8 hours if no period specified
        $periodEnd = $this->periodEnd ?? now();
        $periodStart = $this->periodStart ?? $periodEnd->copy()->subHours(8);
        $shiftLabel = $this->shiftLabel ?? "Turno {$periodStart->format('H:i')} - {$periodEnd->format('H:i')}";

        // Gather metrics
        $metrics = $this->gatherMetrics($company, $periodStart, $periodEnd);

        // If no events in the period, skip summary
        if ($metrics['total_events'] === 0) {
            Log::info('Shift summary: no events in period, skipping', [
                'company_id' => $company->id,
                'period' => "{$periodStart} to {$periodEnd}",
            ]);
            return;
        }

        // Generate summary with LLM
        $summaryText = $this->generateSummaryWithLLM($company, $metrics, $shiftLabel, $periodStart, $periodEnd);
        
        if (!$summaryText) {
            Log::error('Shift summary: LLM failed to generate summary', [
                'company_id' => $company->id,
            ]);
            return;
        }

        // Save
        ShiftSummary::create([
            'company_id' => $company->id,
            'shift_label' => $shiftLabel,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'summary_text' => $summaryText,
            'metrics' => $metrics,
            'model_used' => config('services.openai.standard_model', 'gpt-4o-mini'),
        ]);

        Log::info('Shift summary generated', [
            'company_id' => $company->id,
            'shift_label' => $shiftLabel,
            'total_events' => $metrics['total_events'],
        ]);
    }

    /**
     * Gather all metrics for the shift period.
     */
    private function gatherMetrics(Company $company, Carbon $start, Carbon $end): array
    {
        $query = SamsaraEvent::forCompany($company->id)
            ->whereBetween('occurred_at', [$start, $end]);

        $totalEvents = (clone $query)->count();
        
        $bySeverity = (clone $query)
            ->selectRaw('severity, COUNT(*) as count')
            ->groupBy('severity')
            ->pluck('count', 'severity')
            ->toArray();

        $byVerdict = (clone $query)
            ->whereNotNull('verdict')
            ->selectRaw('verdict, COUNT(*) as count')
            ->groupBy('verdict')
            ->pluck('count', 'verdict')
            ->toArray();

        $byStatus = (clone $query)
            ->selectRaw('ai_status, COUNT(*) as count')
            ->groupBy('ai_status')
            ->pluck('count', 'ai_status')
            ->toArray();

        $topVehicles = (clone $query)
            ->selectRaw('vehicle_name, COUNT(*) as count')
            ->whereNotNull('vehicle_name')
            ->groupBy('vehicle_name')
            ->orderByDesc('count')
            ->limit(5)
            ->pluck('count', 'vehicle_name')
            ->toArray();

        $notificationsSent = (clone $query)
            ->whereNotNull('notification_sent_at')
            ->count();

        $needsReview = (clone $query)
            ->where('human_status', 'pending')
            ->whereIn('ai_status', ['failed', 'investigating'])
            ->count();

        $avgLatencyMs = (clone $query)
            ->whereNotNull('pipeline_latency_ms')
            ->avg('pipeline_latency_ms');

        return [
            'total_events' => $totalEvents,
            'by_severity' => $bySeverity,
            'by_verdict' => $byVerdict,
            'by_status' => $byStatus,
            'top_vehicles' => $topVehicles,
            'notifications_sent' => $notificationsSent,
            'needs_review' => $needsReview,
            'avg_latency_ms' => $avgLatencyMs ? (int) round($avgLatencyMs) : null,
        ];
    }

    /**
     * Generate a narrative summary using OpenAI.
     */
    private function generateSummaryWithLLM(
        Company $company,
        array $metrics,
        string $shiftLabel,
        Carbon $start,
        Carbon $end
    ): ?string {
        $apiKey = config('services.openai.api_key');
        $model = config('services.openai.standard_model', 'gpt-4o-mini');

        if (!$apiKey) {
            Log::error('Shift summary: OpenAI API key not configured');
            return null;
        }

        $metricsJson = json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $prompt = <<<EOT
Eres un asistente operativo de flota. Genera un resumen conciso del turno para el equipo de monitoreo.

Empresa: {$company->name}
Turno: {$shiftLabel}
Periodo: {$start->format('d/m/Y H:i')} a {$end->format('d/m/Y H:i')}

Métricas del turno:
{$metricsJson}

Genera un resumen narrativo en español con:
- Resumen general del turno (1-2 líneas)
- Eventos destacados por severidad
- Vehículos con más incidentes (si hay)
- Alertas pendientes de revisión
- Recomendación breve para el siguiente turno

Formato: Bullet points claros, máximo 200 palabras. Sin markdown complejo, solo texto plano con guiones.
EOT;

        try {
            $response = Http::withToken($apiKey)
                ->timeout(60)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => 'Eres un asistente operativo de flotillas. Respondes en español de forma concisa y profesional.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.3,
                    'max_tokens' => 500,
                ]);

            if ($response->failed()) {
                Log::error('Shift summary: OpenAI API error', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);
                return null;
            }

            $result = $response->json();
            return $result['choices'][0]['message']['content'] ?? null;
        } catch (\Exception $e) {
            Log::error('Shift summary: Exception calling OpenAI', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
