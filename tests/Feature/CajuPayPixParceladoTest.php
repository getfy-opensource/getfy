<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\User;
use App\Services\CajuPayPixParceladoService;
use App\Support\CheckoutPaymentMethodsBuilder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CajuPayPixParceladoTest extends TestCase
{
    private const PLAN_ID = 'plan-test-uuid-1234';

    private const PAYMENT_ID = 'pay-test-uuid-5678';

    private const WEBHOOK_SECRET = 'cwhsec_testsecret123456789012345678901234';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureInstalled::class);
    }

    private function seedCajuPayCredential(int $tenantId = 1, array $extra = []): void
    {
        $cred = GatewayCredential::create([
            'tenant_id' => $tenantId,
            'gateway_slug' => 'cajupay',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials(array_merge([
            'public_key' => 'gpk_test',
            'secret_key' => 'gsk_test',
            'webhook_signing_secret' => self::WEBHOOK_SECRET,
            'pix_parcelado_enrollment_status' => 'active',
            'pay_account_id' => 'pay-acct-test-uuid',
        ], $extra));
        $cred->save();
    }

    private function productWithParcelado(array $overrides = []): Product
    {
        return $this->createTestProduct(array_merge([
            'price' => 150,
            'currency' => 'BRL',
            'billing_type' => Product::BILLING_ONE_TIME,
            'checkout_config' => array_merge(Product::defaultCheckoutConfig(), [
                'payment_gateways' => [
                    'pix_parcelado' => 'cajupay',
                ],
                'pix_parcelado' => [
                    'max_installments' => 6,
                ],
            ]),
        ], $overrides));
    }

    public function test_product_rules_validation_rejects_price_below_minimum(): void
    {
        $service = app(CajuPayPixParceladoService::class);
        $errors = $service->validateProductRules([], [], 49.99);
        $this->assertArrayHasKey('price', $errors);
        $this->assertSame(5000, CajuPayPixParceladoService::MIN_AMOUNT_CENTS);
    }

    public function test_product_rules_validation_against_platform_bands(): void
    {
        $service = app(CajuPayPixParceladoService::class);
        $platformRules = [
            'max_down_payment_bps' => 6000,
            'bands' => [
                ['min_total_cents' => 5000, 'max_total_cents' => 29999, 'max_installments' => 3],
            ],
        ];
        $errors = $service->validateProductRules(
            ['max_installments' => 12],
            $platformRules,
            150.0
        );
        $this->assertArrayHasKey('max_installments', $errors);
    }

    public function test_sdk_options_from_rules_includes_down_payment_for_payment_link(): void
    {
        $service = app(CajuPayPixParceladoService::class);
        $opts = $service->sdkOptionsFromRules([
            'max_installments' => 6,
            'down_payment_cents' => 5000,
            'min_down_payment_bps' => 2000,
            'max_down_payment_bps' => 3000,
        ]);

        $this->assertSame(5000, $opts['parcelado_down_payment_cents']);
        $this->assertSame(2000, $opts['parcelado_min_down_payment_bps']);
        $this->assertSame(3000, $opts['parcelado_max_down_payment_bps']);
    }

    public function test_resolve_down_payment_cents_from_plan_result(): void
    {
        $service = app(CajuPayPixParceladoService::class);

        $this->assertSame(
            5000,
            $service->resolveDownPaymentCentsFromPlanResult([
                'installments' => [
                    ['sequence' => 1, 'amount_cents' => 5000],
                    ['sequence' => 2, 'amount_cents' => 2500],
                ],
            ])
        );

        $this->assertSame(7500, $service->resolveDownPaymentCentsFromPlanResult([], 7500));
    }

    public function test_create_plan_includes_consumer_phone_e164(): void
    {
        Http::fake([
            'api.cajupay.com.br/api/pix-parcelado/plans' => Http::response([
                'id' => self::PLAN_ID,
                'first_payment_id' => self::PAYMENT_ID,
                'pix_copy_paste' => '00020126580014br.gov.bcb.pix',
            ], 200),
        ]);

        $this->seedCajuPayCredential();
        $user = User::factory()->create();
        $product = $this->productWithParcelado();
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 150,
            'currency' => 'BRL',
            'email' => $user->email,
            'gateway' => 'cajupay',
            'metadata' => ['checkout_payment_method' => 'pix_parcelado'],
        ]);

        $service = app(CajuPayPixParceladoService::class);
        $creds = $service->credentialsForTenant(1);
        $body = $service->buildPlanPayload(
            $order,
            $product,
            [
                'name' => 'Maria Silva',
                'email' => 'maria@example.com',
                'document' => '52998224725',
                'phone' => '+5511999999999',
            ],
            ['max_installments' => 3],
            3
        );

        $service->createPlan($creds, $body, 'test-idempotency-key');

        Http::assertSent(function ($request) {
            $data = $request->data();
            $phone = $data['consumer']['phone'] ?? null;

            return $request->url() === 'https://api.cajupay.com.br/api/pix-parcelado/plans'
                && $phone === '+5511999999999';
        });
    }

    public function test_installment_paid_webhook_sequence_one_completes_order(): void
    {
        Event::fake();
        $this->seedCajuPayCredential();

        $user = User::factory()->create();
        $product = $this->productWithParcelado();
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 150,
            'currency' => 'BRL',
            'email' => $user->email,
            'gateway' => 'cajupay',
            'gateway_id' => self::PAYMENT_ID,
            'metadata' => [
                'checkout_payment_method' => 'pix_parcelado',
                'cajupay_parcelado_plan_id' => self::PLAN_ID,
                'cajupay_parcelado_first_payment_id' => self::PAYMENT_ID,
            ],
        ]);

        $payload = [
            'type' => 'pix_parcelado.installment.paid',
            'data' => [
                'object' => [
                    'plan_id' => self::PLAN_ID,
                    'payment_id' => self::PAYMENT_ID,
                    'cajupay_payment_id' => self::PAYMENT_ID,
                    'sequence' => 1,
                    'amount_cents' => 5000,
                ],
            ],
        ];
        $raw = json_encode($payload);
        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts.'.'.$raw, self::WEBHOOK_SECRET);

        $response = $this->call(
            'POST',
            route('webhooks.cajupay'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-CajuPay-Event' => 'pix_parcelado.installment.paid',
                'HTTP_X-CajuPay-Timestamp' => $ts,
                'HTTP_X-CajuPay-Signature' => 't='.$ts.',v1='.$sig,
            ],
            $raw
        );

        $response->assertOk();
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_pix_parcelado_shown_when_enrollment_pending(): void
    {
        $this->seedCajuPayCredential(extra: ['pix_parcelado_enrollment_status' => 'pending']);

        $methods = CheckoutPaymentMethodsBuilder::build(1, [
            'pix_parcelado' => 'cajupay',
        ], null);

        $ids = array_column($methods, 'id');
        $this->assertContains('pix_parcelado', $ids);
    }

    public function test_pix_parcelado_hidden_when_suspended(): void
    {
        $this->seedCajuPayCredential(extra: ['pix_parcelado_enrollment_status' => 'suspended']);

        $methods = CheckoutPaymentMethodsBuilder::build(1, [
            'pix_parcelado' => 'cajupay',
        ], null);

        $ids = array_column($methods, 'id');
        $this->assertNotContains('pix_parcelado', $ids);
    }

    public function test_parcelado_session_rejects_non_brl_currency(): void
    {
        $this->seedCajuPayCredential();
        $product = $this->productWithParcelado();

        $response = $this->postJson('/checkout/cajupay/parcelado/session', [
            'product_id' => $product->id,
            'display_currency' => 'USD',
        ]);

        $response->assertStatus(422);
    }

    public function test_one_time_product_update_persists_pix_parcelado_gateway(): void
    {
        $this->seedCajuPayCredential();

        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'price' => 150,
            'currency' => 'BRL',
            'billing_type' => Product::BILLING_ONE_TIME,
            'checkout_config' => Product::defaultCheckoutConfig(),
        ]);

        $response = $this->actingAs($user)->put(route('produtos.update', $product), [
            'name' => $product->name,
            'slug' => $product->slug,
            'type' => $product->type,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 150,
            'currency' => 'BRL',
            'payment_gateways' => [
                'pix_parcelado' => 'cajupay',
                'pix_parcelado_redundancy' => [],
            ],
        ]);

        $response->assertRedirect();
        $this->assertSame(
            'cajupay',
            $product->fresh()->checkout_config['payment_gateways']['pix_parcelado'] ?? null
        );
    }

    public function test_checkout_show_includes_pix_parcelado_when_configured_and_enrolled(): void
    {
        $this->seedCajuPayCredential();
        $product = $this->productWithParcelado();

        $response = $this->get('/c/'.$product->checkout_slug);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('available_payment_methods', fn ($methods) => collect($methods)->contains(
                fn ($m) => ($m['id'] ?? '') === 'pix_parcelado' && ($m['gateway_slug'] ?? '') === 'cajupay'
            )));
    }

    public function test_resolve_pay_account_id_from_payment_links_list(): void
    {
        Http::fake([
            'api.cajupay.com.br/api/payment-links*' => Http::response([
                [
                    'pay_account_id' => 'a51f5bba-7021-476b-9538-d7b29995f3dd',
                    'token' => 'test-token',
                ],
            ], 200),
        ]);

        $this->seedCajuPayCredential();

        $service = app(CajuPayPixParceladoService::class);
        $creds = $service->credentialsForTenant(1);
        $this->assertNotNull($creds);

        $payAccountId = $service->resolvePayAccountId($creds ?? []);
        $this->assertSame('a51f5bba-7021-476b-9538-d7b29995f3dd', $payAccountId);
    }

    public function test_parcelado_session_returns_pay_account_id(): void
    {
        Http::fake([
            'api.cajupay.com.br/api/pix-parcelado/enrollment' => Http::response(['status' => 'active'], 200),
            'api.cajupay.com.br/api/pix-parcelado/platform-rules' => Http::response([
                'max_down_payment_bps' => 6000,
                'bands' => [
                    ['min_total_cents' => 5000, 'max_total_cents' => 99999999, 'max_installments' => 12],
                ],
            ], 200),
            'api.cajupay.com.br/api/payment-links*' => Http::sequence()
                ->push([
                    ['pay_account_id' => 'a51f5bba-7021-476b-9538-d7b29995f3dd'],
                ], 200)
                ->push([
                    'token' => 'link-token-abc',
                    'pay_account_id' => 'a51f5bba-7021-476b-9538-d7b29995f3dd',
                ], 201),
        ]);

        $this->seedCajuPayCredential(extra: ['pix_parcelado_enrollment_status' => 'active']);
        $product = $this->productWithParcelado();

        $response = $this->postJson('/checkout/cajupay/parcelado/session', [
            'product_id' => $product->id,
            'display_currency' => 'BRL',
        ]);

        $response->assertOk();
        $response->assertJsonPath('pay_account_id', 'a51f5bba-7021-476b-9538-d7b29995f3dd');
        $response->assertJsonPath('payment_link_token', 'link-token-abc');
    }

    public function test_checkout_offer_does_not_override_product_pix_parcelado_gateway(): void
    {
        $this->seedCajuPayCredential();
        $product = $this->productWithParcelado();

        $offer = ProductOffer::create([
            'product_id' => $product->id,
            'name' => 'Oferta PIX Parcelado',
            'price' => 150,
            'currency' => 'BRL',
            'checkout_slug' => Str::lower(Str::random(7)),
            'position' => 1,
            'checkout_config' => [
                'payment_gateways' => [
                    'pix_parcelado' => null,
                ],
            ],
        ]);

        $response = $this->get('/c/'.$product->checkout_slug.'?offer_id='.$offer->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('available_payment_methods', fn ($methods) => collect($methods)->contains(
                fn ($m) => ($m['id'] ?? '') === 'pix_parcelado' && ($m['gateway_slug'] ?? '') === 'cajupay'
            )));
    }
}
