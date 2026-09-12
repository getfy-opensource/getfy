<?php

namespace App\Support;

use App\Models\Order;
use Carbon\Carbon;

class PendingPaymentReconcileSchedule
{
    public static function isPixOrder(Order $order): bool
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $method = $meta['checkout_payment_method'] ?? null;

        return PendingPixCheckoutResolver::isPixLikePaymentMethod(
            is_string($method) ? $method : null
        );
    }

    public static function ageMinutes(Order $order): int
    {
        $createdAt = $order->created_at;
        if (! $createdAt instanceof Carbon) {
            return 0;
        }

        return (int) max(0, $createdAt->diffInMinutes(now()));
    }

    /**
     * Fixed attempt ages in minutes since order creation (e.g. 5, 10, 15).
     *
     * @return list<int>
     */
    public static function attemptAgesMinutes(): array
    {
        $ages = config('payment_reconciliation.attempt_ages_minutes', [5, 10, 15]);
        if (! is_array($ages)) {
            return [5, 10, 15];
        }

        $normalized = [];
        foreach ($ages as $age) {
            $minutes = (int) $age;
            if ($minutes > 0) {
                $normalized[] = $minutes;
            }
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized !== [] ? $normalized : [5, 10, 15];
    }

    public static function attemptCount(Order $order): int
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $count = $meta['reconcile_attempt_count'] ?? 0;

        return max(0, (int) $count);
    }

    public static function isExhausted(Order $order): bool
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        if (! empty($meta['reconcile_exhausted_at'])) {
            return true;
        }

        return static::attemptCount($order) >= count(static::attemptAgesMinutes());
    }

    /**
     * Next attempt index (0-based) that is due for this order age, or null if none.
     */
    public static function dueAttemptIndex(Order $order): ?int
    {
        if (static::isExhausted($order)) {
            return null;
        }

        $ages = static::attemptAgesMinutes();
        $done = static::attemptCount($order);
        $ageMinutes = static::ageMinutes($order);

        if ($done >= count($ages)) {
            return null;
        }

        $nextAge = $ages[$done];
        if ($ageMinutes < $nextAge) {
            return null;
        }

        return $done;
    }

    public static function isDue(Order $order): bool
    {
        return static::dueAttemptIndex($order) !== null;
    }

    /**
     * Auto-cancel unpaid PIX by age. Disabled when pix_max_age_minutes <= 0.
     */
    public static function shouldExpirePix(Order $order): bool
    {
        if (! static::isPixOrder($order)) {
            return false;
        }

        $maxAgeMinutes = (int) config('payment_reconciliation.pix_max_age_minutes', 0);
        if ($maxAgeMinutes <= 0) {
            return false;
        }

        return static::ageMinutes($order) > $maxAgeMinutes;
    }

    public static function lastCheckedAt(Order $order): ?Carbon
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $raw = $meta['reconcile_last_checked_at'] ?? null;

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function markChecked(Order $order): void
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $ages = static::attemptAgesMinutes();
        $count = max(0, (int) ($meta['reconcile_attempt_count'] ?? 0)) + 1;

        $meta['reconcile_last_checked_at'] = now()->toIso8601String();
        $meta['reconcile_attempt_count'] = $count;

        if ($count >= count($ages)) {
            $meta['reconcile_exhausted_at'] = now()->toIso8601String();
        }

        $order->update(['metadata' => $meta]);
    }
}
