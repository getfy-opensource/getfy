<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\PendingPixCheckoutResolver;
use App\Support\PixCheckoutDisplay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CheckoutPixReuseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['checkout_security.enabled' => true]);
        Cache::flush();
    }

    public function test_middleware_redirects_to_existing_pix_without_new_order(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['price' => 99]);

        $this->createPendingPixOrder($buyer, $product, 'reuse@test.com');

        $response = $this->post('/checkout', [
            'product_id' => $product->id,
            'payment_method' => 'pix',
            'email' => 'reuse@test.com',
            'name' => 'Reuse Buyer',
            'cpf' => '52998224725',
            'phone' => '11999999999',
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('checkout/pix', (string) $response->headers->get('Location'));
        $this->assertSame(1, Order::query()->count());
    }

    public function test_find_reusable_ignores_other_product(): void
    {
        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1]);
        $productA = $this->createTestProduct(['name' => 'A']);
        $productB = $this->createTestProduct(['name' => 'B']);

        $this->createPendingPixOrder($buyer, $productA, 'buyer@test.com');

        $request = Request::create('/checkout', 'POST', [
            'product_id' => $productB->id,
            'payment_method' => 'pix',
            'email' => 'buyer@test.com',
        ]);

        $this->assertNull(PendingPixCheckoutResolver::findReusable($request));
    }

    public function test_find_reusable_ignores_expired_pix(): void
    {
        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1]);
        $product = $this->createTestProduct();

        $order = $this->createPendingPixOrder($buyer, $product, 'expired@test.com');
        $order->update([
            'metadata' => array_merge($order->metadata ?? [], [
                'pix_generated_at' => time() - PixCheckoutDisplay::EXPIRY_SECONDS - 60,
            ]),
        ]);

        $request = Request::create('/checkout', 'POST', [
            'product_id' => $product->id,
            'payment_method' => 'pix',
            'email' => 'expired@test.com',
        ]);

        $this->assertNull(PendingPixCheckoutResolver::findReusable($request));
    }

    public function test_guard_pending_limit_redirects_to_existing_pix(): void
    {
        config(['checkout_security.pending.max_per_email_hour' => 1]);

        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1]);
        $product = $this->createTestProduct();
        $this->createPendingPixOrder($buyer, $product, 'limit@test.com');

        $request = Request::create('/checkout', 'POST', [
            'product_id' => $product->id,
            'payment_method' => 'pix',
            'email' => 'limit@test.com',
        ]);

        $response = PendingPixCheckoutResolver::redirectToPixPage(
            PendingPixCheckoutResolver::findReusable($request),
            $request
        );

        $this->assertTrue($response->isRedirect());
        $this->assertStringContainsString('checkout/pix', $response->getTargetUrl());
    }

    public function test_find_reusable_relaxed_finds_expired_pix_payload(): void
    {
        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1]);
        $product = $this->createTestProduct();

        $order = $this->createPendingPixOrder($buyer, $product, 'relaxed@test.com');
        $order->update([
            'metadata' => array_merge($order->metadata ?? [], [
                'pix_generated_at' => time() - PixCheckoutDisplay::EXPIRY_SECONDS - 60,
            ]),
        ]);

        $request = Request::create('/checkout', 'POST', [
            'product_id' => $product->id,
            'payment_method' => 'pix',
            'email' => 'relaxed@test.com',
        ]);

        $this->assertNull(PendingPixCheckoutResolver::findReusable($request));
        $this->assertNotNull(PendingPixCheckoutResolver::findReusableRelaxed($request));
    }

    public function test_flood_threshold_redirects_to_relaxed_pix_without_new_order(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['price' => 99]);

        $order = $this->createPendingPixOrder($buyer, $product, 'flood@test.com');
        $order->update([
            'metadata' => array_merge($order->metadata ?? [], [
                'pix_generated_at' => time() - PixCheckoutDisplay::EXPIRY_SECONDS - 60,
            ]),
        ]);

        $floodKey = 'checkout_flood_pix:'.sha1('flood@test.com|'.$product->id);
        Cache::put($floodKey, 3, now()->addMinute());

        $response = $this->post('/checkout', [
            'product_id' => $product->id,
            'payment_method' => 'pix',
            'email' => 'flood@test.com',
            'name' => 'Flood Buyer',
            'cpf' => '52998224725',
            'phone' => '11999999999',
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('checkout/pix', (string) $response->headers->get('Location'));
        $this->assertSame(1, Order::query()->count());
    }

    public function test_relaxed_rate_limit_defaults(): void
    {
        $this->assertSame(20, config('checkout_security.rate.pix_per_5_minutes'));
        $this->assertSame(30, config('checkout_security.rate.email_per_hour'));
        $this->assertSame(25, config('checkout_security.pending.max_per_ip_hour'));
        $this->assertSame(15, config('checkout_security.pending.max_per_email_hour'));
        $this->assertSame(8, config('checkout_security.flood.pix_attempts_per_minute'));
    }

    public function test_checkout_throttle_returns_friendly_inertia_error(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct();

        for ($i = 0; $i < 20; $i++) {
            $this->post('/checkout', [
                'product_id' => $product->id,
                'payment_method' => 'card',
                'email' => "throttle{$i}@test.com",
                'name' => 'Throttle',
            ]);
        }

        $response = $this->withHeader('X-Inertia', 'true')
            ->post('/checkout', [
                'product_id' => $product->id,
                'payment_method' => 'card',
                'email' => 'throttle-final@test.com',
                'name' => 'Throttle',
            ]);

        if ($response->status() === 429) {
            $this->fail('Expected Inertia back redirect, got raw 429.');
        }

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    private function createPendingPixOrder(User $buyer, Product $product, string $email): Order
    {
        return Order::query()->create([
            'tenant_id' => $product->tenant_id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 99,
            'currency' => 'BRL',
            'email' => $email,
            'gateway' => 'efi',
            'gateway_id' => 'tx-'.uniqid('', true),
            'metadata' => [
                'checkout_payment_method' => 'pix',
                'pix_copy_paste' => '00020126580014br.gov.bcb.pix.test',
                'pix_qrcode' => null,
                'pix_generated_at' => time(),
            ],
        ]);
    }
}
