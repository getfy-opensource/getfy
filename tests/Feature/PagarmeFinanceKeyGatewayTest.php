<?php

namespace Tests\Feature;

use App\Models\GatewayCredential;
use App\Models\PagarmeAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cada produtor conecta a própria conta Pagar.me em Integrações → Gateways → Pagar.me com a chave financeira.
 * Produtores na mesma conta (mesmo recebedor) se juntam numa conta só; ter a chave prova o acesso.
 */
class PagarmeFinanceKeyGatewayTest extends TestCase
{
    private User $producerA;

    private User $producerB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->producerA = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 10]);
        $this->producerB = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 11]);
    }

    private array $recipients = [];

    private int $financeStatus = 200;

    private bool $faked = false;

    /**
     * Um único fake lendo o estado atual (stubs do Http::fake se acumulam; o primeiro que casa vence).
     */
    private function fakePagarme(array $recipients = [['id' => 're_test_main', 'name' => 'Empresa Teste']], int $financeStatus = 200): void
    {
        $this->recipients = $recipients;
        $this->financeStatus = $financeStatus;
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake(function (Request $r) {
            $url = $r->url();
            if (str_contains($url, '/charges')) {
                return Http::response(['data' => []]);
            }
            if ($this->financeStatus !== 200) {
                return Http::response(['message' => 'Authorization has been denied for this request.'], $this->financeStatus);
            }
            if (preg_match('#/recipients/([^/?]+)/balance#', $url)) {
                return Http::response(['available_amount' => 0, 'waiting_funds_amount' => 0]);
            }
            if (str_contains($url, '/recipients')) {
                return Http::response(['data' => $this->recipients]);
            }

            return Http::response([], 404);
        });
    }

    private function save(User $user, array $fields)
    {
        return $this->actingAs($user)->putJson('/configuracoes/gateways/pagarme', array_merge([
            'secret_key' => 'sk_test_checkout', 'public_key' => 'pk_test_checkout',
        ], $fields));
    }

    public function test_producer_connects_finance_key_and_it_is_stored_encrypted_and_masked(): void
    {
        $this->fakePagarme();

        $this->save($this->producerA, ['finance_secret_key' => 'sk_test_finance_first'])
            ->assertOk()
            ->assertJsonPath('finance_connected', true);

        $account = PagarmeAccount::forTenant(10);
        $this->assertNotNull($account);
        $this->assertSame('re_test_main', $account->recipient_id);
        $this->assertSame('sk_test_finance_first', $account->finance_secret_key);
        $this->assertStringNotContainsString('sk_test_finance_first', (string) DB::table('pagarme_accounts')->value('finance_secret_key'));

        $shown = $this->actingAs($this->producerA)->getJson('/configuracoes/gateways/pagarme')->assertOk()->getContent();
        $this->assertStringNotContainsString('sk_test_finance_first', $shown);
    }

    public function test_second_producer_on_the_same_recipient_joins_the_same_account(): void
    {
        $this->fakePagarme();
        $this->save($this->producerA, ['finance_secret_key' => 'sk_test_finance_a'])->assertOk();

        $this->save($this->producerB, ['finance_secret_key' => 'sk_test_finance_b'])->assertOk();

        $this->assertSame(1, PagarmeAccount::count());
        $this->assertSame([10, 11], PagarmeAccount::sole()->tenantIds());
    }

    public function test_explicit_recipient_id_is_used_when_there_are_several(): void
    {
        $this->fakePagarme([['id' => 're_test_one', 'name' => 'A'], ['id' => 're_test_two', 'name' => 'B']]);

        $this->save($this->producerA, ['finance_secret_key' => 'sk_test_finance'])
            ->assertOk()
            ->assertJsonPath('finance_connected', false)
            ->assertJsonPath('finance_warning', fn ($w) => str_contains((string) $w, 'ID do recebedor'));
        $this->assertNull(PagarmeAccount::forTenant(10));

        $this->save($this->producerA, ['finance_secret_key' => 'sk_test_finance', 'finance_recipient_id' => 're_test_two'])
            ->assertOk()->assertJsonPath('finance_connected', true);
        $this->assertSame('re_test_two', PagarmeAccount::forTenant(10)->recipient_id);
    }

    public function test_key_without_permission_saves_checkout_credentials_but_does_not_connect_finance(): void
    {
        $this->fakePagarme(financeStatus: 401);

        $this->save($this->producerA, ['finance_secret_key' => 'sk_test_bad'])
            ->assertOk()
            ->assertJsonPath('finance_connected', false)
            ->assertJsonPath('finance_warning', fn ($w) => str_contains((string) $w, 'permissão'));

        $this->assertNull(PagarmeAccount::forTenant(10));
        $this->assertTrue((bool) GatewayCredential::forTenant(10)->where('gateway_slug', 'pagarme')->value('is_connected'));
    }

    public function test_resaving_with_blank_finance_key_keeps_the_connection_without_calling_the_finance_api(): void
    {
        $this->fakePagarme();
        $this->save($this->producerA, ['finance_secret_key' => 'sk_test_finance'])->assertOk();

        $this->save($this->producerA, ['finance_secret_key' => ''])->assertOk()->assertJsonPath('finance_connected', true);

        Http::assertSentCount(4); // 2x /charges (teste do checkout) + /recipients + /balance só no primeiro
        $this->assertSame('sk_test_finance', PagarmeAccount::forTenant(10)->finance_secret_key);
    }

    public function test_switching_to_another_account_detaches_the_tenant_from_the_old_one(): void
    {
        $this->fakePagarme();
        $this->save($this->producerA, ['finance_secret_key' => 'sk_test_finance'])->assertOk();
        $this->save($this->producerB, ['finance_secret_key' => 'sk_test_finance'])->assertOk();

        $this->fakePagarme([['id' => 're_test_other', 'name' => 'Outra']]);
        $this->save($this->producerB, ['finance_secret_key' => 'sk_test_finance_other'])->assertOk();

        $this->assertSame('re_test_main', PagarmeAccount::forTenant(10)->recipient_id);
        $this->assertSame('re_test_other', PagarmeAccount::forTenant(11)->recipient_id);
        $this->assertSame([10], PagarmeAccount::where('recipient_id', 're_test_main')->sole()->tenantIds());
    }

    public function test_last_tenant_leaving_an_account_removes_it(): void
    {
        $this->fakePagarme();
        $this->save($this->producerA, ['finance_secret_key' => 'sk_test_finance'])->assertOk();

        $this->fakePagarme([['id' => 're_test_other', 'name' => 'Outra']]);
        $this->save($this->producerA, ['finance_secret_key' => 'sk_test_finance_other'])->assertOk();

        $this->assertSame(['re_test_other'], PagarmeAccount::pluck('recipient_id')->all());
    }

    public function test_connection_test_does_not_require_the_optional_finance_fields(): void
    {
        $this->fakePagarme();

        $this->actingAs($this->producerA)->postJson('/configuracoes/gateways/pagarme/test', [
            'secret_key' => 'sk_test_checkout', 'public_key' => 'pk_test_checkout',
        ])->assertOk()->assertJsonPath('success', true);
    }

    public function test_admin_screens_are_gone(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'tenant_id' => 1]);

        $this->actingAs($admin)->get('/admin/financeiro/pagarme')->assertNotFound();
    }
}
