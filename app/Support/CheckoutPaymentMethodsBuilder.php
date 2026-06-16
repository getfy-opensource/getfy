<?php

namespace App\Support;

use App\Gateways\GatewayRegistry;
use App\Models\GatewayCredential;
use App\Models\Setting;
use App\Models\SubscriptionPlan;

class CheckoutPaymentMethodsBuilder
{
    /**
     * @param  array<string, mixed>  $paymentGateways
     * @return array<int, array{id: string, label: string, gateway_name?: string, gateway_slug?: string}>
     */
    public static function build(?int $tenantId, array $paymentGateways, ?SubscriptionPlan $plan = null): array
    {
        $pg = is_array($paymentGateways) ? $paymentGateways : [];
        $orderRaw = Setting::get('gateway_order', null, $tenantId);
        if (is_string($orderRaw)) {
            $orderRaw = json_decode($orderRaw, true);
        }
        $defaultOrder = config('gateways.default_order', [
            'pix' => [],
            'card' => [],
            'boleto' => [],
            'pix_auto' => [],
            'apple_pay' => [],
            'google_pay' => [],
            'pix_parcelado' => [],
        ]);
        $order = is_array($orderRaw) ? $orderRaw : $defaultOrder;
        $order = [
            'pix' => $order['pix'] ?? $defaultOrder['pix'] ?? [],
            'card' => $order['card'] ?? $defaultOrder['card'] ?? [],
            'boleto' => $order['boleto'] ?? $defaultOrder['boleto'] ?? [],
            'pix_auto' => $order['pix_auto'] ?? $defaultOrder['pix_auto'] ?? [],
            'apple_pay' => $order['apple_pay'] ?? $defaultOrder['apple_pay'] ?? [],
            'google_pay' => $order['google_pay'] ?? $defaultOrder['google_pay'] ?? [],
            'pix_parcelado' => $order['pix_parcelado'] ?? $defaultOrder['pix_parcelado'] ?? [],
        ];

        $credentialBySlug = GatewayCredential::forTenant($tenantId)
            ->where('is_connected', true)
            ->get()
            ->keyBy('gateway_slug');

        $methods = [];
        $methodConfig = [
            'pix' => ['id' => 'pix', 'label' => 'PIX'],
            'card' => ['id' => 'card', 'label' => 'Cartão'],
            'boleto' => ['id' => 'boleto', 'label' => 'Boleto'],
            'pix_auto' => ['id' => 'pix_auto', 'label' => 'PIX automático'],
            'apple_pay' => ['id' => 'apple_pay', 'label' => 'Apple Pay'],
            'google_pay' => ['id' => 'google_pay', 'label' => 'Google Pay'],
            'pix_parcelado' => ['id' => 'pix_parcelado', 'label' => 'PIX Parcelado'],
        ];

        foreach ($methodConfig as $methodKey => $meta) {
            if ($methodKey === 'pix_auto' && $plan === null) {
                continue;
            }
            if ($methodKey === 'pix_parcelado' && $plan !== null) {
                continue;
            }
            $productSlug = isset($pg[$methodKey]) ? trim((string) $pg[$methodKey]) : null;
            if ($productSlug === null || $productSlug === '') {
                continue;
            }
            if ($productSlug === '__default__') {
                $slugsToCheck = is_array($order[$methodKey] ?? null) ? $order[$methodKey] : [];
            } else {
                $redundancy = $pg[$methodKey . '_redundancy'] ?? [];
                $redundancy = is_array($redundancy) ? $redundancy : [];
                $slugsToCheck = array_merge([$productSlug], $redundancy);
            }

            foreach ($slugsToCheck as $slug) {
                $cred = $credentialBySlug->get($slug);
                if (! $cred) {
                    continue;
                }
                $gateway = GatewayRegistry::get($slug);
                if (! $gateway || ! in_array($methodKey, $gateway['methods'] ?? [], true)) {
                    continue;
                }
                if (! self::gatewaySupportsMethod($slug, $methodKey, $cred)) {
                    continue;
                }
                $methods[] = [
                    'id' => $meta['id'],
                    'label' => $meta['label'],
                    'gateway_slug' => $slug,
                    'gateway_name' => $gateway['name'] ?? $slug,
                ];
                break;
            }
        }

        return $methods;
    }

    /**
     * @param  array<int, array{id: string, label: string, gateway_slug?: string}>  $methods
     * @return array<int, string>
     */
    public static function methodIds(array $methods): array
    {
        return array_values(array_unique(array_map(fn ($m) => (string) ($m['id'] ?? ''), $methods)));
    }

    /**
     * @param  array<int, array{id: string, gateway_slug?: string}>  $methods
     */
    public static function findMethod(array $methods, string $methodId): ?array
    {
        foreach ($methods as $m) {
            if (($m['id'] ?? '') === $methodId) {
                return $m;
            }
        }

        return null;
    }

    private static function gatewaySupportsMethod(string $slug, string $methodKey, GatewayCredential $cred): bool
    {
        $creds = $cred->getDecryptedCredentials();

        if ($slug === 'stripe' && $methodKey === 'card') {
            return trim((string) ($creds['publishable_key'] ?? '')) !== '';
        }
        if ($slug === 'efi' && in_array($methodKey, ['card', 'boleto', 'pix', 'pix_auto'], true)) {
            $certPath = (string) ($creds['certificate_path'] ?? '');
            if (in_array($methodKey, ['card', 'boleto'], true)) {
                return trim((string) ($creds['payee_code'] ?? '')) !== ''
                    && $certPath !== ''
                    && is_file($certPath);
            }

            return $certPath !== '' && is_file($certPath);
        }
        if ($slug === 'pagarme' && $methodKey === 'card') {
            return trim((string) ($creds['public_key'] ?? '')) !== '';
        }
        if ($slug === 'mercadopago' && in_array($methodKey, ['card', 'pix', 'boleto'], true)) {
            return trim((string) ($creds['public_key'] ?? '')) !== '';
        }
        if ($slug === 'cajupay' && in_array($methodKey, ['card', 'apple_pay', 'google_pay', 'pix', 'boleto', 'pix_parcelado'], true)) {
            if (trim((string) ($creds['public_key'] ?? '')) === ''
                || trim((string) ($creds['secret_key'] ?? '')) === '') {
                return false;
            }
            if ($methodKey === 'pix_parcelado') {
                return ! self::cajupayPixParceladoBlocked($creds);
            }

            return true;
        }

        return true;
    }

    /**
     * PIX Parcelado aparece no checkout quando CajuPay está conectada.
     * Adesão ao contrato (enrollment) é validada na sessão/confirmação do pagamento.
     *
     * @param  array<string, mixed>  $credentials
     */
    private static function cajupayPixParceladoBlocked(array $credentials): bool
    {
        return strtolower(trim((string) ($credentials['pix_parcelado_enrollment_status'] ?? ''))) === 'suspended';
    }
}
