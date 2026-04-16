<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\UtmifyIntegration;
use App\Services\UtmifyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UtmifySendOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public int $utmifyIntegrationId,
        public int $orderId,
        public string $utmifyStatus,
        public ?string $approvedAt = null,
        public ?string $refundedAt = null
    ) {}

    public function handle(UtmifyService $utmifyService): void
    {
        $integration = UtmifyIntegration::with('products:id')
            ->find($this->utmifyIntegrationId);

        if (! $integration || ! $integration->is_active || ! $integration->api_key) {
            return;
        }

        $order = Order::with(['user', 'product', 'orderItems.product', 'orderItems.productOffer', 'orderItems.subscriptionPlan'])
            ->find($this->orderId);

        if (! $order) {
            return;
        }

        try {
            $utmifyService->sendOrder($order, $this->utmifyStatus, $integration->api_key, [
                'approved_at' => $this->approvedAt,
                'refunded_at' => $this->refundedAt,
            ]);
        } catch (\Throwable $e) {
            // Falha na UTMfy não pode interromper checkout, webhooks nem outros fluxos (ex.: credencial inválida 404).
            Log::warning('UtmifySendOrderJob failed', [
                'order_id' => $this->orderId,
                'utmify_integration_id' => $this->utmifyIntegrationId,
                'status' => $this->utmifyStatus,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
