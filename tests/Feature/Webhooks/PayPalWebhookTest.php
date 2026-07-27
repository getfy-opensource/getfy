<?php

namespace Tests\Feature\Webhooks;

use App\Models\GatewayCredential;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayPalWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_invalid_signature(): void
    {
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'tok',
                'expires_in' => 300,
            ], 200),
            'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'FAILURE',
            ], 200),
        ]);

        $product = $this->createTestProduct(['name' => 'PayPal product']);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => null,
            'product_id' => $product->id,
            'amount' => 10,
            'currency' => 'BRL',
            'email' => 'buyer@example.com',
            'gateway' => 'paypal',
            'gateway_id' => 'ORDER_TEST',
            'status' => 'pending',
        ]);

        $cred = new GatewayCredential([
            'tenant_id' => 1,
            'gateway_slug' => 'paypal',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials([
            'client_id' => 'cid',
            'client_secret' => 'sec',
            'webhook_id' => 'WH-1',
            'sandbox' => true,
        ]);
        $cred->save();

        $payload = [
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [
                'id' => 'CAP1',
                'supplementary_data' => [
                    'related_ids' => ['order_id' => 'ORDER_TEST'],
                ],
                'custom_id' => (string) $order->id,
            ],
        ];

        $response = $this->postJson('/webhooks/gateways/paypal', $payload, [
            'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
            'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-1',
            'PAYPAL-TRANSMISSION-ID' => 'tid',
            'PAYPAL-TRANSMISSION-SIG' => 'sig',
            'PAYPAL-TRANSMISSION-TIME' => '2024-01-01T00:00:00Z',
        ]);

        $response->assertStatus(401);
    }

    public function test_unknown_order_returns_received_without_error(): void
    {
        $payload = [
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [
                'id' => 'CAP_UNKNOWN',
                'supplementary_data' => [
                    'related_ids' => ['order_id' => 'ORDER_MISSING'],
                ],
            ],
        ];

        $response = $this->postJson('/webhooks/gateways/paypal', $payload, [
            'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
            'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-1',
            'PAYPAL-TRANSMISSION-ID' => 'tid',
            'PAYPAL-TRANSMISSION-SIG' => 'sig',
            'PAYPAL-TRANSMISSION-TIME' => '2024-01-01T00:00:00Z',
        ]);

        $response->assertOk()->assertJson(['received' => true]);
    }
}
