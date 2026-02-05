<x-pulse full-width>
    {{-- Header: Métricas del Sistema --}}
    <livewire:pulse.servers cols="full" />

    {{-- Sección SAM: Métricas de Alertas y AI --}}
    <livewire:pulse.alerts-processed cols="4" rows="2" />
    <livewire:pulse.ai-performance cols="4" rows="2" />
    <livewire:pulse.notification-status cols="4" rows="2" />

    {{-- Sección SAM: Copilot y Tokens --}}
    <livewire:pulse.copilot-usage cols="6" rows="2" />
    <livewire:pulse.token-consumption cols="6" rows="2" />

    {{-- Métricas de Queue (crítico para SAM) --}}
    <livewire:pulse.queues cols="6" />
    <livewire:pulse.slow-jobs cols="6" />

    {{-- Métricas de Aplicación --}}
    <livewire:pulse.usage cols="4" rows="2" />
    <livewire:pulse.exceptions cols="4" />
    <livewire:pulse.cache cols="4" />

    {{-- Métricas de Performance --}}
    <livewire:pulse.slow-queries cols="6" />
    <livewire:pulse.slow-outgoing-requests cols="6" />

    {{-- Slow Requests --}}
    <livewire:pulse.slow-requests cols="full" />
</x-pulse>
