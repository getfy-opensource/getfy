<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'tenant_id', 'user_id', 'product_id', 'product_offer_id', 'subscription_plan_id',
        'api_application_id', 'api_checkout_session_id',
        'status', 'amount', 'email', 'cpf', 'phone', 'customer_ip', 'coupon_code',
        'gateway', 'gateway_id', 'payment_method', 'approved_manually', 'metadata', 'period_start', 'period_end', 'is_renewal',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'metadata' => 'array',
            'period_start' => 'date',
            'period_end' => 'date',
            'is_renewal' => 'boolean',
            'approved_manually' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productOffer(): BelongsTo
    {
        return $this->belongsTo(ProductOffer::class);
    }

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    public function apiApplication(): BelongsTo
    {
        return $this->belongsTo(ApiApplication::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('position');
    }

    public function getCheckoutSlug(): string
    {
        if ($this->productOffer && $this->productOffer->checkout_slug) {
            return $this->productOffer->checkout_slug;
        }
        if ($this->subscriptionPlan && $this->subscriptionPlan->checkout_slug) {
            return $this->subscriptionPlan->checkout_slug;
        }
        return $this->product?->checkout_slug ?? '';
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $tenantId === null
            ? $query->whereNull('tenant_id')
            : $query->where('tenant_id', $tenantId);
    }
}
