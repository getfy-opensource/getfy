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

    public static function intervalMinutes(Order $order): int
    {
        if (! static::isPixOrder($order)) {
            return max(1, (int) config('payment_reconciliation.legacy_interval_minutes', 2));
        }

        $ageMinutes = static::ageMinutes($order);

        foreach (static::pixTiers() as $tier) {
            if ($ageMinutes <= (int) $tier['max_age_minutes']) {
                return max(1, (int) $tier['interval_minutes']);
            }
        }

        // PIX older than last tier: slow poll so unpaid orders stay pending and can still settle
        return max(1, (int) config('payment_reconciliation.pix_stale_interval_minutes', 60));
    }

    public static function isDue(Order $order): bool
    {
        $intervalMinutes = static::intervalMinutes($order);
        $lastCheckedAt = static::lastCheckedAt($order);

        if ($lastCheckedAt === null) {
            return static::ageMinutes($order) >= $intervalMinutes;
        }

        return now()->greaterThanOrEqualTo($lastCheckedAt->copy()->addMinutes($intervalMinutes));
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
        $meta['reconcile_last_checked_at'] = now()->toIso8601String();
        $order->update(['metadata' => $meta]);
    }

    /**
     * @return array<int, array{max_age_minutes: int, interval_minutes: int}>
     */
    private static function pixTiers(): array
    {
        $tiers = config('payment_reconciliation.pix_tiers', []);

        return is_array($tiers) ? $tiers : [];
    }
}
