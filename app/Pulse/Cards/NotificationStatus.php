<?php

namespace App\Pulse\Cards;

use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

/**
 * Card para mostrar estado de notificaciones por canal.
 */
#[Lazy]
class NotificationStatus extends Card
{
    public function render()
    {
        // Obtener por canal
        $byChannel = $this->aggregate('notification_channel', ['avg', 'count']);

        // Obtener status por canal
        $byStatus = $this->aggregate('notification_status', ['count']);

        // Obtener por nivel de escalación
        $byEscalation = $this->aggregate('notification_escalation', ['count']);

        // Obtener totales
        $totals = $this->aggregate('notification_total', ['count']);

        // Calcular métricas
        $successCount = $totals->firstWhere('key', 'success')?->count ?? 0;
        $failedCount = $totals->firstWhere('key', 'failed')?->count ?? 0;
        $totalNotifications = $successCount + $failedCount;
        $successRate = $totalNotifications > 0 ? round(($successCount / $totalNotifications) * 100, 1) : 0;

        // Parsear status por canal para obtener éxitos y fallos
        $channelStats = collect();
        foreach ($byChannel as $channel) {
            $key = $channel->key;
            $success = $byStatus->firstWhere('key', "{$key}:success")?->count ?? 0;
            $failed = $byStatus->firstWhere('key', "{$key}:failed")?->count ?? 0;
            $total = $success + $failed;
            
            $channelStats->push((object) [
                'channel' => $key,
                'total' => $total,
                'success' => $success,
                'failed' => $failed,
                'avgDuration' => round($channel->avg ?? 0),
                'successRate' => $total > 0 ? round(($success / $total) * 100, 1) : 0,
            ]);
        }

        return View::make('pulse.cards.notification-status', [
            'channelStats' => $channelStats->sortByDesc('total'),
            'byEscalation' => $byEscalation,
            'totalNotifications' => $totalNotifications,
            'successRate' => $successRate,
            'successCount' => $successCount,
            'failedCount' => $failedCount,
        ]);
    }
}
