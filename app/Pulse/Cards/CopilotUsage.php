<?php

namespace App\Pulse\Cards;

use Illuminate\Support\Facades\View;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

/**
 * Card para mostrar uso del Copilot (FleetAgent).
 */
#[Lazy]
class CopilotUsage extends Card
{
    public function render()
    {
        // Obtener mensajes procesados
        $messages = $this->aggregate('copilot_message', ['avg', 'max', 'count']);

        // Obtener top usuarios
        $topUsers = $this->aggregate('copilot_user', ['avg', 'count']);
        $users = Pulse::resolveUsers($topUsers->pluck('key'));

        // Obtener tools más usadas
        $topTools = $this->aggregate('copilot_tool', ['count']);

        // Obtener por modelo
        $byModel = $this->aggregate('copilot_model', ['count']);

        // Obtener mensajes lentos
        $slowMessages = $this->aggregate('copilot_slow', ['count', 'max']);

        // Calcular métricas
        $totalMessages = $messages->sum('count');
        $avgDuration = $messages->avg('avg');
        $maxDuration = $messages->max('max');

        return View::make('pulse.cards.copilot-usage', [
            'totalMessages' => $totalMessages,
            'avgDuration' => round($avgDuration ?? 0),
            'maxDuration' => round($maxDuration ?? 0),
            'topUsers' => $topUsers->take(5)->map(fn ($item) => (object) [
                'user' => $users->find($item->key),
                'count' => $item->count,
                'avgDuration' => round($item->avg ?? 0),
            ]),
            'topTools' => $topTools->sortByDesc('count')->take(8),
            'byModel' => $byModel,
            'slowMessages' => $slowMessages->first()?->count ?? 0,
        ]);
    }
}
