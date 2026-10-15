<?php

namespace App\Services;

use App\Gateways\Pagarme\PagarmeFinanceException;
use App\Models\PagarmeAccount;
use App\Models\PagarmeAnticipation;
use App\Models\PagarmeTransfer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Movimentação de dinheiro na conta Pagar.me (admin). Valores e limites são sempre relidos na API antes de agir;
 * o destino da transferência é a conta bancária cadastrada na Pagar.me (nunca dados vindos do cliente).
 */
class PagarmeAccountActionsService
{
    public function __construct(private readonly PagarmeFinanceSync $sync) {}

    /**
     * @return array{transfer: PagarmeTransfer, replayed: bool}
     */
    public function transfer(PagarmeAccount $account, User $user, int $amountCents, string $clientKey, ?string $ip): array
    {
        $key = 'getfy-transfer-'.$account->id.'-'.$clientKey;
        $existing = PagarmeTransfer::where('idempotency_key', $key)->first();
        if ($existing) {
            return ['transfer' => $existing, 'replayed' => true];
        }

        return $this->withAccountLock($account, function () use ($account, $user, $amountCents, $key, $ip) {
            $available = $this->call(fn () => $account->client()->balance())['available_cents'];
            if ($amountCents > $available) {
                throw ValidationException::withMessages([
                    'amount_cents' => 'Valor maior que o saldo disponível agora ('.number_format($available / 100, 2, ',', '.').').',
                ]);
            }

            $local = PagarmeTransfer::create([
                'pagarme_account_id' => $account->id,
                'idempotency_key' => $key,
                'amount_cents' => $amountCents,
                'status' => PagarmeTransfer::STATUS_REQUESTED,
                'requested_by_user_id' => $user->id,
                'requested_ip' => $ip,
            ]);
            $this->audit('transfer', $account, $user, $amountCents, $ip);

            try {
                $row = $account->client()->createTransfer($amountCents, $key, ['source' => 'getfy', 'user_id' => (string) $user->id]);
            } catch (PagarmeFinanceException $e) {
                $local->update(['status' => PagarmeTransfer::STATUS_FAILED, 'error_message' => mb_substr($e->getMessage(), 0, 255)]);
                throw ValidationException::withMessages(['amount_cents' => $e->getMessage()]);
            } finally {
                $this->forgetCaches($account);
            }

            return ['transfer' => $this->sync->upsertTransfer($account, $row, $local), 'replayed' => false];
        });
    }

    /**
     * @return array{max_cents: int, min_cents: int|null}
     */
    public function limits(PagarmeAccount $account, string $timeframe, string $paymentDate): array
    {
        return $this->call(fn () => $account->client()->anticipationLimits($timeframe, $paymentDate));
    }

    /**
     * @return array{amount_cents: int, total_fee_cents: int, fee_cents: int, anticipation_fee_cents: int}
     */
    public function simulate(PagarmeAccount $account, string $timeframe, string $paymentDate, int $requestedCents): array
    {
        $this->assertBusinessDay($paymentDate);
        $row = $this->call(fn () => $account->client()->simulateAnticipation($timeframe, $paymentDate, $requestedCents));
        $fee = (int) ($row['fee'] ?? 0);
        $anticipationFee = (int) ($row['anticipation_fee'] ?? 0);
        $fraud = (int) ($row['fraud_coverage_fee'] ?? 0);

        return [
            'amount_cents' => (int) ($row['amount'] ?? $requestedCents),
            'fee_cents' => $fee,
            'anticipation_fee_cents' => $anticipationFee,
            'total_fee_cents' => $fee + $anticipationFee + $fraud,
        ];
    }

