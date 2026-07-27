<?php

namespace App\Http\Controllers;

use App\Events\DashboardLoading;
use App\Plugins\PluginExtensionRegistry;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Product;
use App\Services\TrackingService;
use App\Support\OrderCurrencyTotals;
use App\Support\ReportingPeriod;
use App\Services\TeamAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const PERIODS = ['hoje', 'ontem', '7dias', 'mes', 'ano', 'total'];

    private const CACHE_TTL_SECONDS = 300; // 5 minutes

    public function __invoke(Request $request): Response
    {
        $period = $request->query('period', 'hoje');
        if (! in_array($period, self::PERIODS, true)) {
            $period = 'hoje';
        }

        $tenantId = auth()->user()->tenant_id;
        $userId = auth()->id();
        $bust = ReportingPeriod::dashboardBustToken($tenantId);
        $dateSuffix = ReportingPeriod::dashboardCacheSuffix($period);
        $cacheKey = 'dashboard:'.($tenantId ?? 'global').':'.$period.':'.$dateSuffix.':b'.$bust.':u'.($userId ?? '0');
        $cacheTtl = in_array($period, ['hoje', 'ontem'], true) ? 60 : self::CACHE_TTL_SECONDS;

        $payload = Cache::remember($cacheKey, $cacheTtl, function () use ($tenantId, $period) {
            [$start, $end] = ReportingPeriod::boundsForDashboard($period);

            $ordersQuery = Order::forTenant($tenantId);
            if (auth()->user()?->isTeam()) {
                $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
                $ordersQuery->whereIn('product_id', $allowed ?: ['__none__']);
            }
        ReportingPeriod::applyCreatedAtBounds($ordersQuery, $start, $end);

        $ordersCompleted = (clone $ordersQuery)->where('status', 'completed');
        $ordersPending = (clone $ordersQuery)->where('status', 'pending');
        $ordersRefunded = (clone $ordersQuery)->where('status', 'refunded');

        $vendasTotaisPorMoeda = [];
        try {
            $vendasTotaisPorMoeda = OrderCurrencyTotals::valorPorMoedaFromQuery($ordersQuery);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('DashboardController valorPorMoeda', [
                'message' => $e->getMessage(),
                'tenant_id' => $tenantId,
            ]);
            $fallbackTotal = (float) (clone $ordersQuery)->where('status', 'completed')->sum('amount');
            $vendasTotaisPorMoeda = $fallbackTotal > 0
                ? [['currency' => 'BRL', 'total' => round($fallbackTotal, 2)]]
                : [];
        }

        $trackingPayload = app(TrackingService::class)->buildPayload($tenantId, $period, auth()->user());
        $lucroLiquido = (float) data_get($trackingPayload, 'financial.lucro_liquido', 0.0);
        $lucroLiquidoPorMoeda = [['currency' => 'BRL', 'total' => round($lucroLiquido, 2)]];
        $brlRow = collect($vendasTotaisPorMoeda)->firstWhere('currency', 'BRL');
        $vendasTotais = $brlRow ? (float) $brlRow['total'] : 0.0;
        $quantidadeVendas = $ordersCompleted->count();
        $ticketMedio = $quantidadeVendas > 0 && $brlRow
            ? (float) $brlRow['total'] / $quantidadeVendas
            : 0.0;
        $vendasPendentes = (float) (clone $ordersQuery)->where('status', 'pending')->where('currency', 'BRL')->sum('amount');
        $reembolsosCount = $ordersRefunded->count();
        $reembolsosTotal = (float) (clone $ordersQuery)->where('status', 'refunded')->sum('amount');

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

        $graficoVendas = $this->buildGraficoVendas($tenantId, $period, $start, $end);

        $productsQuery = Product::forTenant($tenantId);
        if (auth()->user()?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $productsQuery->whereIn('id', $allowed ?: ['__none__']);
        }
        $quantidadeProdutos = $productsQuery->count();

            $sessionsQuery = CheckoutSession::forTenant($tenantId);
            if (auth()->user()?->isTeam()) {
                $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
                $sessionsQuery->whereIn('product_id', $allowed ?: ['__none__']);
            }
            ReportingPeriod::applyCreatedAtBounds($sessionsQuery, $start, $end);

            $abandonadosVisit = (clone $sessionsQuery)
                ->whereAbandonmentVisitEligible()
                ->count();

            $abandonadosForm = (clone $sessionsQuery)
                ->whereAbandonmentFormEligible()
                ->count();

            $convertedSessions = (clone $sessionsQuery)
                ->where('step', CheckoutSession::STEP_CONVERTED)
                ->count();

            $totalSessoesPeriodo = (clone $sessionsQuery)->count();

            $abandonoCarrinho = $abandonadosVisit + $abandonadosForm;
            $taxaConversao = $totalSessoesPeriodo > 0
                ? round((float) $convertedSessions / $totalSessoesPeriodo * 100, 1)
                : 0.0;

            return [
                'period' => $period,
                'vendas_totais' => round($vendasTotais, 2),
                'vendas_totais_por_moeda' => $vendasTotaisPorMoeda,
                'lucro_liquido' => round($lucroLiquido, 2),
                'lucro_liquido_por_moeda' => $lucroLiquidoPorMoeda,
                'vendas_pendentes' => round($vendasPendentes, 2),
                'quantidade_vendas' => $quantidadeVendas,
                'ticket_medio' => round($ticketMedio, 2),
                'formas_pagamento' => $formasPagamento,
                'taxa_conversao' => $taxaConversao,
                'abandono_carrinho' => $abandonoCarrinho,
                'reembolsos_count' => $reembolsosCount,
                'reembolsos_total' => round($reembolsosTotal, 2),
                'quantidade_produtos' => $quantidadeProdutos,
                'grafico_vendas' => $graficoVendas,
            ];
        });

        $data = new \ArrayObject($payload);
        event(new DashboardLoading($data));

        return Inertia::render('Dashboard/Index', array_merge($data->getArrayCopy(), [
            'layoutFullWidth' => true,
            'plugin_dashboard_widgets' => PluginExtensionRegistry::getDashboardWidgets(),
        ]));
    }

    private function gatewayLabel(?string $gateway): string
    {
        if ($gateway === null || $gateway === '') {
            return 'Outro';
        }
        $g = strtolower($gateway);
        if (str_contains($g, 'pix')) {
            return 'Pix';
        }
        if (str_contains($gateway, 'card') || str_contains($g, 'cartao') || str_contains($g, 'cartão') || str_contains($g, 'credito')) {
            return 'Cartão';
        }
        if (str_contains($g, 'boleto')) {
            return 'Boleto';
        }
        return ucfirst($gateway);
    }

    /**
     * Soma o lucro líquido de pedidos completed agrupado por moeda, respeitando os mesmos filtros do dashboard.
     *
     * @return list<array{currency: string, total: float}>
     */
    private function buildGraficoVendas(?int $tenantId, string $period, ?\Carbon\Carbon $start, ?\Carbon\Carbon $end): array
    {
        $query = Order::forTenant($tenantId)->where('status', 'completed');
        if (auth()->user()?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $query->whereIn('product_id', $allowed ?: ['__none__']);
        }

        ReportingPeriod::applyCreatedAtBounds($query, $start, $end);

        $isHourly = in_array($period, ['hoje', 'ontem'], true);
        $tz = ReportingPeriod::timezone();

        if ($isHourly) {
            $totalsByHour = [];
            $query->select(['created_at', 'amount'])->orderBy('created_at')->chunk(500, function ($orders) use (&$totalsByHour, $tz) {
                foreach ($orders as $order) {
                    $h = (int) $order->created_at->timezone($tz)->format('G');
                    $totalsByHour[$h] = ($totalsByHour[$h] ?? 0.0) + (float) $order->amount;
                }
            });

            $result = [];
            for ($h = 0; $h <= 23; $h++) {
                $result[] = [
                    'data' => (string) $h,
                    'total' => round((float) ($totalsByHour[$h] ?? 0), 2),
                ];
            }

            return $result;
        }

        $totalsByDate = [];
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
