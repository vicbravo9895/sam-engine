<?php

namespace App\Pulse\Cards;

use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

/**
 * Card para mostrar rendimiento del AI Service.
 */
#[Lazy]
class AiPerformance extends Card
{
    public function render()
    {
        // Obtener tiempos de respuesta por endpoint
        $byEndpoint = $this->aggregate('ai_service_response', ['avg', 'max', 'count']);

        // Obtener status (success/failed)
        $byStatus = $this->aggregate('ai_service_status', ['count']);

        // Obtener códigos HTTP
        $byHttpCode = $this->aggregate('ai_service_http', ['count']);

        // Obtener totales
        $totals = $this->aggregate('ai_service_total', ['count']);

        // Obtener requests lentos
        $slowRequests = $this->aggregate('ai_service_slow', ['count', 'max']);

        // Calcular métricas
        $successCount = $totals->firstWhere('key', 'success')?->count ?? 0;
        $failedCount = $totals->firstWhere('key', 'failed')?->count ?? 0;
        $totalRequests = $successCount + $failedCount;
        $successRate = $totalRequests > 0 ? round(($successCount / $totalRequests) * 100, 1) : 0;

        $avgDuration = $byEndpoint->avg('avg');
        $maxDuration = $byEndpoint->max('max');

        return View::make('pulse.cards.ai-performance', [
            'byEndpoint' => $byEndpoint,
            'byStatus' => $byStatus,
            'byHttpCode' => $byHttpCode,
            'slowRequests' => $slowRequests,
            'totalRequests' => $totalRequests,
            'successRate' => $successRate,
            'avgDuration' => round($avgDuration ?? 0),
            'maxDuration' => round($maxDuration ?? 0),
        ]);
    }
}
