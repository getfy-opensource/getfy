<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductOrderBump;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class OrderBumpReportService
{
    /**
     * @return array{products: list<array{id: string, name: string}>, selected_product_id: ?string, eligible_orders: int, accepted_items: int, bumps: list<array{id: int, title: string, target_name: string, sales_count: int, acceptance_rate: float}>}
     */
    public function build(?int $tenantId, ?string $requestedProductId, ?Carbon $start, ?Carbon $end): array
    {
        $productsQuery = Product::forTenant($tenantId)
            ->whereHas('orderBumps')
            ->orderBy('name');

        $products = $productsQuery->get(['id', 'name']);
        $selected = $requestedProductId
            ? $products->firstWhere('id', $requestedProductId)
            : $products->first();

        if (! $selected) {
            return [
                'products' => $this->productOptions($products),
                'selected_product_id' => null,
                'eligible_orders' => 0,
                'accepted_items' => 0,
                'bumps' => [],
            ];
        }

        $bumps = ProductOrderBump::query()
            ->where('product_id', $selected->id)
            ->with('targetProduct:id,name')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $eligibleOrders = \App\Models\Order::forTenant($tenantId)
            ->where('product_id', $selected->id)
            ->where('status', 'completed');
        $this->applyOrderBounds($eligibleOrders, $start, $end);
        $eligibleOrdersCount = $eligibleOrders->count();

        $signatureCounts = $bumps->countBy(fn (ProductOrderBump $bump) => $this->signature($bump));
        $rows = $bumps->map(function (ProductOrderBump $bump) use ($tenantId, $selected, $start, $end, $eligibleOrdersCount, $signatureCounts) {
            $directCount = $this->itemsQuery($tenantId, (string) $selected->id, $start, $end)
                ->where('order_items.product_order_bump_id', $bump->id)
                ->count();

            $legacyCount = 0;
            if (($signatureCounts[$this->signature($bump)] ?? 0) === 1) {
                $legacy = $this->itemsQuery($tenantId, (string) $selected->id, $start, $end)
                    ->whereNull('order_items.product_order_bump_id')
                    ->where('order_items.position', '>', 0)
                    ->where('order_items.product_id', $bump->target_product_id);

                $this->whereNullableId($legacy, 'order_items.product_offer_id', $bump->target_product_offer_id);
                $this->whereNullableId($legacy, 'order_items.subscription_plan_id', $bump->target_subscription_plan_id);
                $legacyCount = $legacy->count();
            }

            $salesCount = $directCount + $legacyCount;

            return [
                'id' => (int) $bump->id,
                'title' => (string) $bump->title,
                'target_name' => (string) ($bump->targetProduct?->name ?? 'Produto removido'),
                'sales_count' => $salesCount,
                'acceptance_rate' => $eligibleOrdersCount > 0
                    ? round($salesCount / $eligibleOrdersCount * 100, 1)
                    : 0.0,
            ];
        })->values();

        return [
            'products' => $this->productOptions($products),
            'selected_product_id' => (string) $selected->id,
            'eligible_orders' => $eligibleOrdersCount,
            'accepted_items' => (int) $rows->sum('sales_count'),
            'bumps' => $rows->all(),
        ];
    }

    private function itemsQuery(?int $tenantId, string $productId, ?Carbon $start, ?Carbon $end): Builder
    {
        $query = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.product_id', $productId)
            ->where('orders.status', 'completed')
            ->when($tenantId === null, fn ($q) => $q->whereNull('orders.tenant_id'), fn ($q) => $q->where('orders.tenant_id', $tenantId));

        $this->applyOrderBounds($query, $start, $end);

        return $query;
    }

    private function applyOrderBounds(Builder $query, ?Carbon $start, ?Carbon $end): void
    {
        if ($start && $end) {
            $query->whereBetween('orders.created_at', [$start, $end]);
        } elseif ($start) {
            $query->where('orders.created_at', '>=', $start);
        } elseif ($end) {
            $query->where('orders.created_at', '<=', $end);
        }
    }

    private function whereNullableId(Builder $query, string $column, mixed $value): void
    {
        $value === null ? $query->whereNull($column) : $query->where($column, $value);
    }

    private function signature(ProductOrderBump $bump): string
    {
        return implode(':', [
            $bump->target_product_id,
            $bump->target_product_offer_id ?? 'null',
            $bump->target_subscription_plan_id ?? 'null',
        ]);
    }

    /** @return list<array{id: string, name: string}> */
    private function productOptions(Collection $products): array
    {
        return $products->map(fn (Product $product) => [
            'id' => (string) $product->id,
            'name' => (string) $product->name,
        ])->values()->all();
    }
}
