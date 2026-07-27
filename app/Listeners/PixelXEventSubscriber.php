<?php

namespace App\Listeners;

use App\Events\AccessDeliveryReady;
use App\Events\BoletoGenerated;
use App\Events\CartAbandoned;
use App\Events\OrderCancelled;
use App\Events\OrderCompleted;
use App\Events\OrderPending;
use App\Events\OrderRefunded;
use App\Events\OrderRejected;
use App\Events\PixGenerated;
use App\Events\SubscriptionCancelled;
use App\Events\SubscriptionCreated;
use App\Events\SubscriptionPastDue;
use App\Events\SubscriptionRenewed;
use App\Jobs\DispatchPixelXJob;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\PixelXIntegration;
use App\Models\Subscription;
use App\Support\WebhookPayloadBuilder;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PixelXEventSubscriber
{
    /**
     * Slugs suportados pela Pixel X (10 eventos mapeados).
     * Eventos sem mapeamento (pedido_cancelado, assinatura_em_atraso, envio_acesso) são ignorados.
     */
    private const SUPPORTED_SLUGS = [
        'pedido_pendente',
        'pedido_pago',
        'pagamento_recusado',
        'reembolso',
        'pix_gerado',
        'boleto_gerado',
        'carrinho_abandonado',
        'assinatura_criada',
        'assinatura_renovada',
        'assinatura_cancelada',
    ];

    /**
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        $eventClasses = array_keys(config('webhook_events.events', []));
        $map = [];
        foreach ($eventClasses as $class) {
            if (class_exists($class)) {
                $map[$class] = 'handleEvent';
            }
        }

        return $map;
    }

    public function handleEvent(object $event): void
    {
        $eventClass = $event::class;

        try {
            Log::debug('PixelXEventSubscriber: received event', [
                'event_class' => $eventClass,
            ]);

            if (! Schema::hasTable('pixel_x_integrations')) {
                Log::debug('PixelXEventSubscriber: pixel_x_integrations table missing, skipping');

                return;
            }

            // Verificar se o evento tem mapeamento na Pixel X
            $eventSlug = config('webhook_events.event_slugs')[$eventClass] ?? null;

            if ($eventSlug === null || ! in_array($eventSlug, self::SUPPORTED_SLUGS, true)) {
                Log::debug('PixelXEventSubscriber: event slug not supported, skipping', [
                    'event_class' => $eventClass,
                    'event_slug'  => $eventSlug,
                ]);

                return;
            }

            $tenantIds = $this->getTenantIdsFromEvent($event);

            if (empty($tenantIds)) {
                Log::debug('PixelXEventSubscriber: no tenant ids resolved', [
                    'event_class' => $eventClass,
                ]);

                return;
            }

            $productId = $this->getProductIdFromEvent($event);

            $integrations = PixelXIntegration::active()
                ->whereIn('tenant_id', $tenantIds)
                ->with('products')
                ->get();

            Log::debug('PixelXEventSubscriber: candidate integrations loaded', [
                'event_class' => $eventClass,
                'event_slug'  => $eventSlug,
                'tenant_ids'  => $tenantIds,
                'product_id'  => $productId,
                'count'       => $integrations->count(),
            ]);

            $payload = $this->buildPayload($event);
            $dispatchSync = $this->shouldDispatchSync($eventClass);

            foreach ($integrations as $integration) {
                if (! $integration->listensTo($eventClass) || ! $integration->shouldFireForProduct($productId)) {
                    continue;
                }

                try {
                    if ($dispatchSync) {
                        DispatchPixelXJob::dispatchAfterResponse($integration->id, $eventSlug, $payload);
                    } else {
                        DispatchPixelXJob::dispatch($integration->id, $eventSlug, $payload);
                    }
                } catch (\Throwable $e) {
                    Log::warning('PixelXEventSubscriber: failed to dispatch job', [
                        'integration_id' => $integration->id,
                        'event_class'    => $eventClass,
                        'event_slug'     => $eventSlug,
                        'tenant_id'      => $integration->tenant_id,
                        'message'        => $e->getMessage(),
                    ]);

                    report($e);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('PixelXEventSubscriber: failed to handle event', [
                'event_class' => $eventClass,
                'message'     => $e->getMessage(),
            ]);

            report($e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(object $event): array
    {
        if ($event instanceof OrderPending || $event instanceof OrderCompleted
            || $event instanceof OrderRejected || $event instanceof OrderCancelled
            || $event instanceof OrderRefunded || $event instanceof PixGenerated
            || $event instanceof BoletoGenerated) {
            $extras = [];
            if ($event instanceof PixGenerated && ! empty($event->pixData)) {
                $extras['pix'] = [
                    'qrcode'         => $event->pixData['qrcode'] ?? null,
                    'copy_paste'     => $event->pixData['copy_paste'] ?? null,
                    'transaction_id' => $event->pixData['transaction_id'] ?? null,
                ];
            }
            if ($event instanceof BoletoGenerated && ! empty($event->boletoData)) {
                $extras['boleto'] = [
                    'amount'    => $event->boletoData['amount'] ?? null,
                    'expire_at' => $event->boletoData['expire_at'] ?? null,
                    'barcode'   => $event->boletoData['barcode'] ?? null,
                    'pdf_url'   => $event->boletoData['pdf_url'] ?? null,
                ];
            }

            return WebhookPayloadBuilder::forOrderEvent($event->order, $extras);
        }

        if ($event instanceof AccessDeliveryReady) {
            return WebhookPayloadBuilder::forOrderEvent($event->order, WebhookPayloadBuilder::sanitizeExtras([
                'access' => is_array($event->access) ? $event->access : [],
            ]));
        }

        if ($event instanceof CartAbandoned) {
            return WebhookPayloadBuilder::forCartAbandoned($event->checkoutSession);
        }

        if ($event instanceof SubscriptionCreated || $event instanceof SubscriptionRenewed
            || $event instanceof SubscriptionCancelled || $event instanceof SubscriptionPastDue) {
            return WebhookPayloadBuilder::forSubscriptionEvent($event->subscription);
        }

        return [];
    }

    private function shouldDispatchSync(string $eventClass): bool
    {
        if (config('getfy.webhooks.dispatch_all_sync', false)) {
            return true;
        }

        if (config('getfy.webhooks.sync_critical_payment_events', true)
            && in_array($eventClass, [OrderCompleted::class, OrderPending::class], true)) {
            return true;
        }

        if (app()->environment('local')) {
            return true;
        }

        if (config('queue.default') === 'sync') {
            return true;
        }

        $heartbeat = Cache::get('queue_heartbeat');
        if (! is_string($heartbeat) || $heartbeat === '') {
            return true;
        }

        try {
            $last = \Illuminate\Support\Carbon::parse($heartbeat);
        } catch (\Throwable) {
            return true;
        }

        return $last->lt(now()->subMinutes(3));
    }

    /**
     * @return array<int|null>
     */
    private function getTenantIdsFromEvent(object $event): array
    {
        $ids = [];
        foreach ((array) $event as $value) {
            if ($value instanceof Model) {
                $tid = $value->getAttribute('tenant_id');

                Log::debug('PixelXEventSubscriber: inspecting model for tenant_id', [
                    'model'           => $value::class,
                    'id'              => $value->getKey(),
                    'tenant_id_attr'  => $tid,
                    'product_id_attr' => method_exists($value, 'getAttribute') ? $value->getAttribute('product_id') : null,
                ]);

                if ($tid === null) {
                    try {
                        if ($value instanceof Order) {
                            $value->loadMissing('product:id,tenant_id');
                            $tid = $value->product?->tenant_id;
                        } elseif ($value instanceof CheckoutSession) {
                            $value->loadMissing('product:id,tenant_id');
                            $tid = $value->product?->tenant_id;
                        } elseif ($value instanceof Subscription) {
                            $value->loadMissing('product:id,tenant_id');
                            $tid = $value->product?->tenant_id;
                        }
                    } catch (\Throwable $e) {
                        Log::debug('PixelXEventSubscriber: failed to infer tenant_id from related product', [
                            'model'   => $value::class,
                            'id'      => $value->getKey(),
                            'message' => $e->getMessage(),
                        ]);
                    }
                }

                if ($tid !== null) {
                    $ids[] = $tid;
                }
            }
            if ($value instanceof \Illuminate\Support\Collection) {
                foreach ($value as $item) {
                    if ($item instanceof Model) {
                        $tid = $item->getAttribute('tenant_id');
                        if ($tid !== null) {
                            $ids[] = $tid;
                        }
                    }
                }
            }
        }

        if (empty($ids) && auth()->check()) {
            $tid = auth()->user()->tenant_id;
            if ($tid !== null) {
                $ids[] = $tid;
            }
        }

        $ids = array_values(array_unique(array_filter($ids, fn ($v) => $v !== null)));

        return $ids;
    }

    private function getProductIdFromEvent(object $event): int|string|null
    {
        if ($event instanceof OrderPending || $event instanceof OrderCompleted
            || $event instanceof OrderRejected || $event instanceof OrderCancelled
            || $event instanceof OrderRefunded || $event instanceof PixGenerated
            || $event instanceof BoletoGenerated || $event instanceof AccessDeliveryReady) {
            return $event->order?->product_id;
        }

        if ($event instanceof CartAbandoned) {
            return $event->checkoutSession?->product_id;
        }

        if ($event instanceof SubscriptionCreated || $event instanceof SubscriptionRenewed
            || $event instanceof SubscriptionCancelled || $event instanceof SubscriptionPastDue) {
            return $event->subscription?->product_id;
        }

        return null;
    }
}