    /**
     * @return array{anticipation: PagarmeAnticipation, replayed: bool}
     */
    public function anticipate(PagarmeAccount $account, User $user, string $timeframe, string $paymentDate, int $requestedCents, string $clientKey, ?string $ip): array
    {
        $key = 'getfy-anticipation-'.$account->id.'-'.$clientKey;
        $existing = PagarmeAnticipation::where('idempotency_key', $key)->first();
        if ($existing) {
            return ['anticipation' => $existing, 'replayed' => true];
        }
        $this->assertBusinessDay($paymentDate);

        return $this->withAccountLock($account, function () use ($account, $user, $timeframe, $paymentDate, $requestedCents, $key, $ip) {
            $limits = $this->limits($account, $timeframe, $paymentDate);
            $min = max(1, (int) ($limits['min_cents'] ?? 1));
            if ($requestedCents > $limits['max_cents'] || $requestedCents < $min) {
                throw ValidationException::withMessages([
                    'requested_amount_cents' => sprintf(
                        'Valor fora do limite de antecipação para esta data (%s a %s).',
                        number_format($min / 100, 2, ',', '.'),
                        number_format($limits['max_cents'] / 100, 2, ',', '.'),
                    ),
                ]);
            }

            $local = PagarmeAnticipation::create([
                'pagarme_account_id' => $account->id,
                'idempotency_key' => $key,
                'requested_amount_cents' => $requestedCents,
                'status' => PagarmeAnticipation::STATUS_REQUESTED,
                'timeframe' => $timeframe,
                'payment_date' => $paymentDate,
                'requested_by_user_id' => $user->id,
                'requested_ip' => $ip,
            ]);
            $this->audit('anticipation', $account, $user, $requestedCents, $ip);

            try {
                $row = $account->client()->createAnticipation($timeframe, $paymentDate, $requestedCents, $key);
            } catch (PagarmeFinanceException $e) {
                $local->update(['status' => PagarmeAnticipation::STATUS_FAILED, 'error_message' => mb_substr($e->getMessage(), 0, 255)]);
                throw ValidationException::withMessages(['requested_amount_cents' => $e->getMessage()]);
            } finally {
                $this->forgetCaches($account);
            }

            return ['anticipation' => $this->sync->upsertAnticipation($account, $row, $local), 'replayed' => false];
        });
    }

    public function cancelAnticipation(PagarmeAccount $account, User $user, PagarmeAnticipation $anticipation, ?string $ip): PagarmeAnticipation
    {
        if ((int) $anticipation->pagarme_account_id !== (int) $account->id) {
            abort(404);
        }
        if ($anticipation->status !== PagarmeAnticipation::STATUS_PENDING || ! $anticipation->anticipation_id) {
            throw ValidationException::withMessages(['anticipation' => 'Só antecipações pendentes podem ser canceladas.']);
        }

        $row = $this->call(fn () => $account->client()->cancelAnticipation((string) $anticipation->anticipation_id));
        $anticipation->update(['status' => (string) ($row['status'] ?? 'canceled')]);
        $this->audit('anticipation_cancel', $account, $user, (int) $anticipation->requested_amount_cents, $ip);
        $this->forgetCaches($account);

        return $anticipation;
    }

    public function updateTransferSettings(PagarmeAccount $account, User $user, bool $enabled, string $interval, int $day, ?string $ip): void
    {
        $valid = match ($interval) {
            'daily' => true,
            'weekly' => $day >= 1 && $day <= 5,
            'monthly' => $day >= 1 && $day <= 31,
            default => false,
        };
        if (! $valid) {
            throw ValidationException::withMessages(['transfer_day' => 'Dia inválido para a frequência escolhida.']);
        }

        $this->call(fn () => $account->client()->updateTransferSettings($enabled, $interval, $interval === 'daily' ? 0 : $day));
        $this->audit('transfer_settings', $account, $user, 0, $ip, ['enabled' => $enabled, 'interval' => $interval, 'day' => $day]);
        $this->forgetCaches($account);
    }

    private function assertBusinessDay(string $paymentDate): void
    {
        $date = Carbon::createFromFormat('Y-m-d', $paymentDate)->startOfDay();
        if ($date->isWeekend() || $date->lt(now()->startOfDay())) {
            throw ValidationException::withMessages(['payment_date' => 'Escolha um dia útil a partir de hoje.']);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function call(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (PagarmeFinanceException $e) {
            throw ValidationException::withMessages(['pagarme' => $e->getMessage()]);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function withAccountLock(PagarmeAccount $account, callable $fn): mixed
    {
        $lock = Cache::lock('pagarme_finance.action.'.$account->id, 30);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['pagarme' => 'Outra operação está em andamento nesta conta. Tente de novo em instantes.']);
        }
        try {
            return $fn();
        } finally {
            $lock->release();
        }
    }

    private function forgetCaches(PagarmeAccount $account): void
    {
        Cache::forget('pagarme_finance.balance.'.$account->id);
        Cache::forget('pagarme_finance.recipient.'.$account->id);
    }

    private function audit(string $action, PagarmeAccount $account, User $user, int $amountCents, ?string $ip, array $extra = []): void
    {
        Log::info('PagarmeFinance action', array_merge([
            'action' => $action,
            'account_id' => $account->id,
            'user_id' => $user->id,
            'amount_cents' => $amountCents,
            'ip' => $ip,
        ], $extra));
    }
}
