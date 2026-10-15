<?php

namespace App\Services;

use App\Gateways\Pagarme\PagarmeFinanceException;
use App\Gateways\Pagarme\PagarmeFinanceUnauthorized;
use App\Models\PagarmeAccount;
use App\Models\PagarmeAnticipation;
use App\Models\PagarmePayable;
use App\Models\PagarmeSettlement;
use App\Models\PagarmeTransfer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Números do /financeiro em modo Pagar.me. Visão do produtor usa só recebíveis do tenant (espelho local);
 * a visão da conta (saldo, transferências, antecipações) é da conta inteira, compartilhada entre os tenants.
 * Valores em centavos.
 */
class PagarmeFinanceSummaryService
{
    public const METHODS = ['credit_card', 'pix', 'boleto', 'debit_card'];

    public const STATUSES = ['waiting_funds', 'paid'];

    private function tenantCredits(int $tenantId): Builder
    {
        return PagarmePayable::query()->where('tenant_id', $tenantId)->where('type', PagarmePayable::TYPE_CREDIT);
    }

    /**
     * @return array<string, int>
     */
    public function overview(int $tenantId, Carbon $from, Carbon $to): array
    {
        $sold = $this->tenantCredits($tenantId)->whereBetween('accrual_at', [$from, $to])->get();
        $waiting = $this->tenantCredits($tenantId)->where('status', PagarmePayable::STATUS_WAITING_FUNDS)->get();
        $received = $this->tenantCredits($tenantId)
            ->where('status', PagarmePayable::STATUS_PAID)
            ->whereBetween('payment_date', [$from->toDateString(), $to->toDateString()])
            ->get();
        $reversals = PagarmePayable::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('type', ['refund', 'chargeback'])
            ->whereBetween('accrual_at', [$from, $to])
            ->sum('amount_cents');

        $fee = (int) $sold->sum(fn (PagarmePayable $p) => $p->fee_cents + $p->fraud_coverage_fee_cents);

        return [
            'gross_cents' => (int) $sold->sum('amount_cents'),
            'fee_cents' => $fee,
            'anticipation_fee_cents' => (int) $sold->sum('anticipation_fee_cents'),
            'net_cents' => (int) $sold->sum(fn (PagarmePayable $p) => $p->netCents()),
            'sales_count' => $sold->pluck('charge_id')->filter()->unique()->count(),
            'waiting_cents' => (int) $waiting->sum(fn (PagarmePayable $p) => $p->netCents()),
            'received_cents' => (int) $received->sum(fn (PagarmePayable $p) => $p->netCents()),
            'reversals_cents' => abs((int) $reversals),
        ];
    }

    /**
     * @return list<array{method: string, gross_cents: int, fee_cents: int, net_cents: int, sales_count: int}>
     */
    public function byMethod(int $tenantId, Carbon $from, Carbon $to): array
    {
        return $this->tenantCredits($tenantId)
            ->whereBetween('accrual_at', [$from, $to])
            ->get()
            ->groupBy(fn (PagarmePayable $p) => $p->payment_method ?: 'outro')
            ->map(fn ($rows, $method) => [
                'method' => (string) $method,
                'gross_cents' => (int) $rows->sum('amount_cents'),
                'fee_cents' => (int) $rows->sum(fn (PagarmePayable $p) => $p->fee_cents + $p->fraud_coverage_fee_cents),
                'net_cents' => (int) $rows->sum(fn (PagarmePayable $p) => $p->netCents()),
                'sales_count' => $rows->pluck('charge_id')->filter()->unique()->count(),
            ])
            ->sortByDesc('gross_cents')
            ->values()
            ->all();
    }

