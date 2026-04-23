<?php

namespace App\Services;

use App\Gateways\GatewayRegistry;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

class PaymentService
{
    private const PAYMENT_TOTAL_TIMEOUT_SECONDS = 25;
    private const SLOW_GATEWAY_CALL_MS = 1500;

    /**
     * Create a PIX payment for the order. Tries gateways in redundancy order until one succeeds.
     *
     * @param  array{name: string, document: string, email: string}  $consumer
     * @param  array<string, mixed>|null  $gatewayConfigOverride  When set (e.g. from API application), used instead of product's payment_gateways.
     * @return array{transaction_id: string, gateway: string, qrcode?: string, copy_paste?: string}
     */
    public function createPixPayment(Order $order, ?Product $product, array $consumer, ?array $gatewayConfigOverride = null): array
    {
        $tenantId = $order->tenant_id;
        $orderSlugs = $this->getGatewayOrderForMethod($tenantId, 'pix', $product, $gatewayConfigOverride);
        $lastException = null;
        $deadline = microtime(true) + self::PAYMENT_TOTAL_TIMEOUT_SECONDS;

        foreach ($orderSlugs as $gatewaySlug) {
            if (microtime(true) > $deadline) {
                break;
            }
            $credential = GatewayCredential::forTenant($tenantId)
                ->where('gateway_slug', $gatewaySlug)
                ->where('is_connected', true)
                ->first();
            if (! $credential) {
                continue;
            }
            $credentials = $credential->getDecryptedCredentials();
            if (empty($credentials)) {
                continue;
            }
            $driver = GatewayRegistry::driver($gatewaySlug);
            if (! $driver) {
                continue;
            }
            try {
                $startedAt = microtime(true);
                $postbackUrl = route('webhooks.spacepag');
                if ($gatewaySlug === 'efi') {
                    $postbackUrl = route('webhooks.efi.pix');
                } elseif ($gatewaySlug !== 'spacepag') {
                    $postbackUrl = $this->webhookUrlForGateway($gatewaySlug);
                }
                $result = $driver->createPixPayment(
                    $credentials,
                    (float) $order->amount,
                    $consumer,
                    (string) $order->id,
                    $postbackUrl
                );
                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
                $order->update([
                    'gateway' => $gatewaySlug,
                    'gateway_id' => $result['transaction_id'] ?? null,
                ]);
                if ($durationMs >= self::SLOW_GATEWAY_CALL_MS) {
                    Log::info('PaymentService: PIX gateway slow success.', [
                        'gateway' => $gatewaySlug,
                        'order_id' => $order->id,
                        'tenant_id' => $tenantId,
                        'duration_ms' => $durationMs,
                    ]);
                }
                return [
                    'transaction_id' => $result['transaction_id'] ?? '',
                    'gateway' => $gatewaySlug,
                    'qrcode' => $result['qrcode'] ?? null,
                    'copy_paste' => $result['copy_paste'] ?? null,
                ];
            } catch (\Throwable $e) {
                Log::warning('PaymentService: PIX gateway failed.', [
                    'gateway' => $gatewaySlug,
                    'order_id' => $order->id,
                    'message' => $e->getMessage(),
                    'duration_ms' => isset($startedAt) ? (int) round((microtime(true) - $startedAt) * 1000) : null,
                ]);
                $lastException = $e;
            }
        }

        if ($lastException) {
            throw $lastException;
        }
        throw new \RuntimeException('Nenhum gateway PIX configurado ou disponível.');
    }

