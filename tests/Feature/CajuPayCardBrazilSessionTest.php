<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CajuPayCardBrazilSessionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureInstalled::class);
    }

    public function test_cajupay_session_sends_installments_and_threeds_for_card(): void
    {
        Http::fake([
            '*/api/sdk/v1/checkout/sessions' => Http::response([
                'token' => 'tok_card_br',
                'checkout_session_id' => 'sess-card-br-1',
                'methods_available' => ['card'],
            ], 201),
            '*/api/sdk/public/checkout/sessions/*' => Http::response([
                'methods_available' => ['card'],
            ], 200),
        ]);

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'price' => 120,
            'checkout_config' => array_replace_recursive(Product::defaultCheckoutConfig(), [
                'payment_gateways' => ['card' => 'cajupay'],
                'card_installments' => ['enabled' => true, 'max' => 6],
                'cajupay_card' => ['require_threeds' => true],
            ]),
        ]);

        $this->createCajupayCredential(1);

        $response = $this->postJson(route('checkout.cajupay.session'), [
            'product_id' => $product->id,
            'payment_method' => 'card',
            'display_currency' => 'BRL',
            'billing_country' => 'BR',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/sdk/v1/checkout/sessions')) {
                return false;
            }
            $body = $request->data();

            return ($body['allow_card'] ?? null) === true
                && ($body['allow_card_installments'] ?? null) === true
                && (int) ($body['card_max_installments'] ?? 0) === 6
                && ($body['require_card_threeds'] ?? null) === true;
        });
    }

    public function test_cajupay_session_omits_card_flags_for_apple_pay(): void
    {
        Http::fake([
            '*/api/sdk/v1/checkout/sessions' => Http::response([
                'token' => 'tok_wallet',
                'checkout_session_id' => 'sess-wallet-1',
                'methods_available' => ['apple_pay', 'card'],
            ], 201),
            '*/api/sdk/public/checkout/sessions/*' => Http::response([
                'methods_available' => ['apple_pay', 'card'],
            ], 200),
        ]);

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'price' => 120,
            'checkout_config' => array_replace_recursive(Product::defaultCheckoutConfig(), [
                'payment_gateways' => [
                    'card' => 'cajupay',
                    'apple_pay' => 'cajupay',
                ],
                'card_installments' => ['enabled' => true, 'max' => 6],
                'cajupay_card' => ['require_threeds' => true],
            ]),
        ]);

        $this->createCajupayCredential(1);

        $response = $this->postJson(route('checkout.cajupay.session'), [
            'product_id' => $product->id,
            'payment_method' => 'apple_pay',
            'display_currency' => 'BRL',
            'billing_country' => 'BR',
        ]);

        $response->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/sdk/v1/checkout/sessions')) {
                return false;
            }
            $body = $request->data();

            // Wallets still promote allow_card, but installment/3DS flags must not be sent
            // when payment_method is not card (options builder returns []).
            return ! array_key_exists('allow_card_installments', $body)
                && ! array_key_exists('require_card_threeds', $body);
        });
    }

    public function test_product_update_persists_cajupay_card_threeds(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'tenant_id' => 1,
            'price' => 99,
            'checkout_config' => Product::defaultCheckoutConfig(),
        ]);

        $response = $this->actingAs($user)->put(route('produtos.update', $product), [
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => '',
            'type' => $product->type,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 99,
            'currency' => 'BRL',
            'is_active' => true,
            'payment_gateways' => [
                'card' => 'cajupay',
            ],
            'card_installments' => [
                'enabled' => true,
                'max' => 4,
            ],
            'cajupay_card' => [
                'require_threeds' => true,
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $product->refresh();
        $config = $product->checkout_config;

        $this->assertTrue((bool) ($config['card_installments']['enabled'] ?? false));
        $this->assertSame(4, (int) ($config['card_installments']['max'] ?? 0));
        $this->assertTrue((bool) ($config['cajupay_card']['require_threeds'] ?? false));
    }

    public function test_webhook_paid_stores_installments_and_threeds_mode(): void
    {
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1])->id,
            'product_id' => $this->createTestProduct(['tenant_id' => 1])->id,
            'status' => 'pending',
            'amount' => 99.0,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'gateway' => 'cajupay',
            'gateway_id' => 'sess-paid-1',
            'metadata' => [
                'cajupay_checkout_session_id' => 'sess-paid-1',
                'checkout_payment_method' => 'card',
            ],
        ]);

        $cred = GatewayCredential::create([
            'tenant_id' => 1,
            'gateway_slug' => 'cajupay',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials([
            'public_key' => 'pk_test',
            'secret_key' => 'sk_test',
        ]);
        $cred->save();

        Http::fake([
            '*/api/sdk/v1/checkout/sessions/*' => Http::response([
                'payment_status' => 'paid',
                'status' => 'completed',
            ], 200),
            '*/api/sdk/public/checkout/sessions/*' => Http::response([
                'payment_status' => 'paid',
            ], 200),
            '*/api/payments*' => Http::response(['data' => []], 200),
        ]);

        ProcessPaymentWebhook::dispatchSync('cajupay', 'sess-paid-1', 'order.paid', 'paid', [
            'type' => 'checkout.payment.paid',
            'data' => [
                'object' => [
                    'checkout_session_id' => 'sess-paid-1',
                    'amount_cents' => 9900,
                    'currency' => 'BRL',
                    'installments' => 3,
                    'threeds_mode' => 'required',
                ],
            ],
            'webhook_source' => 'cajupay_hmac_verified',
        ]);

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertSame(3, (int) ($order->metadata['installments'] ?? 0));
        $this->assertSame('required', $order->metadata['threeds_mode'] ?? null);
    }

    public function test_cajupay_session_sends_save_card_for_subscription_plan(): void
    {
        Http::fake([
            '*/api/sdk/v1/checkout/sessions' => Http::response([
                'token' => 'tok_save_card',
                'checkout_session_id' => 'sess-save-card',
                'methods_available' => ['card'],
            ], 201),
            '*/api/sdk/public/checkout/sessions/*' => Http::response([
                'methods_available' => ['card'],
            ], 200),
        ]);

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'price' => 99,
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'checkout_config' => array_replace_recursive(Product::defaultCheckoutConfig(), [
                'payment_gateways' => ['card' => 'cajupay'],
            ]),
        ]);

        $plan = \App\Models\SubscriptionPlan::query()->create([
            'product_id' => $product->id,
            'name' => 'Mensal',
            'price' => 99,
            'interval' => \App\Models\SubscriptionPlan::INTERVAL_MONTHLY,
            'checkout_slug' => 'plano-save-card-'.uniqid(),
        ]);

        $this->createCajupayCredential(1);

        $response = $this->postJson(route('checkout.cajupay.session'), [
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'payment_method' => 'card',
            'display_currency' => 'BRL',
            'billing_country' => 'BR',
        ]);

        $response->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/sdk/v1/checkout/sessions')) {
                return false;
            }
            $body = $request->data();

            return ($body['allow_card'] ?? null) === true
                && ($body['save_card'] ?? null) === true;
        });
    }

    private function createCajupayCredential(int $tenantId): void
    {
        $cred = GatewayCredential::create([
            'tenant_id' => $tenantId,
            'gateway_slug' => 'cajupay',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials([
            'public_key' => 'pk_test',
            'secret_key' => 'sk_test',
        ]);
        $cred->save();
    }
}