    /**
     * Agenda de recebíveis a receber (líquido por data de pagamento), por semana e por mês.
     *
     * @return array{weeks: list<array{start: string, net_cents: int}>, months: list<array{month: string, net_cents: int}>}
     */
    public function schedule(int $tenantId): array
    {
        $today = now()->startOfDay();
        $rows = $this->tenantCredits($tenantId)
            ->where('status', PagarmePayable::STATUS_WAITING_FUNDS)
            ->where('payment_date', '<=', $today->copy()->addMonths(12)->toDateString())
            ->get(['payment_date', 'amount_cents', 'fee_cents', 'anticipation_fee_cents', 'fraud_coverage_fee_cents']);

        $weeks = [];
        $weekStart = $today->copy()->startOfWeek();
        for ($i = 0; $i < 12; $i++) {
            $weeks[$weekStart->copy()->addWeeks($i)->toDateString()] = 0;
        }
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $months[$today->copy()->startOfMonth()->addMonths($i)->format('Y-m')] = 0;
        }

        foreach ($rows as $p) {
            /** @var PagarmePayable $p */
            $date = $p->payment_date?->copy() ?? $today->copy();
            if ($date->lt($today)) {
                $date = $today->copy();
            }
            $week = $date->copy()->startOfWeek()->toDateString();
            if (array_key_exists($week, $weeks)) {
                $weeks[$week] += $p->netCents();
            }
            $month = $date->format('Y-m');
            if (array_key_exists($month, $months)) {
                $months[$month] += $p->netCents();
            }
        }