    /**
     * Cria pagamento com cartão. Tenta gateways na ordem de redundância até um suceder.
     *
     * @param  array{name: string, document: string, email: string}  $consumer
     * @param  array{payment_token: string, card_mask?: string}  $card
     * @param  array<string, mixed>|null  $gatewayConfigOverride  When set (e.g. from API application), used instead of product's payment_gateways.
     * @return array{transaction_id: string, gateway: string, status?: string}
     */
    public function createCardPayment(Order $order, ?Product $product, array $consumer, array $card, ?array $gatewayConfigOverride = null): array
    {
        $tenantId = $order->tenant_id;
        $orderSlugs = $this->getGatewayOrderForMethod($tenantId, 'card', $product, $gatewayConfigOverride);
        $lastException = null;
        $deadline = microtime(true) + self::PAYMENT_TOTAL_TIMEOUT_SECONDS;

        foreach ($orderSlugs as $gatewaySlug) {
            if (microtime(true) > $deadline) {
                break;
            }
            $credential = GatewayCredential::forTenant($tenantId)
                ->where('gateway_slug', $gatewaySlug)
                ->where('is_connected', true)
                ->first();
            if (! $credential) {
                continue;
            }
            $credentials = $credential->getDecryptedCredentials();
            if (empty($credentials)) {
                continue;
            }
            $driver = GatewayRegistry::driver($gatewaySlug);
            if (! $driver) {
                continue;
            }
            try {
                $startedAt = microtime(true);
                $result = $driver->createCardPayment(
                    $credentials,
                    (float) $order->amount,
                    $consumer,
                    (string) $order->id,
                    $card
                );
                $order->update([
                    'gateway' => $gatewaySlug,
                    'gateway_id' => $result['transaction_id'] ?? null,
                ]);
                $return = [
                    'transaction_id' => $result['transaction_id'] ?? '',
                    'gateway' => $gatewaySlug,
                    'status' => $result['status'] ?? null,
                ];
                if (isset($result['client_secret'])) {
                    $return['client_secret'] = $result['client_secret'];
                }
                return $return;
            } catch (\Throwable $e) {
                Log::warning('PaymentService: cartão gateway falhou.', [
                    'gateway' => $gatewaySlug,
                    'order_id' => $order->id,
                    'message' => $e->getMessage(),
                    'duration_ms' => isset($startedAt) ? (int) round((microtime(true) - $startedAt) * 1000) : null,
                ]);
                $lastException = $e;
            }
        }

        if ($lastException) {
            throw $lastException;
        }
        throw new \RuntimeException('Nenhum gateway de cartão configurado ou disponível.');
    }

    /**
     * Cria pagamento por boleto. Tenta gateways na ordem de redundância até um suceder.
     *
     * @param  array{name: string, document: string, email: string}  $consumer
     * @param  array<string, mixed>|null  $gatewayConfigOverride  When set (e.g. from API application), used instead of product's payment_gateways.
     * @return array{transaction_id: string, gateway: string, amount: float, expire_at: string, barcode: string, pdf_url: string}
     */
    public function createBoletoPayment(Order $order, ?Product $product, array $consumer, ?array $gatewayConfigOverride = null): array
    {
        $tenantId = $order->tenant_id;
        $orderSlugs = $this->getGatewayOrderForMethod($tenantId, 'boleto', $product, $gatewayConfigOverride);
        $lastException = null;
        $deadline = microtime(true) + self::PAYMENT_TOTAL_TIMEOUT_SECONDS;

        foreach ($orderSlugs as $gatewaySlug) {
            if (microtime(true) > $deadline) {
                break;
            }
            $credential = GatewayCredential::forTenant($tenantId)
                ->where('gateway_slug', $gatewaySlug)
                ->where('is_connected', true)
                ->first();
            if (! $credential) {
                continue;
            }
            $credentials = $credential->getDecryptedCredentials();
            if (empty($credentials)) {
                continue;
            }
            $driver = GatewayRegistry::driver($gatewaySlug);
            if (! $driver) {
                continue;
            }
            try {
                $startedAt = microtime(true);
                $notificationUrl = $gatewaySlug === 'efi'
                    ? url('/webhooks/gateways/efi/notification')
                    : $this->webhookUrlForGateway($gatewaySlug);
                $result = $driver->createBoletoPayment(
                    $credentials,
                    (float) $order->amount,
                    $consumer,
                    (string) $order->id,
                    $notificationUrl
                );
                $order->update([
                    'gateway' => $gatewaySlug,
                    'gateway_id' => $result['transaction_id'] ?? null,
                ]);
                return [
                    'transaction_id' => $result['transaction_id'] ?? '',
                    'gateway' => $gatewaySlug,
                    'amount' => (float) ($result['amount'] ?? $order->amount),
                    'expire_at' => $result['expire_at'] ?? '',
                    'barcode' => $result['barcode'] ?? '',
                    'pdf_url' => $result['pdf_url'] ?? '',
                ];
            } catch (\Throwable $e) {
                Log::warning('PaymentService: boleto gateway falhou.', [
                    'gateway' => $gatewaySlug,
                    'order_id' => $order->id,
                    'message' => $e->getMessage(),
                    'duration_ms' => isset($startedAt) ? (int) round((microtime(true) - $startedAt) * 1000) : null,
                ]);
                $lastException = $e;
            }
        }

        if ($lastException) {
            throw $lastException;
        }
        throw new \RuntimeException('Nenhum gateway de boleto configurado ou disponível.');
    }

