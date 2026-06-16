<?php

namespace Tests\Feature;

use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CajuPayPixWebhookTest extends TestCase
{
    private const PAYMENT_UUID = 'a1b2c3d4-e5f6-4789-a012-345678901234';

    private const WEBHOOK_SECRET = 'cwhsec_testsecret123456789012345678901234';

    private function seedCajuPayCredential(int $tenantId = 1): void
    {
        $cred = GatewayCredential::create([
            'tenant_id' => $tenantId,
            'gateway_slug' => 'cajupay',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials([
            'public_key' => 'gpk_test',
            'secret_key' => 'gsk_test',
            'webhook_signing_secret' => self::WEBHOOK_SECRET,
        ]);
        $cred->save();
    }

    /**
     * @param  array<string, mixed>  $objectOverrides
     * @return array{payload: array<string, mixed>, raw: string, ts: string, sig: string}
     */
    private function buildSignedPixWebhook(array $objectOverrides = []): array
    {
        $payload = [
            'type' => 'pix.payment.paid',
            'data' => [
                'object' => array_merge([
                    'gateway' => 'cajupay',
                    'cajupay_payment_id' => self::PAYMENT_UUID,
                    'amount_cents' => 5000,
                    'currency' => 'BRL',
                    'status' => 'paid',
                ], $objectOverrides),
            ],
        ];
        $raw = json_encode($payload);
        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts.'.'.$raw, self::WEBHOOK_SECRET);

        return compact('payload', 'raw', 'ts', 'sig');
    }

    public function test_pix_payment_paid_webhook_completes_pending_order(): void
    {
        Event::fake();

        $this->seedCajuPayCredential();

        $user = User::factory()->create();
        $product = $this->createTestProduct(['price' => 50]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 50,
            'currency' => 'BRL',
            'email' => $user->email,
            'gateway' => 'cajupay',
            'gateway_id' => self::PAYMENT_UUID,
            'metadata' => [
                'checkout_payment_method' => 'pix',
                'cajupay_payment_id' => self::PAYMENT_UUID,
            ],
        ]);

        $signed = $this->buildSignedPixWebhook();

        $response = $this->call(
            'POST',
            route('webhooks.cajupay'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CajuPay-Event' => 'pix.payment.paid',
                'HTTP_X-CajuPay-Timestamp' => $signed['ts'],
                'HTTP_X-CajuPay-Signature' => 't='.$signed['ts'].',v1='.$signed['sig'],
            ],
            $signed['raw']
        );

        $response->assertOk();
        $response->assertJsonPath('received', true);

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertSame(self::PAYMENT_UUID, $order->metadata['cajupay_payment_id'] ?? null);
    }

    public function test_pix_payment_paid_rejects_invalid_hmac(): void
    {
        $this->seedCajuPayCredential();

        $user = User::factory()->create();
        $product = $this->createTestProduct(['price' => 50]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 50,
            'currency' => 'BRL',
            'email' => $user->email,
            'gateway' => 'cajupay',
            'gateway_id' => self::PAYMENT_UUID,
            'metadata' => ['cajupay_payment_id' => self::PAYMENT_UUID],
        ]);

        $signed = $this->buildSignedPixWebhook();

        $response = $this->call(
            'POST',
            route('webhooks.cajupay'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CajuPay-Event' => 'pix.payment.paid',
                'HTTP_X-CajuPay-Timestamp' => $signed['ts'],
                'HTTP_X-CajuPay-Signature' => 't='.$signed['ts'].',v1=deadbeef',
            ],
            $signed['raw']
        );

        $response->assertUnauthorized();
        $order->refresh();
        $this->assertSame('pending', $order->status);
    }

    public function test_pix_payment_paid_rejects_invalid_hmac_when_order_not_found(): void
    {
        $this->seedCajuPayCredential();

        $signed = $this->buildSignedPixWebhook([
            'cajupay_payment_id' => '00000000-0000-4000-8000-000000000099',
        ]);

        $response = $this->call(
            'POST',
            route('webhooks.cajupay'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CajuPay-Event' => 'pix.payment.paid',
                'HTTP_X-CajuPay-Timestamp' => $signed['ts'],
                'HTTP_X-CajuPay-Signature' => 't='.$signed['ts'].',v1=invalidsignature000000000000000000000000',
            ],
            $signed['raw']
        );

        $response->assertUnauthorized();
    }

    public function test_pix_payment_paid_is_idempotent_for_completed_order(): void
    {
        Event::fake();

        $this->seedCajuPayCredential();

        $user = User::factory()->create();
        $product = $this->createTestProduct(['price' => 50]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'currency' => 'BRL',
            'email' => $user->email,
            'gateway' => 'cajupay',
            'gateway_id' => self::PAYMENT_UUID,
            'metadata' => ['cajupay_payment_id' => self::PAYMENT_UUID],
        ]);

        $signed = $this->buildSignedPixWebhook();

        $response = $this->call(
            'POST',
            route('webhooks.cajupay'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CajuPay-Event' => 'pix.payment.paid',
                'HTTP_X-CajuPay-Timestamp' => $signed['ts'],
                'HTTP_X-CajuPay-Signature' => 't='.$signed['ts'].',v1='.$signed['sig'],
            ],
            $signed['raw']
        );

        $response->assertOk();
        $order->refresh();
        $this->assertSame('completed', $order->status);
    }

    public function test_payment_service_persists_cajupay_payment_id_on_pix_create(): void
    {
        config(['app.url' => 'https://getfy.test']);
        URL::forceRootUrl('https://getfy.test');

        Http::fake([
            '*/api/payments/pix' => Http::response([
                'payment_id' => self::PAYMENT_UUID,
                'pix_qr_code' => 'data:image/png;base64,abc',
                'pix_copy_paste' => '00020126580014br.gov.bcb.pix',
            ], 201),
        ]);

        Setting::set('gateway_order', ['pix' => ['cajupay']], 1);

        $cred = GatewayCredential::create([
            'tenant_id' => 1,
            'gateway_slug' => 'cajupay',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials([
            'public_key' => 'gpk_test',
            'secret_key' => 'gsk_test',
        ]);
        $cred->save();

        $user = User::factory()->create();
        $product = $this->createTestProduct([
            'price' => 49.90,
            'checkout_slug' => 'pix-produto-test',
            'checkout_config' => array_merge(Product::defaultCheckoutConfig(), [
                'payment_gateways' => ['pix' => 'cajupay'],
            ]),
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 49.90,
            'currency' => 'BRL',
            'email' => $user->email,
            'metadata' => ['checkout_payment_method' => 'pix'],
        ]);

        app(PaymentService::class)->createPixPayment($order, $product, [
            'name' => 'Cliente Teste',
            'document' => '52998224725',
            'email' => $user->email,
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/payments/pix')) {
                return false;
            }
            $body = $request->data();

            return str_contains((string) ($body['partner_checkout_url'] ?? ''), '/c/pix-produto-test');
        });

        $order->refresh();
        $this->assertSame('cajupay', $order->gateway);
        $this->assertSame(self::PAYMENT_UUID, $order->gateway_id);
        $this->assertSame(self::PAYMENT_UUID, $order->metadata['cajupay_payment_id'] ?? null);
        $this->assertSame('pix', $order->metadata['checkout_payment_method'] ?? null);
    }

    public function test_pix_create_omits_partner_checkout_url_on_http_app_url(): void
    {
        config(['app.url' => 'http://getfy-opensource.test']);
        URL::forceRootUrl('http://getfy-opensource.test');

        Http::fake([
            '*/api/payments/pix' => Http::response([
                'payment_id' => self::PAYMENT_UUID,
                'pix_qr_code' => 'data:image/png;base64,abc',
                'pix_copy_paste' => '00020126580014br.gov.bcb.pix',
            ], 201),
        ]);

        Setting::set('gateway_order', ['pix' => ['cajupay']], 1);

        $cred = GatewayCredential::create([
            'tenant_id' => 1,
            'gateway_slug' => 'cajupay',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials([
            'public_key' => 'gpk_test',
            'secret_key' => 'gsk_test',
        ]);
        $cred->save();

        $user = User::factory()->create();
        $product = $this->createTestProduct([
            'price' => 49.90,
            'checkout_slug' => 'pix-produto-test',
            'checkout_config' => array_merge(Product::defaultCheckoutConfig(), [
                'payment_gateways' => ['pix' => 'cajupay'],
            ]),
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 49.90,
            'currency' => 'BRL',
            'email' => $user->email,
            'metadata' => ['checkout_payment_method' => 'pix'],
        ]);

        app(PaymentService::class)->createPixPayment($order, $product, [
            'name' => 'Cliente Teste',
            'document' => '52998224725',
            'email' => $user->email,
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/payments/pix')) {
                return false;
            }
            $body = $request->data();

            return ! array_key_exists('partner_checkout_url', $body);
        });
    }

    public function test_pix_create_includes_consumer_phone_when_provided(): void
    {
        Http::fake([
            '*/api/payments/pix' => Http::response([
                'payment_id' => self::PAYMENT_UUID,
                'pix_qr_code' => 'data:image/png;base64,abc',
                'pix_copy_paste' => '00020126580014br.gov.bcb.pix',
            ], 201),
        ]);

        Setting::set('gateway_order', ['pix' => ['cajupay']], 1);

        $cred = GatewayCredential::create([
            'tenant_id' => 1,
            'gateway_slug' => 'cajupay',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials([
            'public_key' => 'gpk_test',
            'secret_key' => 'gsk_test',
        ]);
        $cred->save();

        $user = User::factory()->create();
        $product = $this->createTestProduct([
            'price' => 49.90,
            'checkout_config' => array_merge(Product::defaultCheckoutConfig(), [
                'payment_gateways' => ['pix' => 'cajupay'],
            ]),
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 49.90,
            'currency' => 'BRL',
            'email' => $user->email,
            'metadata' => ['checkout_payment_method' => 'pix'],
        ]);

        app(PaymentService::class)->createPixPayment($order, $product, [
            'name' => 'Cliente Teste',
            'document' => '52998224725',
            'email' => $user->email,
            'phone' => '5511987654321',
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/payments/pix')) {
                return false;
            }
            $body = $request->data();
            $consumer = $body['consumer'] ?? [];

            return ($consumer['phone'] ?? null) === '+5511987654321';
        });
    }

    public function test_pix_create_omits_consumer_phone_when_empty(): void
    {
        Http::fake([
            '*/api/payments/pix' => Http::response([
                'payment_id' => self::PAYMENT_UUID,
                'pix_qr_code' => 'data:image/png;base64,abc',
                'pix_copy_paste' => '00020126580014br.gov.bcb.pix',
            ], 201),
        ]);

        Setting::set('gateway_order', ['pix' => ['cajupay']], 1);

        $cred = GatewayCredential::create([
            'tenant_id' => 1,
            'gateway_slug' => 'cajupay',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials([
            'public_key' => 'gpk_test',
            'secret_key' => 'gsk_test',
        ]);
        $cred->save();

        $user = User::factory()->create();
        $product = $this->createTestProduct([
            'price' => 49.90,
            'checkout_config' => array_merge(Product::defaultCheckoutConfig(), [
                'payment_gateways' => ['pix' => 'cajupay'],
            ]),
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 49.90,
            'currency' => 'BRL',
            'email' => $user->email,
            'metadata' => ['checkout_payment_method' => 'pix'],
        ]);

        app(PaymentService::class)->createPixPayment($order, $product, [
            'name' => 'Cliente Teste',
            'document' => '52998224725',
            'email' => $user->email,
            'phone' => '',
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/payments/pix')) {
                return false;
            }
            $body = $request->data();
            $consumer = $body['consumer'] ?? [];

            return ! array_key_exists('phone', $consumer);
        });
    }
}
