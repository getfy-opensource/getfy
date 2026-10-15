<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PagarmeAccount;
use App\Models\PagarmePayable;
use App\Models\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * /financeiro em modo Pagar.me: visão por produtor (recebíveis do tenant) + bloco da conta compartilhada
 * (ações só para dono ou equipe com financeiro.manage).
 */
class PagarmeFinanceiroPageTest extends TestCase
{
    private PagarmeAccount $account;

    private User $producerA;

    private User $producerB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->producerA = User::factory()->create(['tenant_id' => 10, 'role' => User::ROLE_INFOPRODUTOR]);
        $this->producerB = User::factory()->create(['tenant_id' => 11, 'role' => User::ROLE_INFOPRODUTOR]);
        $this->account = PagarmeAccount::create([
            'label' => 'Conta principal', 'recipient_id' => 're_test_main', 'finance_secret_key' => 'sk_test_finance_secret',
        ]);
        $this->account->attachTenants([10, 11]);
    }

    private function fakeAccountApi(): void
    {
        Http::fake([
            'api.pagar.me/core/v5/recipients/re_test_main/balance' => Http::response(['available_amount' => 150000, 'waiting_funds_amount' => 90000]),
            'api.pagar.me/core/v5/recipients/re_test_main' => Http::response([
                'id' => 're_test_main',
                'default_bank_account' => ['bank' => '341', 'branch_number' => '1234', 'account_number' => '9876543', 'holder_name' => 'Empresa'],
                'transfer_settings' => ['transfer_enabled' => true, 'transfer_interval' => 'weekly', 'transfer_day' => 1],
            ]),
        ]);
    }

    private function payable(int $tenantId, array $attrs = []): PagarmePayable
    {
        static $seq = 100;
        $seq++;
        $order = null;
        if ($tenantId > 0) {
            $buyer = User::factory()->create(['tenant_id' => $tenantId, 'role' => User::ROLE_ALUNO, 'name' => 'Comprador '.$seq]);
            $product = $this->createTestProduct(['tenant_id' => $tenantId]);
            $order = Order::create([
                'tenant_id' => $tenantId, 'user_id' => $buyer->id, 'product_id' => $product->id, 'amount' => 100,
                'currency' => 'BRL', 'email' => $buyer->email, 'status' => 'completed', 'gateway' => 'pagarme',
                'gateway_id' => 'ch_test_'.$seq,
            ]);
        }

        return PagarmePayable::create(array_merge([
            'pagarme_account_id' => $this->account->id, 'payable_id' => (string) $seq, 'charge_id' => 'ch_test_'.$seq,
            'order_id' => $order?->id, 'tenant_id' => $order?->tenant_id, 'type' => 'credit', 'status' => 'waiting_funds',
            'payment_method' => 'credit_card', 'installment' => 1, 'amount_cents' => 10000, 'fee_cents' => 400,
            'payment_date' => now()->addDays(20)->toDateString(), 'accrual_at' => now()->subDay(),
        ], $attrs));
    }

    public function test_producer_sees_only_own_payables_and_the_shared_account_block(): void
    {
        $this->fakeAccountApi();
        $this->payable(10, ['amount_cents' => 10000, 'fee_cents' => 400]);
        $this->payable(10, ['amount_cents' => 5000, 'fee_cents' => 50, 'payment_method' => 'pix', 'status' => 'paid', 'payment_date' => now()->subDay()->toDateString()]);
        $this->payable(11, ['amount_cents' => 700000, 'fee_cents' => 10000]);
        $this->payable(0, ['amount_cents' => 30000, 'fee_cents' => 1000]);

        $this->actingAs($this->producerA)->get('/financeiro')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Financeiro/Pagarme')
                ->where('overview.gross_cents', 15000)
                ->where('overview.fee_cents', 450)
                ->where('overview.net_cents', 14550)
                ->where('overview.waiting_cents', 9600)
                ->where('overview.received_cents', 4950)
                ->has('payables.data', 2)
                ->where('account.balance.available_cents', 150000)
                ->where('account.bank_account.account_last4', '6543')
                ->where('account.outside_getfy.waiting_cents', 29000)
                ->where('account.can_act', true)
                ->where('write_enabled', false)
            );
    }

    public function test_other_producer_never_sees_foreign_payables(): void
    {
        $this->fakeAccountApi();
        $foreign = $this->payable(10, ['amount_cents' => 10000]);
        $this->payable(11, ['amount_cents' => 2000, 'fee_cents' => 20]);

        $response = $this->actingAs($this->producerB)->get('/financeiro?status=waiting_funds');

        $response->assertInertia(fn ($page) => $page
            ->component('Financeiro/Pagarme')
            ->where('overview.gross_cents', 2000)
            ->has('payables.data', 1)
            ->where('payables.data.0.charge_id', fn ($id) => $id !== $foreign->charge_id)
        );
    }

    public function test_page_never_exposes_the_finance_key_or_full_bank_account(): void
    {
        $this->fakeAccountApi();
        $this->payable(10);

        $content = $this->actingAs($this->producerA)->get('/financeiro')->getContent();

        $this->assertStringNotContainsString('sk_test_finance_secret', $content);
        $this->assertStringNotContainsString('9876543', $content);
    }

    public function test_team_member_with_view_permission_sees_pagarme_page(): void
    {
        $this->fakeAccountApi();
        $role = TeamRole::create(['tenant_id' => 10, 'name' => 'Financeiro leitura', 'permissions' => ['financeiro.view' => true]]);
        $team = User::factory()->create(['tenant_id' => 10, 'role' => User::ROLE_TEAM, 'team_role_id' => $role->id]);

        $this->actingAs($team)->get('/financeiro')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Financeiro/Pagarme')->where('can_manage', false)->where('account.can_act', false));
    }

    public function test_tenant_without_pagarme_account_keeps_the_wallet_page(): void
    {
        $other = User::factory()->create(['tenant_id' => 20, 'role' => User::ROLE_INFOPRODUTOR]);

        $this->actingAs($other)->get('/financeiro')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Financeiro/Index'));
    }

    public function test_balance_api_failure_still_renders_with_warning(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Authorization has been denied for this request.'], 401)]);
        $this->payable(10);

        $this->actingAs($this->producerA)->get('/financeiro')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Financeiro/Pagarme')
                ->where('account.balance', null)
                ->where('account.error', fn ($msg) => str_contains((string) $msg, 'permissão'))
            );
    }
}
