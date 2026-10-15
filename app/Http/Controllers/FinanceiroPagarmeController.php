<?php

namespace App\Http\Controllers;

use App\Models\PagarmeAccount;
use App\Models\PagarmeAnticipation;
use App\Services\PagarmeAccountActionsService;
use App\Services\PagarmeFinanceSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ações da conta Pagar.me do próprio tenant em /financeiro (dono ou equipe com financeiro.manage).
 * A conta vem sempre do tenant do usuário, nunca da URL. Escrita exige GETFY_PAGARME_FINANCE_WRITE e a senha.
 */
class FinanceiroPagarmeController extends Controller
{
    public function __construct(private readonly PagarmeAccountActionsService $actions) {}

    private function account(Request $request): PagarmeAccount
    {
        $account = PagarmeAccount::forTenant((int) $request->user()->tenant_id);
        abort_unless($account, 404);

        return $account;
    }

    public function sync(Request $request, PagarmeFinanceSync $sync): JsonResponse
    {
        $account = $this->account($request);
        $stats = $sync->syncAccount($account);

        return response()->json(['stats' => $stats, 'error' => $account->fresh()->last_sync_error]);
    }

    public function transfer(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $data = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:100', 'max:2147483647'],
            'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/'],
            'current_password' => ['required', 'current_password'],
        ]);

        $result = $this->actions->transfer($account, $request->user(), (int) $data['amount_cents'], $data['idempotency_key'], $request->ip());

        return response()->json([
            'replayed' => $result['replayed'],
            'transfer' => [
                'id' => $result['transfer']->id,
                'status' => $result['transfer']->status,
                'amount_cents' => $result['transfer']->amount_cents,
                'fee_cents' => $result['transfer']->fee_cents,
            ],
        ]);
    }

    public function anticipationLimits(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $data = $request->validate([
            'timeframe' => ['required', Rule::in(['start', 'end'])],
            'payment_date' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json($this->actions->limits($account, $data['timeframe'], $data['payment_date']));
    }

    public function simulateAnticipation(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $data = $request->validate([
            'timeframe' => ['required', Rule::in(['start', 'end'])],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'requested_amount_cents' => ['required', 'integer', 'min:100'],
        ]);

        return response()->json($this->actions->simulate($account, $data['timeframe'], $data['payment_date'], (int) $data['requested_amount_cents']));
    }

    public function anticipate(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $data = $request->validate([
            'timeframe' => ['required', Rule::in(['start', 'end'])],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'requested_amount_cents' => ['required', 'integer', 'min:100'],
            'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/'],
            'current_password' => ['required', 'current_password'],
        ]);

        $result = $this->actions->anticipate(
            $account, $request->user(), $data['timeframe'], $data['payment_date'],
            (int) $data['requested_amount_cents'], $data['idempotency_key'], $request->ip(),
        );

        return response()->json([
            'replayed' => $result['replayed'],
            'anticipation' => ['id' => $result['anticipation']->id, 'status' => $result['anticipation']->status],
        ]);
    }

    public function cancelAnticipation(Request $request, PagarmeAnticipation $anticipation): JsonResponse
    {
        $account = $this->account($request);
        $request->validate(['current_password' => ['required', 'current_password']]);

        $updated = $this->actions->cancelAnticipation($account, $request->user(), $anticipation, $request->ip());

        return response()->json(['anticipation' => ['id' => $updated->id, 'status' => $updated->status]]);
    }

    public function updateTransferSettings(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $data = $request->validate([
            'transfer_enabled' => ['required', 'boolean'],
            'transfer_interval' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'transfer_day' => ['required', 'integer', 'min:0', 'max:31'],
            'current_password' => ['required', 'current_password'],
        ]);

        $this->actions->updateTransferSettings(
            $account, $request->user(), (bool) $data['transfer_enabled'], $data['transfer_interval'], (int) $data['transfer_day'], $request->ip(),
        );

        return response()->json(['success' => true]);
    }
}
