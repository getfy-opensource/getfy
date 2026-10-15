<?php

namespace App\Models;

use App\Gateways\Pagarme\PagarmeFinanceClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $label
 * @property string $recipient_id
 * @property string $finance_secret_key
 * @property \Illuminate\Support\Carbon|null $payables_synced_until
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 * @property string|null $last_sync_error
 *
 * Conta Pagar.me (recebedor) com a chave financeira, que é separada da chave transacional do checkout.
 * Uma conta pode atender vários tenants (vários produtores na mesma conta): o saldo é da conta, os recebíveis são do pedido.
 */
class PagarmeAccount extends Model
{
    protected $fillable = [
        'label',
        'recipient_id',
        'finance_secret_key',
        'payables_synced_until',
        'last_synced_at',
        'last_sync_error',
    ];

    protected $hidden = ['finance_secret_key'];

    protected function casts(): array
    {
        return [
            'finance_secret_key' => 'encrypted',
            'payables_synced_until' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public static function forTenant(int $tenantId): ?self
    {
        $accountId = DB::table('pagarme_account_tenant')->where('tenant_id', $tenantId)->value('pagarme_account_id');

        return $accountId ? self::find($accountId) : null;
    }

    /**
     * @return list<int>
     */
    public function tenantIds(): array
    {
        return DB::table('pagarme_account_tenant')
            ->where('pagarme_account_id', $this->id)
            ->orderBy('tenant_id')
            ->pluck('tenant_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Substitui os tenants ligados à conta. Um tenant só pode estar em uma conta.
     *
     * @param  list<int>  $tenantIds
     */
    public function attachTenants(array $tenantIds): void
    {
        DB::transaction(function () use ($tenantIds) {
            DB::table('pagarme_account_tenant')->where('pagarme_account_id', $this->id)->delete();
            foreach (array_unique(array_map('intval', $tenantIds)) as $tenantId) {
                DB::table('pagarme_account_tenant')->updateOrInsert(
                    ['tenant_id' => $tenantId],
                    ['pagarme_account_id' => $this->id, 'created_at' => now(), 'updated_at' => now()],
                );
            }
        });
    }

    /**
     * Liga um tenant à conta (um tenant fica em uma conta só).
     */
    public function addTenant(int $tenantId): void
    {
        DB::table('pagarme_account_tenant')->updateOrInsert(
            ['tenant_id' => $tenantId],
            ['pagarme_account_id' => $this->id, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    /**
     * Desliga o tenant; a conta sem nenhum tenant é removida (com o espelho local).
     */
    public function detachTenant(int $tenantId): void
    {
        DB::table('pagarme_account_tenant')->where('pagarme_account_id', $this->id)->where('tenant_id', $tenantId)->delete();
        if ($this->tenantIds() === []) {
            $this->delete();
        }
    }

    public function client(): PagarmeFinanceClient
    {
        return new PagarmeFinanceClient((string) $this->finance_secret_key, (string) $this->recipient_id);
    }

    public function payables(): HasMany
    {
        return $this->hasMany(PagarmePayable::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(PagarmeTransfer::class);
    }

    public function anticipations(): HasMany
    {
        return $this->hasMany(PagarmeAnticipation::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(PagarmeSettlement::class);
    }
}
