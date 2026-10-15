<?php

namespace App\Services;

use App\Gateways\Pagarme\PagarmeFinanceClient;
use App\Gateways\Pagarme\PagarmeFinanceException;
use App\Gateways\Pagarme\PagarmeFinanceUnauthorized;
use App\Models\PagarmeAccount;
use Illuminate\Support\Facades\Log;

/**
 * Liga o tenant à conta Pagar.me da chave financeira cadastrada em Integrações → Gateways → Pagar.me.
 * Produtores no mesmo recebedor compartilham a conta (ter a chave prova o acesso). Nunca loga a chave.
 */
class PagarmeAccountLinker
{
    /**
     * @return array{connected: bool, warning: string|null}
     */
    public function link(int $tenantId, string $financeKey, ?string $recipientId = null): array
    {
        $financeKey = trim($financeKey);
        $recipientId = $recipientId !== null && trim($recipientId) !== '' ? trim($recipientId) : null;

        try {
            $recipientId ??= $this->detectMainRecipient($financeKey);
            if ($recipientId === null) {
                return ['connected' => false, 'warning' => 'A conta Pagar.me tem mais de um recebedor. Informe o ID do recebedor principal (Configurações → Recebedores na Pagar.me).'];
            }
            (new PagarmeFinanceClient($financeKey, $recipientId))->balance();
        } catch (PagarmeFinanceUnauthorized) {
            return ['connected' => false, 'warning' => 'A chave financeira não tem permissão. Crie a chave com os escopos Financeiro e Recebedores (leitura e escrita).'];
        } catch (PagarmeFinanceException $e) {
            Log::warning('PagarmeAccountLinker: finance key check failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);

            return ['connected' => false, 'warning' => 'Não foi possível validar a chave financeira: '.$e->getMessage()];
        }

        $account = PagarmeAccount::query()->where('recipient_id', $recipientId)->first();
        if ($account) {
            $account->update(['finance_secret_key' => $financeKey]);
        } else {
            $account = PagarmeAccount::create([
                'label' => 'Conta Pagar.me',
                'recipient_id' => $recipientId,
                'finance_secret_key' => $financeKey,
            ]);
        }

        $previous = PagarmeAccount::forTenant($tenantId);
        if ($previous && $previous->id !== $account->id) {
            $previous->detachTenant($tenantId);
        }
        $account->addTenant($tenantId);
        Log::info('PagarmeAccountLinker: tenant linked', ['tenant_id' => $tenantId, 'account_id' => $account->id]);

        return ['connected' => true, 'warning' => null];
    }

    /**
     * Recebedor principal da conta: só é detectado quando a conta tem um único recebedor; com vários,
     * o produtor informa o ID.
     */
    private function detectMainRecipient(string $financeKey): ?string
    {
        $candidates = array_values(array_filter(
            (new PagarmeFinanceClient($financeKey))->recipients(),
            fn (array $r) => ! empty($r['id']),
        ));

        return count($candidates) === 1 ? (string) $candidates[0]['id'] : null;
    }
}
