<?php

namespace App\Http\Controllers;

use App\Events\OrderCompleted;
use App\Models\Order;
use App\Models\Subscription;
use App\Services\AccessEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VendasController extends Controller
{
    private const STATUS_FILTERS = ['aprovadas', 'med', 'todas'];

    public function index(Request $request): InertiaResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $statusFilter = $request->query('status_filter', 'todas');
        if (! in_array($statusFilter, self::STATUS_FILTERS, true)) {
            $statusFilter = 'todas';
        }

        $baseQuery = Order::forTenant($tenantId);

        $filteredQuery = match ($statusFilter) {
            'aprovadas' => (clone $baseQuery)->where('status', 'completed'),
            'med' => (clone $baseQuery)->where('status', 'disputed'),
            default => clone $baseQuery,
        };

        $vendas = $filteredQuery
            ->with(['product:id,name,slug,checkout_slug', 'user:id,name,email', 'productOffer:id,name,checkout_slug', 'subscriptionPlan:id,name,checkout_slug'])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(function (Order $o) {
                $arr = $o->toArray();
                $arr['gateway_label'] = $this->gatewayLabel($o->gateway);
                $arr['payment_method_label'] = $this->paymentMethodLabel($o->payment_method, $o->gateway);
                $arr['product_display_name'] = $this->productDisplayName($o);
                $arr['checkout_url'] = url('/c/' . $o->getCheckoutSlug());
                $arr['payment_type_label'] = $this->paymentTypeLabel($o);

                return $arr;
            });

        $statsQuery = match ($statusFilter) {
            'aprovadas' => (clone $baseQuery)->where('status', 'completed'),
            'med' => (clone $baseQuery)->where('status', 'disputed'),
            default => clone $baseQuery,
        };

        $vendasEncontradas = (clone $statsQuery)->count();

        $valorLiquido = (float) (clone $statsQuery)
            ->where('status', 'completed')
            ->sum('amount');

        $vendasPix = (clone $statsQuery)
            ->where(function ($q) {
                $q->where('payment_method', 'pix')
                    ->orWhere(function ($q2) {
                        $q2->whereNull('payment_method')
                            ->whereIn('gateway', ['spacepag', 'sapcepag']);
                    });
            })
            ->count();

        $vendasCartao = (clone $statsQuery)
            ->where(function ($q) {
                $q->where('payment_method', 'card')
                    ->orWhere(function ($q2) {
                        $q2->whereNull('payment_method')
                            ->where(function ($q3) {
                                $q3->where('gateway', 'card')
                                    ->orWhereRaw("LOWER(gateway) LIKE '%card%'")
                                    ->orWhereRaw("LOWER(gateway) LIKE '%cartao%'")
                                    ->orWhereRaw("LOWER(gateway) LIKE '%credito%'");
                            });
                    });
            })
            ->count();

        $vendasBoleto = (clone $statsQuery)
            ->where(function ($q) {
                $q->where('payment_method', 'boleto')
                    ->orWhere(function ($q2) {
                        $q2->whereNull('payment_method')
                            ->where(function ($q3) {
                                $q3->where('gateway', 'boleto')
                                    ->orWhereRaw("LOWER(gateway) LIKE '%boleto%'");
                            });
                    });
            })
            ->count();

        $stats = [
            'vendas_encontradas' => $vendasEncontradas,
            'valor_liquido' => round($valorLiquido, 2),
            'vendas_pix' => $vendasPix,
            'vendas_cartao' => $vendasCartao,
            'vendas_boleto' => $vendasBoleto,
        ];

        return Inertia::render('Vendas/Index', [
            'vendas' => $vendas,
            'stats' => $stats,
            'status_filter' => $statusFilter,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $format = $request->query('format', 'csv');
        if (! in_array($format, ['csv', 'xls'], true)) {
            $format = 'csv';
        }

        $statusFilter = $request->query('status_filter', 'todas');
        if (! in_array($statusFilter, self::STATUS_FILTERS, true)) {
            $statusFilter = 'todas';
        }

        $tenantId = auth()->user()->tenant_id;
        $baseQuery = Order::forTenant($tenantId);

        $filteredQuery = match ($statusFilter) {
            'aprovadas' => (clone $baseQuery)->where('status', 'completed'),
            'med' => (clone $baseQuery)->where('status', 'disputed'),
            default => clone $baseQuery,
        };

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
        return match ($gateway) {
            'stripe' => 'Stripe',
            'mercadopago' => 'Mercado Pago',
            'asaas' => 'Asaas',
            'efi' => 'Efí',
            'pushinpay' => 'PushinPay',
            'spacepag', 'sapcepag' => 'SpacePag',
            'manual' => 'Manual',
            null, '' => '–',
            default => ucfirst($gateway),
        };
    }

    private function paymentMethodLabel(?string $paymentMethod, ?string $gateway): string
    {
        // Se a coluna payment_method existe, usar diretamente
        if ($paymentMethod) {
            return match ($paymentMethod) {
                'pix' => 'PIX',
                'card' => 'Cartão',
                'boleto' => 'Boleto',
                'manual' => 'Manual',
                default => ucfirst($paymentMethod),
            };
        }

        // Fallback para pedidos antigos sem payment_method: inferir do gateway
        if ($gateway === null || $gateway === '') {
            return '–';
        }
        $g = strtolower($gateway);
        if (in_array($g, ['spacepag', 'sapcepag'], true) || str_contains($g, 'pix')) {
            return 'PIX';
        }
        if ($g === 'card' || str_contains($g, 'cartao') || str_contains($g, 'credito')) {
            return 'Cartão';
        }
        if ($g === 'boleto' || str_contains($g, 'boleto')) {
            return 'Boleto';
        }
        if ($g === 'manual') {
            return 'Manual';
        }

        return '–';
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
