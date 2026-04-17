<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Tests\TestCase;

class ProductComboAccessTest extends TestCase
{
    public function test_grant_attaches_buyer_to_combo_product_on_base_price(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $combo = $this->createTestProduct(['name' => 'Combo alvo']);
        $main = $this->createTestProduct([
            'name' => 'Principal',
            'combo_product_ids' => [$combo->id],
        ]);

        $buyer = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $main->id,
            'product_offer_id' => null,
            'subscription_plan_id' => null,
            'status' => 'completed',
            'amount' => 10.00,
        ]);

        $order->grantPurchasedProductAccessToBuyer();

        $this->assertTrue($main->users()->where('users.id', $buyer->id)->exists());
        $this->assertTrue($combo->users()->where('users.id', $buyer->id)->exists());
        $this->assertSame(10.0, (float) $order->fresh()->amount);
    }

    public function test_grant_uses_combo_from_offer_when_product_offer_id_set(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $combo = $this->createTestProduct(['name' => 'Via oferta']);
        $main = $this->createTestProduct(['name' => 'Principal 2']);

        $offer = ProductOffer::create([
            'product_id' => $main->id,
            'name' => 'Black',
            'price' => 7,
            'currency' => 'BRL',
            'checkout_slug' => ProductOffer::generateUniqueCheckoutSlug(),
            'position' => 1,
            'combo_product_ids' => [$combo->id],
        ]);

        $buyer = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $main->id,
            'product_offer_id' => $offer->id,
            'subscription_plan_id' => null,
            'status' => 'completed',
            'amount' => 7.00,
        ]);

        $order->grantPurchasedProductAccessToBuyer();

        $this->assertTrue($combo->users()->where('users.id', $buyer->id)->exists());
    }

    public function test_grant_uses_combo_from_subscription_plan_when_plan_set(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $combo = $this->createTestProduct(['name' => 'Via plano']);
        $main = $this->createTestProduct([
            'name' => 'Assinatura',
            'billing_type' => Product::BILLING_SUBSCRIPTION,
        ]);

        $plan = SubscriptionPlan::create([
            'product_id' => $main->id,
            'name' => 'Mensal',
            'price' => 49,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'checkout_slug' => SubscriptionPlan::generateUniqueCheckoutSlug(),
            'position' => 1,
            'combo_product_ids' => [$combo->id],
        ]);

        $buyer = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $main->id,
            'product_offer_id' => null,
            'subscription_plan_id' => $plan->id,
            'status' => 'completed',
            'amount' => 49.00,
        ]);

        $order->grantPurchasedProductAccessToBuyer();

        $this->assertTrue($combo->users()->where('users.id', $buyer->id)->exists());
    }

    public function test_grant_attaches_buyer_to_all_combo_products_when_multiple_configured(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $comboA = $this->createTestProduct(['name' => 'Extra A']);
        $comboB = $this->createTestProduct(['name' => 'Extra B']);
        $main = $this->createTestProduct([
            'name' => 'Principal multi',
            'combo_product_ids' => [$comboA->id, $comboB->id],
        ]);

        $buyer = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $main->id,
            'product_offer_id' => null,
            'subscription_plan_id' => null,
            'status' => 'completed',
            'amount' => 15.00,
        ]);

        $order->grantPurchasedProductAccessToBuyer();

        $this->assertTrue($comboA->users()->where('users.id', $buyer->id)->exists());
        $this->assertTrue($comboB->users()->where('users.id', $buyer->id)->exists());
    }
}
