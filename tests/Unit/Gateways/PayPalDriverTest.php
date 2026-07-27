<?php

namespace Tests\Unit\Gateways;

use App\Gateways\PayPal\PayPalDriver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayPalDriverTest extends TestCase
{
    public function test_base_url_respects_sandbox_flag(): void
    {
        $driver = new PayPalDriver();
        $this->assertSame(
            'https://api-m.sandbox.paypal.com',
            $driver->baseUrl(['sandbox' => true])
        );
        $this->assertSame(
            'https://api-m.paypal.com',
            $driver->baseUrl(['sandbox' => false])
        );
    }

    public function test_create_paypal_order_posts_capture_intent(): void
    {
        Cache::flush();
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'tok_test',
                'expires_in' => 300,
            ], 200),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'ORDER_ABC',
                'status' => 'CREATED',
            ], 201),
        ]);

        $driver = new PayPalDriver();
        $result = $driver->createPayPalOrder(
            [
                'client_id' => 'cid',
                'client_secret' => 'csecret',
                'sandbox' => true,
            ],
            49.90,
            'BRL',
            '123',
            ['email' => 'buyer@example.com', 'phone' => '+55 11 99999-8888', 'name' => 'João Silva', 'locale' => 'pt_BR', 'country' => 'BR'],
            'Produto Teste'
        );

        $this->assertSame('ORDER_ABC', $result['transaction_id']);
        $this->assertSame('created', $result['status']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/v2/checkout/orders')) {
                return false;
            }
            $data = $request->data();

            return ($data['intent'] ?? null) === 'CAPTURE'
                && ($data['purchase_units'][0]['amount']['currency_code'] ?? null) === 'BRL'
                && ($data['purchase_units'][0]['amount']['value'] ?? null) === '49.90'
                && ($data['purchase_units'][0]['custom_id'] ?? null) === '123'
                && ($data['payer']['email_address'] ?? null) === 'buyer@example.com'
                && ($data['payer']['phone']['phone_number']['national_number'] ?? null) === '5511999998888'
                && ($data['payer']['name']['given_name'] ?? null) === 'João'
                && ($data['application_context']['locale'] ?? null) === 'pt-BR'
                && ($data['application_context']['shipping_preference'] ?? null) === 'NO_SHIPPING'
                && ! isset($data['payment_source']);
        });
    }

    public function test_create_paypal_order_card_fields_includes_verification(): void
    {
        Cache::flush();
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'tok_test',
                'expires_in' => 300,
            ], 200),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'ORDER_CF',
                'status' => 'CREATED',
            ], 201),
        ]);

        $driver = new PayPalDriver();
        $driver->createPayPalOrder(
            [
                'client_id' => 'cid',
                'client_secret' => 'csecret',
                'sandbox' => true,
            ],
            10.0,
            'USD',
            '55',
            [],
            'Produto',
            'card_fields'
        );

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/v2/checkout/orders')) {
                return false;
            }
            $data = $request->data();

            return ($data['payment_source']['card']['attributes']['verification']['method'] ?? null) === 'SCA_WHEN_REQUIRED';
        });
    }

    public function test_create_card_payment_captures_and_maps_paid(): void
    {
        Cache::flush();
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'tok_test',
                'expires_in' => 300,
            ], 200),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders/ORDER_XYZ/capture' => Http::response([
                'id' => 'ORDER_XYZ',
                'status' => 'COMPLETED',
                'purchase_units' => [
                    [
                        'payments' => [
                            'captures' => [
                                ['id' => 'CAP_1', 'status' => 'COMPLETED'],
                            ],
                        ],
                    ],
                ],
            ], 201),
        ]);

        $driver = new PayPalDriver();
        $result = $driver->createCardPayment(
            [
                'client_id' => 'cid',
                'client_secret' => 'csecret',
                'sandbox' => true,
            ],
            10.0,
            ['name' => 'Buyer', 'document' => '00000000000', 'email' => 'b@example.com'],
            'order_9',
            ['payment_token' => 'ORDER_XYZ']
        );

        $this->assertSame('ORDER_XYZ', $result['transaction_id']);
        $this->assertSame('paid', $result['status']);
        $this->assertSame('CAP_1', $result['capture_id']);
    }

    public function test_get_transaction_status_maps_completed_to_paid(): void
    {
        Cache::flush();
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'tok_test',
                'expires_in' => 300,
            ], 200),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders/ORDER_1' => Http::response([
                'id' => 'ORDER_1',
                'status' => 'COMPLETED',
            ], 200),
        ]);

        $driver = new PayPalDriver();
        $status = $driver->getTransactionStatus('ORDER_1', [
            'client_id' => 'cid',
            'client_secret' => 'csecret',
            'sandbox' => true,
        ]);

        $this->assertSame('paid', $status);
    }

    public function test_verify_webhook_signature_success(): void
    {
        Cache::flush();
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'tok_test',
                'expires_in' => 300,
            ], 200),
            'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'SUCCESS',
            ], 200),
        ]);

        $driver = new PayPalDriver();
        $ok = $driver->verifyWebhookSignature(
            [
                'client_id' => 'cid',
                'client_secret' => 'csecret',
                'sandbox' => true,
                'webhook_id' => 'WH-123',
            ],
            [
                'paypal-auth-algo' => 'SHA256withRSA',
                'paypal-cert-url' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-1',
                'paypal-transmission-id' => 'tid',
                'paypal-transmission-sig' => 'sig',
                'paypal-transmission-time' => '2024-01-01T00:00:00Z',
            ],
            ['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'id' => 'WH-EVT']
        );

        $this->assertTrue($ok);
    }

    public function test_pix_and_boleto_are_unsupported(): void
    {
        $driver = new PayPalDriver();
        $this->expectException(\RuntimeException::class);
        $driver->createPixPayment([], 1.0, [], '1', 'https://example.com');
    }
}
