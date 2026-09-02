<?php

namespace App\Services;

use App\Models\CheckoutFieldEvent;
use App\Models\CheckoutSession;
use App\Models\CommissionEntry;
use App\Models\Order;
use App\Models\TrackingAdSpend;
use App\Models\TrackingAdSpendOverride;
use App\Support\CountryCatalog;
use App\Support\OrderFinancialTotals;
use App\Support\ReportingPeriod;
use App\Services\TeamAccessService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TrackingService
{
    /** @var list<string> */
    public const PERIODS = ['hoje', 'ontem', '7dias', 'mes', 'ano', 'total'];

    private const CACHE_TTL_SECONDS = 300;

    /** @var list<string> */
    private const FIELD_ORDER = ['email', 'name', 'cpf', 'phone', 'payment_method', 'submit'];

    /** @var array<string, string> */
    private const FIELD_LABELS = [
        'email' => 'E-mail',
        'name' => 'Nome',
        'cpf' => 'CPF',
        'phone' => 'Telefone',
        'payment_method' => 'Pagamento',
        'submit' => 'Finalizar compra',
    ];

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(?int $tenantId, string $period, ?\App\Models\User $user = null): array
    {
        if (! in_array($period, self::PERIODS, true)) {
            $period = 'hoje';
        }

        $userId = $user?->id ?? auth()->id();
        $bust = ReportingPeriod::dashboardBustToken($tenantId);
        $dateSuffix = ReportingPeriod::dashboardCacheSuffix($period);
        $cacheKey = 'tracking:'.($tenantId ?? 'global').':'.$period.':'.$dateSuffix.':b'.$bust.':u'.($userId ?? '0');
        $cacheTtl = in_array($period, ['hoje', 'ontem'], true) ? 60 : self::CACHE_TTL_SECONDS;

        return Cache::remember($cacheKey, $cacheTtl, function () use ($tenantId, $period, $user) {
            return $this->computePayload($tenantId, $period, $user);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function computePayload(?int $tenantId, string $period, ?\App\Models\User $user): array
    {
        [$start, $end] = ReportingPeriod::boundsForDashboard($period);

        $ordersQuery = Order::forTenant($tenantId);
        $sessionsQuery = CheckoutSession::forTenant($tenantId);
        $this->applyTeamProductFilter($ordersQuery, $user);
        $this->applyTeamProductFilter($sessionsQuery, $user);
        ReportingPeriod::applyCreatedAtBounds($ordersQuery, $start, $end);
        ReportingPeriod::applyCreatedAtBounds($sessionsQuery, $start, $end);

        $completedQuery = (clone $ordersQuery)->where('status', 'completed');
        $financialRows = OrderFinancialTotals::porMoedaFromQuery(clone $completedQuery);
        $brlFinancial = OrderFinancialTotals::brlTotals($financialRows);
        $faturamentoBruto = $brlFinancial['gross'];
        $taxasGateway = $brlFinancial['fees'];
        $receitaLiquida = $brlFinancial['net'];
        $quantidadeVendas = (clone $completedQuery)->count();
        $ticketMedio = $quantidadeVendas > 0 ? round($faturamentoBruto / $quantidadeVendas, 2) : 0.0;

        $reembolsosTotal = (float) (clone $ordersQuery)->where('status', 'refunded')->where('currency', 'BRL')->sum('amount');

        $orderIds = (clone $completedQuery)->pluck('id');
        $comissoesParceiros = 0.0;

        if ($orderIds->isNotEmpty()) {
            $comissoesParceiros = (float) CommissionEntry::query()
                ->whereIn('order_id', $orderIds)
                ->whereIn('role', [CommissionEntry::ROLE_AFILIADO, CommissionEntry::ROLE_COPRODUTOR])
                ->sum('commission_amount');
        }

        $adSpend = $this->resolveAdSpend($tenantId, $period, $start, $end);
        $gastoAds = (float) $adSpend['amount'];
        $lucroOperacional = round($receitaLiquida - $comissoesParceiros - $reembolsosTotal - $gastoAds, 2);
        $roi = $gastoAds > 0 ? round(($lucroOperacional / $gastoAds) * 100, 1) : null;
        $roas = $gastoAds > 0 ? round($faturamentoBruto / $gastoAds, 2) : null;

        $totalSessoes = (clone $sessionsQuery)->count();
        $visitas = (clone $sessionsQuery)->count();
        $formStarted = (clone $sessionsQuery)->whereIn('step', [
            CheckoutSession::STEP_FORM_STARTED,
            CheckoutSession::STEP_FORM_FILLED,
            CheckoutSession::STEP_CONVERTED,
        ])->count();
        $formFilled = (clone $sessionsQuery)->whereIn('step', [
            CheckoutSession::STEP_FORM_FILLED,
            CheckoutSession::STEP_CONVERTED,
        ])->count();
        $convertidos = (clone $sessionsQuery)->where('step', CheckoutSession::STEP_CONVERTED)->count();
        $abandono = (clone $sessionsQuery)->whereAbandonmentVisitEligible()->count()
            + (clone $sessionsQuery)->whereAbandonmentFormEligible()->count();
        $taxaConversao = $totalSessoes > 0 ? round($convertidos / $totalSessoes * 100, 1) : 0.0;

        $salesByCountry = $this->aggregateByCountry(
            (clone $completedQuery)->where('currency', 'BRL'),
            'country_code',
            'amount'
        );
        $visitsByCountry = $this->aggregateByCountry(
            clone $sessionsQuery,
            'country_code',
            null
        );

        $topCountry = $salesByCountry[0] ?? null;

        $paymentMethods = $this->buildPaymentMethodsBreakdown(clone $completedQuery);
        $recentSales = $this->buildRecentSales(clone $completedQuery);
        $fieldDropoff = $this->buildFieldDropoff($tenantId, $start, $end, $user);
        $utmSources = $this->buildUtmSources(clone $sessionsQuery);
        $chartRevenue = $this->buildChartRevenue($tenantId, $period, $start, $end, $user);

        return [
            'period' => $period,
            'financial' => [
                'faturamento_bruto' => round($faturamentoBruto, 2),
                'taxas_gateway' => round($taxasGateway, 2),
                'receita_liquida' => round($receitaLiquida, 2),
                'comissoes_parceiros' => round($comissoesParceiros, 2),
                'reembolsos' => round($reembolsosTotal, 2),
                'lucro_operacional' => $lucroOperacional,
                'lucro_liquido' => $lucroOperacional,
                'gasto_ads' => round($gastoAds, 2),
                'roi' => $roi,
                'roas' => $roas,
                'ticket_medio' => $ticketMedio,
            ],
            'funnel' => [
                'visitas' => $visitas,
                'form_started' => $formStarted,
                'form_filled' => $formFilled,
                'convertidos' => $convertidos,
                'taxa_conversao' => $taxaConversao,
                'abandono' => $abandono,
            ],
            'sales_by_country' => $salesByCountry,
            'visits_by_country' => $visitsByCountry,
            'top_country' => $topCountry,
            'payment_methods' => $paymentMethods,
            'recent_sales' => $recentSales,
            'field_dropoff' => $fieldDropoff,
            'utm_sources' => $utmSources,
            'chart_revenue' => $chartRevenue,
            'ad_spend' => $adSpend,
        ];
    }

    /**
     * @return array{amount: float, type: string, override: bool, period_key: string}
     */
    public function resolveAdSpend(?int $tenantId, string $period, ?Carbon $start, ?Carbon $end): array
    {
        $override = TrackingAdSpendOverride::forTenant($tenantId)
            ->where('period_key', $period)
            ->where('currency', 'BRL')
            ->first();

        if ($override) {
            return [
                'amount' => (float) $override->amount,
                'type' => 'override',
                'override' => true,
                'period_key' => $period,
            ];
        }

        if ($start === null || $end === null) {
            $dailySum = (float) TrackingAdSpend::forTenant($tenantId)
                ->where('currency', 'BRL')
                ->sum('amount');

            return [
                'amount' => $dailySum,
                'type' => 'daily_sum',
                'override' => false,
                'period_key' => $period,
            ];
        }

        $dailySum = (float) TrackingAdSpend::forTenant($tenantId)
            ->where('currency', 'BRL')
            ->whereBetween('spent_on', [$start->toDateString(), $end->toDateString()])
            ->sum('amount');

        return [
            'amount' => $dailySum,
            'type' => 'daily_sum',
            'override' => false,
            'period_key' => $period,
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return list<array<string, mixed>>
     */
    private function aggregateByCountry($query, string $column, ?string $sumColumn): array
    {
        $select = $sumColumn
            ? "COALESCE({$column}, 'XX') as country_code, SUM({$sumColumn}) as total, COUNT(*) as count"
            : "COALESCE({$column}, 'XX') as country_code, COUNT(*) as count";

        $rows = $query
            ->selectRaw($select)
            ->groupBy(DB::raw("COALESCE({$column}, 'XX')"))
            ->orderByDesc($sumColumn ? 'total' : 'count')
            ->limit(20)
            ->get();

        $grandTotal = $rows->sum(fn ($r) => (float) ($r->total ?? $r->count ?? 0));

        return $rows->map(function ($row) use ($grandTotal, $sumColumn) {
            $code = $row->country_code === 'XX' ? null : $row->country_code;
            $value = $sumColumn ? (float) $row->total : (int) $row->count;
            $coords = CountryCatalog::coordinates($code);

            return [
                'country_code' => $code,
                'country_name' => CountryCatalog::name($code),
                'total' => $sumColumn ? round($value, 2) : $value,
                'count' => (int) $row->count,
                'percent' => $grandTotal > 0 ? round($value / $grandTotal * 100, 1) : 0.0,
                'lat' => $coords['lat'] ?? null,
                'lng' => $coords['lng'] ?? null,
                'has_geo' => $coords !== null,
            ];
        })->values()->all();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return list<array<string, mixed>>
     */
    private function buildPaymentMethodsBreakdown($query): array
    {
        $orders = $query->get(['amount', 'metadata', 'gateway']);

        $totals = [];
        foreach ($orders as $order) {
            $method = $order->checkoutPaymentMethod();
            $label = $this->paymentMethodLabel($method);
            if (! isset($totals[$method])) {
                $totals[$method] = ['metodo' => $method, 'label' => $label, 'total' => 0.0, 'quantidade' => 0];
            }
            $totals[$method]['total'] += (float) $order->amount;
            $totals[$method]['quantidade']++;
        }

        usort($totals, fn ($a, $b) => $b['total'] <=> $a['total']);

        return array_values(array_map(function ($row) {
            $row['total'] = round($row['total'], 2);

            return $row;
        }, $totals));
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return list<array<string, mixed>>
     */
    private function buildRecentSales($query): array
    {
        return $query
            ->with(['product:id,name'])
            ->orderByDesc('created_at')
            ->limit(15)
            ->get(['id', 'product_id', 'amount', 'currency', 'country_code', 'metadata', 'created_at'])
            ->map(function (Order $order) {
                return [
                    'id' => $order->id,
                    'product_name' => $order->product?->name ?? 'Produto',
                    'amount' => (float) $order->amount,
                    'currency' => $order->currency ?? 'BRL',
                    'country_code' => $order->country_code,
                    'country_name' => CountryCatalog::name($order->country_code),
                    'payment_method' => $order->checkoutPaymentMethod(),
                    'payment_label' => $this->paymentMethodLabel($order->checkoutPaymentMethod()),
                    'created_at' => $order->created_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildFieldDropoff(?int $tenantId, ?Carbon $start, ?Carbon $end, ?\App\Models\User $user): array
    {
        $eventsQuery = CheckoutFieldEvent::forTenant($tenantId);
        if ($user?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor($user);
            $eventsQuery->whereIn('product_id', $allowed ?: ['__none__']);
        }
        if ($start && $end) {
            $eventsQuery->whereBetween('created_at', [$start, $end]);
        }

        $totalVisits = CheckoutSession::forTenant($tenantId);
        $this->applyTeamProductFilter($totalVisits, $user);
        if ($start && $end) {
            ReportingPeriod::applyCreatedAtBounds($totalVisits, $start, $end);
        }
        $baseline = max(1, (clone $totalVisits)->count());

        $result = [];
        foreach (self::FIELD_ORDER as $fieldKey) {
            $reached = (clone $eventsQuery)
                ->where('field_key', $fieldKey)
                ->where('event', CheckoutFieldEvent::EVENT_REACHED)
                ->distinct('session_token')
                ->count('session_token');
            $completed = (clone $eventsQuery)
                ->where('field_key', $fieldKey)
                ->where('event', CheckoutFieldEvent::EVENT_COMPLETED)
                ->distinct('session_token')
                ->count('session_token');

            $reachedPct = round($reached / $baseline * 100, 1);
            $completedPct = $reached > 0 ? round($completed / $reached * 100, 1) : 0.0;
            $dropoffPct = $reached > 0 ? round(100 - $completedPct, 1) : 0.0;

            $result[] = [
                'field_key' => $fieldKey,
                'label' => self::FIELD_LABELS[$fieldKey] ?? $fieldKey,
                'reached' => $reached,
                'completed' => $completed,
                'reached_percent' => $reachedPct,
                'completed_percent' => $completedPct,
                'dropoff_percent' => $dropoffPct,
            ];
        }

        return $result;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return list<array<string, mixed>>
     */
    private function buildUtmSources($query): array
    {
        return $query
            ->selectRaw("COALESCE(NULLIF(TRIM(utm_source), ''), 'direct') as source, COALESCE(NULLIF(TRIM(utm_medium), ''), 'none') as medium, COUNT(*) as count")
            ->groupBy('source', 'medium')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'source' => $row->source,
                'medium' => $row->medium,
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{data: string, total: float}>
     */
    private function buildChartRevenue(?int $tenantId, string $period, ?Carbon $start, ?Carbon $end, ?\App\Models\User $user): array
    {
        $query = Order::forTenant($tenantId)->where('status', 'completed')->where('currency', 'BRL');
        $this->applyTeamProductFilter($query, $user);
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

    private function paymentMethodLabel(string $method): string
    {
        return match ($method) {
            'pix', 'pix_auto' => 'Pix',
            'card' => 'Cartão',
            'boleto' => 'Boleto',
            'apple_pay' => 'Apple Pay',
            'google_pay' => 'Google Pay',
            default => ucfirst(str_replace('_', ' ', $method)),
        };
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    private function applyTeamProductFilter($query, ?\App\Models\User $user): void
    {
        if ($user?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor($user);
            $query->whereIn('product_id', $allowed ?: ['__none__']);
        }
    }

    public function bustCache(?int $tenantId): void
    {
        ReportingPeriod::bustDashboardCache($tenantId);
    }
}
