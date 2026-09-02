<?php

namespace App\Support;

use App\Models\Order;
use App\Services\NetAmountCalculator;
use Illuminate\Database\Eloquent\Builder;

class OrderFinancialTotals
{
    /**
     * Agrega bruto, taxas e líquido por moeda para pedidos completed na query filtrada.
     *
     * @param  Builder<Order>  $statsQuery
     * @return list<array{currency: string, gross: float, fees: float, net: float}>
     */
    public static function porMoedaFromQuery(Builder $statsQuery): array
    {
        $grossRows = OrderCurrencyTotals::valorPorMoedaFromQuery($statsQuery);
        if ($grossRows === []) {
            return [];
        }

        $idQuery = (clone $statsQuery)
            ->reorder()
            ->where('orders.status', 'completed');

        $idQuery->getQuery()->columns = null;
        $idQuery->getQuery()->groups = null;
        $idQuery->getQuery()->orders = null;
        $idQuery->getQuery()->limit = null;
        $idQuery->getQuery()->offset = null;
        $idQuery->getQuery()->unionOrders = null;
        $idQuery->select('orders.id');

        $calculator = app(NetAmountCalculator::class);
        $feesByCurrency = [];
        $netByCurrency = [];

        Order::query()
            ->whereIn('id', $idQuery)
            ->with([
                'orderItems:id,order_id,amount',
                'commissionEntries:id,order_id,role,gateway_fee_amount,net_amount',
            ])
            ->select(['id', 'amount', 'currency', 'gateway', 'metadata', 'tenant_id'])
            ->orderBy('id')
            ->chunkById(200, function ($orders) use ($calculator, &$feesByCurrency, &$netByCurrency) {
                foreach ($orders as $order) {
                    $breakdown = $calculator->forOrder($order);
                    $currency = $order->getCurrencyOrDefault();
                    $feesByCurrency[$currency] = ($feesByCurrency[$currency] ?? 0.0) + $breakdown['fee'];
                    $netByCurrency[$currency] = ($netByCurrency[$currency] ?? 0.0) + $breakdown['net'];
                }
            });

        $out = [];
        foreach ($grossRows as $row) {
            $currency = $row['currency'];
            $gross = (float) $row['total'];
            $fees = round($feesByCurrency[$currency] ?? 0.0, 2);
            $net = round($netByCurrency[$currency] ?? max(0, $gross - $fees), 2);
            $out[] = [
                'currency' => $currency,
                'gross' => round($gross, 2),
                'fees' => $fees,
                'net' => $net,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{currency: string, gross: float, fees: float, net: float}>  $rows
     * @return array{gross: float, fees: float, net: float}
     */
    public static function brlTotals(array $rows): array
    {
        $brl = collect($rows)->firstWhere('currency', 'BRL');

        return [
            'gross' => round((float) ($brl['gross'] ?? 0.0), 2),
            'fees' => round((float) ($brl['fees'] ?? 0.0), 2),
            'net' => round((float) ($brl['net'] ?? 0.0), 2),
        ];
    }

    /**
     * Agrega bruto, taxas e líquido por gateway (slug) para pedidos completed.
     *
     * @param  Builder<Order>  $statsQuery
     * @return list<array{metodo: string, gross: float, fees: float, net: float, quantidade: int}>
     */
    public static function porGatewayFromQuery(Builder $statsQuery): array
    {
        $idQuery = (clone $statsQuery)
            ->reorder()
            ->where('orders.status', 'completed');

        $idQuery->getQuery()->columns = null;
        $idQuery->getQuery()->groups = null;
        $idQuery->getQuery()->orders = null;
        $idQuery->getQuery()->limit = null;
        $idQuery->getQuery()->offset = null;
        $idQuery->getQuery()->unionOrders = null;
        $idQuery->select('orders.id');

        $calculator = app(NetAmountCalculator::class);
        $byGateway = [];

        Order::query()
            ->whereIn('id', $idQuery)
            ->with([
                'orderItems:id,order_id,amount',
                'commissionEntries:id,order_id,role,gateway_fee_amount,net_amount',
            ])
            ->select(['id', 'amount', 'currency', 'gateway', 'metadata', 'tenant_id'])
            ->orderBy('id')
            ->chunkById(200, function ($orders) use ($calculator, &$byGateway) {
                foreach ($orders as $order) {
                    $breakdown = $calculator->forOrder($order);
                    $gateway = strtolower((string) ($order->gateway ?? 'outro'));
                    if ($gateway === '') {
                        $gateway = 'outro';
                    }
                    if (! isset($byGateway[$gateway])) {
                        $byGateway[$gateway] = ['gross' => 0.0, 'fees' => 0.0, 'net' => 0.0, 'quantidade' => 0];
                    }
                    $byGateway[$gateway]['gross'] += $breakdown['gross'];
                    $byGateway[$gateway]['fees'] += $breakdown['fee'];
                    $byGateway[$gateway]['net'] += $breakdown['net'];
                    $byGateway[$gateway]['quantidade']++;
                }
            });

        $out = [];
        foreach ($byGateway as $metodo => $row) {
            $out[] = [
                'metodo' => $metodo,
                'gross' => round($row['gross'], 2),
                'fees' => round($row['fees'], 2),
                'net' => round($row['net'], 2),
                'quantidade' => $row['quantidade'],
            ];
        }

        usort($out, fn ($a, $b) => $b['gross'] <=> $a['gross']);

        return $out;
    }
}
