<?php

namespace App\Jobs;

use App\Events\OrderCancelled;
use App\Events\OrderCompleted;
use App\Events\OrderRejected;
use App\Events\OrderRefunded;
use App\Events\SubscriptionCreated;
use App\Events\SubscriptionRenewed;
use App\Gateways\GatewayRegistry;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Subscription;
use App\Services\EfiPixRecorrenteService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessPaymentWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    /**
     * @param  array<string, mixed>  $payload  Optional raw payload for logging/future use.
     */
    public function __construct(
        public string $gatewaySlug,
        public string $transactionId,
        public string $event,
        public string $status,
        public array $payload = []
    ) {}

    public function handle(): void
    {
        $order = Order::where('gateway', $this->gatewaySlug)
            ->where('gateway_id', $this->transactionId)
            ->first();

        if (! $order) {
            Log::info('ProcessPaymentWebhook: order not found for gateway transaction', [
                'gateway' => $this->gatewaySlug,
                'transaction_id' => $this->transactionId,
                'event' => $this->event,
                'status' => $this->status,
            ]);

            return;
        }

        if ($this->isConfirmedPaidWebhook()) {
            $lockKey = 'webhook_processing.' . $this->gatewaySlug . '.' . $this->transactionId;
            if (! Cache::add($lockKey, true, now()->addMinutes(5))) {
                Log::info('ProcessPaymentWebhook: paid branch skipped (concurrent lock)', [
                    'order_id' => $order->id,
                    'gateway' => $this->gatewaySlug,
                    'transaction_id' => $this->transactionId,
                    'event' => $this->event,
                ]);

                return;
            }
            if ($order->status === 'completed') {
                Log::info('ProcessPaymentWebhook: paid branch skipped (order already completed)', [
                    'order_id' => $order->id,
                    'gateway' => $this->gatewaySlug,
                    'transaction_id' => $this->transactionId,
                    'event' => $this->event,
                ]);

                return;
            }
            $apiStatus = $this->fetchGatewayTransactionStatus($order);
            if ($apiStatus !== 'paid') {
                Log::warning('ProcessPaymentWebhook: paid branch aborted (gateway reconfirm not paid)', [
                    'order_id' => $order->id,
                    'gateway' => $this->gatewaySlug,
                    'transaction_id' => $this->transactionId,
                    'event' => $this->event,
                    'api_status' => $apiStatus,
                ]);

                return;
            }
            $order->update(['status' => 'completed']);
            $order->refresh();
            $order->syncUtmMetadataFromCheckoutSession();
            $order->grantPurchasedProductAccessToBuyer();
            if ($order->subscription_plan_id) {
                $plan = $order->subscriptionPlan;
                if ($plan) {
                    if ($order->is_renewal) {
                        $sub = Subscription::where('user_id', $order->user_id)
                            ->where('product_id', $order->product_id)
                            ->where('subscription_plan_id', $plan->id)
                            ->where('status', Subscription::STATUS_ACTIVE)
                            ->first();
                        if ($sub && $order->period_start && $order->period_end) {
                            $sub->update([
                                'current_period_start' => $order->period_start,
                                'current_period_end' => $order->period_end,
                            ]);
                            event(new SubscriptionRenewed($sub->fresh()));
                        }
                    } elseif (! Subscription::where('user_id', $order->user_id)->where('product_id', $order->product_id)->where('subscription_plan_id', $plan->id)->where('status', Subscription::STATUS_ACTIVE)->exists()) {
                        [$periodStart, $periodEnd] = $plan->getCurrentPeriod();
                        $idRec = null;
                        $metadata = $order->metadata ?? [];
                        if (isset($metadata['efi_pix_auto_id_rec']) && $this->gatewaySlug === 'efi') {
                            $idRec = $metadata['efi_pix_auto_id_rec'];
                        } elseif (isset($metadata['pushinpay_subscription_id']) && $this->gatewaySlug === 'pushinpay') {
                            $idRec = $metadata['pushinpay_subscription_id'];
                        }
                        $subscription = Subscription::create([
                            'tenant_id' => $order->tenant_id,
                            'user_id' => $order->user_id,
                            'product_id' => $order->product_id,
                            'subscription_plan_id' => $plan->id,
                            'status' => Subscription::STATUS_ACTIVE,
                            'current_period_start' => $periodStart,
                            'current_period_end' => $periodEnd,
                            'gateway_subscription_id' => $idRec,
                        ]);
                        event(new SubscriptionCreated($subscription));

                        if ($idRec !== null && $this->gatewaySlug === 'efi') {
                            $this->createEfiPixAutoCobrForNextPeriod($order, $subscription, $plan);
                        }
                    }
                }
            }
            event(new OrderCompleted($order));
        }

        if ($this->event === 'order.cancelled' && in_array($this->status, ['cancelled', 'canceled'], true)) {
            if ($order->status === 'pending') {
                if (! $this->reconfirmGatewayStatus($order, ['cancelled'])) {
                    return;
                }
                $order->update(['status' => 'cancelled']);
                event(new OrderCancelled($order));
            }
        }

        if (in_array($this->event, ['order.rejected', 'payment.rejected'], true) && in_array($this->status, ['rejected', 'refused', 'failed'], true)) {
            if ($order->status === 'pending') {
                if (! $this->reconfirmGatewayStatus($order, ['cancelled'])) {
                    return;
                }
                $order->update(['status' => 'rejected']);
                event(new OrderRejected($order));
            }
        }

        if (in_array($this->event, ['order.refunded', 'payment.refunded'], true) && in_array($this->status, ['refunded', 'refund'], true)) {
            if ($order->status === 'completed') {
                if (! $this->reconfirmGatewayStatus($order, ['cancelled'])) {
                    return;
                }
                $order->update(['status' => 'refunded']);
                event(new OrderRefunded($order));
            }
        }
    }

    /**
     * Pagamento confirmado: formato comum `order.paid` ou Stripe `payment_intent.succeeded` (com status mapeado para paid).
     */
    private function isConfirmedPaidWebhook(): bool
    {
        if ($this->status !== 'paid') {
            return false;
        }
        if ($this->event === 'order.paid') {
            return true;
        }
        if ($this->gatewaySlug === 'stripe' && $this->event === 'payment_intent.succeeded') {
            return true;
        }

        return false;
    }

    private function fetchGatewayTransactionStatus(Order $order): ?string
    {
        $credential = GatewayCredential::forTenant($order->tenant_id)
            ->where('gateway_slug', $this->gatewaySlug)
            ->where('is_connected', true)
            ->first();

        if (! $credential) {
            return null;
        }

        $driver = GatewayRegistry::driver($this->gatewaySlug);
        if (! $driver) {
            return null;
        }

        $credentials = $credential->getDecryptedCredentials();
        if (empty($credentials)) {
            return null;
        }

        return $driver->getTransactionStatus($this->transactionId, $credentials);
    }

    /**
     * @param  list<string>  $expectedStatuses  e.g. ['cancelled'] — vários drivers mapeiam refund/rejected para cancelled
     */
    private function reconfirmGatewayStatus(Order $order, array $expectedStatuses): bool
    {
        $apiStatus = $this->fetchGatewayTransactionStatus($order);
        if ($apiStatus === null) {
            return $this->shouldAcceptUnconfirmedDestructive();
        }

        return in_array($apiStatus, $expectedStatuses, true);
    }

    private function shouldAcceptUnconfirmedDestructive(): bool
    {
        $perGateway = config("webhooks.reconfirm_fail_policy.{$this->gatewaySlug}");
        if (is_string($perGateway) && $perGateway !== '') {
            $accept = $perGateway === 'accept';
        } else {
            $accept = config('webhooks.reconfirm_fail_policy.default', 'accept') === 'accept';
        }

        if (! $accept) {
            Log::warning('Webhook cancel/refund/reject skipped: reconfirmation unavailable (policy=reject)', [
                'gateway' => $this->gatewaySlug,
                'transaction_id' => $this->transactionId,
                'event' => $this->event,
            ]);
        }

        return $accept;
    }

    private function createEfiPixAutoCobrForNextPeriod(Order $order, Subscription $subscription, $plan): void
    {
        $credential = GatewayCredential::forTenant($order->tenant_id)
            ->where('gateway_slug', 'efi')
            ->where('is_connected', true)
            ->first();
        if (! $credential) {
            return;
        }
        $credentials = $credential->getDecryptedCredentials();
        if (empty($credentials['certificate_path'])) {
            return;
        }

        $idRec = $subscription->gateway_subscription_id;
        if ($idRec === null || $idRec === '') {
            return;
        }

        $amount = (float) $plan->price;
        $periodEnd = $subscription->current_period_end;
        $dataDeVencimento = $periodEnd ? $periodEnd->format('Y-m-d') : now()->addMonth()->format('Y-m-d');

        $devedor = [
            'name' => $order->user ? $order->user->name : null ?? $order->email,
            'email' => $order->email,
        ];

        try {
            $service = new EfiPixRecorrenteService($credentials);
            $service->createCobrancaRecorrente(
                $idRec,
                $amount,
                $dataDeVencimento,
                null,
                $devedor,
                'Renovação assinatura - Pedido #' . $order->id
            );
        } catch (\Throwable $e) {
            Log::warning('ProcessPaymentWebhook: falha ao criar cobr PIX automático', [
                'order_id' => $order->id,
                'idRec' => $idRec,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
