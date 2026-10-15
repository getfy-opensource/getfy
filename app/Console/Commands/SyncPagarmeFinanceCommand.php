<?php

namespace App\Console\Commands;

use App\Models\PagarmeAccount;
use App\Services\PagarmeFinanceSync;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncPagarmeFinanceCommand extends Command
{
    protected $signature = 'pagarme:sync-finance
        {--account= : ID da conta Pagar.me (padrão: todas)}
        {--since= : Recebíveis alterados desde (YYYY-MM-DD); padrão: última sincronização}';

    protected $description = 'Espelha recebíveis, transferências, antecipações e liquidações da Pagar.me e grava a taxa real das vendas';

    public function handle(PagarmeFinanceSync $sync): int
    {
        $since = $this->option('since') ? Carbon::parse((string) $this->option('since'))->startOfDay() : null;
        $accounts = PagarmeAccount::query()
            ->when($this->option('account'), fn ($q, $id) => $q->whereKey((int) $id))
            ->get();

        foreach ($accounts as $account) {
            $stats = $sync->syncAccount($account, $since);
            $this->line(sprintf('Conta #%d (%s): %s', $account->id, $account->label, json_encode($stats)));
            if ($account->fresh()->last_sync_error) {
                $this->warn('  '.$account->fresh()->last_sync_error);
            }
        }

        return self::SUCCESS;
    }
}