        return [
            'weeks' => collect($weeks)->map(fn ($v, $k) => ['start' => $k, 'net_cents' => $v])->values()->all(),
            'months' => collect($months)->map(fn ($v, $k) => ['month' => $k, 'net_cents' => $v])->values()->all(),
        ];
    }

    /**
     * @param  array{status?: string|null, method?: string|null, from?: string|null, to?: string|null}  $filters
     */
    public function payables(int $tenantId, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = PagarmePayable::query()
            ->where('tenant_id', $tenantId)
            ->with(['order:id,user_id,email,product_id', 'order.user:id,name', 'order.product:id,name'])
            ->orderByDesc('payment_date')
            ->orderByDesc('id');

        if (in_array($filters['status'] ?? null, self::STATUSES, true)) {
            $query->where('status', $filters['status']);
        }
        if (in_array($filters['method'] ?? null, self::METHODS, true)) {
            $query->where('payment_method', $filters['method']);
        }
        if (! empty($filters['from'])) {
            $query->where('payment_date', '>=', Carbon::parse($filters['from'])->toDateString());
        }
        if (! empty($filters['to'])) {
            $query->where('payment_date', '<=', Carbon::parse($filters['to'])->toDateString());
        }

        return $query->paginate($perPage)->withQueryString()->through(fn (PagarmePayable $p) => [
            'id' => $p->id,
            'charge_id' => $p->charge_id,
            'order_id' => $p->order_id,
            'customer' => $p->order?->user?->getAttribute('name') ?: $p->order?->email,
            'product' => $p->order?->product?->getAttribute('name'),
            'type' => $p->type,
            'status' => $p->status,
            'payment_method' => $p->payment_method,
            'installment' => $p->installment,
            'payment_date' => $p->payment_date?->toDateString(),
            'amount_cents' => $p->amount_cents,
            'fee_cents' => $p->fee_cents + $p->fraud_coverage_fee_cents,
            'anticipation_fee_cents' => $p->anticipation_fee_cents,
            'net_cents' => $p->netCents(),
        ]);
    }

    /**
     * Visão da conta Pagar.me (compartilhada). Saldo e dados do recebedor vêm da API com cache curto;
     * listas vêm do espelho local. Nunca devolve a chave nem a conta bancária completa.
     *
     * @return array<string, mixed>
     */
    public function account(PagarmeAccount $account, bool $canAct, bool $fresh = false): array
    {
        $error = null;
        $balance = null;
        $recipient = null;
        try {
            $cacheKey = 'pagarme_finance.balance.'.$account->id;
            if ($fresh) {
                Cache::forget($cacheKey);
            }
            $balance = Cache::remember($cacheKey, 60, fn () => $account->client()->balance());
            $recipient = Cache::remember('pagarme_finance.recipient.'.$account->id, 600, function () use ($account) {
                $r = $account->client()->recipient();

                return [
                    'bank_account' => app(PagarmeFinanceSync::class)->maskBankAccount($r['default_bank_account'] ?? null),
                    'transfer_settings' => is_array($r['transfer_settings'] ?? null) ? [
                        'transfer_enabled' => (bool) ($r['transfer_settings']['transfer_enabled'] ?? false),
                        'transfer_interval' => (string) ($r['transfer_settings']['transfer_interval'] ?? ''),
                        'transfer_day' => (int) ($r['transfer_settings']['transfer_day'] ?? 0),
                    ] : null,
                    'automatic_anticipation_enabled' => (bool) ($r['automatic_anticipation_settings']['enabled'] ?? false),
                ];
            });
        } catch (PagarmeFinanceUnauthorized $e) {
            $error = $e->getMessage().' Atualize a chave financeira em Integrações → Gateways → Pagar.me (escopos Financeiro e Recebedores).';
        } catch (PagarmeFinanceException $e) {
            $error = $e->getMessage();
        }

        $outside = PagarmePayable::query()
            ->where('pagarme_account_id', $account->id)
            ->whereNull('tenant_id')
            ->where('type', PagarmePayable::TYPE_CREDIT)
            ->where('status', PagarmePayable::STATUS_WAITING_FUNDS)
            ->get();

        return [
            'id' => $account->id,
            'label' => $account->label,
            'can_act' => $canAct,
            'error' => $error,
            'balance' => $balance,
            'bank_account' => $recipient['bank_account'] ?? null,
            'transfer_settings' => $recipient['transfer_settings'] ?? null,
            'automatic_anticipation_enabled' => $recipient['automatic_anticipation_enabled'] ?? null,
            'outside_getfy' => ['waiting_cents' => (int) $outside->sum(fn (PagarmePayable $p) => $p->netCents())],
            'last_synced_at' => $account->last_synced_at?->toIso8601String(),
            'last_sync_error' => $account->last_sync_error,
            'transfers' => PagarmeTransfer::where('pagarme_account_id', $account->id)
                ->orderByDesc('created_at')->limit(20)->get()
                ->map(fn (PagarmeTransfer $t) => [
                    'id' => $t->id,
                    'amount_cents' => $t->amount_cents,
                    'fee_cents' => $t->fee_cents,
                    'status' => $t->status,
                    'type' => $t->type,
                    'funding_estimated_at' => $t->funding_estimated_at?->toIso8601String(),
                    'created_at' => ($t->gateway_created_at ?? $t->created_at)?->toIso8601String(),
                    'error_message' => $t->error_message,
                ])->all(),
            'anticipations' => PagarmeAnticipation::where('pagarme_account_id', $account->id)
                ->orderByDesc('created_at')->limit(20)->get()
                ->map(fn (PagarmeAnticipation $a) => [
                    'id' => $a->id,
                    'requested_amount_cents' => $a->requested_amount_cents,
                    'amount_cents' => $a->amount_cents,
                    'fee_cents' => (int) $a->fee_cents + (int) $a->anticipation_fee_cents,
                    'status' => $a->status,
                    'timeframe' => $a->timeframe,
                    'payment_date' => $a->payment_date?->toDateString(),
                    'created_at' => ($a->gateway_created_at ?? $a->created_at)?->toIso8601String(),
                    'error_message' => $a->error_message,
                ])->all(),
            'settlements' => PagarmeSettlement::where('pagarme_account_id', $account->id)
                ->orderByDesc('payment_date')->limit(20)->get()
                ->map(fn (PagarmeSettlement $s) => [
                    'id' => $s->id,
                    'amount_cents' => $s->amount_cents,
                    'product' => $s->product,
                    'card_brand' => $s->card_brand,
                    'status' => $s->status,
                    'payment_date' => $s->payment_date?->toDateString(),
                ])->all(),
        ];
    }
}
