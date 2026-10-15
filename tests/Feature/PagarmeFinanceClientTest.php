<?php

namespace Tests\Feature;

use App\Gateways\Pagarme\PagarmeFinanceClient;
use App\Gateways\Pagarme\PagarmeFinanceUnauthorized;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cliente da API financeira da Pagar.me (v5): saldo, recebíveis (cursor), transferências e antecipações.
 * A chave financeira é separada da chave transacional do checkout e nunca aparece em mensagens de erro.
 */
class PagarmeFinanceClientTest extends TestCase
{
    private function client(): PagarmeFinanceClient
    {
        return new PagarmeFinanceClient('sk_test_finance_secret', 're_test_main');
    }

    public function test_payables_follow_forward_cursor_across_pages(): void
    {
        Http::fake([
            'api.pagar.me/core/v5/payables*' => Http::sequence()
                ->push(['data' => [['id' => 1], ['id' => 2]], 'paging' => ['forward_cursor' => 'cur_2']])
                ->push(['data' => [['id' => 3]], 'paging' => []]),
        ]);

        $ids = [];
        foreach ($this->client()->payables(['charge_id' => 'ch_test_1']) as $payable) {
            $ids[] = $payable['id'];
        }

        $this->assertSame([1, 2, 3], $ids);
        $requests = Http::recorded()->map(fn ($pair) => $pair[0]);
        $this->assertCount(2, $requests);
        $this->assertStringContainsString('charge_id=ch_test_1', $requests[0]->url());
        $this->assertStringContainsString('forward_cursor=cur_2', $requests[1]->url());
    }

    public function test_unauthorized_key_raises_dedicated_exception_without_leaking_the_key(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Authorization has been denied for this request.'], 401)]);

        try {
            iterator_to_array($this->client()->payables([]));
            $this->fail('expected PagarmeFinanceUnauthorized');
        } catch (PagarmeFinanceUnauthorized $e) {
            $this->assertStringNotContainsString('sk_test_finance_secret', $e->getMessage());
            $this->assertStringContainsString('payables', $e->getMessage());
        }
    }

    public function test_balance_returns_cents(): void
    {
        Http::fake(['api.pagar.me/core/v5/recipients/re_test_main/balance' => Http::response([
            'currency' => 'BRL', 'available_amount' => 12345, 'waiting_funds_amount' => 500, 'transferred_amount' => null,
        ])]);

        $balance = $this->client()->balance();

        $this->assertSame(12345, $balance['available_cents']);
        $this->assertSame(500, $balance['waiting_funds_cents']);
    }

    public function test_create_transfer_sends_idempotency_key_and_only_amount_and_recipient(): void
    {
        Http::fake(['api.pagar.me/core/v5/transfers' => Http::response([
            'id' => 65485, 'amount' => 1000, 'type' => 'ted', 'status' => 'pending_transfer', 'fee' => 300,
        ])]);

        $transfer = $this->client()->createTransfer(1000, 'getfy-transfer-abc');

        $this->assertSame('65485', (string) $transfer['id']);
        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST'
                && $r->hasHeader('Idempotency-Key', 'getfy-transfer-abc')
                && $r['amount'] === 1000
                && $r['recipient_id'] === 're_test_main'
                && ! isset($r['bank_account']);
        });
    }
}
