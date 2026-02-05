<x-pulse::card :cols="$cols" :rows="$rows" :class="$class" wire:poll.5s="">
    <x-pulse::card-header name="AI Service Performance">
        <x-slot:icon>
            <x-pulse::icons.command-line class="w-6 h-6" />
        </x-slot:icon>
        <x-slot:actions>
            <span class="text-xs {{ $successRate >= 95 ? 'text-green-500' : ($successRate >= 80 ? 'text-yellow-500' : 'text-red-500') }}">
                {{ $successRate }}% éxito
            </span>
        </x-slot:actions>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand">
        {{-- Métricas principales --}}
        <div class="grid grid-cols-4 gap-2 mb-4">
            <div class="text-center p-2 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <div class="text-xl font-bold text-gray-700 dark:text-gray-300">
                    {{ number_format($totalRequests) }}
                </div>
                <div class="text-[10px] text-gray-500 dark:text-gray-400">Requests</div>
            </div>
            <div class="text-center p-2 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <div class="text-xl font-bold {{ $successRate >= 95 ? 'text-green-600 dark:text-green-400' : ($successRate >= 80 ? 'text-yellow-600 dark:text-yellow-400' : 'text-red-600 dark:text-red-400') }}">
                    {{ $successRate }}%
                </div>
                <div class="text-[10px] text-gray-500 dark:text-gray-400">Éxito</div>
            </div>
            <div class="text-center p-2 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <div class="text-xl font-bold text-blue-600 dark:text-blue-400">
                    {{ number_format($avgDuration) }}ms
                </div>
                <div class="text-[10px] text-gray-500 dark:text-gray-400">Promedio</div>
            </div>
            <div class="text-center p-2 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <div class="text-xl font-bold text-orange-600 dark:text-orange-400">
                    {{ number_format($maxDuration) }}ms
                </div>
                <div class="text-[10px] text-gray-500 dark:text-gray-400">Máximo</div>
            </div>
        </div>

        {{-- Por Endpoint --}}
        @if($byEndpoint->isNotEmpty())
            <div class="mb-4">
                <h4 class="text-xs font-medium text-gray-700 dark:text-gray-300 mb-2">Por Endpoint</h4>
                <div class="space-y-2">
                    @foreach($byEndpoint->sortByDesc('count') as $item)
                        <div class="p-2 bg-gray-50 dark:bg-gray-800 rounded-lg">
                            <div class="flex items-center justify-between mb-1">
                                <code class="text-xs font-mono text-purple-600 dark:text-purple-400">
                                    {{ $item->key }}
                                </code>
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ number_format($item->count) }} calls
                                </span>
                            </div>
                            <div class="flex gap-4 text-[10px] text-gray-500 dark:text-gray-400">
                                <span>Avg: {{ number_format($item->avg ?? 0) }}ms</span>
                                <span>Max: {{ number_format($item->max ?? 0) }}ms</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Códigos HTTP --}}
        @if($byHttpCode->isNotEmpty())
            <div class="mb-4">
                <h4 class="text-xs font-medium text-gray-700 dark:text-gray-300 mb-2">Códigos HTTP</h4>
                <div class="flex flex-wrap gap-2">
                    @foreach($byHttpCode->sortBy('key') as $item)
                        @php
                            $code = (int) $item->key;
                            $color = match(true) {
                                $code >= 200 && $code < 300 => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
                                $code >= 400 && $code < 500 => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
                                $code >= 500 => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                                default => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200',
                            };
                        @endphp
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-mono font-medium {{ $color }}">
                            {{ $item->key }}: {{ number_format($item->count) }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Requests Lentos --}}
        @if($slowRequests->isNotEmpty() && $slowRequests->sum('count') > 0)
            <div class="p-2 bg-orange-50 dark:bg-orange-900/20 rounded-lg border border-orange-200 dark:border-orange-800">
                <div class="flex items-center gap-2">
                    <x-pulse::icons.exclamation-triangle class="w-4 h-4 text-orange-500" />
                    <span class="text-xs font-medium text-orange-800 dark:text-orange-200">
                        {{ $slowRequests->sum('count') }} requests lentos (>5s)
                    </span>
                </div>
            </div>
        @endif

        @if($totalRequests === 0)
            <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                <x-pulse::icons.command-line class="w-8 h-8 mx-auto mb-2 opacity-50" />
                <p class="text-sm">No hay llamadas al AI Service en este período</p>
            </div>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
