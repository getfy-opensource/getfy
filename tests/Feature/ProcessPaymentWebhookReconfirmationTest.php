<?php

namespace Tests\Feature;

use App\Jobs\ProcessPaymentWebhook;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ProcessPaymentWebhookReconfirmationTest extends TestCase
{
    public function test_cancel_skipped_when_mercadopago_reconfirm_policy_reject_and_api_unavailable(): void
    {
        Config::set('webhooks.reconfirm_fail_policy.mercadopago', 'reject');

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['name' => 'P']);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10,
            'email' => 't@test.com',
            'gateway' => 'mercadopago',
            'gateway_id' => 'mp-tx-1',
        ]);

        ProcessPaymentWebhook::dispatchSync('mercadopago', 'mp-tx-1', 'order.cancelled', 'cancelled', []);

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_cancel_applied_when_policy_accept_and_api_unavailable(): void
    {
        Config::set('webhooks.reconfirm_fail_policy.default', 'accept');

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['name' => 'P2']);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10,
            'email' => 't2@test.com',
            'gateway' => 'pushinpay',
            'gateway_id' => 'pp-tx-1',
        ]);

        ProcessPaymentWebhook::dispatchSync('pushinpay', 'pp-tx-1', 'order.cancelled', 'cancelled', []);

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_refund_skipped_when_mercadopago_policy_reject_and_api_unavailable(): void
    {
        Config::set('webhooks.reconfirm_fail_policy.mercadopago', 'reject');

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['name' => 'P3']);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => 't3@test.com',
            'gateway' => 'mercadopago',
            'gateway_id' => 'mp-tx-2',
        ]);

        ProcessPaymentWebhook::dispatchSync('mercadopago', 'mp-tx-2', 'order.refunded', 'refunded', []);

        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_mercadopago_uses_reject_policy_by_default_from_config(): void
    {
        $merged = require config_path('webhooks.php');

        $this->assertSame('reject', $merged['reconfirm_fail_policy']['mercadopago']);
        $this->assertSame('accept', $merged['reconfirm_fail_policy']['default']);
    }
}
