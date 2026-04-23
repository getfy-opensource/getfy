<?php

namespace App\Listeners;

use App\Events\BoletoGenerated;
use App\Jobs\SendPanelPushJob;
use Illuminate\Support\Facades\Log;

class SendPanelPushOnBoletoGenerated
{
    public function handle(BoletoGenerated $event): void
    {
        $order = $event->order;

        try {
            $productName = $order->product?->name ?? 'Produto';
            $amount = number_format((float) $order->amount, 2, ',', '.');
            $title = 'Boleto gerado!';
            $body = "{$productName} - R$ {$amount} - Aguardando pagamento";
            $url = url('/vendas');

            SendPanelPushJob::dispatchAfterResponse(
                $order->tenant_id,
                'boleto_generated',
                $title,
                $body,
                $url,
                'boleto_' . $order->id
            );
        } catch (\Throwable $e) {
            Log::warning('SendPanelPushOnBoletoGenerated: falha ao enviar push', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
