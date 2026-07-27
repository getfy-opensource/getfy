<?php

namespace App\Http\Controllers;

use App\Events\OrderCompleted;
use App\Events\OrderStatusChanged;
use App\Events\SubscriptionRenewed;
use App\Plugins\PluginExtensionRegistry;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductAffiliate;
use App\Models\ProductOffer;
use App\Support\AffiliateAttribution;
use App\Models\OrderItem;
use App\Models\Subscription;
use App\Services\AccessEmailService;
use App\Services\NetAmountCalculator;
use App\Services\ProducerSaleAmount;
use App\Services\RefundService;
use App\Services\TeamAccessService;
use App\Support\MoneyMinorUnits;
use App\Support\OrderCurrencyTotals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VendasController extends Controller
{
    private const STATUS_FILTERS = ['aprovadas', 'med', 'todas'];

    private function normalizeStatusFilter(Request $request): string
    {
        $statusFilter = $request->query('status_filter', 'todas');
        if (! in_array($statusFilter, self::STATUS_FILTERS, true)) {
            $statusFilter = 'todas';
        }

        return $statusFilter;
    }

    private function normalizeString(?string $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v !== '' ? $v : null;
    }

    private function applyStatusFilter($query, string $statusFilter)
    {
        return match ($statusFilter) {
            'aprovadas' => $query->where('status', 'completed'),
            'med' => $query->where('status', 'disputed'),
            default => $query,
        };
    }

    private function applyPeriodFilter($query, Request $request)
    {
        $period = $this->normalizeString($request->query('period'));
        $from = $this->normalizeString($request->query('date_from'));
        $to = $this->normalizeString($request->query('date_to'));

        if ($period === null || $period === 'all') {
            return $query;
        }

        $tz = config('app.timezone', 'America/Sao_Paulo');
        $now = now($tz);
        $start = null;
        $end = null;

        if ($period === 'today') {
            $start = $now->copy()->startOfDay();
            $end = $now->copy()->endOfDay();
        } elseif ($period === '7d') {
            $start = $now->copy()->subDays(6)->startOfDay();
            $end = $now->copy()->endOfDay();
        } elseif ($period === '30d') {
            $start = $now->copy()->subDays(29)->startOfDay();
            $end = $now->copy()->endOfDay();
        } elseif ($period === 'this_month') {
            $start = $now->copy()->startOfMonth()->startOfDay();
            $end = $now->copy()->endOfDay();
        } elseif ($period === 'last_month') {
            $start = $now->copy()->subMonthNoOverflow()->startOfMonth()->startOfDay();
            $end = $now->copy()->subMonthNoOverflow()->endOfMonth()->endOfDay();
        } elseif ($period === 'custom') {
            $start = $from ? \Illuminate\Support\Carbon::parse($from, $tz)->startOfDay() : null;
            $end = $to ? \Illuminate\Support\Carbon::parse($to, $tz)->endOfDay() : null;
        }

        if ($start && $end) {
            $query->whereBetween('created_at', [$start, $end]);
        } elseif ($start) {
            $query->where('created_at', '>=', $start);
        } elseif ($end) {
            $query->where('created_at', '<=', $end);
        }

        return $query;
    }

    private function applySearchFilter($query, Request $request)
    {
        $q = $this->normalizeString($request->query('q'));
        if ($q === null) {
            return $query;
        }
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';

        return $query->where(function ($sub) use ($like) {
            $sub->where('orders.id', 'like', $like)
                ->orWhere('orders.email', 'like', $like)
                ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', $like)->orWhere('email', 'like', $like))
                ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', $like))
                ->orWhereHas('productOffer', fn ($oq) => $oq->where('name', 'like', $like))
                ->orWhereHas('subscriptionPlan', fn ($sq) => $sq->where('name', 'like', $like));
        });
    }

    private function applyProductFilters($query, Request $request)
    {
        $productId = $this->normalizeString($request->query('product_id'));
        $offerId = $request->query('offer_id');
        $offerId = is_string($offerId) || is_int($offerId) ? (string) $offerId : null;
        $offerId = $this->normalizeString($offerId);

        if ($productId !== null) {
            $query->where('product_id', $productId);
        }
        if ($offerId !== null) {
            $query->where('product_offer_id', (int) $offerId);
        }

        return $query;
    }

    private function applyPaymentFilters($query, Request $request)
    {
        $method = $this->normalizeString($request->query('payment_method'));
        if ($method !== null) {
            $m = strtolower($method);
            if ($m === 'pix') {
                $query->where(function ($q) {
                    $q->whereIn('gateway', ['spacepag'])
                        ->orWhereRaw("LOWER(gateway) LIKE '%pix%'")
                        ->orWhere(function ($q2) {
                            $q2->where('metadata->checkout_payment_method', 'pix')
                                ->orWhere('metadata->checkout_payment_method', 'pix_auto');
                        });
                });
            } elseif ($m === 'card') {
                $query->where(function ($q) {
                    $q->where('gateway', 'card')
                        ->orWhereRaw("LOWER(gateway) LIKE '%card%'")
                        ->orWhereRaw("LOWER(gateway) LIKE '%cartao%'")
                        ->orWhereRaw("LOWER(gateway) LIKE '%cartão%'")
                        ->orWhereRaw("LOWER(gateway) LIKE '%credito%'")
                        ->orWhereIn('metadata->checkout_payment_method', ['card', 'apple_pay', 'google_pay', 'paypal']);
                });
            } elseif ($m === 'boleto') {
                $query->where(function ($q) {
                    $q->where('gateway', 'boleto')
                        ->orWhereRaw("LOWER(gateway) LIKE '%boleto%'")
                        ->orWhere('metadata->checkout_payment_method', 'boleto');
                });
            }
        }

        $paymentStatus = $this->normalizeString($request->query('payment_status'));
        if ($paymentStatus !== null && $paymentStatus !== 'all') {
            $query->where('status', $paymentStatus);
        }

        return $query;
    }

    private function applyUtmFilters($query, Request $request, ?int $tenantId)
    {
        $utmSource = $this->normalizeString($request->query('utm_source'));
        $utmMedium = $this->normalizeString($request->query('utm_medium'));
        $utmCampaign = $this->normalizeString($request->query('utm_campaign'));

        if ($utmSource === null && $utmMedium === null && $utmCampaign === null) {
            return $query;
        }

        return $query->where(function ($outer) use ($tenantId, $utmSource, $utmMedium, $utmCampaign) {
            $outer->whereExists(function ($q) use ($tenantId, $utmSource, $utmMedium, $utmCampaign) {
                $q->select(DB::raw(1))
                    ->from('checkout_sessions')
                    ->whereColumn('checkout_sessions.order_id', 'orders.id');

                if ($tenantId === null) {
                    $q->whereNull('checkout_sessions.tenant_id');
                } else {
                    $q->where('checkout_sessions.tenant_id', $tenantId);
                }

                if ($utmSource !== null) {
                    $q->where('checkout_sessions.utm_source', $utmSource);
                }
                if ($utmMedium !== null) {
                    $q->where('checkout_sessions.utm_medium', $utmMedium);
                }
                if ($utmCampaign !== null) {
                    $q->where('checkout_sessions.utm_campaign', $utmCampaign);
                }
            });
            $outer->orWhere(function ($metaQ) use ($utmSource, $utmMedium, $utmCampaign) {
                if ($utmSource !== null) {
                    $metaQ->where('orders.metadata->utm_source', $utmSource);
                }
                if ($utmMedium !== null) {
                    $metaQ->where('orders.metadata->utm_medium', $utmMedium);
                }
                if ($utmCampaign !== null) {
                    $metaQ->where('orders.metadata->utm_campaign', $utmCampaign);
                }
            });
        });
    }

    private function buildFilteredQuery(Request $request, ?int $tenantId)
    {
        $statusFilter = $this->normalizeStatusFilter($request);
        $query = Order::forTenant($tenantId);

        if (auth()->user()?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $query->whereIn('product_id', $allowed ?: ['__none__']);
        }

        $query = $this->applyStatusFilter($query, $statusFilter);
        $query = $this->applyPeriodFilter($query, $request);
        $query = $this->applySearchFilter($query, $request);
        $query = $this->applyProductFilters($query, $request);
        $query = $this->applyPaymentFilters($query, $request);
        $query = $this->applyUtmFilters($query, $request, $tenantId);

        return [$query, $statusFilter];
    }

    public function index(Request $request): InertiaResponse
    {
        $tenantId = auth()->user()->tenant_id;
        [$filteredQuery, $statusFilter] = $this->buildFilteredQuery($request, $tenantId);

        $paginator = $filteredQuery
            ->with([
                'product:id,name,slug,checkout_slug',
                'user:id,name,email',
                'productOffer:id,name,checkout_slug',
                'subscriptionPlan:id,name,checkout_slug',
                'orderItems:id,order_id,product_id,product_offer_id,subscription_plan_id,amount,position',
                'orderItems.product:id,name',
                'orderItems.productOffer:id,name',
                'orderItems.subscriptionPlan:id,name',
                'checkoutSession:id,order_id,utm_source,utm_medium,utm_campaign',
                'commissionEntries:id,order_id,role,commission_amount',
            ])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $affiliateLookup = $this->affiliateLookupForOrders($paginator->getCollection());
        $producerSaleAmount = app(ProducerSaleAmount::class);
        $netAmountCalculator = app(NetAmountCalculator::class);

        $vendas = $paginator->through(function (Order $o) use ($affiliateLookup, $producerSaleAmount, $netAmountCalculator) {
                $arr = $o->toArray();
                $arr['currency'] = $o->getCurrencyOrDefault();
                $arr['gateway_label'] = $o->paymentMethodDisplayLabel();
                $arr['product_display_name'] = $this->productDisplayName($o);
                $arr['checkout_url'] = url('/c/'.$o->getCheckoutSlug());
                $arr['payment_type_label'] = $this->paymentTypeLabel($o);
                $arr['amount_total'] = $o->lineItemsTotalAmount();
                $arr['billed_amount'] = $arr['amount_total'];
                $producerAmount = $producerSaleAmount->forOrder($o);
                if ($o->status === 'completed') {
                    $netProfitAmount = $producerAmount['is_producer_share']
                        ? (float) $producerAmount['amount']
                        : (float) $netAmountCalculator->forOrder($o)['net'];
                    $arr['net_profit_amount'] = round($netProfitAmount, 2);
                    $arr['net_profit_amount_is_estimated'] = $producerAmount['is_estimated'] || ! $producerAmount['is_producer_share'];
                } else {
                    $arr['net_profit_amount'] = null;
                    $arr['net_profit_amount_is_estimated'] = false;
                }
                $arr['display_amount'] = $arr['billed_amount'];
                $arr['display_amount_is_producer_share'] = $producerAmount['is_producer_share'];
                $arr['display_amount_is_estimated'] = $producerAmount['is_estimated'];
                $arr['sale_gross_total'] = $producerAmount['gross_total'];
                $arr['sale_channel'] = $o->saleChannel();
                $arr['is_affiliate_sale'] = $o->saleChannel() === 'affiliate';
                $affiliateKey = $o->product_id.':'.($o->affiliateCode() ?? '');
                $affiliate = $affiliateLookup->get($affiliateKey);
                $arr['affiliate'] = $affiliate ? [
                    'code' => $affiliate['code'],
                    'name' => $affiliate['name'],
                ] : null;
                try {
                    // Sem HTTP ao gateway na listagem (resolveGatewayPayment=false).
                    $refundCheck = app(RefundService::class)->canRefundFromPanel($o, false);
                    $arr['can_refund'] = $refundCheck['can'];
                    $arr['refund_auto_cajupay_pix'] = $refundCheck['auto_cajupay_pix'];
                } catch (\Throwable $e) {
                    Log::warning('VendasController::index canRefundFromPanel', [
                        'order_id' => $o->id,
                        'message' => $e->getMessage(),
                    ]);
                    $arr['can_refund'] = false;
                    $arr['refund_auto_cajupay_pix'] = false;
                }

                return $arr;
            });

        [$statsQuery] = $this->buildFilteredQuery($request, $tenantId);

        $vendasEncontradas = (clone $statsQuery)->count();

        try {
            $valorPorMoeda = OrderCurrencyTotals::valorPorMoedaFromQuery($statsQuery);
        } catch (\Throwable $e) {
            Log::error('VendasController::index valorPorMoeda', [
                'message' => $e->getMessage(),
                'tenant_id' => $tenantId,
            ]);
            $fallbackTotal = (float) (clone $statsQuery)->where('status', 'completed')->sum('amount');
            $valorPorMoeda = $fallbackTotal > 0
                ? [['currency' => 'BRL', 'total' => round($fallbackTotal, 2)]]
                : [];
        }
        $lucroLiquidoPorMoeda = $this->lucroLiquidoPorMoedaFromQuery($statsQuery, $producerSaleAmount, $netAmountCalculator);

        $vendasPix = (clone $statsQuery)
            ->where(function ($q) {
                $q->whereIn('gateway', ['spacepag'])
                    ->orWhereRaw("LOWER(gateway) LIKE '%pix%'")
                    ->orWhere(function ($q2) {
                        $q2->where('metadata->checkout_payment_method', 'pix')
                            ->orWhere('metadata->checkout_payment_method', 'pix_auto');
                    });
            })
            ->count();

        $vendasCartao = (clone $statsQuery)
            ->where(function ($q) {
                $q->where('gateway', 'card')
                    ->orWhereRaw("LOWER(gateway) LIKE '%card%'")
                    ->orWhereRaw("LOWER(gateway) LIKE '%cartao%'")
                    ->orWhereRaw("LOWER(gateway) LIKE '%cartão%'")
                    ->orWhereRaw("LOWER(gateway) LIKE '%credito%'")
                    ->orWhereIn('metadata->checkout_payment_method', ['card', 'apple_pay', 'google_pay', 'paypal']);
            })
            ->count();

        $vendasBoleto = (clone $statsQuery)
            ->where(function ($q) {
                $q->where('gateway', 'boleto')
                    ->orWhereRaw("LOWER(gateway) LIKE '%boleto%'")
                    ->orWhere('metadata->checkout_payment_method', 'boleto');
            })
            ->count();

        $stats = [
            'vendas_encontradas' => $vendasEncontradas,
            'valor_por_moeda' => $valorPorMoeda,
            'lucro_liquido_por_moeda' => $lucroLiquidoPorMoeda,
            'valor_faturado' => ($brl = collect($valorPorMoeda)->firstWhere('currency', 'BRL')) ? (float) $brl['total'] : 0.0,
            'lucro_liquido' => ($brl = collect($lucroLiquidoPorMoeda)->firstWhere('currency', 'BRL')) ? (float) $brl['total'] : 0.0,
            'valor_liquido' => ($brl = collect($valorPorMoeda)->firstWhere('currency', 'BRL')) ? (float) $brl['total'] : 0.0,
            'vendas_pix' => $vendasPix,
            'vendas_cartao' => $vendasCartao,
            'vendas_boleto' => $vendasBoleto,
        ];

        $productsQuery = Product::forTenant($tenantId)->orderBy('name');
        if (auth()->user()->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $productsQuery->whereIn('id', $allowed ?: ['__none__']);
        }
        $products = $productsQuery->get(['id', 'name']);
        $offers = ProductOffer::query()
            ->whereHas('product', fn ($q) => $q->forTenant($tenantId))
            ->with('product:id,name')
            ->orderBy('product_id')
            ->orderBy('position')
            ->get()
            ->map(fn (ProductOffer $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'product_id' => $o->product_id,
                'product_name' => $o->product?->name,
            ])
            ->values()
            ->all();

        if (auth()->user()->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $offers = array_values(array_filter($offers, fn ($o) => in_array($o['product_id'], $allowed, true)));
        }

        return Inertia::render('Vendas/Index', [
            'vendas' => $vendas,
            'stats' => $stats,
            'plugin_fulfillment_providers' => PluginExtensionRegistry::getOrderFulfillmentProviders(),
            'plugin_vendas_row_actions' => PluginExtensionRegistry::getVendasRowActions(),
            'plugin_order_detail_panels' => PluginExtensionRegistry::getOrderDetailPanels(),
            'status_filter' => $statusFilter,
            'filters' => [
                'q' => $this->normalizeString($request->query('q')),
                'period' => $this->normalizeString($request->query('period')) ?? 'all',
                'date_from' => $this->normalizeString($request->query('date_from')),
                'date_to' => $this->normalizeString($request->query('date_to')),
                'product_id' => $this->normalizeString($request->query('product_id')),
                'offer_id' => $this->normalizeString((string) ($request->query('offer_id') ?? '')),
                'payment_method' => $this->normalizeString($request->query('payment_method')) ?? 'all',
                'payment_status' => $this->normalizeString($request->query('payment_status')) ?? 'all',
                'utm_source' => $this->normalizeString($request->query('utm_source')),
                'utm_medium' => $this->normalizeString($request->query('utm_medium')),
                'utm_campaign' => $this->normalizeString($request->query('utm_campaign')),
            ],
            'products' => $products,
            'offers' => $offers,
        ]);
    }

    /**
     * Soma o lucro líquido de pedidos completed agrupado por moeda, respeitando os mesmos filtros da tela.
     *
     * @return list<array{currency: string, total: float}>
     */
    private function lucroLiquidoPorMoedaFromQuery($statsQuery, ProducerSaleAmount $producerSaleAmount, NetAmountCalculator $netAmountCalculator): array
    {
        $orders = (clone $statsQuery)
            ->where('orders.status', 'completed')
            ->with([
                'orderItems:id,order_id,amount',
                'commissionEntries:id,order_id,role,commission_amount',
            ])
            ->get(['orders.id', 'orders.tenant_id', 'orders.amount', 'orders.gateway', 'orders.metadata', 'orders.currency', 'orders.product_id']);

        if ($orders->isEmpty()) {
            return [];
        }

        $merged = [];
        foreach ($orders as $order) {
            $producerAmount = $producerSaleAmount->forOrder($order);
            $netProfitAmount = $producerAmount['is_producer_share']
                ? (float) $producerAmount['amount']
                : (float) $netAmountCalculator->forOrder($order)['net'];

            $currency = MoneyMinorUnits::normalizeCurrencyCode($order->getCurrencyOrDefault());
            $merged[$currency] = ($merged[$currency] ?? 0.0) + $netProfitAmount;
        }

        ksort($merged);

        return collect($merged)
            ->map(fn ($total, $currency) => [
                'currency' => $currency,
                'total' => round((float) $total, 2),
            ])
            ->values()
            ->all();
    }

    public function export(Request $request): StreamedResponse
    {
        $format = $request->query('format', 'csv');
        if (! in_array($format, ['csv', 'xls'], true)) {
            $format = 'csv';
        }

        $tenantId = auth()->user()->tenant_id;
        [$filteredQuery] = $this->buildFilteredQuery($request, $tenantId);

        $vendas = $filteredQuery
            ->with([
                'product:id,name',
                'user:id,name,email',
                'orderItems:id,order_id,amount',
                'commissionEntries:id,order_id,role,commission_amount',
            ])
            ->orderByDesc('created_at')
            ->get();

        $producerSaleAmount = app(ProducerSaleAmount::class);
        $netAmountCalculator = app(NetAmountCalculator::class);

        $rows = $vendas->map(function (Order $o) use ($producerSaleAmount, $netAmountCalculator) {
            $billedAmount = $o->lineItemsTotalAmount();
            $netProfitAmount = null;
            if ($o->status === 'completed') {
                $display = $producerSaleAmount->forOrder($o);
                $netProfitAmount = $display['is_producer_share']
                    ? (float) $display['amount']
                    : (float) $netAmountCalculator->forOrder($o)['net'];
            }

            return [
                'data' => $o->created_at?->format('d/m/Y H:i'),
                'produto' => $this->productDisplayName($o),
                'cliente' => $o->user?->name ?? $o->email ?? '–',
                'email' => $o->email ?? '–',
                'status' => $this->statusLabel($o->status),
                'gateway' => $o->paymentMethodDisplayLabel(),
                'moeda' => $o->getCurrencyOrDefault(),
                'valor_faturado' => number_format($billedAmount, 2, ',', '.'),
                'lucro_liquido' => $netProfitAmount === null ? '' : number_format($netProfitAmount, 2, ',', '.'),
            ];
        })->all();

        $headers = ['Data', 'Produto', 'Cliente', 'E-mail', 'Status', 'Método', 'Moeda', 'Valor faturado', 'Lucro líquido'];

        if ($format === 'csv') {
            $filename = 'vendas_'.date('Y-m-d_His').'.csv';

            return response()->streamDownload(function () use ($headers, $rows) {
                $out = fopen('php://output', 'w');
                fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8
                fputcsv($out, $headers, ';');
                foreach ($rows as $r) {
                    fputcsv($out, array_values($r), ';');
                }
                fclose($out);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        $filename = 'vendas_'.date('Y-m-d_His').'.xls';

        return response()->streamDownload(function () use ($headers, $rows) {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            $xml .= '<?mso-application progid="Excel.Sheet"?>'."\n";
            $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'."\n";
            $xml .= '<Worksheet ss:Name="Vendas">'."\n";
            $xml .= '<Table>'."\n";

            foreach (array_merge([$headers], array_map(fn ($r) => array_values($r), $rows)) as $row) {
                $xml .= '<Row>';
                foreach ($row as $cell) {
                    $cell = htmlspecialchars((string) $cell, ENT_XML1, 'UTF-8');
                    $xml .= '<Cell><Data ss:Type="String">'.$cell.'</Data></Cell>';
                }
                $xml .= '</Row>'."\n";
            }

            $xml .= '</Table></Worksheet></Workbook>';

            echo $xml;
        }, $filename, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }

    private function statusLabel(?string $status): string
    {
        $map = [
            'completed' => 'Pago',
            'pending' => 'Pendente',
            'disputed' => 'MED',
            'cancelled' => 'Cancelado',
            'refunded' => 'Reembolsado',
        ];

        return $map[$status ?? ''] ?? ($status ?? '–');
    }

    public function resendAccessEmail(Order $order, AccessEmailService $accessEmailService): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        if ($order->tenant_id !== $tenantId) {
            return response()->json(['success' => false, 'message' => 'Pedido não encontrado.'], 404);
        }

        if ($accessEmailService->sendForOrder($order, true)) {
            return response()->json(['success' => true]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Não foi possível reenviar o e-mail. Verifique se o produto possui template de e-mail configurado.',
        ], 422);
    }

    public function approveManually(Order $order): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        if ($order->tenant_id !== $tenantId) {
            return response()->json(['success' => false, 'message' => 'Pedido não encontrado.'], 404);
        }

        if ($order->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Só é possível aprovar pedidos com status pendente.',
            ], 422);
        }

        $order->load(['product', 'productOffer', 'subscriptionPlan', 'orderItems.product']);

        $previousStatus = (string) $order->status;
        $order->update(['status' => 'completed', 'approved_manually' => true]);
        event(new OrderStatusChanged($order->fresh(), $previousStatus, 'completed'));
        $order->syncUtmMetadataFromCheckoutSession();

        try {
            $order->grantPurchasedProductAccessToBuyer();

            if ($order->subscription_plan_id && $order->subscriptionPlan) {
                $plan = $order->subscriptionPlan;
                if ($order->is_renewal) {
                    $sub = Subscription::where('user_id', $order->user_id)
                        ->where('product_id', $order->product_id)
                        ->where('subscription_plan_id', $plan->id)
                        ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
                        ->first();
                    if ($sub) {
                        [$periodStart, $periodEnd] = $order->period_start && $order->period_end
                            ? [$order->period_start, $order->period_end]
                            : $plan->getCurrentPeriod();
                        $sub->update([
                            'status' => Subscription::STATUS_ACTIVE,
                            'current_period_start' => $periodStart,
                            'current_period_end' => $periodEnd,
                            'past_due_at' => null,
                        ]);
                        event(new SubscriptionRenewed($sub->fresh()));
                    }
                } else {
                    $exists = Subscription::where('user_id', $order->user_id)
                        ->where('product_id', $order->product_id)
                        ->where('subscription_plan_id', $plan->id)
                        ->where('status', Subscription::STATUS_ACTIVE)
                        ->exists();
                    if (! $exists) {
                        [$periodStart, $periodEnd] = $plan->getCurrentPeriod();
                        Subscription::create([
                            'tenant_id' => $order->tenant_id,
                            'user_id' => $order->user_id,
                            'product_id' => $order->product_id,
                            'subscription_plan_id' => $plan->id,
                            'status' => Subscription::STATUS_ACTIVE,
                            'current_period_start' => $periodStart,
                            'current_period_end' => $periodEnd,
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Pedido marcado como pago, mas houve um erro ao conceder acesso: '.$e->getMessage(),
            ], 500);
        }

        try {
            event(new OrderCompleted($order));
        } catch (\Throwable $e) {
            Log::error('VendasController::approve OrderCompleted falhou', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Pedido aprovado e acesso concedido. Houve falha ao disparar integrações (webhook/Meta/Utmify): '.$e->getMessage(),
            ]);
        }

        return response()->json(['success' => true, 'message' => 'Pedido aprovado. O e-mail de acesso foi enviado ao cliente.']);
    }

    public function refund(Order $order, RefundService $refundService): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        if ($order->tenant_id !== $tenantId) {
            return response()->json(['success' => false, 'message' => 'Pedido não encontrado.'], 404);
        }

        if (auth()->user()->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            if ($allowed !== [] && ! in_array($order->product_id, $allowed, true)) {
                return response()->json(['success' => false, 'message' => 'Sem permissão para este produto.'], 403);
            }
        }

        $validated = request()->validate([
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $result = $refundService->initiateRefundFromPanel(
                $order,
                auth()->user(),
                $validated['admin_notes'] ?? null
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? 'Não foi possível reembolsar.';

            return response()->json(['success' => false, 'message' => $message], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Erro ao processar reembolso.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'auto_cajupay_pix' => $result['auto_cajupay_pix'],
        ]);
    }

    private function productDisplayName(Order $order): string
    {
        $pluginLabel = \App\Plugins\Commerce\CommerceCheckoutContextRegistry::resolveOrderLabel($order);
        if ($pluginLabel !== null && $pluginLabel !== '') {
            return $pluginLabel;
        }

        $product = $order->product;
        if (! $product) {
            return '—';
        }
        $name = $product->name;
        if ($order->productOffer) {
            $name .= ' - '.$order->productOffer->name;
        } elseif ($order->subscriptionPlan) {
            $name .= ' - '.$order->subscriptionPlan->name;
        }

        return $name;
    }

    private function paymentTypeLabel(Order $order): string
    {
        if ($order->subscription_plan_id || $order->is_renewal) {
            return 'Pagamento recorrente';
        }

        return 'Pagamento único';
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Order>  $orders
     * @return \Illuminate\Support\Collection<string, array{code: string, name: string|null}>
     */
    private function affiliateLookupForOrders($orders): \Illuminate\Support\Collection
    {
        $productIds = $orders
            ->filter(fn (Order $o) => $o->saleChannel() === 'affiliate' && $o->affiliateCode() && $o->product_id)
            ->pluck('product_id')
            ->unique()
            ->values()
            ->all();

        if ($productIds === []) {
            return collect();
        }

        $lookup = collect();
        ProductAffiliate::query()
            ->with('user:id,name')
            ->whereIn('product_id', $productIds)
            ->where('status', ProductAffiliate::STATUS_APPROVED)
            ->get()
            ->each(function (ProductAffiliate $affiliate) use ($lookup) {
                $code = AffiliateAttribution::normalizeRef($affiliate->affiliate_code);
                if ($code === null) {
                    return;
                }
                $lookup->put($affiliate->product_id.':'.$code, [
                    'code' => $affiliate->affiliate_code,
                    'name' => $affiliate->user?->name,
                ]);
            });

        return $lookup;
    }
}
