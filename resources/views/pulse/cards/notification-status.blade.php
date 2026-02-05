<x-pulse::card :cols="$cols" :rows="$rows" :class="$class" wire:poll.5s="">
    <x-pulse::card-header name="Notificaciones">
        <x-slot:icon>
            <x-pulse::icons.bell class="w-6 h-6" />
        </x-slot:icon>
        <x-slot:actions>
            <span class="text-xs {{ $successRate >= 95 ? 'text-green-500' : ($successRate >= 80 ? 'text-yellow-500' : 'text-red-500') }}">
                {{ $successRate }}% entregadas
            </span>
        </x-slot:actions>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand">
        {{-- Métricas principales --}}
        <div class="grid grid-cols-3 gap-3 mb-4">
            <div class="text-center p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <div class="text-2xl font-bold text-gray-700 dark:text-gray-300">
                    {{ number_format($totalNotifications) }}
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Total</div>
            </div>
            <div class="text-center p-3 bg-green-50 dark:bg-green-900/20 rounded-lg">
                <div class="text-2xl font-bold text-green-600 dark:text-green-400">
                    {{ number_format($successCount) }}
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Exitosas</div>
            </div>
            <div class="text-center p-3 bg-red-50 dark:bg-red-900/20 rounded-lg">
                <div class="text-2xl font-bold text-red-600 dark:text-red-400">
                    {{ number_format($failedCount) }}
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Fallidas</div>
            </div>
        </div>

        {{-- Por Canal --}}
        @if($channelStats->isNotEmpty())
            <div class="mb-4">
                <h4 class="text-xs font-medium text-gray-700 dark:text-gray-300 mb-2">Por Canal</h4>
                <div class="space-y-2">
                    @foreach($channelStats as $channel)
                        @php
                            $icon = match($channel->channel) {
                                'sms' => '📱',
                                'whatsapp' => '💬',
                                'voice', 'call' => '📞',
                                default => '📧',
                            };
                        @endphp
                        <div class="p-2 bg-gray-50 dark:bg-gray-800 rounded-lg">
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">
                                    {{ $icon }} {{ ucfirst($channel->channel) }}
                                </span>
                                <span class="text-xs {{ $channel->successRate >= 95 ? 'text-green-500' : ($channel->successRate >= 80 ? 'text-yellow-500' : 'text-red-500') }}">
                                    {{ $channel->successRate }}%
                                </span>
                            </div>
                            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2 mb-1">
                                <div class="h-2 rounded-full {{ $channel->successRate >= 95 ? 'bg-green-500' : ($channel->successRate >= 80 ? 'bg-yellow-500' : 'bg-red-500') }}" 
                                     style="width: {{ $channel->successRate }}%"></div>
                            </div>
                            <div class="flex justify-between text-[10px] text-gray-500 dark:text-gray-400">
                                <span>✓ {{ number_format($channel->success) }}</span>
                                <span>✗ {{ number_format($channel->failed) }}</span>
                                <span>~{{ number_format($channel->avgDuration) }}ms</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Por Nivel de Escalación --}}
        @if($byEscalation->isNotEmpty())
            <div>
                <h4 class="text-xs font-medium text-gray-700 dark:text-gray-300 mb-2">Por Nivel de Escalación</h4>
                <div class="flex flex-wrap gap-2">
                    @foreach($byEscalation->sortByDesc('count') as $item)
                        @php
                            $colors = [
                                'critical' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                                'high' => 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
                                'standard' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
                                'low' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200',
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

        @if($totalNotifications === 0)
            <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                <x-pulse::icons.bell class="w-8 h-8 mx-auto mb-2 opacity-50" />
                <p class="text-sm">No hay notificaciones en este período</p>
            </div>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
