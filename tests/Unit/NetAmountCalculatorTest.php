<?php

namespace Tests\Unit;

use App\Models\CommissionEntry;
use App\Models\GatewayFeeSetting;
use App\Models\Order;
use App\Models\User;
use App\Services\NetAmountCalculator;
use Tests\TestCase;

class NetAmountCalculatorTest extends TestCase
{
    public function test_uses_gateway_webhook_fee_when_present_in_metadata(): void
    {
        $user = User::factory()->create();
        $product = $this->createTestProduct();

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 37,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'gateway' => 'cajupay',
            'metadata' => [
                'checkout_payment_method' => 'pix',
                'gateway_fee_cents' => 90,
                'gateway_net_cents' => 3610,
                'gateway_fee_source' => 'cajupay_webhook',
            ],
        ]);

        $result = app(NetAmountCalculator::class)->forOrder($order);

        $this->assertSame(37.0, $result['gross']);
        $this->assertSame(0.9, $result['fee']);
        $this->assertSame(36.1, $result['net']);
        $this->assertSame('cajupay_webhook', $result['fee_source']);
    }

    public function test_uses_commission_entry_fee_when_no_webhook_metadata(): void
    {
        $user = User::factory()->create();
        $product = $this->createTestProduct();

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'gateway' => 'cajupay',
            'metadata' => ['checkout_payment_method' => 'pix'],
        ]);

        CommissionEntry::create([
            'order_id' => $order->id,
            'tenant_id' => 1,
            'beneficiary_user_id' => $user->id,
            'role' => CommissionEntry::ROLE_PRODUTOR,
            'gross_amount' => 100,
            'gateway_fee_amount' => 2.5,
            'net_amount' => 97.5,
            'commission_amount' => 97.5,
            'status' => CommissionEntry::STATUS_PENDING,
            'payment_method' => 'pix',
        ]);

        $result = app(NetAmountCalculator::class)->forOrder($order->fresh());

        $this->assertSame(2.5, $result['fee']);
        $this->assertSame(97.5, $result['net']);
        $this->assertSame(NetAmountCalculator::FEE_SOURCE_COMMISSION_ENTRY, $result['fee_source']);
    }

    public function test_falls_back_to_estimated_fee_from_gateway_settings(): void
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

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 37,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'gateway' => 'cajupay',
            'metadata' => ['checkout_payment_method' => 'pix'],
        ]);

        $result = app(NetAmountCalculator::class)->forOrder($order);

        $this->assertSame(0.9, $result['fee']);
        $this->assertSame(36.1, $result['net']);
        $this->assertSame(NetAmountCalculator::FEE_SOURCE_ESTIMATED, $result['fee_source']);
    }

    public function test_webhook_fee_takes_priority_over_commission_entry(): void
    {
        $user = User::factory()->create();
        $product = $this->createTestProduct();

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 37,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'gateway' => 'cajupay',
            'metadata' => [
                'checkout_payment_method' => 'pix',
                'gateway_fee_cents' => 50,
                'gateway_net_cents' => 3650,
                'gateway_fee_source' => 'cajupay_webhook',
            ],
        ]);

        CommissionEntry::create([
            'order_id' => $order->id,
            'tenant_id' => 1,
            'beneficiary_user_id' => $user->id,
            'role' => CommissionEntry::ROLE_PRODUTOR,
            'gross_amount' => 37,
            'gateway_fee_amount' => 0.99,
            'net_amount' => 36.01,
            'commission_amount' => 36.01,
            'status' => CommissionEntry::STATUS_PENDING,
            'payment_method' => 'pix',
        ]);

        $result = app(NetAmountCalculator::class)->forOrder($order->fresh());

        $this->assertSame(0.5, $result['fee']);
        $this->assertSame(36.5, $result['net']);
        $this->assertSame('cajupay_webhook', $result['fee_source']);
    }
}
