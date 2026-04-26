<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Events\AccessDeliveryReady;
use App\Services\AccessEmailService;
use Illuminate\Support\Facades\Log;

class SendAccessEmailOnOrderCompleted
{
    public function __construct(
        protected AccessEmailService $accessEmailService
    ) {}

    public function handle(OrderCompleted $event): void
    {
        $order = $event->order;
        Log::info('SendAccessEmailOnOrderCompleted: disparando envio de e-mail de acesso.', ['order_id' => $order->id]);

        try {
            // Dispara evento de WhatsApp (AutoZap) com dados prontos de acesso (best-effort).
            $access = $this->accessEmailService->getAccessDataForOrder($order);
            if (is_array($access)) {
                AccessDeliveryReady::dispatch($order, $access);
            }

            $sent = $this->accessEmailService->sendForOrder($order);
            if (! $sent) {
                Log::warning('SendAccessEmailOnOrderCompleted: sendForOrder retornou false.', ['order_id' => $order->id]);
            }
        } catch (\Throwable $e) {
            Log::error('SendAccessEmailOnOrderCompleted: exceção ao enviar e-mail.', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
