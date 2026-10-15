<?php

namespace App\Services;

use App\Gateways\Pagarme\PagarmeFinanceException;
use App\Models\Order;
use App\Models\PagarmeAccount;
use App\Models\PagarmeAnticipation;
use App\Models\PagarmePayable;
use App\Models\PagarmeSettlement;
use App\Models\PagarmeTransfer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Espelha a Pagar.me (recebíveis, transferências, antecipações, liquidações) e grava a taxa real por venda.
 * Recebível é atribuído ao tenant do pedido pelo charge_id; sem pedido no Getfy, fica sem tenant (só na visão da conta).
 */
class PagarmeFinanceSync
{
    public const FEE_SOURCE = 'pagarme_payables';

    public function __construct(private readonly ProducerGatewayFeeSync $producerFeeSync) {}

    /**
     * Sincroniza tudo da conta. Cada parte falha sozinha (ex.: chave sem permissão para liquidações).
     *
     * @return array<string, int|string>
     */
    public function syncAccount(PagarmeAccount $account, ?Carbon $since = null): array
    {
        $stats = [];
        $errors = [];
        foreach ([
            'payables' => fn () => $this->syncPayables($account, $since),
            'transfers' => fn () => $this->syncTransfers($account),
            'anticipations' => fn () => $this->syncAnticipations($account),
            'settlements' => fn () => $this->syncSettlements($account),
        ] as $part => $run) {
            try {
                $stats[$part] = $run();
            } catch (PagarmeFinanceException $e) {
                $errors[] = $e->getMessage();
                Log::warning('PagarmeFinanceSync: '.$part.' failed', ['account_id' => $account->id, 'error' => $e->getMessage()]);
            }
        }

        $account->forceFill([
            'last_synced_at' => now(),
            'last_sync_error' => $errors === [] ? null : mb_substr(implode(' | ', $errors), 0, 255),
        ])->save();

        return $stats;
    }

    /**
     * Recebíveis alterados desde a última sincronização (com 1h de folga), ou desde $since.
     */
    public function syncPayables(PagarmeAccount $account, ?Carbon $since = null): int
    {
        $startedAt = now();
        $since ??= $account->payables_synced_until?->copy()->subHour() ?? now()->subMonths(24);

        $count = 0;
        $touchedOrders = [];
        foreach ($account->client()->payables(['updated_since' => $since->toIso8601String()]) as $row) {
            $payable = $this->upsertPayable($account, $row);
            if (! $payable) {
                continue;
            }
            $count++;
            if ($payable->order_id) {
                $touchedOrders[$payable->order_id] = true;
            }
        }

        foreach (array_keys($touchedOrders) as $orderId) {
            $order = Order::find($orderId);
            if ($order) {
                $this->recordOrderFee($order);
            }
        }

        $account->forceFill(['payables_synced_until' => $startedAt])->save();

        return $count;
    }

