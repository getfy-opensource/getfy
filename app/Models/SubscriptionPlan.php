<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SubscriptionPlan extends Model
{
    public const INTERVAL_WEEKLY = 'weekly';
    public const INTERVAL_MONTHLY = 'monthly';
    public const INTERVAL_QUARTERLY = 'quarterly';
    public const INTERVAL_SEMI_ANNUAL = 'semi_annual';
    public const INTERVAL_ANNUAL = 'annual';
    public const INTERVAL_LIFETIME = 'lifetime';

    protected $fillable = [
        'product_id',
        'name',
        'price',
        'currency',
        'interval',
        'checkout_slug',
        'checkout_config',
        'position',
        'gateway_plan_id',
        'combo_product_ids',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'checkout_config' => 'array',
            'combo_product_ids' => 'array',
        ];
    }

    /**
     * checkout_slug não é gerado por padrão: planos usam o checkout principal.
     * Só é definido quando o usuário cria um checkout exclusivo (ensureCheckoutSlug).
     */
    public static function generateUniqueCheckoutSlug(): string
    {
        do {
            $slug = Str::lower(Str::random(7));
        } while (static::slugExists($slug));

        return $slug;
    }

    public static function slugExists(string $slug): bool
    {
        return static::where('checkout_slug', $slug)->exists()
            || Product::where('checkout_slug', $slug)->exists()
            || ProductOffer::where('checkout_slug', $slug)->exists();
    }

    public static function intervalLabels(): array
    {
        return [
            self::INTERVAL_WEEKLY => 'Semanal',
            self::INTERVAL_MONTHLY => 'Mensal',
            self::INTERVAL_QUARTERLY => 'Trimestral',
            self::INTERVAL_SEMI_ANNUAL => 'Semestral',
            self::INTERVAL_ANNUAL => 'Anual',
            self::INTERVAL_LIFETIME => 'Vitalício',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isLifetime(): bool
    {
        return $this->interval === self::INTERVAL_LIFETIME;
    }

    public function getCurrencyOrDefault(): string
    {
        return $this->currency ?? $this->product?->currency ?? 'BRL';
    }

    /**
     * Return [period_start, period_end] for the current period (from now).
     * For lifetime, period_end is null (no renewal).
     *
     * @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon|null}
     */
    public function getCurrentPeriod(): array
    {
        $start = now()->startOfDay();
        if ($this->interval === self::INTERVAL_LIFETIME) {
            return [$start, null];
        }
        $end = match ($this->interval) {
            self::INTERVAL_WEEKLY => $start->copy()->addWeek(),
            self::INTERVAL_MONTHLY => $start->copy()->addMonth(),
            self::INTERVAL_QUARTERLY => $start->copy()->addMonths(3),
            self::INTERVAL_SEMI_ANNUAL => $start->copy()->addMonths(6),
            self::INTERVAL_ANNUAL => $start->copy()->addYear(),
            default => $start->copy()->addMonth(),
        };
        return [$start, $end];
    }
}
