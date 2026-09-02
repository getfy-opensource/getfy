<?php

namespace Tests\Feature;

use App\Models\GatewayFeeSetting;
use App\Models\Order;
use App\Models\User;
use App\Support\OrderFinancialTotals;
use Tests\TestCase;

class OrderFinancialTotalsTest extends TestCase
{
    public function test_aggregates_gross_fees_and_net_for_completed_orders(): void
    {
        $user = User::factory()->create();
        $product = $this->createTestProduct();

        GatewayFeeSetting::create([
            'tenant_id' => 1,
            'gateway_slug' => 'cajupay',
            'method' => 'pix',
            'percent' => 0,
            'fixed_cents' => 90,
        ]);

        foreach (range(1, 6) as $i) {
            Order::create([
                'tenant_id' => 1,
                'user_id' => $user->id,
                'product_id' => $product->id,
                'status' => 'completed',
                'amount' => 37,
                'currency' => 'BRL',
                'email' => "buyer{$i}@test.com",
                'gateway' => 'cajupay',
                'metadata' => ['checkout_payment_method' => 'pix'],
            ]);
        }

        $query = Order::query()->where('tenant_id', 1);
        $rows = OrderFinancialTotals::porMoedaFromQuery($query);
        $brl = OrderFinancialTotals::brlTotals($rows);

        $this->assertSame(222.0, $brl['gross']);
        $this->assertSame(5.4, $brl['fees']);
        $this->assertSame(216.6, $brl['net']);
    }

    public function test_uses_real_webhook_fees_in_aggregation(): void
    {
        $user = User::factory()->create();
        $product = $this->createTestProduct();

        Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'gateway' => 'cajupay',
            'metadata' => [
                'checkout_payment_method' => 'pix',
                'gateway_fee_cents' => 120,
                'gateway_net_cents' => 9880,
                'gateway_fee_source' => 'cajupay_webhook',
            ],
        ]);

        $query = Order::query()->where('tenant_id', 1);
        $brl = OrderFinancialTotals::brlTotals(OrderFinancialTotals::porMoedaFromQuery($query));

        $this->assertSame(100.0, $brl['gross']);
        $this->assertSame(1.2, $brl['fees']);
        $this->assertSame(98.8, $brl['net']);
    }
}