    /**
     * Recebíveis de uma cobrança (logo após o pagamento). Sem conta ou sem permissão, mantém a taxa estimada.
     */
    public function syncCharge(Order $order): void
    {
        if ($order->gateway !== 'pagarme' || ! is_string($order->gateway_id) || $order->gateway_id === '') {
            return;
        }
        $account = PagarmeAccount::forTenant((int) $order->tenant_id);
        if (! $account) {
            return;
        }

        try {
            foreach ($account->client()->payables(['charge_id' => $order->gateway_id]) as $row) {
                $this->upsertPayable($account, $row, $order);
            }
        } catch (PagarmeFinanceException $e) {
            Log::warning('PagarmeFinanceSync: charge payables unavailable, keeping estimated fee', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->recordOrderFee($order);
    }

    /**
     * Taxa real = fee + fraud_coverage_fee dos créditos da cobrança. Antecipação é custo financeiro, guardado à parte.
     * O líquido continua bruto − taxa (bruto sem juros de parcelamento), como na estimativa.
     */
    public function recordOrderFee(Order $order): void
    {
        $credits = PagarmePayable::query()
            ->where('order_id', $order->id)
            ->where('type', PagarmePayable::TYPE_CREDIT)
            ->get();
        if ($credits->isEmpty()) {
            return;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        // Líquido da Pagar.me inclui juros do parcelamento; o Getfy usa bruto − taxa (ver docblock).
        $meta = array_diff_key($meta, ['gateway_net_cents' => true]);
        $meta['gateway_fee_cents'] = (int) $credits->sum(fn (PagarmePayable $p) => $p->fee_cents + $p->fraud_coverage_fee_cents);
        $meta['gateway_anticipation_fee_cents'] = (int) $credits->sum('anticipation_fee_cents');
        $meta['gateway_charged_cents'] = (int) $credits->sum('amount_cents');
        $meta['gateway_fee_source'] = self::FEE_SOURCE;

        if ($meta !== $order->metadata) {
            $order->update(['metadata' => $meta]);
        }
        $this->producerFeeSync->apply($order->fresh());
    }

    /**
     * Só recebíveis do recebedor da conta; recebíveis de outros recebedores da mesma cobrança não entram na taxa.
     *
     * @param  array<string, mixed>  $row
     */
    private function upsertPayable(PagarmeAccount $account, array $row, ?Order $knownOrder = null): ?PagarmePayable
    {
        $recipientId = isset($row['recipient_id']) && is_string($row['recipient_id']) ? $row['recipient_id'] : '';
        if ($recipientId !== '' && $recipientId !== $account->recipient_id) {
            return null;
        }
        $chargeId = isset($row['charge_id']) && is_string($row['charge_id']) && $row['charge_id'] !== '' ? $row['charge_id'] : null;
        $order = $knownOrder && $knownOrder->gateway_id === $chargeId ? $knownOrder : $this->orderForCharge($chargeId, $account);

        return PagarmePayable::updateOrCreate(
            ['payable_id' => (string) $row['id']],
            [
                'pagarme_account_id' => $account->id,
                'charge_id' => $chargeId,
                'order_id' => $order?->id,
                'tenant_id' => $order?->tenant_id,
                'type' => (string) ($row['type'] ?? 'credit'),
                'status' => (string) ($row['status'] ?? 'waiting_funds'),
                'payment_method' => isset($row['payment_method']) ? (string) $row['payment_method'] : null,
                'installment' => isset($row['installment']) ? (int) $row['installment'] : null,
                'amount_cents' => (int) ($row['amount'] ?? 0),
                'fee_cents' => (int) ($row['fee'] ?? 0),
                'anticipation_fee_cents' => (int) ($row['anticipation_fee'] ?? 0),
                'fraud_coverage_fee_cents' => (int) ($row['fraud_coverage_fee'] ?? 0),
                'payment_date' => $this->date($row['payment_date'] ?? null)?->toDateString(),
                'accrual_at' => $this->date($row['accrual_at'] ?? null),
                'settlement_id' => isset($row['settlement_id']) ? (string) $row['settlement_id'] : null,
                'gateway_created_at' => $this->date($row['created_at'] ?? null),
            ],
        );
    }

    /** @var array<string, Order|null> */
    private array $orderCache = [];

    private function orderForCharge(?string $chargeId, PagarmeAccount $account): ?Order
    {
        if ($chargeId === null) {
            return null;
        }
        if (! array_key_exists($chargeId, $this->orderCache)) {
            $this->orderCache[$chargeId] = Order::query()
                ->where('gateway', 'pagarme')
                ->where('gateway_id', $chargeId)
                ->whereIn('tenant_id', $account->tenantIds())
                ->first();
        }

        return $this->orderCache[$chargeId];
    }

    public function syncTransfers(PagarmeAccount $account): int
    {
        $rows = $account->client()->transfers();
        foreach ($rows as $row) {
            $this->upsertTransfer($account, $row);
        }

        return count($rows);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function upsertTransfer(PagarmeAccount $account, array $row, ?PagarmeTransfer $local = null): PagarmeTransfer
    {
        $attrs = [
            'pagarme_account_id' => $account->id,
            'transfer_id' => (string) $row['id'],
            'amount_cents' => (int) ($row['amount'] ?? 0),
            'fee_cents' => isset($row['fee']) ? (int) $row['fee'] : null,
            'status' => (string) ($row['status'] ?? 'pending_transfer'),
            'type' => isset($row['type']) ? (string) $row['type'] : null,
            'funding_estimated_at' => $this->date($row['funding_estimated_date'] ?? null),
            'bank_account_masked' => $this->maskBankAccount($row['bank_account'] ?? null),
            'gateway_created_at' => $this->date($row['created_at'] ?? ($row['date_created'] ?? null)),
        ];
        if ($local) {
            $local->update($attrs);

            return $local;
        }

        return PagarmeTransfer::updateOrCreate(['transfer_id' => (string) $row['id']], $attrs);
    }

    public function syncAnticipations(PagarmeAccount $account): int
    {
        $rows = $account->client()->anticipations();
        foreach ($rows as $row) {
            $this->upsertAnticipation($account, $row);
        }

        return count($rows);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function upsertAnticipation(PagarmeAccount $account, array $row, ?PagarmeAnticipation $local = null): PagarmeAnticipation
    {
        $attrs = [
            'pagarme_account_id' => $account->id,
            'anticipation_id' => (string) $row['id'],
            'amount_cents' => isset($row['amount']) ? (int) $row['amount'] : null,
            'fee_cents' => isset($row['fee']) ? (int) $row['fee'] : null,
            'anticipation_fee_cents' => isset($row['anticipation_fee']) ? (int) $row['anticipation_fee'] : null,
            'status' => (string) ($row['status'] ?? 'pending'),
            'timeframe' => isset($row['timeframe']) ? (string) $row['timeframe'] : null,
            'payment_date' => $this->date($row['payment_date'] ?? null)?->toDateString(),
            'gateway_created_at' => $this->date($row['created_at'] ?? null),
        ];
        if ($local) {
            $local->update($attrs);

            return $local;
        }
        $existing = PagarmeAnticipation::where('anticipation_id', (string) $row['id'])->first();
        if ($existing) {
            $existing->update($attrs);

            return $existing;
        }

        return PagarmeAnticipation::create($attrs + ['requested_amount_cents' => (int) ($row['requested_amount'] ?? $row['amount'] ?? 0)]);
    }

    public function syncSettlements(PagarmeAccount $account, int $days = 35): int
    {
        $rows = $account->client()->settlements(now()->subDays($days)->toDateString(), now()->addDays(60)->toDateString());
        foreach ($rows as $row) {
            if (! isset($row['id'])) {
                continue;
            }
            PagarmeSettlement::updateOrCreate(['settlement_id' => (string) $row['id']], [
                'pagarme_account_id' => $account->id,
                'amount_cents' => (int) ($row['amount'] ?? 0),
                'product' => isset($row['product']) ? (string) $row['product'] : null,
                'card_brand' => isset($row['card_brand']) ? (string) $row['card_brand'] : null,
                'status' => isset($row['status']) ? (string) $row['status'] : null,
                'payment_date' => $this->date($row['payment_date'] ?? null)?->toDateString(),
            ]);
        }

        return count($rows);
    }

    /**
     * Só banco, agência e os 4 últimos dígitos da conta.
     *
     * @return array<string, string>|null
     */
    public function maskBankAccount(mixed $bank): ?array
    {
        if (! is_array($bank)) {
            return null;
        }
        $account = preg_replace('/\D/', '', (string) ($bank['account_number'] ?? $bank['conta'] ?? ''));

        return array_filter([
            'bank' => (string) ($bank['bank'] ?? $bank['bank_code'] ?? ''),
            'branch' => (string) ($bank['branch_number'] ?? $bank['agencia'] ?? ''),
            'account_last4' => $account !== '' ? substr($account, -4) : '',
            'holder_name' => (string) ($bank['holder_name'] ?? $bank['legal_name'] ?? ''),
        ], fn ($v) => $v !== '');
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
