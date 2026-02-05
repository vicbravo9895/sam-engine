<?php

namespace App\Pulse\Recorders;

use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Log;
use Laravel\Pulse\Facades\Pulse;

/**
 * Recorder para métricas de notificaciones.
 * 
 * Registra:
 * - Notificaciones por canal (SMS, WhatsApp, Voice)
 * - Tasa de éxito/fallo
 * - Tiempo de entrega
 */
class NotificationRecorder
{
    /**
     * The events to listen for.
     *
     * @var array<int, class-string>
     */
    public array $listen = [
        \App\Events\NotificationSent::class,
    ];

    public function __construct(
        protected Repository $config,
    ) {}

    /**
     * Record a notification event.
     */
    public function record(\App\Events\NotificationSent $event): void
    {
        self::recordNotification(
            channel: $event->channel,
            success: $event->success,
            durationMs: $event->durationMs ?? 0,
            companyId: $event->companyId ?? null,
            escalationLevel: $event->escalationLevel ?? 'standard'
        );
    }

    /**
     * Record directly without event (for use in Jobs).
     */
    public static function recordNotification(
        string $channel,
        bool $success,
        int $durationMs = 0,
        ?int $companyId = null,
        string $escalationLevel = 'standard'
    ): void {
        $status = $success ? 'success' : 'failed';

        // Registrar por canal
        Pulse::record(
            type: 'notification_channel',
            key: $channel,
            value: $durationMs
        )->avg()->count();

        // Registrar status por canal
        Pulse::record(
            type: 'notification_status',
            key: "{$channel}:{$status}",
            value: 1
        )->count();

        // Registrar por nivel de escalación
        Pulse::record(
            type: 'notification_escalation',
            key: $escalationLevel,
            value: 1
        )->count();

        // Registrar totales de éxito/fallo
        Pulse::record(
            type: 'notification_total',
            key: $status,
            value: 1
        )->count();

        // Registrar por company
        if ($companyId) {
            Pulse::record(
                type: 'notification_company',
                key: (string) $companyId,
                value: 1
            )->count();
        }

        Log::debug('Pulse: Notification recorded', [
            'channel' => $channel,
            'success' => $success,
            'duration_ms' => $durationMs,
            'escalation' => $escalationLevel,
        ]);
    }
}
