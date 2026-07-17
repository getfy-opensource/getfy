<?php

namespace App\Http\Controllers;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\MetaCustomAudienceCsvService;
use App\Services\OrderBumpReportService;
use App\Support\ReportingPeriod;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RelatoriosController extends Controller
{
    private const PERIODS = ['hoje', 'ontem', '7dias', 'mes', 'ano', 'total', 'personalizado'];

    public function __construct(
        private MetaCustomAudienceCsvService $metaCustomAudienceCsv,
        private OrderBumpReportService $orderBumpReport,
    ) {}

    public function index(Request $request): Response
    {
        $period = $request->query('period', 'hoje');
        if (! in_array($period, self::PERIODS, true)) {
            $period = 'hoje';
        }

        $dateFrom = null;
        $dateTo = null;
        if ($period === 'personalizado') {
            $dateFrom = (string) $request->query('date_from', ReportingPeriod::now()->startOfMonth()->toDateString());
            $dateTo = (string) $request->query('date_to', ReportingPeriod::now()->toDateString());
            $request->merge(['date_from' => $dateFrom, 'date_to' => $dateTo]);
            $request->validate([
                'date_from' => ['required', 'date_format:Y-m-d'],
                'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            ]);
            [$start, $end] = ReportingPeriod::boundsForVendas('custom', $dateFrom, $dateTo);
        } else {
            [$start, $end] = ReportingPeriod::boundsForDashboard($period);
        }

        $tenantId = auth()->user()->tenant_id;

        $ordersQuery = Order::forTenant($tenantId);
        ReportingPeriod::applyCreatedAtBounds($ordersQuery, $start, $end);

        $ordersCompleted = (clone $ordersQuery)->where('status', 'completed');
        $ordersRefunded = (clone $ordersQuery)->where('status', 'refunded');

        $receitaTotal = (float) $ordersCompleted->sum('amount');
        $quantidadeVendas = $ordersCompleted->count();
        $ticketMedio = $quantidadeVendas > 0 ? $receitaTotal / $quantidadeVendas : 0.0;
        $reembolsosCount = $ordersRefunded->count();
        $reembolsosTotal = (float) $ordersRefunded->sum('amount');

        $totalAlunos = User::where('role', User::ROLE_ALUNO)
            ->whereHas('products', fn ($q) => $tenantId === null ? $q->whereNull('tenant_id') : $q->where('tenant_id', $tenantId))
            ->count();
        $totalProdutos = Product::forTenant($tenantId)->count();

        $formasPagamento = (clone $ordersQuery)
            ->where('status', 'completed')
            ->selectRaw('gateway, SUM(amount) as total, COUNT(*) as quantidade')
            ->groupBy('gateway')
            ->get()
            ->map(function ($row) {
                $label = $this->gatewayLabel($row->gateway);

                return [
                    'metodo' => $row->gateway ?? 'outro',
                    'label' => $label,
                    'total' => (float) $row->total,
                    'quantidade' => (int) $row->quantidade,
                ];
            })
            ->values()
            ->all();

        $graficoReceita = $this->buildGraficoReceita($tenantId, $start, $end);

        $receitaPorProduto = Order::query()
            ->when($tenantId === null, fn ($q) => $q->whereNull('orders.tenant_id'), fn ($q) => $q->where('orders.tenant_id', $tenantId))
            ->where('orders.status', 'completed');
        if ($start && $end) {
            $receitaPorProduto->whereBetween('orders.created_at', [$start, $end]);
        } elseif ($start) {
            $receitaPorProduto->where('orders.created_at', '>=', $start);
        } elseif ($end) {
            $receitaPorProduto->where('orders.created_at', '<=', $end);
        }
        $receitaPorProduto = $receitaPorProduto
            ->join('products', 'orders.product_id', '=', 'products.id')
            ->selectRaw('products.id as product_id, products.name as product_name, SUM(orders.amount) as total, COUNT(*) as quantidade')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'product_id' => $r->product_id,
                'product_name' => $r->product_name,
                'total' => (float) $r->total,
                'quantidade' => (int) $r->quantidade,
            ])
            ->values()
            ->all();

        $sessionsQuery = CheckoutSession::forTenant($tenantId);
        ReportingPeriod::applyCreatedAtBounds($sessionsQuery, $start, $end);

        $abandonadosVisit = (clone $sessionsQuery)
            ->whereAbandonmentVisitEligible()
            ->count();

        $abandonadosForm = (clone $sessionsQuery)
            ->whereAbandonmentFormEligible()
            ->count();

        $converted = (clone $sessionsQuery)
            ->where('step', CheckoutSession::STEP_CONVERTED)
            ->count();

        $totalSessoesPeriodo = (clone $sessionsQuery)->count();

        $abandonadosTotal = $abandonadosVisit + $abandonadosForm;
        $taxaConversao = $totalSessoesPeriodo > 0
            ? round((float) $converted / $totalSessoesPeriodo * 100, 1)
            : 0.0;

        $abandonadosComEmail = CheckoutSession::forTenant($tenantId)
            ->whereAbandonmentFormEligible()
            ->whereNotNull('email')
            ->where('email', '!=', '');
        ReportingPeriod::applyCreatedAtBounds($abandonadosComEmail, $start, $end);
        $abandonadosComEmail = $abandonadosComEmail
            ->with('product:id,name')
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'email' => $s->email,
                'name' => $s->name,
                'product_name' => $s->product?->name ?? '–',
                'updated_at' => $s->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $orderBumpReport = $this->orderBumpReport->build(
            $tenantId,
            $request->query('order_bump_product_id'),
            $start,
            $end,
        );

        return Inertia::render('Relatorios/Index', [
            'period' => $period,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'order_bump_report' => $orderBumpReport,
            'meta_export_products' => $this->metaCustomAudienceCsv->productsForExportDropdown($request->user()),
            'receita_total' => round($receitaTotal, 2),
            'quantidade_vendas' => $quantidadeVendas,
            'ticket_medio' => round($ticketMedio, 2),
            'total_alunos' => $totalAlunos,
            'total_produtos' => $totalProdutos,
            'formas_pagamento' => $formasPagamento,
            'grafico_receita' => $graficoReceita,
            'receita_por_produto' => $receitaPorProduto,
            'abandonados_visit' => $abandonadosVisit,
            'abandonados_form' => $abandonadosForm,
            'abandonados_total' => $abandonadosTotal,
            'taxa_conversao' => $taxaConversao,
            'abandonados_com_email' => $abandonadosComEmail,
            'reembolsos_count' => $reembolsosCount,
            'reembolsos_total' => round($reembolsosTotal, 2),
        ]);
    }

    public function exportMetaCompradores(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'string'],
        ]);

        return $this->metaCustomAudienceCsv->streamPurchasers($request->user(), $data['product_id']);
    }

    public function exportMetaAbandonos(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'string'],
        ]);

        return $this->metaCustomAudienceCsv->streamAbandonedEngaged($request->user(), $data['product_id']);
    }

    private function gatewayLabel(?string $gateway): string
    {
        if ($gateway === null || $gateway === '') {
            return 'Outro';
        }
        $g = strtolower($gateway);
        if (str_contains($g, 'pix') || in_array($g, ['spacepag'], true)) {
            return 'Pix';
        }
        if (str_contains($g, 'card') || str_contains($g, 'cartao') || str_contains($g, 'cartão') || str_contains($g, 'credito')) {
            return 'Cartão';
        }
        if (str_contains($g, 'boleto')) {
            return 'Boleto';
        }

        return ucfirst($gateway);
    }

    private function buildGraficoReceita(?int $tenantId, ?\Carbon\Carbon $start, ?\Carbon\Carbon $end): array
    {
        $query = Order::forTenant($tenantId)->where('status', 'completed');
        ReportingPeriod::applyCreatedAtBounds($query, $start, $end);

        $totalsByDate = [];
        $tz = ReportingPeriod::timezone();
        $query->select(['created_at', 'amount'])->orderBy('created_at')->chunk(500, function ($orders) use (&$totalsByDate, $tz) {
            foreach ($orders as $order) {
                $d = $order->created_at->timezone($tz)->format('Y-m-d');
                $totalsByDate[$d] = ($totalsByDate[$d] ?? 0.0) + (float) $order->amount;
            }
        });
        ksort($totalsByDate);

        $out = [];
        foreach ($totalsByDate as $data => $total) {
            $out[] = ['data' => $data, 'total' => round($total, 2)];
        }

        return $out;
    }
}
