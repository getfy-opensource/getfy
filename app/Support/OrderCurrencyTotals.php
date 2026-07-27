<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderCurrencyTotals
{
    /**
     * Soma valores de pedidos completed agrupados por moeda (sem misturar moedas).
     *
     * Agrega no SQL em vez de hidratar todos os pedidos completed em memória.
     * Usa subquery de IDs (sem join) para não ambigüizar colunas como product_id/tenant_id
     * presentes no filtro da query original.
     *
     * @param  Builder<Order>  $statsQuery  Query já filtrada (tenant, período, etc.)
     * @return list<array{currency: string, total: float}>
     */
    public static function valorPorMoedaFromQuery(Builder $statsQuery): array
    {
        $hasCurrencyColumn = Schema::hasTable('orders') && Schema::hasColumn('orders', 'currency');
        $currencyExpr = $hasCurrencyColumn
            ? "COALESCE(NULLIF(UPPER(TRIM(orders.currency)), ''), 'BRL')"
            : "'BRL'";

        // 1) Só IDs — sem JOIN (evita "ambiguous column name: product_id").
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

        // 2) Agrega totais por pedido + moeda (JOIN só aqui, sobre orders.id).
        $perOrder = DB::table('orders')
            ->whereIn('orders.id', $idQuery)
            ->leftJoin('order_items', 'order_items.order_id', '=', 'orders.id')
            ->selectRaw(
                "orders.id as order_agg_id, {$currencyExpr} as currency_code, ".
                'COALESCE(SUM(order_items.amount), MAX(orders.amount)) as order_total'
            )
            ->groupByRaw($hasCurrencyColumn ? 'orders.id, orders.currency' : 'orders.id');

        $rows = DB::query()
            ->fromSub($perOrder, 'order_currency_totals')
            ->selectRaw('currency_code, SUM(order_total) as total')
            ->groupBy('currency_code')
            ->orderBy('currency_code')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'currency' => (string) ($row->currency_code ?: 'BRL'),
                'total' => round((float) $row->total, 2),
            ];
        }

        return $out;
    }
}
