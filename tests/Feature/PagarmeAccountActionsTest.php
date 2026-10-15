<?php

namespace Tests\Feature;

use App\Models\PagarmeAccount;
use App\Models\PagarmeAnticipation;
use App\Models\PagarmeTransfer;
use App\Models\TeamRole;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ações de dinheiro na conta Pagar.me do próprio produtor (/financeiro): dono ou equipe com financeiro.manage,
 * atrás de flag, com senha, valor validado no servidor contra o saldo/limite lido na hora, idempotência e destino
 * sempre a conta bancária cadastrada na Pagar.me. A conta vem do tenant do usuário, nunca da URL.
 */
class PagarmeAccountActionsTest extends TestCase
{
    private PagarmeAccount $account;

    private User $producer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['getfy.pagarme_finance_write' => true]);
        $this->producer = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 10, 'password' => 'p-654321']);
        $this->account = PagarmeAccount::create([
            'label' => 'Conta principal', 'recipient_id' => 're_test_main', 'finance_secret_key' => 'sk_test_finance_secret',
        ]);
        $this->account->attachTenants([10, 11]);
    }

    private function url(string $suffix = ''): string
    {
        return '/financeiro/pagarme'.$suffix;
    }

    private function fakeApi(int $availableCents = 50000, array $limits = ['maximum' => ['amount' => 30000], 'minimum' => ['amount' => 1000]]): void
    {
        Http::fake([
            'api.pagar.me/core/v5/recipients/re_test_main/balance' => Http::response(['available_amount' => $availableCents, 'waiting_funds_amount' => 0]),
            'api.pagar.me/core/v5/transfers' => Http::response(['id' => 777, 'amount' => 10000, 'status' => 'pending_transfer', 'type' => 'ted', 'fee' => 300]),
            'api.pagar.me/core/v5/recipients/re_test_main/bulk_anticipations/limits*' => Http::response($limits),
            'api.pagar.me/core/v5/recipients/re_test_main/bulk_anticipations/simulate' => Http::response(['amount' => 20000, 'fee' => 100, 'anticipation_fee' => 400, 'fraud_coverage_fee' => 0]),
            'api.pagar.me/core/v5/recipients/re_test_main/bulk_anticipations/ba_test_1/cancel' => Http::response(['id' => 'ba_test_1', 'status' => 'canceled']),
            'api.pagar.me/core/v5/recipients/re_test_main/bulk_anticipations' => Http::response(['id' => 'ba_test_1', 'status' => 'pending', 'amount' => 20000, 'fee' => 100, 'anticipation_fee' => 400, 'timeframe' => 'start', 'payment_date' => '2026-10-20T00:00:00Z']),
            'api.pagar.me/core/v5/recipients/re_test_main/transfer-settings' => Http::response(['transfer_enabled' => true]),
            'api.pagar.me/core/v5/recipients/re_test_main' => Http::response(['id' => 're_test_main']),
        ]);
    }

    private function transferPosts(): array
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/transfers'))->values()->all();
    }

    private function anticipationPosts(): array
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/bulk_anticipations'))->values()->all();
    }

    private function transfer(array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->producer)->postJson($this->url('/transfers'), array_merge([
            'amount_cents' => 10000, 'idempotency_key' => 'k-0001-abcdef', 'current_password' => 'p-654321',
        ], $overrides));
    }

    private function nextBusinessDay(): string
    {
        $d = Carbon::now()->addDay();
        while ($d->isWeekend()) {
            $d->addDay();
        }

        return $d->toDateString();
    }

    public function test_view_only_team_other_tenants_and_students_cannot_move_money(): void
    {
        $this->fakeApi();
        $viewer = TeamRole::create(['tenant_id' => 10, 'name' => 'Fin leitura', 'permissions' => ['financeiro.view' => true]]);
        $team = User::factory()->create(['role' => User::ROLE_TEAM, 'tenant_id' => 10, 'team_role_id' => $viewer->id, 'password' => 'p-123456']);
        $otherProducer = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 12, 'password' => 'p-123456']);
        $student = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 10, 'password' => 'p-123456']);

        $this->transfer(['current_password' => 'p-123456'], $team)->assertForbidden();
        $this->actingAs($team)->postJson($this->url('/sync'))->assertForbidden();
        $this->transfer(['current_password' => 'p-123456'], $otherProducer)->assertNotFound();
        $this->actingAs($otherProducer)->postJson($this->url('/sync'))->assertNotFound();
        $this->assertContains($this->transfer(['current_password' => 'p-123456'], $student)->status(), [302, 403]);

        $this->assertSame([], $this->transferPosts());
    }

    public function test_team_member_with_manage_permission_can_transfer(): void
    {
        $this->fakeApi();
        $manager = TeamRole::create(['tenant_id' => 10, 'name' => 'Fin', 'permissions' => ['financeiro.view' => true, 'financeiro.manage' => true]]);
        $team = User::factory()->create(['role' => User::ROLE_TEAM, 'tenant_id' => 10, 'team_role_id' => $manager->id, 'password' => 'p-123456']);

        $this->transfer(['current_password' => 'p-123456'], $team)->assertOk();

        $this->assertCount(1, $this->transferPosts());
        $this->assertSame($team->id, (int) PagarmeTransfer::sole()->requested_by_user_id);
    }

    public function test_producer_sharing_the_account_acts_on_the_same_account(): void
    {
        $this->fakeApi();
        $sibling = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 11, 'password' => 'p-123456']);

        $this->transfer(['current_password' => 'p-123456'], $sibling)->assertOk();

        $this->assertSame($this->account->id, (int) PagarmeTransfer::sole()->pagarme_account_id);
    }

    public function test_write_actions_return_404_when_flag_is_off(): void
    {
        config(['getfy.pagarme_finance_write' => false]);
        $this->fakeApi();

        $this->transfer()->assertNotFound();
        $this->assertSame([], $this->transferPosts());
    }

    public function test_wrong_password_is_rejected_without_calling_the_api(): void
    {
        $this->fakeApi();

        $this->transfer(['current_password' => 'wrong'])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertSame([], $this->transferPosts());
    }

    public function test_amount_above_fresh_balance_or_invalid_is_rejected(): void
    {
        $this->fakeApi(availableCents: 5000);

        $this->transfer(['amount_cents' => 10000])->assertStatus(422)->assertJsonValidationErrors('amount_cents');
        $this->transfer(['amount_cents' => -100, 'idempotency_key' => 'k-0002-abcdef'])->assertStatus(422);
        $this->transfer(['amount_cents' => '10.5', 'idempotency_key' => 'k-0003-abcdef'])->assertStatus(422);

        $this->assertSame([], $this->transferPosts());
    }

    public function test_transfer_goes_to_registered_account_and_replay_does_not_duplicate(): void
    {
        $this->fakeApi();

        $this->transfer(['bank_account' => ['bank' => '001', 'account_number' => '999']])->assertOk()->assertJsonPath('replayed', false);
        $this->transfer(['bank_account' => ['bank' => '001', 'account_number' => '999']])->assertOk()->assertJsonPath('replayed', true);

        $posts = $this->transferPosts();
        $this->assertCount(1, $posts);
        [$request] = $posts[0];
        $this->assertSame(['amount' => 10000, 'recipient_id' => 're_test_main'], array_intersect_key($request->data(), ['amount' => 1, 'recipient_id' => 1]));
        $this->assertArrayNotHasKey('bank_account', $request->data());
        $this->assertTrue($request->hasHeader('Idempotency-Key'));

        $row = PagarmeTransfer::sole();
        $this->assertSame('777', $row->transfer_id);
        $this->assertSame($this->producer->id, (int) $row->requested_by_user_id);
        $this->assertSame('pending_transfer', $row->status);
    }

    public function test_anticipation_respects_server_side_limits_and_business_days(): void
    {
        $this->fakeApi();
        $date = $this->nextBusinessDay();
        $base = ['timeframe' => 'start', 'payment_date' => $date, 'idempotency_key' => 'a-0001-abcdef', 'current_password' => 'p-654321'];

        $this->actingAs($this->producer)->postJson($this->url('/anticipations'), $base + ['requested_amount_cents' => 30001])
            ->assertStatus(422)->assertJsonValidationErrors('requested_amount_cents');
        $this->actingAs($this->producer)->postJson($this->url('/anticipations'), $base + ['requested_amount_cents' => 500])
            ->assertStatus(422)->assertJsonValidationErrors('requested_amount_cents');
        $saturday = Carbon::now()->next(Carbon::SATURDAY)->toDateString();
        $this->actingAs($this->producer)->postJson($this->url('/anticipations'), array_merge($base, ['payment_date' => $saturday, 'requested_amount_cents' => 20000]))
            ->assertStatus(422)->assertJsonValidationErrors('payment_date');
        $this->assertSame([], $this->anticipationPosts());

        $this->actingAs($this->producer)->postJson($this->url('/anticipations'), $base + ['requested_amount_cents' => 20000])->assertOk();
        $this->assertCount(1, $this->anticipationPosts());
        $this->assertSame('ba_test_1', PagarmeAnticipation::sole()->anticipation_id);
    }

    public function test_simulation_returns_cost_without_creating_anything(): void
    {
        $this->fakeApi();

        $this->actingAs($this->producer)->postJson($this->url('/anticipations/simulate'), [
            'timeframe' => 'start', 'payment_date' => $this->nextBusinessDay(), 'requested_amount_cents' => 20000,
        ])->assertOk()->assertJson(['amount_cents' => 20000, 'total_fee_cents' => 500]);

        $this->assertSame(0, PagarmeAnticipation::count());
    }

    public function test_cancel_only_pending_anticipation_of_the_same_account(): void
    {
        $this->fakeApi();
        $pending = PagarmeAnticipation::create(['pagarme_account_id' => $this->account->id, 'anticipation_id' => 'ba_test_1', 'requested_amount_cents' => 100, 'status' => 'pending']);
        $approved = PagarmeAnticipation::create(['pagarme_account_id' => $this->account->id, 'anticipation_id' => 'ba_test_2', 'requested_amount_cents' => 100, 'status' => 'approved']);
        $other = PagarmeAccount::create(['label' => 'Outra', 'recipient_id' => 're_test_other', 'finance_secret_key' => 'sk_x']);
        $foreign = PagarmeAnticipation::create(['pagarme_account_id' => $other->id, 'anticipation_id' => 'ba_test_3', 'requested_amount_cents' => 100, 'status' => 'pending']);
        $pw = ['current_password' => 'p-654321'];

        $this->actingAs($this->producer)->postJson($this->url('/anticipations/'.$foreign->id.'/cancel'), $pw)->assertNotFound();
        $this->actingAs($this->producer)->postJson($this->url('/anticipations/'.$approved->id.'/cancel'), $pw)->assertStatus(422);
        $this->actingAs($this->producer)->postJson($this->url('/anticipations/'.$pending->id.'/cancel'), $pw)->assertOk();

        $this->assertSame('canceled', $pending->fresh()->status);
    }

    public function test_transfer_settings_are_validated(): void
    {
        $this->fakeApi();
        $pw = ['current_password' => 'p-654321'];

        $this->actingAs($this->producer)->patchJson($this->url('/transfer-settings'), $pw + ['transfer_enabled' => true, 'transfer_interval' => 'weekly', 'transfer_day' => 7])
            ->assertStatus(422);
        $this->actingAs($this->producer)->patchJson($this->url('/transfer-settings'), $pw + ['transfer_enabled' => true, 'transfer_interval' => 'yearly', 'transfer_day' => 1])
            ->assertStatus(422);
        $this->actingAs($this->producer)->patchJson($this->url('/transfer-settings'), $pw + ['transfer_enabled' => true, 'transfer_interval' => 'monthly', 'transfer_day' => 10])
            ->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['transfer_interval'] === 'monthly' && $r['transfer_day'] === 10);
    }
}
