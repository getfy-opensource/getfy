<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Order;
use App\Models\User;
use Tests\TestCase;

class VendasPaginationTest extends TestCase
{
    public function test_vendas_pagination_returns_distinct_pages_with_valid_links(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct(['tenant_id' => 1]);

        for ($i = 1; $i <= 25; $i++) {
            Order::create([
                'tenant_id' => 1,
                'user_id' => $user->id,
                'product_id' => $product->id,
                'status' => 'completed',
                'amount' => $i,
                'email' => "buyer{$i}@example.com",
                'gateway' => 'manual',
            ]);
        }

        $page1 = $this->actingAs($user)->get('/vendas');
        $page1->assertOk();
        $page1->assertInertia(fn ($page) => $page
            ->where('vendas.current_page', 1)
            ->where('vendas.last_page', 2)
            ->has('vendas.data', 20)
            ->where('vendas.data.0.billed_amount', 25.0)
            ->where('vendas.data.0.net_profit_amount', 25.0)
            ->has('vendas.links'));

        $page2 = $this->actingAs($user)->get('/vendas?page=2');
        $page2->assertOk();
        $page2->assertInertia(fn ($page) => $page
            ->where('vendas.current_page', 2)
            ->where('vendas.last_page', 2)
            ->has('vendas.data', 5));

        $links = $page2->original->getData()['page']['props']['vendas']['links'] ?? [];
        $next = $links[count($links) - 1] ?? null;
        $this->assertIsArray($next);
        $this->assertNull($next['url']);
    }

    public function test_vendas_page_three_works_with_many_orders(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct(['tenant_id' => 1]);

        for ($i = 1; $i <= 55; $i++) {
            Order::create([
                'tenant_id' => 1,
                'user_id' => $user->id,
                'product_id' => $product->id,
                'status' => 'completed',
                'amount' => $i,
                'email' => "buyer{$i}@example.com",
                'gateway' => 'manual',
            ]);
        }

        $this->actingAs($user)
            ->get('/vendas?page=3')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('vendas.current_page', 3)
                ->where('vendas.last_page', 3)
                ->has('vendas.data', 15));
    }
}
