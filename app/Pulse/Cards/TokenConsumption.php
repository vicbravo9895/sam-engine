<?php

namespace App\Pulse\Cards;

use Illuminate\Support\Facades\View;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

/**
 * Card para mostrar consumo de tokens LLM por modelo y usuario.
 */
#[Lazy]
class TokenConsumption extends Card
{
    public function render()
    {
        // Obtener tokens por modelo
        $byModel = $this->aggregate('token_usage_model', ['sum', 'count']);
        
        // Obtener input vs output tokens
        $inputByModel = $this->aggregate('token_input', ['sum']);
        $outputByModel = $this->aggregate('token_output', ['sum']);

        // Obtener por tipo de request
        $byRequestType = $this->aggregate('token_request_type', ['sum', 'count']);

        // Obtener top usuarios
        $topUsers = $this->aggregate('token_user', ['sum', 'count']);
        $users = Pulse::resolveUsers($topUsers->pluck('key'));

        // Calcular totales
        $totalTokens = $byModel->sum('sum');
        $totalRequests = $byModel->sum('count');

        return View::make('pulse.cards.token-consumption', [
            'byModel' => $byModel,
            'inputByModel' => $inputByModel,
            'outputByModel' => $outputByModel,
            'byRequestType' => $byRequestType,
            'topUsers' => $topUsers->map(fn ($item) => (object) [
                'user' => $users->find($item->key),
                'sum' => $item->sum,
                'count' => $item->count,
            ]),
            'totalTokens' => $totalTokens,
            'totalRequests' => $totalRequests,
        ]);
    }
}
