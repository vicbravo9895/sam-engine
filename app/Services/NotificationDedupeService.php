<?php

namespace App\Services;

use App\Models\NotificationDedupeLog;
use App\Models\NotificationThrottleLog;
use Illuminate\Support\Facades\Log;

/**
 * Service for notification deduplication and throttling.
 * 
 * Uses persistent database storage instead of in-memory stores.
 * This ensures dedupe/throttle state survives service restarts.
 */
class NotificationDedupeService
{
    /**
     * Default TTL for dedupe keys in hours.
     */
    protected int $dedupeTtlHours = 24;

    /**
     * Default throttle window in minutes.
     */
    protected int $throttleWindowMinutes = 30;

    /**
     * Maximum notifications in throttle window.
     */
    protected int $throttleMaxNotifications = 5;

    /**
     * Constructor with configurable settings.
     */
    public function __construct(
        ?int $dedupeTtlHours = null,
        ?int $throttleWindowMinutes = null,
        ?int $throttleMaxNotifications = null
    ) {
        $this->dedupeTtlHours = $dedupeTtlHours ?? config('sam.notification.dedupe_ttl_hours', 24);
        $this->throttleWindowMinutes = $throttleWindowMinutes ?? config('sam.notification.throttle_window_minutes', 30);
        $this->throttleMaxNotifications = $throttleMaxNotifications ?? config('sam.notification.throttle_max_notifications', 5);
    }

    /**
     * Check if a notification should be sent (not duplicate, not throttled).
     * 
     * @param string $dedupeKey Unique key for deduplication
     * @param string|null $vehicleId Vehicle ID for throttling
     * @param string|null $driverId Driver ID for throttling
     * @param int|null $eventId Event ID for logging
     * @return array ['should_send' => bool, 'reason' => ?string, 'throttled' => bool]
     */
    public function shouldSend(
        string $dedupeKey,
        ?string $vehicleId = null,
        ?string $driverId = null,
        ?int $eventId = null
    ): array {
        // Check deduplication first
        if ($this->isDuplicate($dedupeKey)) {
            return [
                'should_send' => false,
                'reason' => 'Notificación duplicada (dedupe_key ya procesado)',
                'throttled' => false,
            ];
        }

        // Check throttling
        $throttleKey = NotificationThrottleLog::generateKey($vehicleId, $driverId);
        [$shouldThrottle, $throttleReason] = $this->shouldThrottle($throttleKey);

        if ($shouldThrottle) {
            return [
                'should_send' => false,
                'reason' => $throttleReason,
                'throttled' => true,
            ];
        }

        // Record that we're going to send
        $this->recordNotification($throttleKey, $eventId);

        return [
            'should_send' => true,
            'reason' => null,
            'throttled' => false,
        ];
    }

    /**
     * Check if a dedupe key is a duplicate.
     * Also marks the key as seen if it's new.
     * 
     * @param string $dedupeKey
     * @return bool True if duplicate, false if new
     */
    public function isDuplicate(string $dedupeKey): bool
    {
        if (empty($dedupeKey)) {
            return false;
        }

        $isDuplicate = NotificationDedupeLog::isDuplicate($dedupeKey, $this->dedupeTtlHours);

        if ($isDuplicate) {
            Log::info('NotificationDedupeService: Duplicate dedupe_key detected', [
                'dedupe_key' => $dedupeKey,
            ]);
        }

        return $isDuplicate;
    }

    /**
     * Check if should throttle based on vehicle/driver.
     * 
     * @param string $throttleKey
     * @return array [bool $shouldThrottle, ?string $reason]
     */
    public function shouldThrottle(string $throttleKey): array
    {
        [$shouldThrottle, $reason] = NotificationThrottleLog::shouldThrottle(
            $throttleKey,
            $this->throttleWindowMinutes,
            $this->throttleMaxNotifications
        );

        if ($shouldThrottle) {
            Log::info('NotificationDedupeService: Throttling notification', [
                'throttle_key' => $throttleKey,
                'reason' => $reason,
            ]);
        }

        return [$shouldThrottle, $reason];
    }

    /**
     * Record a notification being sent.
     * 
     * @param string $throttleKey
     * @param int|null $eventId
     */
    public function recordNotification(string $throttleKey, ?int $eventId = null): void
    {
        NotificationThrottleLog::record($throttleKey, $eventId);
    }

    /**
     * Get dedupe stats for a key.
     */
    public function getDedupeStats(string $dedupeKey): ?array
    {
        return NotificationDedupeLog::getStats($dedupeKey);
    }

    /**
     * Get throttle count for a key in the current window.
     */
    public function getThrottleCount(string $throttleKey): int
    {
        return NotificationThrottleLog::getCountInWindow($throttleKey, $this->throttleWindowMinutes);
    }

    /**
     * Cleanup expired entries.
     * Should be called periodically (e.g., daily via scheduled command).
     */
    public function cleanup(): array
    {
        $dedupeDeleted = NotificationDedupeLog::cleanupExpired($this->dedupeTtlHours);
        $throttleDeleted = NotificationThrottleLog::cleanupOld($this->throttleWindowMinutes * 2);

        Log::info('NotificationDedupeService: Cleanup completed', [
            'dedupe_entries_deleted' => $dedupeDeleted,
            'throttle_entries_deleted' => $throttleDeleted,
        ]);

        return [
            'dedupe_deleted' => $dedupeDeleted,
            'throttle_deleted' => $throttleDeleted,
        ];
    }

    /**
     * Force mark a dedupe key as processed (for idempotency).
     */
    public function markAsProcessed(string $dedupeKey): void
    {
        if (empty($dedupeKey)) {
            return;
        }

        // This will create the entry if it doesn't exist
        NotificationDedupeLog::isDuplicate($dedupeKey, $this->dedupeTtlHours);
    }

    /**
     * Reset throttle for a specific key (admin use).
     */
    public function resetThrottle(string $throttleKey): int
    {
        return NotificationThrottleLog::where('throttle_key', $throttleKey)->delete();
    }

    /**
     * Generate a dedupe key from event data.
     */
    public static function generateDedupeKey(
        string $vehicleId,
        string $eventTime,
        string $alertType
    ): string {
        return "{$vehicleId}:{$eventTime}:{$alertType}";
    }

    /**
     * Generate throttle key from vehicle/driver IDs.
     */
    public static function generateThrottleKey(?string $vehicleId, ?string $driverId): string
    {
        return NotificationThrottleLog::generateKey($vehicleId, $driverId);
    }
}
