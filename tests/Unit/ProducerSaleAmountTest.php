<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\ProductAffiliate;
use App\Models\ProductAffiliateProgram;
use App\Models\User;
use App\Services\ProducerSaleAmount;
use Tests\TestCase;

class ProducerSaleAmountTest extends TestCase
{
    public function test_direct_sale_uses_gross_total(): void
    {
        $product = $this->createTestProduct();
        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'BRL',
            'email' => 'a@test.com',
            'metadata' => ['sale_channel' => 'producer'],
        ]);

        $result = app(ProducerSaleAmount::class)->forOrder($order);

        $this->assertSame(100.0, $result['amount']);
        $this->assertFalse($result['is_producer_share']);
    }

    public function test_affiliate_sale_estimates_producer_share(): void
    {
        $product = $this->createTestProduct();
        ProductAffiliateProgram::create([
            'product_id' => $product->id,
            'enabled' => true,
            'default_commission_percent' => 20,
            'manual_approval' => false,
        ]);
        ProductAffiliate::create([
            'product_id' => $product->id,
            'user_id' => User::factory()->create()->id,
            'affiliate_code' => 'refabc12',
            'status' => ProductAffiliate::STATUS_APPROVED,
        ]);

        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 100,
            'currency' => 'BRL',
            'email' => 'b@test.com',
            'metadata' => [
                'sale_channel' => 'affiliate',
                'affiliate_code' => 'refabc12',
                'checkout_payment_method' => 'pix',
            ],
        ]);

        $result = app(ProducerSaleAmount::class)->forOrder($order);

        $this->assertTrue($result['is_producer_share']);
        $this->assertTrue($result['is_estimated']);
        $this->assertSame(80.0, $result['amount']);
        $this->assertSame(100.0, $result['gross_total']);
        $this->assertTrue($result['has_partner_split']);
    }

    public function test_affiliate_channel_without_valid_partner_uses_gross_total(): void
    {
        $product = $this->createTestProduct();
        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10,
            'currency' => 'BRL',
            'email' => 'c@test.com',
            'metadata' => [
                'sale_channel' => 'affiliate',
                'affiliate_code' => 'invalid999',
                'checkout_payment_method' => 'pix',
            ],
        ]);

        $result = app(ProducerSaleAmount::class)->forOrder($order);

        $this->assertSame(10.0, $result['amount']);
        $this->assertFalse($result['is_producer_share']);
        $this->assertFalse($result['has_partner_split']);
    }
}
