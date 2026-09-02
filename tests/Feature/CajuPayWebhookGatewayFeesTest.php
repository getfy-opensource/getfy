<?php

namespace Tests\Feature;

use App\Jobs\ProcessPaymentWebhook;
use App\Models\CommissionEntry;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CajuPayWebhookGatewayFeesTest extends TestCase
{
    public function test_paid_webhook_persists_gateway_fee_and_net_cents(): void
    {
        Event::fake();

        $user = User::factory()->create();
        $product = $this->createTestProduct(['price' => 37]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 37,
            'currency' => 'BRL',
            'email' => $user->email,
            'gateway' => 'cajupay',
            'gateway_id' => 'sess-fee-1',
            'metadata' => [
                'checkout_payment_method' => 'pix',
                'cajupay_checkout_session_id' => 'sess-fee-1',
            ],
        ]);

        $payload = [
            'type' => 'checkout.payment.paid',
            'webhook_source' => 'cajupay_hmac_verified',
            'data' => [
                'object' => [
                    'checkout_session_id' => 'sess-fee-1',
                    'amount_cents' => 3700,
                    'currency' => 'brl',
                    'fee_cents' => 90,
                    'net_cents' => 3610,
                ],
            ],
        ];

        ProcessPaymentWebhook::dispatchSync('cajupay', 'sess-fee-1', 'order.paid', 'paid', $payload);

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $meta = $order->metadata ?? [];
        $this->assertSame(90, $meta['gateway_fee_cents'] ?? null);
        $this->assertSame(3610, $meta['gateway_net_cents'] ?? null);
        $this->assertSame('cajupay_webhook', $meta['gateway_fee_source'] ?? null);

        $breakdown = $order->financialBreakdown();
        $this->assertSame(0.9, $breakdown['fee']);
        $this->assertSame(36.1, $breakdown['net']);
    }

    public function test_completed_webhook_resyncs_producer_commission_entry_fee(): void
    {
        Event::fake();

        $user = User::factory()->create();
        $product = $this->createTestProduct(['price' => 37]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 37,
            'currency' => 'BRL',
            'email' => $user->email,
            'gateway' => 'cajupay',
            'gateway_id' => 'sess-fee-2',
            'metadata' => [
                'checkout_payment_method' => 'pix',
                'cajupay_checkout_session_id' => 'sess-fee-2',
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

        $payload = [
            'type' => 'checkout.payment.paid',
            'webhook_source' => 'cajupay_hmac_verified',
            'data' => [
                'object' => [
                    'checkout_session_id' => 'sess-fee-2',
                    'amount_cents' => 3700,
                    'currency' => 'brl',
                    'fee_cents' => 90,
                    'net_cents' => 3610,
                ],
            ],
        ];

        ProcessPaymentWebhook::dispatchSync('cajupay', 'sess-fee-2', 'order.paid', 'paid', $payload);

        $this->assertDatabaseHas('commission_entries', [
            'order_id' => $order->id,
            'role' => CommissionEntry::ROLE_PRODUTOR,
            'gateway_fee_amount' => 0.9,
            'net_amount' => 36.1,
        ]);
    }
}
