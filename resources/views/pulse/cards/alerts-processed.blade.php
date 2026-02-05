<x-pulse::card :cols="$cols" :rows="$rows" :class="$class" wire:poll.5s="">
    <x-pulse::card-header name="Alertas Procesadas">
        <x-slot:icon>
            <x-pulse::icons.bolt class="w-6 h-6" />
        </x-slot:icon>
        <x-slot:actions>
            <span class="text-xs text-gray-500 dark:text-gray-400">
                {{ number_format($totalProcessed) }} total
            </span>
        </x-slot:actions>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand">
        {{-- Métricas principales --}}
        <div class="grid grid-cols-3 gap-3 mb-4">
            <div class="text-center p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <div class="text-2xl font-bold text-purple-600 dark:text-purple-400">
                    {{ number_format($totalProcessed) }}
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Procesadas</div>
            </div>
            <div class="text-center p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                    {{ number_format($avgDuration) }}ms
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Promedio</div>
            </div>
            <div class="text-center p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <div class="text-2xl font-bold text-orange-600 dark:text-orange-400">
                    {{ number_format($maxDuration) }}ms
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Máximo</div>
            </div>
        </div>

        {{-- Por Severidad --}}
        @if($bySeverity->isNotEmpty())
            <div class="mb-4">
                <h4 class="text-xs font-medium text-gray-700 dark:text-gray-300 mb-2">Por Severidad</h4>
                <div class="flex flex-wrap gap-2">
                    @foreach($bySeverity->sortByDesc('count') as $item)
                        @php
                            $colors = [
                                'critical' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                                'warning' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
                                'info' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
                            ];
                            $color = $colors[$item->key] ?? 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200';
                        @endphp
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $color }}">
                            {{ ucfirst($item->key) }}: {{ number_format($item->count) }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Por Verdict --}}
        @if($byVerdict->isNotEmpty())
            <div class="mb-4">
                <h4 class="text-xs font-medium text-gray-700 dark:text-gray-300 mb-2">Por Veredicto</h4>
                <div class="space-y-1">
                    @foreach($byVerdict->sortByDesc('count')->take(6) as $item)
                        @php
                            $total = $byVerdict->sum('count');
                            $percentage = $total > 0 ? ($item->count / $total) * 100 : 0;
                        @endphp
                        <div class="flex items-center gap-2">
                            <div class="flex-1 text-xs text-gray-600 dark:text-gray-400 truncate">
                                {{ str_replace('_', ' ', ucfirst($item->key)) }}
                            </div>
                            <div class="w-24 bg-gray-200 dark:bg-gray-700 rounded-full h-1.5">
                                <div class="bg-purple-600 h-1.5 rounded-full" style="width: {{ $percentage }}%"></div>
                            </div>
                            <div class="text-xs font-medium text-gray-700 dark:text-gray-300 w-12 text-right">
                                {{ number_format($item->count) }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Por Status --}}
        @if($byStatus->isNotEmpty())
            <div>
                <h4 class="text-xs font-medium text-gray-700 dark:text-gray-300 mb-2">Por Status</h4>
                <div class="flex flex-wrap gap-2">
                    @foreach($byStatus as $item)
                        @php
                            $colors = [
                                'completed' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
                                'investigating' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
                                'failed' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                                'processing' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
                            ];
                            $color = $colors[$item->key] ?? 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200';
                        @endphp
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $color }}">
                            {{ ucfirst($item->key) }}: {{ number_format($item->count) }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        @if($totalProcessed === 0)
            <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                <x-pulse::icons.bolt class="w-8 h-8 mx-auto mb-2 opacity-50" />
                <p class="text-sm">No hay alertas procesadas en este período</p>
            </div>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
