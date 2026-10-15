<?php

namespace App\Http\Controllers;

use App\Models\CommissionEntry;
use App\Models\GatewayCredential;
use App\Models\PagarmeAccount;
use App\Models\WalletTransaction;
use App\Plugins\PluginExtensionRegistry;
use App\Services\PagarmeFinanceSummaryService;
use App\Services\PartnerPayoutApprovalService;
use App\Services\PayoutService;
use App\Services\TeamAccessService;
use App\Services\WalletLedgerService;
use App\Support\WalletBucket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class FinanceiroController extends Controller
{
    public function __construct(
        private readonly WalletLedgerService $ledger,
        private readonly PayoutService $payoutService,
        private readonly PartnerPayoutApprovalService $partnerPayouts,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;

        $pagarmeAccount = PagarmeAccount::forTenant($tenantId);
        if ($pagarmeAccount) {
            return $this->pagarmeIndex($request, $pagarmeAccount);
        }

        $credential = GatewayCredential::forTenant($tenantId)
            ->where('gateway_slug', 'cajupay')
            ->where('is_connected', true)
            ->first();

        $producerRoles = [CommissionEntry::ROLE_PRODUTOR];
        $balances = $this->ledger->balancesForUser($user, $producerRoles);

        $transactions = WalletTransaction::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (WalletTransaction $t) => [
                'id' => $t->id,
                'type' => $t->type,
                'amount' => (float) $t->amount,
                'description' => $t->description,
                'created_at' => $t->created_at?->toIso8601String(),
            ]);

        $partnerCommissionsPaid = CommissionEntry::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('role', [CommissionEntry::ROLE_AFILIADO, CommissionEntry::ROLE_COPRODUTOR])
            ->where('status', CommissionEntry::STATUS_PAID)
            ->sum('commission_amount');

        $partnerCommissionsPending = CommissionEntry::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('role', [CommissionEntry::ROLE_AFILIADO, CommissionEntry::ROLE_COPRODUTOR])
            ->whereIn('status', [
                CommissionEntry::STATUS_PENDING,
                CommissionEntry::STATUS_AVAILABLE,
                CommissionEntry::STATUS_RESERVED,
            ])
            ->sum('commission_amount');

        return Inertia::render('Financeiro/Index', [
            'cajupay_connected' => (bool) $credential,
            'balances' => $balances,
            'transactions' => $transactions,
            'wallet_labels' => collect(WalletBucket::keys())->mapWithKeys(
                fn ($k) => [$k => WalletBucket::label($k)]
            )->all(),
            'payouts' => $this->payoutService->recentPayoutsForUser($user),
            'pix_key' => $user->pix_key,
            'pix_key_type' => $user->pix_key_type,
            'pix_owner_document' => $user->pix_owner_document,
            'min_payout_cents' => (int) config('commissions.min_payout_cents', 100),
            'summary' => [
                'partner_commissions_paid' => (float) $partnerCommissionsPaid,
                'partner_commissions_pending' => (float) $partnerCommissionsPending,
                'producer_ledger_available' => (float) ($balances['totals']['available'] ?? 0),
            ],
            'partner_payouts' => $this->partnerPayouts->listForTenant($tenantId),
            'plugin_financeiro_tabs' => PluginExtensionRegistry::getFinanceiroTabs(),
        ]);
    }

    /**
     * Financeiro com a Pagar.me: números do tenant (recebíveis dele) e a conta, que pode ser compartilhada por
     * vários tenants. Transferir/antecipar: dono ou equipe com financeiro.manage (FinanceiroPagarmeController).
     */
    private function pagarmeIndex(Request $request, PagarmeAccount $account): Response
    {
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        $summary = app(PagarmeFinanceSummaryService::class);
        $canManage = $user->isAdmin() || $user->isInfoprodutor() || app(TeamAccessService::class)->can($user, 'financeiro.manage');

        $to = $this->dateOrNull($request->query('period_to'))?->endOfDay() ?? now()->endOfDay();
        $from = $this->dateOrNull($request->query('period_from'))?->startOfDay() ?? $to->copy()->subDays(29)->startOfDay();
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }
        $filters = [
            'status' => $request->query('status'),
            'method' => $request->query('method'),
            'from' => $this->dateOrNull($request->query('from'))?->toDateString(),
            'to' => $this->dateOrNull($request->query('to'))?->toDateString(),
        ];

        return Inertia::render('Financeiro/Pagarme', [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'overview' => $summary->overview($tenantId, $from, $to),
            'by_method' => $summary->byMethod($tenantId, $from, $to),
            'schedule' => $summary->schedule($tenantId),
            'payables' => $summary->payables($tenantId, $filters),
            'filters' => $filters,
            'account' => $summary->account($account, $canManage),
            'shared_tenants_count' => count($account->tenantIds()),
            'can_manage' => $canManage,
            'write_enabled' => (bool) config('getfy.pagarme_finance_write'),
            'plugin_financeiro_tabs' => PluginExtensionRegistry::getFinanceiroTabs(),
        ]);
    }

    private function dateOrNull(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function updatePix(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isInfoprodutor()) {
            abort(403);
        }

        $validated = $request->validate([
            'pix_key' => ['required', 'string', 'max:255'],
            'pix_key_type' => ['required', 'string', 'in:cpf,cnpj,email,phone,random'],
            'pix_owner_document' => ['nullable', 'string', 'max:32'],
        ]);

        $user->update($validated);

        return response()->json(['success' => true]);
    }

    public function requestPayout(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'wallet_bucket' => ['required', 'string', 'in:'.implode(',', WalletBucket::keys())],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'withdraw_all' => ['sometimes', 'boolean'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);

        $withdrawAll = (bool) ($validated['withdraw_all'] ?? false);
        $amount = $withdrawAll ? null : (float) ($validated['amount'] ?? 0);

        if (! $withdrawAll && $amount <= 0) {
            return response()->json(['message' => 'Informe o valor do saque ou use sacar tudo.'], 422);
        }

        $result = $this->payoutService->requestPayout(
            $user,
            $validated['wallet_bucket'],
            $amount,
            $withdrawAll,
            $request,
            [CommissionEntry::ROLE_PRODUTOR],
            $validated['idempotency_key'] ?? null,
        );

        return response()->json([
            'success' => true,
            'replayed' => $result['replayed'] ?? false,
            'message' => $result['message'] ?? null,
            'payout' => $this->payoutService->formatPayoutForApi($result['payout_request']),
            'payout_request' => [
                'uuid' => $result['payout_request']->uuid,
                'amount' => $result['payout_request']->amount_cents / 100,
                'status' => $result['payout_request']->status,
                'pix_destination' => $this->payoutService->formatPixDestination(
                    $result['payout_request']->pix_key,
                    $result['payout_request']->pix_key_type,
                ),
            ],
        ]);
    }
}
