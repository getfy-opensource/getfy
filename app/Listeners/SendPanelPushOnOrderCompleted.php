<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Jobs\SendPanelPushJob;
use Illuminate\Support\Facades\Log;

class SendPanelPushOnOrderCompleted
{
    public function handle(OrderCompleted $event): void
    {
        $order = $event->order;

        try {
            $productName = $order->product?->name ?? 'Produto';
            $amount = number_format((float) $order->amount, 2, ',', '.');
            $title = 'Venda aprovada!';
            $body = "{$productName} - R$ {$amount}";
            $url = url('/vendas?order=' . $order->id);

            SendPanelPushJob::dispatchAfterResponse(
                $order->tenant_id,
                'sale_approved',
                $title,
                $body,
                $url,
                'sale_' . $order->id
            );
        } catch (\Throwable $e) {
            Log::warning('SendPanelPushOnOrderCompleted: falha ao enviar push', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
