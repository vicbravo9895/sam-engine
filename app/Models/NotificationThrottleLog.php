<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * Modelo para log de throttling de notificaciones.
 * 
 * Almacena timestamps de notificaciones para aplicar throttling por vehículo/conductor.
 * Reemplaza el almacenamiento en memoria que se perdía al reiniciar.
 */
class NotificationThrottleLog extends Model
{
    use HasFactory;
    /**
     * The table associated with the model.
     */
    protected $table = 'notification_throttle_logs';

    /**
     * Disable timestamps - using custom timestamp column.
     */
    public $timestamps = false;

    protected $fillable = [
        'throttle_key',
        'notification_timestamp',
        'alert_id',
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
     * Alert that triggered this notification.
     */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
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
        if (! Schema::hasTable('notification_throttle_logs')) {
            return [false, null];
        }
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
    public static function record(string $throttleKey, ?int $alertId = null): ?self
    {
        if (! Schema::hasTable('notification_throttle_logs')) {
            return null;
        }
        return self::create([
            'throttle_key' => $throttleKey,
            'notification_timestamp' => now(),
            'alert_id' => $alertId,
        ]);
    }

    /**
     * Clean up old entries.
     */
    public static function cleanupOld(int $olderThanMinutes = 60): int
    {
        if (! Schema::hasTable('notification_throttle_logs')) {
            return 0;
        }
        return self::where('notification_timestamp', '<', now()->subMinutes($olderThanMinutes))->delete();
    }

    /**
     * Get count in window for a throttle key.
     */
    public static function getCountInWindow(string $throttleKey, int $windowMinutes = 30): int
    {
        if (! Schema::hasTable('notification_throttle_logs')) {
            return 0;
        }
        return self::where('throttle_key', $throttleKey)
            ->where('notification_timestamp', '>', now()->subMinutes($windowMinutes))
            ->count();
    }
}
