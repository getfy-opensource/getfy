<?php

namespace App\Listeners;

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
use App\Jobs\DispatchWebhookJob;
use App\Models\Webhook;
use App\Models\Order;
use App\Models\CheckoutSession;
use App\Models\Subscription;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class WebhookEventSubscriber
{
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
            Log::debug('WebhookEventSubscriber: received event', [
                'event_class' => $eventClass,
            ]);

            $tenantIds = $this->getTenantIdsFromEvent($event);

            if (empty($tenantIds)) {
                Log::debug('WebhookEventSubscriber: no tenant ids resolved', [
                    'event_class' => $eventClass,
                ]);
                return;
            }

            $productId = $this->getProductIdFromEvent($event);

            $webhooks = Webhook::active()
                ->where(function ($q) use ($tenantIds) {
                    $q->whereIn('tenant_id', $tenantIds)
                        ->orWhereNull('tenant_id');
                })
                ->with('products')
                ->get();

            Log::debug('WebhookEventSubscriber: candidate webhooks loaded', [
                'event_class' => $eventClass,
                'tenant_ids' => $tenantIds,
                'product_id' => $productId,
                'count' => $webhooks->count(),
            ]);

            $payload = $this->serializeEventPayload($event);
            $payload = $this->enrichPayload($event, $payload);
            $dispatchSync = $this->shouldDispatchSync();

            foreach ($webhooks as $webhook) {
                if (! $webhook->listensTo($eventClass) || ! $webhook->shouldFireForProduct($productId)) {
                    continue;
                }

                try {
                    if ($dispatchSync) {
                        (new DispatchWebhookJob($webhook->id, $eventClass, $payload))->handle();
                    } else {
                        DispatchWebhookJob::dispatch($webhook->id, $eventClass, $payload);
                    }
                } catch (\Throwable $e) {
                    Log::warning('WebhookEventSubscriber: failed to dispatch webhook', [
                        'webhook_id' => $webhook->id,
                        'event_class' => $eventClass,
                        'tenant_id' => $webhook->tenant_id,
                        'message' => $e->getMessage(),
                    ]);

                    report($e);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('WebhookEventSubscriber: failed to handle event', [
                'event_class' => $eventClass,
                'message' => $e->getMessage(),
            ]);

            report($e);
        }
    }

    private function shouldDispatchSync(): bool
    {
        // Em dev/local, é comum não ter worker configurado corretamente; dispara sync para evitar “silêncio”.
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

                Log::debug('WebhookEventSubscriber: inspecting model for tenant_id', [
                    'model' => $value::class,
                    'id' => $value->getKey(),
                    'tenant_id_attr' => $tid,
                    'product_id_attr' => method_exists($value, 'getAttribute') ? $value->getAttribute('product_id') : null,
                ]);

                // Alguns fluxos podem emitir eventos com tenant_id nulo no modelo principal.
                // Nesses casos, inferimos o tenant pelo produto relacionado.
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
                        Log::debug('WebhookEventSubscriber: failed to infer tenant_id from related product', [
                            'model' => $value::class,
                            'id' => $value->getKey(),
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

        // IMPORTANTE: `array_filter()` sem callback remove valores "falsy" como 0.
        // Queremos remover apenas `null` (tenant 0 pode existir em alguns ambientes/dados).
        $ids = array_values(array_unique(array_filter($ids, fn ($v) => $v !== null)));

        return $ids;
    }

    /**
     * Extract product_id from event (Order events, CartAbandoned, Subscription events)
     */
    private function getProductIdFromEvent(object $event): int|string|null
    {
        if ($event instanceof OrderPending || $event instanceof OrderCompleted
            || $event instanceof OrderRejected || $event instanceof OrderCancelled
            || $event instanceof OrderRefunded || $event instanceof PixGenerated
            || $event instanceof BoletoGenerated) {
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

    /**
     * @return array<string, mixed>
     */
    private function serializeEventPayload(object $event): array
    {
        $result = [];
        foreach ((array) $event as $key => $value) {
            $cleanKey = preg_replace('/^\x00[^\x00]*\x00/', '', $key);
            $result[$cleanKey] = $this->serializeValue($value);
        }

        return $result;
    }

    private function serializeValue(mixed $value): mixed
    {
        if ($value instanceof Model) {
            return $value->toArray();
        }

        if ($value instanceof \ArrayObject) {
            return $this->serializeValue($value->getArrayCopy());
        }

        if (is_array($value)) {
            return array_map(fn ($v) => $this->serializeValue($v), $value);
        }

        return $value;
    }

    /**
     * Adiciona customer, checkout_link e (para Pix) pix ao payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enrichPayload(object $event, array $payload): array
    {
        $extra = [];

        if ($event instanceof OrderPending || $event instanceof OrderCompleted
            || $event instanceof OrderRejected || $event instanceof OrderCancelled
            || $event instanceof OrderRefunded || $event instanceof PixGenerated
            || $event instanceof BoletoGenerated) {
            $order = $event->order;
            $order->loadMissing(['user', 'product', 'productOffer', 'subscriptionPlan']);
            $extra['customer'] = [
                'name' => $order->user?->name ?? '',
                'email' => $order->email ?? '',
                'phone' => $order->phone ?? '',
                'cpf' => $order->cpf ?? '',
            ];
            $slug = $order->getCheckoutSlug();
            $extra['checkout_link'] = $slug ? URL::route('checkout.show', ['slug' => $slug]) : '';
        }

        if ($event instanceof PixGenerated && ! empty($event->pixData)) {
            $extra['pix'] = [
                'qrcode' => $event->pixData['qrcode'] ?? null,
                'copy_paste' => $event->pixData['copy_paste'] ?? null,
                'transaction_id' => $event->pixData['transaction_id'] ?? null,
            ];
        }

        if ($event instanceof CartAbandoned) {
            $session = $event->checkoutSession;
            $session->loadMissing('product');
            $extra['customer'] = [
                'name' => $session->name ?? '',
                'email' => $session->email ?? '',
                'phone' => '',
                'cpf' => '',
            ];
            $slug = $session->checkout_slug ?? $session->product?->checkout_slug ?? '';
            $extra['checkout_link'] = $slug ? URL::route('checkout.show', ['slug' => $slug]) : '';
        }

        if ($event instanceof SubscriptionCreated || $event instanceof SubscriptionRenewed
            || $event instanceof SubscriptionCancelled || $event instanceof SubscriptionPastDue) {
            $subscription = $event->subscription;
            $subscription->loadMissing(['user', 'product', 'subscriptionPlan']);
            $user = $subscription->user;
            $extra['customer'] = [
                'name' => $user?->name ?? '',
                'email' => $user?->email ?? '',
                'phone' => '',
                'cpf' => '',
            ];
            $slug = $subscription->subscriptionPlan?->checkout_slug
                ?? $subscription->product?->checkout_slug
                ?? '';
            $extra['checkout_link'] = $slug ? URL::route('checkout.show', ['slug' => $slug]) : '';
        }

        return array_merge($payload, $extra);
    }
}
