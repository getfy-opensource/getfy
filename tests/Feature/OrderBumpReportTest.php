<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductOrderBump;
use App\Models\User;
use App\Services\OrderBumpReportService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderBumpReportTest extends TestCase
{
    public function test_report_counts_direct_and_legacy_sales_and_keeps_zero_sales_bumps(): void
    {
        $main = $this->createTestProduct(['name' => 'Produto principal']);
        $targetA = $this->createTestProduct(['name' => 'Bônus A']);
        $targetB = $this->createTestProduct(['name' => 'Bônus B']);

        $bumpA = ProductOrderBump::create([
            'product_id' => $main->id,
            'target_product_id' => $targetA->id,
            'title' => 'Order Bump A',
            'cta_title' => 'Adicionar A',
            'position' => 0,
        ]);
        $bumpB = ProductOrderBump::create([
            'product_id' => $main->id,
            'target_product_id' => $targetB->id,
            'title' => 'Order Bump B',
            'cta_title' => 'Adicionar B',
            'position' => 1,
        ]);

        $directOrder = $this->completedOrder($main->id, now()->subDay());
        OrderItem::create([
            'order_id' => $directOrder->id,
            'product_id' => $targetA->id,
            'product_order_bump_id' => $bumpA->id,
            'amount' => 20,
            'position' => 1,
        ]);

        $legacyOrder = $this->completedOrder($main->id, now()->subDays(2));
        OrderItem::create([
            'order_id' => $legacyOrder->id,
            'product_id' => $targetA->id,
            'amount' => 20,
            'position' => 1,
        ]);

        $this->completedOrder($main->id, now()->subDays(3));
        $outsidePeriod = $this->completedOrder($main->id, now()->subDays(40));
        OrderItem::create([
            'order_id' => $outsidePeriod->id,
            'product_id' => $targetA->id,
            'product_order_bump_id' => $bumpA->id,
            'amount' => 20,
            'position' => 1,
        ]);

        $report = app(OrderBumpReportService::class)->build(
            1,
            (string) $main->id,
            now()->subDays(7)->startOfDay(),
            now()->endOfDay(),
        );

        $this->assertSame(3, $report['eligible_orders']);
        $this->assertSame(2, $report['accepted_items']);
        $this->assertCount(2, $report['bumps']);
        $this->assertSame(2, $report['bumps'][0]['sales_count']);
        $this->assertSame(66.7, $report['bumps'][0]['acceptance_rate']);
        $this->assertSame(0, $report['bumps'][1]['sales_count']);
        $this->assertSame(0.0, $report['bumps'][1]['acceptance_rate']);
        $this->assertSame('Bônus B', $report['bumps'][1]['target_name']);
    }

    public function test_report_does_not_mix_other_tenants(): void
    {
        $main = $this->createTestProduct(['tenant_id' => 1]);
        $target = $this->createTestProduct(['tenant_id' => 1]);
        $bump = ProductOrderBump::create([
            'product_id' => $main->id,
            'target_product_id' => $target->id,
            'title' => 'Bump',
            'cta_title' => 'Adicionar',
        ]);

        $foreignOrder = $this->completedOrder($main->id, now(), 2);
        OrderItem::create([
            'order_id' => $foreignOrder->id,
            'product_id' => $target->id,
            'product_order_bump_id' => $bump->id,
            'amount' => 10,
            'position' => 1,
        ]);

        $report = app(OrderBumpReportService::class)->build(1, (string) $main->id, null, null);

        $this->assertSame(0, $report['eligible_orders']);
        $this->assertSame(0, $report['bumps'][0]['sales_count']);
    }

    public function test_reports_page_exposes_order_bumps_for_custom_period(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);
        $main = $this->createTestProduct(['name' => 'Produto do relatório']);
        $target = $this->createTestProduct(['name' => 'Oferta adicional']);
        ProductOrderBump::create([
            'product_id' => $main->id,
            'target_product_id' => $target->id,
            'title' => 'Bump do relatório',
            'cta_title' => 'Adicionar',
        ]);

        $this->actingAs($user)
            ->get('/relatorios?period=personalizado&date_from=2026-07-01&date_to=2026-07-31&order_bump_product_id='.$main->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Relatorios/Index')
                ->where('period', 'personalizado')
                ->where('date_from', '2026-07-01')
                ->where('date_to', '2026-07-31')
                ->where('order_bump_report.selected_product_id', (string) $main->id)
                ->where('order_bump_report.bumps.0.title', 'Bump do relatório')
                ->where('order_bump_report.bumps.0.sales_count', 0)
            );
    }

    private function completedOrder(string $productId, \DateTimeInterface $createdAt, int $tenantId = 1): Order
    {
        $order = Order::create([
            'tenant_id' => $tenantId,
            'product_id' => $productId,
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'BRL',
            'email' => uniqid('buyer-', true).'@example.com',
            'gateway' => 'manual',
        ]);

        $order->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $order->fresh();
    }
}
