<?php

namespace App\Pulse\Cards;

use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

/**
 * Card para mostrar alertas procesadas por tipo, severidad y verdict.
 */
#[Lazy]
class AlertsProcessed extends Card
{
    public function render()
    {
        // Obtener agregados de alertas
        $byType = $this->aggregate('alert_processing', ['avg', 'max', 'count']);
        $bySeverity = $this->aggregate('alert_severity', ['count']);
        $byVerdict = $this->aggregate('alert_verdict', ['count']);
        $byStatus = $this->aggregate('alert_status', ['count']);

        // Calcular totales
        $totalProcessed = $byType->sum('count');
        $avgDuration = $byType->avg('avg');
        $maxDuration = $byType->max('max');

        return View::make('pulse.cards.alerts-processed', [
            'byType' => $byType,
            'bySeverity' => $bySeverity,
            'byVerdict' => $byVerdict,
            'byStatus' => $byStatus,
            'totalProcessed' => $totalProcessed,
            'avgDuration' => round($avgDuration ?? 0),
            'maxDuration' => round($maxDuration ?? 0),
        ]);
    }
}
