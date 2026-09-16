<?php

namespace App\Support;

use App\Models\Product;

/**
 * Flags de Cartão Brasil (parcelamento + 3DS) para POST /api/sdk/v1/checkout/sessions.
 *
 * @see docs/Docs Gateways/docs-cajupay.md módulo 06-card-embedded
 */
final class CajuPayCardSessionOptions
{
    /**
     * @param  array<string, mixed>|null  $checkoutConfig  checkout_config do produto (já merged ou raw)
     * @return array{
     *     allow_card_installments?: bool,
     *     card_max_installments?: int,
     *     require_card_threeds?: bool,
     *     save_card?: bool
     * }
     */
    public static function fromCheckoutConfig(?array $checkoutConfig, string $paymentMethod, bool $saveCard = false): array
    {
        if ($paymentMethod !== 'card') {
            return [];
        }

        $config = is_array($checkoutConfig) ? $checkoutConfig : [];
        $defaults = Product::defaultCheckoutConfig();
        $merged = array_replace_recursive($defaults, $config);

        $options = [];

        $installments = is_array($merged['card_installments'] ?? null) ? $merged['card_installments'] : [];
        $enabled = ! empty($installments['enabled']);
        $max = min(12, max(1, (int) ($installments['max'] ?? 1)));
        if ($enabled && $max >= 2) {
            $options['allow_card_installments'] = true;
            $options['card_max_installments'] = $max;
        } else {
            // Explícito: produto sem parcelamento → sessão só à vista (não herdar default da conta).
            $options['allow_card_installments'] = false;
        }

        $cajupayCard = is_array($merged['cajupay_card'] ?? null) ? $merged['cajupay_card'] : [];
        if (! empty($cajupayCard['require_threeds'])) {
            $options['require_card_threeds'] = true;
        }

        if ($saveCard) {
            $options['save_card'] = true;
        }

        return $options;
    }

    /**
     * Snapshot para draft/cache/order metadata.
     *
     * @param  array<string, mixed>  $cardOptions
     * @return array{
     *     allow_card_installments: bool,
     *     card_max_installments: int|null,
     *     require_card_threeds: bool,
     *     save_card: bool
     * }
     */
    public static function draftSnapshot(array $cardOptions): array
    {
        return [
            'allow_card_installments' => ! empty($cardOptions['allow_card_installments']),
            'card_max_installments' => ! empty($cardOptions['allow_card_installments'])
                ? min(12, max(1, (int) ($cardOptions['card_max_installments'] ?? 1)))
                : null,
            'require_card_threeds' => ! empty($cardOptions['require_card_threeds']),
            'save_card' => ! empty($cardOptions['save_card']),
        ];
    }
}
