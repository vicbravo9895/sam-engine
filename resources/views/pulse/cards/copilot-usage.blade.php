<x-pulse::card :cols="$cols" :rows="$rows" :class="$class" wire:poll.5s="">
    <x-pulse::card-header name="Copilot (FleetAgent)">
        <x-slot:icon>
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
            </svg>
        </x-slot:icon>
        <x-slot:actions>
            <span class="text-xs text-gray-500 dark:text-gray-400">
                {{ number_format($totalMessages) }} mensajes
            </span>
        </x-slot:actions>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand">
        {{-- Métricas principales --}}
        <div class="grid grid-cols-3 gap-3 mb-4">
            <div class="text-center p-3 bg-gradient-to-br from-indigo-50 to-purple-50 dark:from-indigo-900/20 dark:to-purple-900/20 rounded-lg">
                <div class="text-2xl font-bold text-indigo-600 dark:text-indigo-400">
                    {{ number_format($totalMessages) }}
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Mensajes</div>
            </div>
            <div class="text-center p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                    {{ number_format($avgDuration) }}ms
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Promedio</div>
            </div>
            <div class="text-center p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <div class="text-2xl font-bold text-orange-600 dark:text-orange-400">
                    {{ $slowMessages }}
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Lentos (>3s)</div>
            </div>
        </div>

        {{-- Top Tools --}}
        @if($topTools->isNotEmpty())
            <div class="mb-4">
                <h4 class="text-xs font-medium text-gray-700 dark:text-gray-300 mb-2">Tools Más Usadas</h4>
                <div class="space-y-1">
                    @foreach($topTools as $tool)
                        @php
                            $maxCount = $topTools->max('count');
                            $percentage = $maxCount > 0 ? ($tool->count / $maxCount) * 100 : 0;
                        @endphp
                        <div class="flex items-center gap-2">
                            <code class="flex-1 text-xs font-mono text-purple-600 dark:text-purple-400 truncate">
                                {{ $tool->key }}
                            </code>
                            <div class="w-20 bg-gray-200 dark:bg-gray-700 rounded-full h-1.5">
                                <div class="bg-indigo-500 h-1.5 rounded-full" style="width: {{ $percentage }}%"></div>
                            </div>
                            <span class="text-xs text-gray-500 dark:text-gray-400 w-8 text-right">
                                {{ number_format($tool->count) }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Por Modelo --}}
        @if($byModel->isNotEmpty())
            <div class="mb-4">
                <h4 class="text-xs font-medium text-gray-700 dark:text-gray-300 mb-2">Por Modelo</h4>
                <div class="flex flex-wrap gap-2">
                    @foreach($byModel as $model)
                        <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200">
                            {{ $model->key }}: {{ number_format($model->count) }}
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
                    @foreach($topUsers as $item)
                        @if($item->user)
                            <x-pulse::user-card :user="$item->user" :stats="number_format($item->count) . ' msgs (~' . number_format($item->avgDuration) . 'ms)'" />
                        @else
                            <div class="text-xs text-gray-600 dark:text-gray-400">
                                Usuario desconocido: {{ number_format($item->count ?? 0) }} mensajes
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

        @if($totalMessages === 0)
            <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                <svg class="w-8 h-8 mx-auto mb-2 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                </svg>
                <p class="text-sm">No hay mensajes del copilot en este período</p>
            </div>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
