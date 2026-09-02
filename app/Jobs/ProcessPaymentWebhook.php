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
use App\Support\MoneyMinorUnits;
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
        $order = $this->resolveOrderForGateway();

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
            // Lock por pedido (não por transaction id): payment_id e session_id
            // não devem competir e “engolir” a aprovação.
            $lockKey = 'webhook_processing.order.'.$order->id;
            if (! Cache::add($lockKey, true, now()->addMinutes(5))) {
                $order->refresh();
                if ($order->status === 'completed') {
                    $order->loadMissing('orderItems.product', 'product');
                    $order->grantPurchasedProductAccessToBuyer();

                    return;
                }
                // Outro worker pode estar no meio — espera e reavalia.
                usleep(300000);
                $order->refresh();
                if ($order->status === 'completed') {
                    return;
                }
                Log::info('ProcessPaymentWebhook: paid branch skipped (concurrent lock, still pending)', [
                    'order_id' => $order->id,
                    'gateway' => $this->gatewaySlug,
                    'transaction_id' => $this->transactionId,
                    'event' => $this->event,
                ]);

                return;
            }
            if ($order->status === 'completed') {
                $order->loadMissing('orderItems.product', 'product');
                $this->applyCajuPayPaidAmountFromWebhook($order);
                $order->refresh();
                $this->syncProducerGatewayFeeFromOrderMetadata($order);
                $order->grantPurchasedProductAccessToBuyer();
                Log::info('ProcessPaymentWebhook: paid branch skipped (order already completed, access re-synced)', [
                    'order_id' => $order->id,
                    'gateway' => $this->gatewaySlug,
                    'transaction_id' => $this->transactionId,
                    'event' => $this->event,
                ]);

                return;
            }
            $apiStatus = $this->fetchGatewayTransactionStatus($order);
            $trustedPaidSource = $this->isTrustedCajuPayPaidSource();
            if ($apiStatus !== 'paid') {
                if (! $trustedPaidSource) {
                    Log::warning('ProcessPaymentWebhook: paid branch aborted (gateway reconfirm not paid)', [
                        'order_id' => $order->id,
                        'gateway' => $this->gatewaySlug,
                        'transaction_id' => $this->transactionId,
                        'event' => $this->event,
                        'api_status' => $apiStatus,
                    ]);

                    return;
                }
                Log::info('ProcessPaymentWebhook: CajuPay paid applied on trusted source (reconfirm not paid yet)', [
                    'order_id' => $order->id,
                    'transaction_id' => $this->transactionId,
                    'api_status' => $apiStatus,
                    'source' => $this->payload['source'] ?? ($this->payload['webhook_source'] ?? null),
                ]);
            }
            $this->applyCajuPayPaidAmountFromWebhook($order);
            $order->update(['status' => 'completed']);
            $order->refresh();
            $this->syncProducerGatewayFeeFromOrderMetadata($order);
            $order->syncUtmMetadataFromCheckoutSession();
            $order->grantPurchasedProductAccessToBuyer();
            if ($order->subscription_plan_id) {
                $plan = $order->subscriptionPlan;
                if ($plan) {
                    if ($order->is_renewal) {
                        $sub = Subscription::where('user_id', $order->user_id)
                            ->where('product_id', $order->product_id)
                            ->where('subscription_plan_id', $plan->id)
                            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
                            ->first();
                        if ($sub) {
                            [$periodStart, $periodEnd] = $order->period_start && $order->period_end
                                ? [$order->period_start, $order->period_end]
                                : $plan->getCurrentPeriod();
                            $sub->update([
                                'status' => Subscription::STATUS_ACTIVE,
                                'current_period_start' => $periodStart,
                                'current_period_end' => $periodEnd,
                                'past_due_at' => null,
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
                        } elseif (isset($metadata['cajupay_subscription_id']) && $this->gatewaySlug === 'cajupay') {
                            $idRec = $metadata['cajupay_subscription_id'];
                        } elseif ($this->gatewaySlug === 'cajupay' && ! empty($order->gateway_id) && ($metadata['checkout_payment_method'] ?? '') === 'pix_auto') {
                            $idRec = $order->gateway_id;
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
                if (! $this->reconfirmGatewayStatus($order, ['cancelled', 'refunded'])) {
                    return;
                }
                $order->update(['status' => 'refunded']);
                event(new OrderRefunded($order));
            }
        }
    }

    private function resolveOrderForGateway(): ?Order
    {
        if ($this->gatewaySlug !== 'cajupay') {
            return Order::where('gateway', $this->gatewaySlug)
                ->where('gateway_id', $this->transactionId)
                ->first();
        }

        $orderId = (int) ($this->payload['getfy_order_id'] ?? 0);
        if ($orderId > 0) {
            $byId = Order::query()->where('id', $orderId)->where('gateway', 'cajupay')->first();
            if ($byId) {
                return $byId;
            }
        }

        $tid = $this->transactionId;
        $sessionFromPayload = trim((string) ($this->payload['cajupay_checkout_session_id'] ?? ''));
        $paymentFromPayload = trim((string) ($this->payload['cajupay_payment_id'] ?? ''));

        return Order::where('gateway', 'cajupay')
            ->where(function ($q) use ($tid, $sessionFromPayload, $paymentFromPayload) {
                $q->where('gateway_id', $tid)
                    ->orWhere('metadata->cajupay_checkout_session_id', $tid)
                    ->orWhere('metadata->cajupay_session_token', $tid)
                    ->orWhere('metadata->cajupay_payment_id', $tid)
                    ->orWhere('metadata->cajupay_subscription_id', $tid);
                if ($sessionFromPayload !== '' && $sessionFromPayload !== $tid) {
                    $q->orWhere('gateway_id', $sessionFromPayload)
                        ->orWhere('metadata->cajupay_checkout_session_id', $sessionFromPayload);
                }
                if ($paymentFromPayload !== '' && $paymentFromPayload !== $tid) {
                    $q->orWhere('gateway_id', $paymentFromPayload)
                        ->orWhere('metadata->cajupay_payment_id', $paymentFromPayload);
                }
            })
            ->orderByDesc('id')
            ->first();
    }

    private function isTrustedCajuPayPaidSource(): bool
    {
        if ($this->gatewaySlug !== 'cajupay') {
            return false;
        }

        $webhookSource = (string) ($this->payload['webhook_source'] ?? '');
        if (in_array($webhookSource, ['cajupay_hmac_verified', 'cajupay_subscription_verified'], true)) {
            return true;
        }

        // Poll/reconcile já confirmaram paid na API antes do dispatch — não abortar
        // se a 2ª consulta oscilar para pending/null.
        $source = (string) ($this->payload['source'] ?? '');

        return in_array($source, [
            'reconcile_pending',
            'order_status_poll',
            'cajupay_paid_buffer',
        ], true);
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
        if ($this->gatewaySlug === 'paypal' && $this->event === 'PAYMENT.CAPTURE.COMPLETED') {
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

        if ($this->gatewaySlug === 'cajupay') {
            $primary = $driver->getTransactionStatus($this->transactionId, $credentials);
            if ($primary === 'paid') {
                return 'paid';
            }
            $meta = $order->metadata;
            if (! is_array($meta)) {
                $meta = [];
            }
            $sessionTok = isset($meta['cajupay_session_token']) && is_string($meta['cajupay_session_token'])
                ? $meta['cajupay_session_token']
                : '';
            $checkoutSess = isset($meta['cajupay_checkout_session_id']) && is_string($meta['cajupay_checkout_session_id'])
                ? $meta['cajupay_checkout_session_id']
                : '';
            foreach ([$sessionTok, $checkoutSess] as $alt) {
                if ($alt === '' || $alt === $this->transactionId) {
                    continue;
                }
                $secondary = $driver->getTransactionStatus($alt, $credentials);
                if ($secondary === 'paid') {
                    return 'paid';
                }
            }

            return $primary;
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

    private function applyCajuPayPaidAmountFromWebhook(Order $order): void
    {
        if ($this->gatewaySlug !== 'cajupay' || ! $this->isConfirmedPaidWebhook()) {
            return;
        }

        $object = $this->extractCajuPayWebhookObject($this->payload);
        if ($object === null) {
            return;
        }

        $currencyRaw = $object['currency'] ?? null;
        $amountCentsRaw = $object['amount_cents'] ?? ($object['payment_amount_cents'] ?? null);

        $updates = [];
        $meta = $order->metadata ?? [];

        if (is_string($currencyRaw) && trim($currencyRaw) !== '') {
            $currency = MoneyMinorUnits::normalizeCurrencyCode($currencyRaw);
            $updates['currency'] = $currency;
            $meta['payment_currency'] = $currency;
        } else {
            $currency = $order->getCurrencyOrDefault();
        }

        if (is_numeric($amountCentsRaw)) {
            $minor = (int) $amountCentsRaw;
            if ($minor > 0) {
                $updates['amount'] = MoneyMinorUnits::fromMinorUnits($minor, $currency);
                $meta['payment_amount_cents'] = $minor;
            }
        }

        $settlementCents = $object['settlement_amount_cents'] ?? null;
        if (is_numeric($settlementCents) && (int) $settlementCents > 0) {
            $meta['settlement_amount_cents'] = (int) $settlementCents;
        }
        $settlementCurrency = $object['settlement_currency'] ?? null;
        if (is_string($settlementCurrency) && trim($settlementCurrency) !== '') {
            $meta['settlement_currency'] = MoneyMinorUnits::normalizeCurrencyCode($settlementCurrency);
        }
        $fxRate = $object['fx_rate'] ?? null;
        if ($fxRate !== null && $fxRate !== '') {
            $meta['fx_rate'] = is_string($fxRate) ? $fxRate : (string) $fxRate;
        }

        $installments = $object['installments'] ?? ($object['installment_count'] ?? null);
        if (is_numeric($installments) && (int) $installments >= 1) {
            $meta['installments'] = (int) $installments;
        }
        $threedsMode = $object['threeds_mode'] ?? null;
        if (is_string($threedsMode) && in_array($threedsMode, ['off', 'required'], true)) {
            $meta['threeds_mode'] = $threedsMode;
        }

        $feeCents = $object['fee_cents'] ?? null;
        if (is_numeric($feeCents) && (int) $feeCents >= 0) {
            $meta['gateway_fee_cents'] = (int) $feeCents;
            $meta['gateway_fee_source'] = 'cajupay_webhook';
        }
        $netCents = $object['net_cents'] ?? null;
        if (is_numeric($netCents) && (int) $netCents >= 0) {
            $meta['gateway_net_cents'] = (int) $netCents;
        }

        if ($updates !== [] || $meta !== ($order->metadata ?? [])) {
            $updates['metadata'] = $meta;
            $order->update($updates);
        }
    }

    private function syncProducerGatewayFeeFromOrderMetadata(Order $order): void
    {
        if ($this->gatewaySlug !== 'cajupay') {
            return;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        if (! isset($meta['gateway_fee_cents']) || ! is_numeric($meta['gateway_fee_cents'])) {
            return;
        }

        $currency = $order->getCurrencyOrDefault();
        $fee = MoneyMinorUnits::fromMinorUnits((int) $meta['gateway_fee_cents'], $currency);
        $gross = round($order->lineItemsTotalAmount(), 2);
        $net = isset($meta['gateway_net_cents']) && is_numeric($meta['gateway_net_cents'])
            ? MoneyMinorUnits::fromMinorUnits((int) $meta['gateway_net_cents'], $currency)
            : max(0, round($gross - $fee, 2));

        $producerEntry = $order->commissionEntries()
            ->where('role', \App\Models\CommissionEntry::ROLE_PRODUTOR)
            ->first();

        if (! $producerEntry) {
            return;
        }

        $currentFee = round((float) $producerEntry->gateway_fee_amount, 2);
        if (abs($currentFee - $fee) < 0.01) {
            return;
        }

        $producerEntry->update([
            'gateway_fee_amount' => round($fee, 2),
            'net_amount' => round($net, 2),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function extractCajuPayWebhookObject(array $payload): ?array
    {
        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            $object = $data['object'] ?? null;
            if (is_array($object)) {
                return $object;
            }

            return $data;
        }
        if (isset($payload['object']) && is_array($payload['object'])) {
            return $payload['object'];
        }

        return null;
    }
}
