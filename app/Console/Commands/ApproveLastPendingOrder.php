<?php

namespace App\Console\Commands;

use App\Events\OrderCompleted;
use App\Models\Order;
use App\Models\Product;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ApproveLastPendingOrder extends Command
{
    protected $signature = 'checkout:approve-last-pending
                            {--order= : ID do pedido a aprovar (se já estiver completed, só gera o link do upsell)}
                            {--tenant= : ID do tenant (opcional)}';

    protected $description = 'Aprova o último pedido com status pending (para testar upsell/flow pós-pagamento).';

    public function handle(): int
    {
        $orderId = $this->option('order');
        if ($orderId !== null) {
            $order = Order::with('product', 'productOffer', 'subscriptionPlan', 'orderItems')->find($orderId);
            if (! $order) {
                $this->error("Pedido #{$orderId} não encontrado.");
                return self::FAILURE;
            }
            $alreadyCompleted = $order->status === 'completed';
        } else {
            $query = Order::with('product', 'productOffer', 'subscriptionPlan', 'orderItems')
                ->where('status', 'pending')
                ->orderByDesc('created_at');

            if ($this->option('tenant') !== null) {
                $query->where('tenant_id', (int) $this->option('tenant'));
            }

            $order = $query->first();
            $alreadyCompleted = false;
        }

        if (! $order) {
            $this->error('Nenhum pedido pendente encontrado.');

            return self::FAILURE;
        }

        $this->info("Pedido #{$order->id} (produto: {$order->product?->name}, valor: R$ " . number_format((float) $order->amount, 2, ',', '.') . ')');

        if (! $alreadyCompleted) {
            $order->update(['status' => 'completed']);

            try {
                $order->loadMissing('orderItems.product');
                $order->grantPurchasedProductAccessToBuyer();

                if ($order->subscription_plan_id && $order->subscriptionPlan) {
                    $plan = $order->subscriptionPlan;
                    if (! $order->is_renewal && ! Subscription::where('user_id', $order->user_id)->where('product_id', $order->product_id)->where('subscription_plan_id', $plan->id)->where('status', Subscription::STATUS_ACTIVE)->exists()) {
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
                $this->warn('Aviso: ' . $e->getMessage());
                $this->line('(Pedido já foi marcado como concluído; link do upsell abaixo.)');
            }
        } else {
            $this->line('Pedido já estava aprovado. Gerando link do upsell.');
        }

        $config = $this->getOrderCheckoutConfig($order);
        $upsell = $config['upsell'] ?? [];
        if (! empty($upsell['enabled']) && ! empty($upsell['products']) && is_array($upsell['products'])) {
            $upsellToken = Str::random(64);
            Cache::put('upsell_token.' . $upsellToken, [
                'order_id' => $order->id,
                'gateway' => 'pix',
            ], now()->addMinutes(60));
            $url = route('checkout.upsell', ['token' => $upsellToken]);
            $this->newLine();
            $this->info('Pedido aprovado. Para testar o upsell, acesse:');
            $this->line($url);
        } else {
            $this->newLine();
            $this->info('Pedido aprovado. Upsell não está configurado para este produto.');
        }

        return self::SUCCESS;
    }

    private function getOrderCheckoutConfig(Order $order): array
    {
        if ($order->subscriptionPlan?->checkout_config) {
            return array_replace_recursive(Product::defaultCheckoutConfig(), $order->subscriptionPlan->checkout_config);
        }
        if ($order->productOffer?->checkout_config) {
            return array_replace_recursive(Product::defaultCheckoutConfig(), $order->productOffer->checkout_config);
        }

        return $order->product?->checkout_config ?? [];
    }
}
