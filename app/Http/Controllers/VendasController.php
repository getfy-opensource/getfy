<?php

namespace App\Http\Controllers;

use App\Events\OrderCompleted;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Subscription;
use App\Services\AccessEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $now = now();
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
            $start = $now->copy()->startOfMonth();
            $end = $now->copy()->endOfDay();
        } elseif ($period === 'last_month') {
            $start = $now->copy()->subMonthNoOverflow()->startOfMonth();
            $end = $now->copy()->subMonthNoOverflow()->endOfMonth();
        } elseif ($period === 'custom') {
            $start = $from ? \Illuminate\Support\Carbon::parse($from)->startOfDay() : null;
            $end = $to ? \Illuminate\Support\Carbon::parse($to)->endOfDay() : null;
        }

        if ($start && $end) {
            return $query->whereBetween('created_at', [$start, $end]);
        }
        if ($start) {
            return $query->where('created_at', '>=', $start);
        }
        if ($end) {
            return $query->where('created_at', '<=', $end);
        }
        return $query;
    }

    private function applySearchFilter($query, Request $request)
    {
        $q = $this->normalizeString($request->query('q'));
        if ($q === null) {
            return $query;
        }
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';

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
                    $q->whereIn('gateway', ['spacepag', 'sapcepag'])
                        ->orWhereRaw("LOWER(gateway) LIKE '%pix%'");
                });
            } elseif ($m === 'card') {
                $query->where(function ($q) {
                    $q->where('gateway', 'card')
                        ->orWhereRaw("LOWER(gateway) LIKE '%card%'")
                        ->orWhereRaw("LOWER(gateway) LIKE '%cartao%'")
                        ->orWhereRaw("LOWER(gateway) LIKE '%cartão%'")
                        ->orWhereRaw("LOWER(gateway) LIKE '%credito%'");
                });
            } elseif ($m === 'boleto') {
                $query->where(function ($q) {
                    $q->where('gateway', 'boleto')
                        ->orWhereRaw("LOWER(gateway) LIKE '%boleto%'");
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

        return $query->whereExists(function ($q) use ($tenantId, $utmSource, $utmMedium, $utmCampaign) {
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
    }

    private function buildFilteredQuery(Request $request, ?int $tenantId)
    {
        $statusFilter = $this->normalizeStatusFilter($request);
        $query = Order::forTenant($tenantId);
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

        $vendas = $filteredQuery
            ->with(['product:id,name,slug,checkout_slug', 'user:id,name,email', 'productOffer:id,name,checkout_slug', 'subscriptionPlan:id,name,checkout_slug'])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(function (Order $o) {
                $arr = $o->toArray();
                $arr['gateway_label'] = $this->gatewayLabel($o->gateway);
                $arr['product_display_name'] = $this->productDisplayName($o);
                $arr['checkout_url'] = url('/c/' . $o->getCheckoutSlug());
                $arr['payment_type_label'] = $this->paymentTypeLabel($o);

                return $arr;
            });

        [$statsQuery] = $this->buildFilteredQuery($request, $tenantId);

        $vendasEncontradas = (clone $statsQuery)->count();

        $valorLiquido = (float) (clone $statsQuery)
            ->where('status', 'completed')
            ->sum('amount');

        $vendasPix = (clone $statsQuery)
            ->where(function ($q) {
                $q->whereIn('gateway', ['spacepag', 'sapcepag'])
                    ->orWhereRaw("LOWER(gateway) LIKE '%pix%'");
            })
            ->count();

        $vendasCartao = (clone $statsQuery)
            ->where(function ($q) {
                $q->where('gateway', 'card')
                    ->orWhereRaw("LOWER(gateway) LIKE '%card%'")
                    ->orWhereRaw("LOWER(gateway) LIKE '%cartao%'")
                    ->orWhereRaw("LOWER(gateway) LIKE '%cartão%'")
                    ->orWhereRaw("LOWER(gateway) LIKE '%credito%'");
            })
            ->count();

        $vendasBoleto = (clone $statsQuery)
            ->where(function ($q) {
                $q->where('gateway', 'boleto')
                    ->orWhereRaw("LOWER(gateway) LIKE '%boleto%'");
            })
            ->count();

        $stats = [
            'vendas_encontradas' => $vendasEncontradas,
            'valor_liquido' => round($valorLiquido, 2),
            'vendas_pix' => $vendasPix,
            'vendas_cartao' => $vendasCartao,
            'vendas_boleto' => $vendasBoleto,
        ];

        $products = Product::forTenant($tenantId)->orderBy('name')->get(['id', 'name']);
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

        return Inertia::render('Vendas/Index', [
            'vendas' => $vendas,
            'stats' => $stats,
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

    public function export(Request $request): StreamedResponse
    {
        $format = $request->query('format', 'csv');
        if (! in_array($format, ['csv', 'xls'], true)) {
            $format = 'csv';
        }

        $tenantId = auth()->user()->tenant_id;
        [$filteredQuery] = $this->buildFilteredQuery($request, $tenantId);

        $vendas = $filteredQuery
            ->with(['product:id,name', 'user:id,name,email'])
            ->orderByDesc('created_at')
            ->get();

        $rows = $vendas->map(function (Order $o) {
            return [
                'data' => $o->created_at?->format('d/m/Y H:i'),
                'produto' => $this->productDisplayName($o),
                'cliente' => $o->user?->name ?? $o->email ?? '–',
                'email' => $o->email ?? '–',
                'status' => $this->statusLabel($o->status),
                'gateway' => $this->gatewayLabel($o->gateway),
                'valor_liquido' => number_format((float) $o->amount, 2, ',', '.'),
            ];
        })->all();

        $headers = ['Data', 'Produto', 'Cliente', 'E-mail', 'Status', 'Método', 'Valor líquido'];

        if ($format === 'csv') {
            $filename = 'vendas_' . date('Y-m-d_His') . '.csv';

            return response()->streamDownload(function () use ($headers, $rows) {
                $out = fopen('php://output', 'w');
                fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8
                fputcsv($out, $headers, ';');
                foreach ($rows as $r) {
                    fputcsv($out, array_values($r), ';');
                }
                fclose($out);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        $filename = 'vendas_' . date('Y-m-d_His') . '.xls';

        return response()->streamDownload(function () use ($headers, $rows) {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            $xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
            $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
            $xml .= '<Worksheet ss:Name="Vendas">' . "\n";
            $xml .= '<Table>' . "\n";

            foreach (array_merge([$headers], array_map(fn ($r) => array_values($r), $rows)) as $row) {
                $xml .= '<Row>';
                foreach ($row as $cell) {
                    $cell = htmlspecialchars((string) $cell, ENT_XML1, 'UTF-8');
                    $xml .= '<Cell><Data ss:Type="String">' . $cell . '</Data></Cell>';
                }
                $xml .= '</Row>' . "\n";
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

        if ($accessEmailService->sendForOrder($order)) {
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

        $order->update(['status' => 'completed', 'approved_manually' => true]);

        try {
            if ($order->product) {
                $order->product->users()->syncWithoutDetaching([$order->user_id]);
            }
            foreach ($order->orderItems as $item) {
                if ($item->product) {
                    $item->product->users()->syncWithoutDetaching([$order->user_id]);
                }
            }

            if ($order->subscription_plan_id && $order->subscriptionPlan) {
                $plan = $order->subscriptionPlan;
                $exists = Subscription::where('user_id', $order->user_id)
                    ->where('product_id', $order->product_id)
                    ->where('subscription_plan_id', $plan->id)
                    ->where('status', Subscription::STATUS_ACTIVE)
                    ->exists();
                if (! $order->is_renewal && ! $exists) {
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

            event(new OrderCompleted($order));
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Pedido marcado como pago, mas houve um erro ao conceder acesso: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json(['success' => true, 'message' => 'Pedido aprovado. O e-mail de acesso foi enviado ao cliente.']);
    }

    private function gatewayLabel(?string $gateway): string
    {
        if ($gateway === null || $gateway === '') {
            return 'Outro';
        }
        $g = strtolower($gateway);
        if (in_array($g, ['spacepag', 'sapcepag'], true) || str_contains($g, 'pix')) {
            return 'PIX';
        }
        if ($g === 'card' || str_contains($g, 'cartao') || str_contains($g, 'cartão') || str_contains($g, 'credito')) {
            return 'Cartão';
        }
        if ($g === 'boleto' || str_contains($g, 'boleto')) {
            return 'Boleto';
        }
        if ($g === 'manual') {
            return 'Manual';
        }

        return ucfirst($gateway);
    }

    private function productDisplayName(Order $order): string
    {
        $product = $order->product;
        if (! $product) {
            return '—';
        }
        $name = $product->name;
        if ($order->productOffer) {
            $name .= ' - ' . $order->productOffer->name;
        } elseif ($order->subscriptionPlan) {
            $name .= ' - ' . $order->subscriptionPlan->name;
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
}
