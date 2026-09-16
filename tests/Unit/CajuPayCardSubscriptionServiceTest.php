<?php

namespace Tests\Unit;

use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\CajuPaySubscriptionService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CajuPayCardSubscriptionServiceTest extends TestCase
{
    #[Test]
    public function extract_card_token_from_nested_payload(): void
    {
        $extracted = CajuPaySubscriptionService::extractCardTokenPayload([
            'type' => 'checkout.payment.paid',
            'object' => [
                'card_token' => 'tok_abc',
                'card_brand' => 'Visa',
                'card_last4' => '4242',
            ],
        ]);

        $this->assertSame('tok_abc', $extracted['card_token']);
        $this->assertSame('Visa', $extracted['card_brand']);
        $this->assertSame('4242', $extracted['card_last4']);
    }

    #[Test]
    public function create_card_subscription_posts_method_card(): void
    {
        Http::fake([
            '*/api/subscriptions' => Http::response([
                'subscription_id' => 'sub-card-1',
                'status' => 'active',
                'correlation_id' => 'order-1',
                'card_brand' => 'Visa',
                'card_last4' => '4242',
            ], 201),
        ]);

        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        GatewayCredential::query()->create([
            'tenant_id' => 1,
            'gateway_slug' => 'cajupay',
            'is_connected' => true,
            'credentials' => '',
        ]);
        $cred = GatewayCredential::query()->where('gateway_slug', 'cajupay')->where('tenant_id', 1)->first();
        $cred->setEncryptedCredentials([
            'public_key' => 'gpk_test',
            'secret_key' => 'gsk_test',
        ]);
        $cred->save();

        $product = $this->createTestProduct([
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'price' => 99,
        ]);

        $plan = SubscriptionPlan::query()->create([
            'product_id' => $product->id,
            'name' => 'Mensal',
            'price' => 99,
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'checkout_slug' => 'plano-mensal-'.uniqid(),
        ]);

        $buyer = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
            'email' => 'aluno@example.com',
        ]);

        $order = Order::query()->create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'amount' => 99,
            'status' => 'pending',
            'gateway' => 'cajupay',
            'metadata' => ['checkout_payment_method' => 'card'],
        ]);

        $service = app(CajuPaySubscriptionService::class);
        $result = $service->createCard(
            $order,
            $plan,
            ['name' => 'Aluno', 'email' => 'aluno@example.com', 'document' => '11144477735'],
            99.0,
            'tok_saved_xyz',
            'Visa'
        );

        $this->assertSame('sub-card-1', $result['subscription_id']);
        $this->assertSame('active', $result['status']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/subscriptions')) {
                return false;
            }
            $body = $request->data();

            return ($body['method'] ?? null) === 'card'
                && ($body['card_token'] ?? null) === 'tok_saved_xyz'
                && ($body['frequency'] ?? null) === 'MONTHLY';
        });
    }

    #[Test]
    public function persist_card_token_stores_metadata_and_saved_method(): void
    {
        Http::fake([
            '*/api/subscriptions' => Http::response([
                'subscription_id' => 'sub-from-persist',
                'status' => 'active',
                'correlation_id' => 'order-x',
            ], 201),
        ]);

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        GatewayCredential::query()->create([
            'tenant_id' => 1,
            'gateway_slug' => 'cajupay',
            'is_connected' => true,
            'credentials' => '',
        ]);
        $cred = GatewayCredential::query()->where('gateway_slug', 'cajupay')->where('tenant_id', 1)->first();
        $cred->setEncryptedCredentials([
            'public_key' => 'gpk_test',
            'secret_key' => 'gsk_test',
        ]);
        $cred->save();

        $product = $this->createTestProduct([
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'price' => 50,
        ]);

        $plan = SubscriptionPlan::query()->create([
            'product_id' => $product->id,
            'name' => 'Mensal',
            'price' => 50,
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'checkout_slug' => 'plano-mensal-'.uniqid(),
        ]);

        $buyer = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);

        $order = Order::query()->create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'amount' => 50,
            'status' => 'pending',
            'gateway' => 'cajupay',
            'metadata' => ['checkout_payment_method' => 'card'],
        ]);

        app(CajuPaySubscriptionService::class)->persistCardTokenAndMaybeCreateRemote($order, [
            'object' => [
                'card_token' => 'tok_persist_1',
                'card_brand' => 'Master',
                'card_last4' => '1111',
            ],
        ]);

        $order->refresh();
        $this->assertSame('tok_persist_1', $order->metadata['cajupay_card_token'] ?? null);
        $this->assertSame('sub-from-persist', $order->metadata['cajupay_subscription_id'] ?? null);
        $this->assertDatabaseHas('saved_payment_methods', [
            'user_id' => $buyer->id,
            'gateway' => 'cajupay',
            'gateway_payment_method_id' => 'tok_persist_1',
            'last_four' => '1111',
            'brand' => 'Master',
        ]);
    }
}