    /**
     * Retorna o primeiro gateway disponível para o método (com credencial conectada e driver).
     * Usado pelo checkout para pix_auto quando há múltiplos gateways (Efí, Pushin Pay, etc.).
     *
     * @return string|null
     */
    /**
     * @param  array<string, mixed>|null  $gatewayConfigOverride
     */
    public function getFirstAvailableGatewayForMethod(?int $tenantId, string $method, ?Product $product = null, ?array $gatewayConfigOverride = null): ?string
    {
        $orderSlugs = $this->getGatewayOrderForMethod($tenantId, $method, $product, $gatewayConfigOverride);
        foreach ($orderSlugs as $gatewaySlug) {
            $credential = GatewayCredential::forTenant($tenantId)
                ->where('gateway_slug', $gatewaySlug)
                ->where('is_connected', true)
                ->first();
            if (! $credential) {
                continue;
            }
            $credentials = $credential->getDecryptedCredentials();
            if (empty($credentials)) {
                continue;
            }
            $driver = GatewayRegistry::driver($gatewaySlug);
            if (! $driver) {
                continue;
            }
            $gateway = GatewayRegistry::get($gatewaySlug);
            if (! $gateway || ! in_array($method, $gateway['methods'] ?? [], true)) {
                continue;
            }
            return $gatewaySlug;
        }
        return null;
    }

    /**
     * Ordem de gateways para o método (produto ou gatewayConfigOverride pode fixar gateway + redundância; senão usa ordem global).
     *
     * @param  array<string, mixed>|null  $gatewayConfigOverride  When set (e.g. API application payment_gateways), used like product's config.
     * @return array<int, string>
     */
    public function getGatewayOrderForMethod(?int $tenantId, string $method, ?Product $product = null, ?array $gatewayConfigOverride = null): array
    {
        $raw = Setting::get('gateway_order', null, $tenantId);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        $default = config('gateways.default_order', ['pix' => [], 'card' => [], 'boleto' => [], 'pix_auto' => []]);
        $globalOrder = is_array($raw) ? ($raw[$method] ?? $default[$method] ?? []) : ($default[$method] ?? []);
        $globalOrder = is_array($globalOrder) ? $globalOrder : [];

        // Inclui slugs de gateways registrados por plugins que ainda não estão na ordem (ex.: ao fim).
        $allGateways = GatewayRegistry::all();
        $existingSet = array_flip($globalOrder);
        foreach ($allGateways as $g) {
            $slug = $g['slug'] ?? '';
            if ($slug === '' || isset($existingSet[$slug])) {
                continue;
            }
            if (in_array($method, $g['methods'] ?? [], true)) {
                $globalOrder[] = $slug;
                $existingSet[$slug] = true;
            }
        }

        $pg = $gatewayConfigOverride;
        if ($pg === null && $product !== null) {
            $pg = $product->checkout_config['payment_gateways'] ?? [];
        }
        if (is_array($pg) && ! empty($pg)) {
            $slug = isset($pg[$method]) ? trim((string) $pg[$method]) : null;
            if ($slug !== null && $slug !== '' && $slug !== '__default__') {
                $redundancy = $pg[$method . '_redundancy'] ?? [];
                $redundancy = is_array($redundancy) ? $redundancy : [];
                return array_merge([$slug], $redundancy);
            }
        }

        return $globalOrder;
    }

    private function webhookUrlForGateway(string $gatewaySlug): string
    {
        $name = 'webhooks.' . $gatewaySlug;
        if (Route::has($name)) {
            return route($name);
        }
        return url('/webhooks/gateways/' . $gatewaySlug);
    }
}
