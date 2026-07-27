<?php

namespace App\Support;

use App\Models\PayoutRequest;

class WalletBucket
{
    /**
     * @return array<string, list<string>>
     */
    public static function definitions(): array
    {
        return config('commissions.wallet_buckets', [
            'pix' => ['pix', 'pix_auto'],
            'card' => ['card', 'apple_pay', 'google_pay', 'paypal'],
            'boleto' => ['boleto'],
        ]);
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::definitions());
    }

    public static function isValid(string $bucket): bool
    {
        return array_key_exists($bucket, self::definitions());
    }

    public static function resolveFromPaymentMethod(?string $method): string
    {
        $method = strtolower((string) ($method ?? 'pix'));
        if ($method === '') {
            $method = 'pix';
        }

        foreach (self::definitions() as $bucket => $methods) {
            if (in_array($method, $methods, true)) {
                return $bucket;
            }
        }

        return PayoutRequest::BUCKET_PIX;
    }

    /**
     * @return list<string>
     */
    public static function paymentMethodsFor(string $bucket): array
    {
        return self::definitions()[$bucket] ?? [];
    }

    public static function label(string $bucket): string
    {
        return match ($bucket) {
            PayoutRequest::BUCKET_PIX => 'PIX',
            PayoutRequest::BUCKET_CARD => 'Cartão',
            PayoutRequest::BUCKET_BOLETO => 'Boleto',
            default => ucfirst($bucket),
        };
    }
}
