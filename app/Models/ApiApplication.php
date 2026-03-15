<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ApiApplication extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'logo',
        'checkout_sidebar_bg',
        'api_key_hash',
        'payment_gateways',
        'allowed_ips',
        'webhook_url',
        'default_return_url',
        'webhook_secret',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'payment_gateways' => 'array',
            'allowed_ips' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Default payment_gateways structure (same as Product checkout_config).
     *
     * @return array<string, mixed>
     */
    public static function defaultPaymentGateways(): array
    {
        return [
            'pix' => null,
            'pix_redundancy' => [],
            'card' => null,
            'card_redundancy' => [],
            'boleto' => null,
            'boleto_redundancy' => [],
            'pix_auto' => null,
            'pix_auto_redundancy' => [],
            'crypto' => null,
            'crypto_redundancy' => [],
        ];
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        if ($tenantId === null) {
            return $query->whereNull('tenant_id');
        }
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Verify that the given plain API key matches the stored hash.
     */
    public function verifyApiKey(string $plainKey): bool
    {
        return password_verify($plainKey, $this->api_key_hash);
    }

    /**
     * Hash an API key for storage. Use this when creating/regenerating keys.
     */
    public static function hashApiKey(string $plainKey): string
    {
        return password_hash($plainKey, PASSWORD_DEFAULT);
    }

    /**
     * Check if the given IP is allowed (empty allowed_ips = all allowed).
     */
    public function isIpAllowed(?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return true;
        }
        $allowed = $this->allowed_ips;
        if (! is_array($allowed) || count($allowed) === 0) {
            return true;
        }
        return in_array($ip, $allowed, true);
    }

    public function apiCheckoutSessions(): HasMany
    {
        return $this->hasMany(ApiCheckoutSession::class, 'api_application_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'api_application_id');
    }

    /**
     * Generate a unique slug for the tenant.
     */
    public static function generateUniqueSlug(?int $tenantId, string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'app';
        }
        $slug = $base;
        $n = 0;
        while (static::forTenant($tenantId)->where('slug', $slug)->exists()) {
            $n++;
            $slug = $base . '-' . $n;
        }
        return $slug;
    }
}
