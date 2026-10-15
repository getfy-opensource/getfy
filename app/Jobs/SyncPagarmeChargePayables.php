<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\PagarmeFinanceSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Lê os recebíveis da cobrança na Pagar.me e grava a taxa real no pedido (e no lançamento do produtor).
 * Disparado pelo webhook; a taxa nunca vem do corpo do webhook.
 */
class SyncPagarmeChargePayables implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $orderId) {}

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(PagarmeFinanceSync $sync): void
    {
        $order = Order::find($this->orderId);
        if ($order) {
            $sync->syncCharge($order);
        }
    }
}
