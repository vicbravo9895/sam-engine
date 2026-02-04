<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo para log de throttling de notificaciones.
 * 
 * Almacena timestamps de notificaciones para aplicar throttling por vehículo/conductor.
 * Reemplaza el almacenamiento en memoria que se perdía al reiniciar.
 */
class NotificationThrottleLog extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'notification_throttle_log';

    /**
     * Disable timestamps - using custom timestamp column.
     */
    public $timestamps = false;

    protected $fillable = [
        'throttle_key',
        'notification_timestamp',
        'samsara_event_id',
    ];

    protected $casts = [
        'notification_timestamp' => 'datetime',
    ];

    /**
     * ========================================
     * RELATIONSHIPS
     * ========================================
     */

    /**
     * Event that triggered this notification.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(SamsaraEvent::class, 'samsara_event_id');
    }

    /**
     * ========================================
     * STATIC HELPERS
     * ========================================
     */

    /**
     * Generate throttle key from vehicle and driver IDs.
     */
    public static function generateKey(?string $vehicleId, ?string $driverId): string
    {
        $parts = [];

        if ($vehicleId) {
            $parts[] = "v:{$vehicleId}";
        }

        if ($driverId) {
            $parts[] = "d:{$driverId}";
        }

        if (empty($parts)) {
            return 'global';
        }

        return implode(':', $parts);
    }

    /**
     * Check if should throttle.
     * 
     * @param string $throttleKey
     * @param int $windowMinutes Time window in minutes (default 30)
     * @param int $maxNotifications Max notifications in window (default 5)
     * @return array [bool $shouldThrottle, ?string $reason]
     */
    public static function shouldThrottle(
        string $throttleKey,
        int $windowMinutes = 30,
        int $maxNotifications = 5
    ): array {
        // Clean up old entries first
        self::cleanupOld($windowMinutes * 2);

        // Count notifications in window
        $windowStart = now()->subMinutes($windowMinutes);
        $count = self::where('throttle_key', $throttleKey)
            ->where('notification_timestamp', '>', $windowStart)
            ->count();

        if ($count >= $maxNotifications) {
            return [
                true,
                "Límite de {$maxNotifications} notificaciones en {$windowMinutes} minutos alcanzado",
            ];
        }

        return [false, null];
    }

    /**
     * Record a notification.
     */
    public static function record(string $throttleKey, ?int $eventId = null): self
    {
        return self::create([
            'throttle_key' => $throttleKey,
            'notification_timestamp' => now(),
            'samsara_event_id' => $eventId,
        ]);
    }

    /**
     * Clean up old entries.
     */
    public static function cleanupOld(int $olderThanMinutes = 60): int
    {
        return self::where('notification_timestamp', '<', now()->subMinutes($olderThanMinutes))->delete();
    }

    /**
     * Get count in window for a throttle key.
     */
    public static function getCountInWindow(string $throttleKey, int $windowMinutes = 30): int
    {
        return self::where('throttle_key', $throttleKey)
            ->where('notification_timestamp', '>', now()->subMinutes($windowMinutes))
            ->count();
    }
}
