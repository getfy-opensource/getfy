<?php

namespace App\Services;

use App\Models\ApiCheckoutSession;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Support\OrderReportingAmounts;
use Illuminate\Support\Facades\Http;

class UtmifyService
{
    private const ENDPOINT = 'https://api.utmify.com.br/api-credentials/orders';

    /**
     * Build payload and send order to UTMfy API.
     *
     * @param  array{approved_at?: string|null, refunded_at?: string|null}  $options
     */
    public function sendOrder(
        Order $order,
        string $utmifyStatus,
        string $apiKey,
        array $options = []
    ): void {
        $body = $this->buildPayload($order, $utmifyStatus, $options);
        $this->post($apiKey, $body);
    }

    /**
     * @param  array{approved_at?: string|null, refunded_at?: string|null, is_test?: bool}  $options
     * @return array<string, mixed>
     */
    public function buildPayload(Order $order, string $utmifyStatus, array $options = []): array
    {
        $order->loadMissing(['user', 'orderItems.product', 'orderItems.productOffer', 'orderItems.subscriptionPlan']);

        $session = CheckoutSession::where('order_id', $order->id)->orderByDesc('id')->first();
        $apiSession = null;
        if (! $session) {
            if ($order->api_checkout_session_id !== null) {
                $apiSession = ApiCheckoutSession::find($order->api_checkout_session_id);
            }
            if (! $apiSession) {
                $apiSession = ApiCheckoutSession::where('order_id', $order->id)->first();
            }
        }

        $orderId = $order->gateway_id ?: (string) $order->id;
        $paymentMethod = $this->mapPaymentMethod($order);
        $totalCentsBrl = OrderReportingAmounts::totalCentsBrl($order);
        $createdAt = $order->created_at->utc()->format('Y-m-d H:i:s');
        $approvedDate = $options['approved_at'] ?? ($utmifyStatus === 'paid' ? $order->updated_at->utc()->format('Y-m-d H:i:s') : null);
        $refundedAt = $options['refunded_at'] ?? null;

        $apiCustomer = $apiSession?->customer;
        $apiCustomer = is_array($apiCustomer) ? $apiCustomer : [];

        $customerName = $session?->name
            ?? (string) ($apiCustomer['name'] ?? '')
            ?? $order->user?->name
            ?? '';
        $customer = [
            'name' => $customerName,
            'email' => $order->email ?? '',
            'phone' => $order->phone ?? '',
            'document' => $order->cpf ?? '',
            'country' => 'BR',
            'ip' => $order->customer_ip ?? '',
        ];

        $products = [];
        foreach ($order->orderItems as $item) {
            $product = $item->product;
            $planId = $item->product_offer_id ?? $item->subscription_plan_id;
            $planName = null;
            if ($item->productOffer) {
                $planName = $item->productOffer->name;
            } elseif ($item->subscriptionPlan) {
                $planName = $item->subscriptionPlan->name;
            }
            $products[] = [
                'id' => (string) ($product?->id ?? $item->product_id ?? $item->id),
                'name' => $product?->name ?? 'Produto',
                'planId' => $planId ? (string) $planId : null,
                'planName' => $planName,
                'quantity' => 1,
                'priceInCents' => OrderReportingAmounts::lineCentsBrl($order, (float) $item->amount),
            ];
        }

        if (empty($products)) {
            $mainProduct = $order->product;
            $products[] = [
                'id' => (string) ($mainProduct?->id ?? $order->product_id),
                'name' => $mainProduct?->name ?? 'Produto',
                'planId' => null,
                'planName' => null,
                'quantity' => 1,
                'priceInCents' => $totalCentsBrl,
            ];
        }

        $apiMeta = $apiSession?->metadata;
        $apiMeta = is_array($apiMeta) ? $apiMeta : [];

        $trackingParameters = $this->buildMergedTrackingParameters($session, $apiMeta, $order);

        $commission = [
            'totalPriceInCents' => $totalCentsBrl,
            'gatewayFeeInCents' => 0,
            'userCommissionInCents' => $totalCentsBrl,
        ];

        $body = [
            'orderId' => $orderId,
            'platform' => 'Primicia',
            'paymentMethod' => $paymentMethod,
            'status' => $utmifyStatus,
            'createdAt' => $createdAt,
            'approvedDate' => $approvedDate,
            'refundedAt' => $refundedAt,
            'customer' => $customer,
            'products' => $products,
            'trackingParameters' => $trackingParameters,
            'commission' => $commission,
        ];

        if (! empty($options['is_test'])) {
            $body['isTest'] = true;
        }

        return $body;
    }

    /**
     * POST to UTMfy API. Throws on failure.
     */
    public function post(string $apiKey, array $body): \Illuminate\Http\Client\Response
    {
        $response = Http::timeout(15)
            ->withHeaders(['x-api-token' => $apiKey])
            ->post(self::ENDPOINT, $body);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'UTMfy API error: ' . $response->status() . ' ' . $response->body()
            );
        }

        return $response;
    }

    /**
     * Mescla parâmetros de tráfego: camadas posteriores sobrescrevem as anteriores (metadata do pedido ganha).
     *
     * @param  array<string, mixed>  $apiMeta
     * @return array<string, string|null>
     */
    private function buildMergedTrackingParameters(?CheckoutSession $session, array $apiMeta, Order $order): array
    {
        $keys = [
            'src', 'sck',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
            'fbclid', 'gclid', 'msclkid', 'fbp', 'fbc',
        ];

        $sessionUtm = [
            'utm_source' => $session?->utm_source,
            'utm_medium' => $session?->utm_medium,
            'utm_campaign' => $session?->utm_campaign,
        ];
        $sessionTracking = is_array($session?->tracking_metadata) ? $session->tracking_metadata : [];
        $orderMeta = is_array($order->metadata) ? $order->metadata : [];

        $layers = [$apiMeta, $sessionUtm, $sessionTracking, $orderMeta];

        $out = array_fill_keys($keys, null);
        foreach ($layers as $layer) {
            if (! is_array($layer)) {
                continue;
            }
            foreach ($keys as $k) {
                if (! array_key_exists($k, $layer)) {
                    continue;
                }
                $v = $layer[$k];
                if (! is_string($v) && ! is_numeric($v)) {
                    continue;
                }
                $s = trim((string) $v);
                if ($s === '') {
                    continue;
                }
                $out[$k] = mb_substr($s, 0, 512);
            }
        }

        return $out;
    }

    private function mapPaymentMethod(Order $order): string
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $checkoutMethod = strtolower((string) ($meta['checkout_payment_method'] ?? ''));

        return match ($checkoutMethod) {
            'pix', 'pix_auto' => 'pix',
            'boleto' => 'boleto',
            'card', 'apple_pay', 'google_pay', 'paypal' => 'credit_card',
            default => $this->mapPaymentMethodFromGatewaySlug($order->gateway),
        };
    }

    private function mapPaymentMethodFromGatewaySlug(?string $gateway): string
    {
        if (! $gateway) {
            return 'pix';
        }
        $g = strtolower($gateway);
        if (str_contains($g, 'pix')) {
            return 'pix';
        }
        if (str_contains($g, 'boleto') || str_contains($g, 'ticket')) {
            return 'boleto';
        }
        if (str_contains($g, 'card') || str_contains($g, 'credit') || str_contains($g, 'cartao') || str_contains($g, 'cajupay') || str_contains($g, 'paypal')) {
            return 'credit_card';
        }

        return 'pix';
    }
}
