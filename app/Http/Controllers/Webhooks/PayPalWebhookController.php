<?php

namespace App\Http\Controllers\Webhooks;

use App\Gateways\GatewayRegistry;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PayPalWebhookController extends Controller
{
    /**
     * Handle PayPal webhook (POST /webhooks/gateways/paypal).
     * Verifies signature via PayPal verify-webhook-signature API.
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        if (! is_string($payload) || $payload === '') {
            return response()->json(['message' => 'Invalid request'], 400);
        }

        $payloadData = json_decode($payload, true);
        if (! is_array($payloadData)) {
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        $eventType = (string) ($payloadData['event_type'] ?? '');
        $resource = is_array($payloadData['resource'] ?? null) ? $payloadData['resource'] : [];

        $paypalOrderId = $this->resolvePayPalOrderId($eventType, $resource, $payloadData);
        $getfyOrderId = $this->resolveGetfyOrderId($resource);

        $order = null;
        if ($paypalOrderId !== null) {
            $order = Order::where('gateway', 'paypal')->where('gateway_id', $paypalOrderId)->first();
        }
        if (! $order && $getfyOrderId !== null) {
            $order = Order::where('gateway', 'paypal')->where('id', $getfyOrderId)->first();
        }
        if (! $order && ! empty($resource['id']) && $eventType === 'PAYMENT.CAPTURE.COMPLETED') {
            // Capture id may have been stored as gateway_id in edge cases
            $order = Order::where('gateway', 'paypal')->where('gateway_id', (string) $resource['id'])->first();
        }

        if (! $order) {
            Log::info('PayPalWebhook: order not found', [
                'event' => $eventType,
                'paypal_order_id' => $paypalOrderId,
                'custom_id' => $getfyOrderId,
            ]);

            return response()->json(['received' => true]);
        }

        $credential = GatewayCredential::forTenant($order->tenant_id)
            ->where('gateway_slug', 'paypal')
            ->where('is_connected', true)
            ->first();

        if (! $credential) {
            return response()->json(['message' => 'Credential not found'], 400);
        }

        $credentials = $credential->getDecryptedCredentials();
        $headers = [
            'paypal-auth-algo' => (string) $request->header('PAYPAL-AUTH-ALGO', ''),
            'paypal-cert-url' => (string) $request->header('PAYPAL-CERT-URL', ''),
            'paypal-transmission-id' => (string) $request->header('PAYPAL-TRANSMISSION-ID', ''),
            'paypal-transmission-sig' => (string) $request->header('PAYPAL-TRANSMISSION-SIG', ''),
            'paypal-transmission-time' => (string) $request->header('PAYPAL-TRANSMISSION-TIME', ''),
        ];

        /** @var \App\Gateways\PayPal\PayPalDriver|null $driver */
        $driver = GatewayRegistry::driver('paypal');
        if (! $driver || ! $driver->verifyWebhookSignature($credentials, $headers, $payloadData)) {
            Log::warning('PayPalWebhook: signature verification failed', [
                'order_id' => $order->id,
                'event' => $eventType,
            ]);

            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $transactionId = $order->gateway_id ?: ($paypalOrderId ?? (string) ($resource['id'] ?? ''));
        if ($transactionId === '') {
            return response()->json(['received' => true]);
        }

        if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
            ProcessPaymentWebhook::dispatchSync('paypal', $transactionId, 'PAYMENT.CAPTURE.COMPLETED', 'paid', $payloadData);
        } elseif ($eventType === 'PAYMENT.CAPTURE.DENIED') {
            ProcessPaymentWebhook::dispatchSync('paypal', $transactionId, 'payment.rejected', 'rejected', $payloadData);
        } elseif ($eventType === 'PAYMENT.CAPTURE.REFUNDED') {
            ProcessPaymentWebhook::dispatchSync('paypal', $transactionId, 'payment.refunded', 'refunded', $payloadData);
        }

        return response()->json(['received' => true]);
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  array<string, mixed>  $payloadData
     */
    private function resolvePayPalOrderId(string $eventType, array $resource, array $payloadData): ?string
    {
        $related = $resource['supplementary_data']['related_ids']['order_id'] ?? null;
        if (is_string($related) && $related !== '') {
            return $related;
        }

        if (str_starts_with($eventType, 'CHECKOUT.ORDER.') && ! empty($resource['id'])) {
            return (string) $resource['id'];
        }

        $links = $resource['links'] ?? [];
        if (is_array($links)) {
            foreach ($links as $link) {
                if (! is_array($link)) {
                    continue;
                }
                $rel = (string) ($link['rel'] ?? '');
                $href = (string) ($link['href'] ?? '');
                if ($rel === 'up' && preg_match('#/v2/checkout/orders/([A-Z0-9]+)#i', $href, $m)) {
                    return $m[1];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function resolveGetfyOrderId(array $resource): ?int
    {
        $customId = $resource['custom_id']
            ?? $resource['purchase_units'][0]['custom_id']
            ?? null;
        if (is_numeric($customId)) {
            return (int) $customId;
        }

        return null;
    }
}
