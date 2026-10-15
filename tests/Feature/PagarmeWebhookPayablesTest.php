<?php

namespace Tests\Feature;

use App\Jobs\SyncPagarmeChargePayables;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\PagarmeAccount;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Webhook da Pagar.me agenda a leitura dos recebíveis da cobrança (taxa real).
 * A taxa só vem da API (/payables); valores no corpo do webhook são ignorados.
 */
class PagarmeWebhookPayablesTest extends TestCase
{
    private function order(int $tenantId, string $chargeId): Order
    {
        $user = User::factory()->create(['tenant_id' => $tenantId]);
        $product = $this->createTestProduct(['tenant_id' => $tenantId]);

        return Order::create([
            'tenant_id' => $tenantId, 'user_id' => $user->id, 'product_id' => $product->id, 'status' => 'completed',
            'amount' => 100, 'email' => $user->email, 'gateway' => 'pagarme', 'gateway_id' => $chargeId,
            'metadata' => ['checkout_payment_method' => 'pix'],
        ]);
    }

    private function connect(int $tenantId): void
    {
        $cred = GatewayCredential::create(['tenant_id' => $tenantId, 'gateway_slug' => 'pagarme', 'credentials' => '', 'is_connected' => true]);
        $cred->setEncryptedCredentials(['secret_key' => 'sk_test_tx', 'public_key' => 'pk_test_tx']);
        $cred->save();
    }

    public function test_charge_webhook_schedules_payables_sync_for_tenant_with_finance_account(): void
    {
        Bus::fake([SyncPagarmeChargePayables::class]);
        Http::fake(['api.pagar.me/core/v5/charges/*' => Http::response(['id' => 'ch_test_paid', 'status' => 'paid'])]);
        $this->connect(10);
        PagarmeAccount::create(['label' => 'Conta', 'recipient_id' => 're_test_main', 'finance_secret_key' => 'sk_test_fin'])
            ->attachTenants([10]);
        $order = $this->order(10, 'ch_test_paid');

        $this->postJson('/webhooks/gateways/pagarme', [
            'type' => 'charge.paid',
            'data' => ['id' => 'ch_test_paid', 'status' => 'paid', 'fee' => 1, 'fee_cents' => 1, 'net_cents' => 9999],
        ])->assertOk();

        Bus::assertDispatched(SyncPagarmeChargePayables::class, fn ($job) => $job->orderId === $order->id);
        $meta = $order->fresh()->metadata;
        $this->assertArrayNotHasKey('gateway_fee_cents', $meta);
        $this->assertArrayNotHasKey('gateway_net_cents', $meta);
    }

    public function test_no_sync_is_scheduled_without_finance_account(): void
    {
        Bus::fake([SyncPagarmeChargePayables::class]);
        Http::fake(['api.pagar.me/core/v5/charges/*' => Http::response(['id' => 'ch_test_x', 'status' => 'paid'])]);
        $this->connect(12);
        $this->order(12, 'ch_test_x');

        $this->postJson('/webhooks/gateways/pagarme', [
            'type' => 'charge.paid',
            'data' => ['id' => 'ch_test_x', 'status' => 'paid'],
        ])->assertOk();

        Bus::assertNotDispatched(SyncPagarmeChargePayables::class);
    }
}
