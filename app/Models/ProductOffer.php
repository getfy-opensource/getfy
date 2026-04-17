<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProductOffer extends Model
{
    protected $fillable = [
        'product_id',
        'name',
        'price',
        'currency',
        'checkout_slug',
        'checkout_config',
        'position',
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
     * checkout_slug não é gerado por padrão: ofertas usam o checkout principal.
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
            || SubscriptionPlan::where('checkout_slug', $slug)->exists();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getCurrencyOrDefault(): string
    {
        return $this->currency ?? $this->product?->currency ?? 'BRL';
    }
}
