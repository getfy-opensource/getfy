<?php

namespace Tests\Feature;

use App\Models\CommissionEntry;
use App\Models\Order;
use App\Models\PagarmeAccount;
use App\Models\PagarmePayable;
use App\Models\User;
use App\Services\NetAmountCalculator;
use App\Services\PagarmeFinanceSync;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Espelho local dos recebíveis da Pagar.me e taxa real por venda.
 * A conta Pagar.me é compartilhada por dois tenants: cada recebível é atribuído ao tenant do pedido (charge_id).
 */
class PagarmeFinanceSyncTest extends TestCase
{
    private PagarmeAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = PagarmeAccount::create([
            'label' => 'Conta principal', 'recipient_id' => 're_test_main', 'finance_secret_key' => 'sk_test_finance_secret',
        ]);
        $this->account->attachTenants([10, 11]);
    }

    private function order(int $tenantId, string $chargeId, array $attrs = []): Order
    {
        $buyer = User::factory()->create(['tenant_id' => $tenantId, 'role' => User::ROLE_ALUNO]);
        $product = $this->createTestProduct(['tenant_id' => $tenantId, 'price' => 100]);

        return Order::create(array_merge([
            'tenant_id' => $tenantId, 'user_id' => $buyer->id, 'product_id' => $product->id,
            'amount' => 100, 'currency' => 'BRL', 'email' => $buyer->email, 'status' => 'completed',
            'gateway' => 'pagarme', 'gateway_id' => $chargeId, 'metadata' => ['checkout_payment_method' => 'card', 'installments' => 2],
        ], $attrs));
    }

    private function payable(array $attrs): array
    {
        return array_merge([
            'id' => random_int(1000, 999999), 'status' => 'waiting_funds', 'amount' => 5000, 'fee' => 200,
            'anticipation_fee' => 0, 'fraud_coverage_fee' => 0, 'installment' => 1, 'charge_id' => 'ch_test_a',
            'recipient_id' => 're_test_main', 'payment_date' => '2026-11-14T03:00:00Z', 'type' => 'credit',
            'payment_method' => 'credit_card', 'accrual_at' => '2026-10-14T13:00:00Z', 'created_at' => '2026-10-14T13:00:01Z',
        ], $attrs);
    }

    private function fakePayables(array $payables): void
    {
        Http::fake(['api.pagar.me/core/v5/payables*' => Http::response(['data' => $payables, 'paging' => []])]);
    }

    public function test_sync_upserts_payables_idempotently_and_attributes_them_to_the_order_tenant(): void
    {
        $orderA = $this->order(10, 'ch_test_a');
        $orderB = $this->order(11, 'ch_test_b');
        $rows = [
            $this->payable(['id' => 11, 'charge_id' => 'ch_test_a']),
            $this->payable(['id' => 12, 'charge_id' => 'ch_test_b']),
            $this->payable(['id' => 13, 'charge_id' => 'ch_test_outside']),
        ];
        $updated = $rows;
        $updated[0]['status'] = 'paid';
        Http::fake(['api.pagar.me/core/v5/payables*' => Http::sequence()
            ->push(['data' => $rows, 'paging' => []])
            ->push(['data' => $updated, 'paging' => []])]);

        app(PagarmeFinanceSync::class)->syncPayables($this->account);
        app(PagarmeFinanceSync::class)->syncPayables($this->account);

        $this->assertSame(3, PagarmePayable::count());
        $a = PagarmePayable::where('payable_id', '11')->first();
        $this->assertSame($orderA->id, $a->order_id);
        $this->assertSame(10, $a->tenant_id);
        $this->assertSame('paid', $a->status);
        $this->assertSame(11, PagarmePayable::where('payable_id', '12')->value('tenant_id'));
        $outside = PagarmePayable::where('payable_id', '13')->first();
        $this->assertNull($outside->order_id);
        $this->assertNull($outside->tenant_id);
        $this->assertNotNull($this->account->fresh()->payables_synced_until);
        $this->assertNotNull($orderB);
    }

    public function test_payables_of_other_recipients_are_ignored(): void
    {
        $order = $this->order(10, 'ch_test_a');
        $this->fakePayables([
            $this->payable(['id' => 31, 'charge_id' => 'ch_test_a', 'fee' => 200]),
            $this->payable(['id' => 32, 'charge_id' => 'ch_test_a', 'recipient_id' => 're_test_other', 'fee' => 900]),
        ]);

        app(PagarmeFinanceSync::class)->syncPayables($this->account);

        $this->assertSame(['31'], PagarmePayable::pluck('payable_id')->all());
        $this->assertSame(200, $order->fresh()->metadata['gateway_fee_cents']);
    }

    public function test_real_fee_from_payables_overrides_the_estimate_and_updates_producer_entry(): void
    {
        $order = $this->order(10, 'ch_test_a');
        $producer = User::factory()->create(['tenant_id' => 10, 'role' => User::ROLE_INFOPRODUTOR]);
        CommissionEntry::create([
            'order_id' => $order->id, 'tenant_id' => 10, 'beneficiary_user_id' => $producer->id,
            'role' => CommissionEntry::ROLE_PRODUTOR, 'gross_amount' => 100, 'gateway_fee_amount' => 9.99,
            'net_amount' => 90.01, 'commission_percent' => 100, 'commission_amount' => 90.01,
            'status' => CommissionEntry::STATUS_AVAILABLE, 'payment_method' => 'card',
        ]);
        $this->fakePayables([
            $this->payable(['id' => 21, 'installment' => 1, 'amount' => 5000, 'fee' => 200, 'fraud_coverage_fee' => 10]),
            $this->payable(['id' => 22, 'installment' => 2, 'amount' => 5000, 'fee' => 200, 'anticipation_fee' => 40]),
            // Estorno não é taxa da venda.
            $this->payable(['id' => 23, 'type' => 'refund', 'amount' => -100, 'fee' => 0]),
        ]);

        app(PagarmeFinanceSync::class)->syncCharge($order);

        $meta = $order->fresh()->metadata;
        $this->assertSame(410, $meta['gateway_fee_cents']);
        $this->assertSame(40, $meta['gateway_anticipation_fee_cents']);
        $this->assertSame(PagarmeFinanceSync::FEE_SOURCE, $meta['gateway_fee_source']);
        $breakdown = app(NetAmountCalculator::class)->forOrder($order->fresh());
        $this->assertSame(4.1, $breakdown['fee']);
        $this->assertSame(95.9, $breakdown['net']);
        $this->assertSame(PagarmeFinanceSync::FEE_SOURCE, $breakdown['fee_source']);
        $entry = $order->commissionEntries()->where('role', CommissionEntry::ROLE_PRODUTOR)->first();
        $this->assertEquals(4.10, (float) $entry->gateway_fee_amount);
        $this->assertEquals(95.90, (float) $entry->net_amount);
    }

    public function test_denied_api_keeps_the_estimate_and_never_logs_the_key(): void
    {
        $order = $this->order(10, 'ch_test_a');
        Http::fake(['*' => Http::response(['message' => 'Authorization has been denied for this request.'], 401)]);
        Log::spy();

        app(PagarmeFinanceSync::class)->syncCharge($order);

        $this->assertArrayNotHasKey('gateway_fee_cents', $order->fresh()->metadata);
        $this->assertSame(NetAmountCalculator::FEE_SOURCE_ESTIMATED, app(NetAmountCalculator::class)->forOrder($order->fresh())['fee_source']);
        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) {
            return ! str_contains($message.json_encode($context), 'sk_test_finance_secret');
        });
    }

    public function test_order_of_tenant_without_account_is_ignored(): void
    {
        $order = $this->order(20, 'ch_test_other');
        Http::fake();

        app(PagarmeFinanceSync::class)->syncCharge($order);

        Http::assertNothingSent();
        $this->assertSame(0, PagarmePayable::count());
    }

    public function test_the_key_is_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $raw = \DB::table('pagarme_accounts')->where('id', $this->account->id)->value('finance_secret_key');

        $this->assertNotSame('sk_test_finance_secret', $raw);
        $this->assertSame('sk_test_finance_secret', $this->account->fresh()->finance_secret_key);
        $this->assertArrayNotHasKey('finance_secret_key', $this->account->fresh()->toArray());
    }
}
