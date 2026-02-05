<x-pulse::card :cols="$cols" :rows="$rows" :class="$class" wire:poll.5s="">
    <x-pulse::card-header name="Consumo de Tokens">
        <x-slot:icon>
            <x-pulse::icons.sparkles class="w-6 h-6" />
        </x-slot:icon>
        <x-slot:actions>
            <span class="text-xs text-gray-500 dark:text-gray-400">
                {{ number_format($totalTokens) }} tokens
            </span>
        </x-slot:actions>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand">
        {{-- Métricas principales --}}
        <div class="grid grid-cols-2 gap-3 mb-4">
            <div class="text-center p-3 bg-gradient-to-br from-purple-50 to-indigo-50 dark:from-purple-900/20 dark:to-indigo-900/20 rounded-lg">
                <div class="text-2xl font-bold text-purple-600 dark:text-purple-400">
                    {{ number_format($totalTokens) }}
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Tokens Totales</div>
            </div>
            <div class="text-center p-3 bg-gradient-to-br from-blue-50 to-cyan-50 dark:from-blue-900/20 dark:to-cyan-900/20 rounded-lg">
                <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                    {{ number_format($totalRequests) }}
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Requests</div>
            </div>
        </div>

        {{-- Por Modelo --}}
        @if($byModel->isNotEmpty())
            <div class="mb-4">
                <h4 class="text-xs font-medium text-gray-700 dark:text-gray-300 mb-2">Por Modelo</h4>
                <div class="space-y-2">
                    @foreach($byModel->sortByDesc('sum') as $item)
                        @php
                            $percentage = $totalTokens > 0 ? ($item->sum / $totalTokens) * 100 : 0;
                            $input = $inputByModel->firstWhere('key', $item->key)?->sum ?? 0;
                            $output = $outputByModel->firstWhere('key', $item->key)?->sum ?? 0;
                        @endphp
                        <div class="p-2 bg-gray-50 dark:bg-gray-800 rounded-lg">
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">
                                    {{ $item->key }}
                                </span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ number_format($item->sum) }} tokens
                                </span>
                            </div>
                            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                <div class="bg-gradient-to-r from-purple-500 to-indigo-500 h-2 rounded-full" style="width: {{ $percentage }}%"></div>
                            </div>
                            <div class="flex justify-between mt-1 text-[10px] text-gray-500 dark:text-gray-400">
                                <span>Input: {{ number_format($input) }}</span>
                                <span>Output: {{ number_format($output) }}</span>
                                <span>{{ number_format($item->count) }} calls</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Por Tipo de Request --}}
        @if($byRequestType->isNotEmpty())
            <div class="mb-4">
                <h4 class="text-xs font-medium text-gray-700 dark:text-gray-300 mb-2">Por Tipo de Request</h4>
                <div class="flex flex-wrap gap-2">
                    @foreach($byRequestType->sortByDesc('sum') as $item)
                        <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200">
                            {{ ucfirst($item->key) }}: {{ number_format($item->sum) }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Top Usuarios --}}
        @if($topUsers->isNotEmpty())
            <div>
                <h4 class="text-xs font-medium text-gray-700 dark:text-gray-300 mb-2">Top Usuarios</h4>
                <div class="space-y-2">
                    @foreach($topUsers->take(5) as $item)
                        <div class="flex items-center gap-2">
                            @if($item->user)
                                <x-pulse::user-card :user="$item->user" :stats="number_format($item->sum) . ' tokens'" />
                            @else
                                <div class="text-xs text-gray-600 dark:text-gray-400">
                                    Usuario #{{ $item->key ?? 'unknown' }}: {{ number_format($item->sum ?? 0) }} tokens
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if($totalTokens === 0)
            <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                <x-pulse::icons.sparkles class="w-8 h-8 mx-auto mb-2 opacity-50" />
                <p class="text-sm">No hay consumo de tokens en este período</p>
            </div>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
