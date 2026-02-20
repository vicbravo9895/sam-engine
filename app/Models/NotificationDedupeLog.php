<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Modelo para log de deduplicación de notificaciones.
 * 
 * Almacena dedupe keys para evitar notificaciones duplicadas.
 * Reemplaza el almacenamiento en memoria que se perdía al reiniciar.
 */
class NotificationDedupeLog extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'notification_dedupe_logs';

    /**
     * Disable timestamps - using custom timestamp columns.
     */
    public $timestamps = false;

    /**
     * The primary key is dedupe_key (string).
     */
    protected $primaryKey = 'dedupe_key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'dedupe_key',
        'first_seen_at',
        'last_seen_at',
        'count',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'count' => 'integer',
    ];

    /**
     * ========================================
     * STATIC HELPERS
     * ========================================
     */

    /**
     * Check if dedupe key exists (is duplicate).
     * 
     * @param string $dedupeKey
     * @param int $ttlHours TTL in hours (default 24)
     * @return bool True if duplicate, false if new
     */
    public static function isDuplicate(string $dedupeKey, int $ttlHours = 24): bool
    {
        if (empty($dedupeKey)) {
            return false;
        }
        if (! Schema::hasTable('notification_dedupe_logs')) {
            return false;
        }

        // Clean up expired entries
        self::cleanupExpired($ttlHours);

        // Check if exists
        $existing = self::find($dedupeKey);

        if ($existing) {
            // Update last seen and count
            $existing->update([
                'last_seen_at' => now(),
                'count' => $existing->count + 1,
            ]);
            return true;
        }

        // Create new entry
        self::create([
            'dedupe_key' => $dedupeKey,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'count' => 1,
        ]);

        return false;
    }

    /**
     * Clean up expired entries.
     */
    public static function cleanupExpired(int $ttlHours = 24): int
    {
        if (! Schema::hasTable('notification_dedupe_logs')) {
            return 0;
        }
        return self::where('last_seen_at', '<', now()->subHours($ttlHours))->delete();
    }

    /**
     * Get statistics for a dedupe key.
     */
    public static function getStats(string $dedupeKey): ?array
    {
        if (! Schema::hasTable('notification_dedupe_logs')) {
            return null;
        }
        $entry = self::find($dedupeKey);

        if (!$entry) {
            return null;
        }

        return [
            'dedupe_key' => $entry->dedupe_key,
            'first_seen_at' => $entry->first_seen_at,
            'last_seen_at' => $entry->last_seen_at,
            'count' => $entry->count,
        ];
    }
}
