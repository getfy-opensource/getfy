<?php

namespace App\Http\Controllers\Webhooks;

use App\Gateways\CajuPay\CajuPayDriver;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\PayoutRequest;
use App\Models\RefundRequest;
use App\Models\Subscription;
use App\Services\PayoutService;
use App\Services\RefundService;
use App\Services\CajuPaySubscriptionService;
use App\Services\SubscriptionLifecycleService;
use App\Support\CajuPayPaymentId;
use App\Support\CajuPayPaidSessionBuffer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CajuPayWebhookController extends Controller
{
    private const SLUG = 'cajupay';

    /**
     * POST /webhooks/gateways/cajupay — webhooks outbound da CajuPay assinados com HMAC SHA256.
     *
     * Cabeçalhos esperados:
     *  - X-CajuPay-Event       (ex.: checkout.payment.paid, pix.payment.paid)
     *  - X-CajuPay-Event-Id    (mesmo valor do id no envelope)
     *  - X-CajuPay-Timestamp   (unix segundos)
     *  - X-CajuPay-Signature   (formato t=<unix>,v1=<hex_hmac>)
     *
     * Assinatura: HMAC_SHA256(signing_secret, timestamp + "." + raw_body)
     */
    public function handle(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        if (! is_string($raw) || $raw === '') {
            return response()->json(['message' => 'Empty body'], 400);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid JSON'], 400);
        }

        $eventType = (string) ($request->header('X-CajuPay-Event') ?? ($payload['type'] ?? ''));
        $signatureHeader = (string) ($request->header('X-CajuPay-Signature') ?? '');
        $timestampHeader = (string) ($request->header('X-CajuPay-Timestamp') ?? '');

        $sigParts = $this->parseSignatureHeader($signatureHeader);
        $signatureTs = $sigParts['t'] ?? $timestampHeader;
        $signatureHex = strtolower($sigParts['v1'] ?? '');

        if ($signatureHex === '' || $signatureTs === '' || ! is_numeric($signatureTs)) {
            return response()->json(['message' => 'Invalid signature header'], 400);
        }

        $age = abs(time() - (int) $signatureTs);
        if ($age > 300) {
            Log::warning('CajuPayWebhook: timestamp fora da janela', ['age_seconds' => $age]);

            return response()->json(['message' => 'Stale timestamp'], 401);
        }

        if ($this->isPayoutEvent($eventType)) {
            return $this->handlePayoutEvent(
                $payload,
                $eventType,
                $raw,
                (string) $signatureTs,
                $signatureHex,
            );
        }

        $object = $this->extractObject($payload);
        $sessionId = $this->pickSessionId($object);
        $paymentId = CajuPayPaymentId::pickFromWebhookObject($object);
        $subscriptionId = $this->pickSubscriptionId($object);

        $order = $this->findOrderForWebhook($sessionId, $paymentId, $object);
        if ($order === null && $subscriptionId !== '') {
            $order = Order::where('gateway', self::SLUG)
                ->where(function ($q) use ($subscriptionId) {
                    $q->where('gateway_id', $subscriptionId)
                        ->orWhere('metadata->cajupay_subscription_id', $subscriptionId);
                })
                ->orderByDesc('id')
                ->first();
        }

        $signingSecret = $this->resolveSigningSecret($raw, (string) $signatureTs, $signatureHex, $order?->tenant_id);
        if ($signingSecret === null) {
            Log::warning('CajuPayWebhook: assinatura inválida ou sem signing_secret', [
                'event' => $eventType,
                'payment_id' => $paymentId,
                'session_id' => $sessionId,
                'subscription_id' => $subscriptionId,
                'order_id' => $order?->id,
            ]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (str_starts_with($eventType, 'subscription.')) {
            return $this->handleSubscriptionEvent($eventType, $object, $order, $payload, $paymentId, $subscriptionId);
        }

        if ($order === null) {
            if ($this->isPaidEvent($eventType)) {
                $pollingToken = is_string($sessionId) && $sessionId !== ''
                    ? Cache::get('cajupay_session_by_checkout.'.$sessionId)
                    : null;
                $hasDraft = is_string($pollingToken) && $pollingToken !== ''
                    && Cache::has('cajupay_draft.'.$pollingToken);

                // Corrida clássica: webhook paid chega antes do confirm-order.
                // Bufferiza o paid; o confirm-order (ou retry job) aplica no pedido.
                if ((is_string($sessionId) && $sessionId !== '') || $paymentId !== '') {
                    CajuPayPaidSessionBuffer::store(
                        is_string($sessionId) ? $sessionId : '',
                        $paymentId,
                        array_merge($payload, [
                            'webhook_source' => 'cajupay_hmac_verified',
                            'buffered_event' => $eventType,
                        ])
                    );
                    \App\Jobs\RetryCajuPayBufferedPaid::dispatch(
                        is_string($sessionId) ? $sessionId : '',
                        $paymentId
                    )->delay(now()->addSeconds(8));
                }

                Log::warning('CajuPayWebhook: pagamento aprovado sem pedido no Getfy (bufferizado)', [
                    'event' => $eventType,
                    'session_id' => $sessionId,
                    'payment_id' => $paymentId,
                    'draft_still_in_cache' => $hasDraft,
                    'hint' => $hasDraft
                        ? 'Webhook paid antes do confirm-order; buffer + retry aplicados.'
                        : 'Pedido não encontrado; bufferado por session/payment id.',
                ]);
            } else {
                Log::debug('CajuPayWebhook: order not found', [
                    'event' => $eventType,
                    'session_id' => $sessionId,
                    'payment_id' => $paymentId,
                ]);
            }

            return response()->json(['received' => true]);
        }

        if ($paymentId !== '') {
            CajuPayPaymentId::persistOnOrder($order, $paymentId);
            app(RefundService::class)->persistCajuPayPaymentId($order->fresh(), $paymentId);
            $order->refresh();
        }

        // NÃO sobrescrever gateway_id (checkout_session_id) com payment_id no paid:
        // o lookup do webhook/reconcile depende do session id. O payment_id fica em metadata.
        // Exceção: se gateway_id ainda estiver vazio, preenche com paymentId ou sessionId.
        if (($order->gateway_id === null || $order->gateway_id === '') && ($paymentId !== '' || (is_string($sessionId) && $sessionId !== ''))) {
            try {
                $order->update(['gateway_id' => $paymentId !== '' ? $paymentId : $sessionId]);
                $order->refresh();
            } catch (\Throwable $e) {
                Log::debug('CajuPayWebhook: falha ao preencher gateway_id vazio', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $dispatchId = (is_string($sessionId) && $sessionId !== '')
            ? $sessionId
            : ($paymentId !== '' ? $paymentId : (string) ($order->gateway_id ?: ''));
        $refundId = is_array($object) && is_string($object['refund_id'] ?? null) ? $object['refund_id'] : null;
        $webhookMeta = array_merge($payload, [
            'webhook_source' => 'cajupay_hmac_verified',
            'getfy_order_id' => $order->id,
            'cajupay_checkout_session_id' => $sessionId,
            'cajupay_payment_id' => $paymentId !== '' ? $paymentId : null,
        ]);

        switch ($eventType) {
            case 'pix_parcelado.installment.paid':
                $sequence = (int) ($object['sequence'] ?? ($object['installment_sequence'] ?? 0));
                $shouldComplete = $sequence <= 1;
                if ($shouldComplete && $dispatchId !== '') {
                    ProcessPaymentWebhook::dispatchSync(self::SLUG, $dispatchId, 'order.paid', 'paid', array_merge(
                        $webhookMeta,
                        ['pix_parcelado_sequence' => $sequence]
                    ));
                } elseif ($order !== null) {
                    $meta = is_array($order->metadata) ? $order->metadata : [];
                    $meta['pix_parcelado_last_paid_sequence'] = $sequence;
                    $order->update(['metadata' => $meta]);
                }
                break;
            case 'pix_parcelado.plan.defaulted':
                if ($order !== null) {
                    $meta = is_array($order->metadata) ? $order->metadata : [];
                    $meta['pix_parcelado_defaulted'] = true;
                    $order->update(['metadata' => $meta]);
                }
                break;
            case 'checkout.payment.paid':
            case 'pix.payment.paid':
            case 'card.payment.succeeded':
                $productRef = is_array($object) ? (string) ($object['product_ref'] ?? '') : '';
                if ($productRef === 'pix_parcelado' && $order !== null && $order->status !== 'completed') {
                    ProcessPaymentWebhook::dispatchSync(self::SLUG, $dispatchId !== '' ? $dispatchId : (string) $order->gateway_id, 'order.paid', 'paid', $webhookMeta);
                    break;
                }
                if ($order !== null && is_array($object)) {
                    try {
                        app(CajuPaySubscriptionService::class)->persistCardTokenAndMaybeCreateRemote(
                            $order,
                            array_merge($payload, ['object' => $object])
                        );
                        $order->refresh();
                    } catch (\Throwable $e) {
                        Log::debug('CajuPayWebhook: persist card_token', [
                            'order_id' => $order->id,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
                if ($dispatchId !== '') {
                    ProcessPaymentWebhook::dispatchSync(self::SLUG, $dispatchId, 'order.paid', 'paid', $webhookMeta);
                }
                break;
            case 'checkout.payment.failed':
            case 'card.payment.failed':
                if ($dispatchId !== '') {
                    ProcessPaymentWebhook::dispatchSync(self::SLUG, $dispatchId, 'order.rejected', 'rejected', $webhookMeta);
                }
                break;
            case 'checkout.payment.refunded':
            case 'card.payment.refunded':
            case 'pix.payment.refunded':
                if ($refundId) {
                    RefundRequest::query()
                        ->where('order_id', $order->id)
                        ->whereIn('status', [RefundRequest::STATUS_PENDING, RefundRequest::STATUS_PROCESSING])
                        ->update(['cajupay_refund_id' => $refundId]);
                }
                if ($dispatchId !== '') {
                    ProcessPaymentWebhook::dispatchSync(self::SLUG, $dispatchId, 'order.refunded', 'refunded', array_merge(
                        $webhookMeta,
                        ['cajupay_refund_id' => $refundId]
                    ));
                }
                break;
            case 'checkout.payment.disputed':
            case 'card.payment.disputed':
                Log::info('CajuPayWebhook: disputa recebida', [
                    'order_id' => $order->id,
                    'payment_id' => $dispatchId,
                ]);
                break;
            default:
                Log::debug('CajuPayWebhook: tipo não tratado', ['event' => $eventType]);
                break;
        }

        return response()->json(['received' => true]);
    }

    private function isPaidEvent(string $eventType): bool
    {
        return in_array($eventType, [
            'checkout.payment.paid',
            'pix.payment.paid',
            'card.payment.succeeded',
            'pix_parcelado.installment.paid',
        ], true);
    }

    private function isPayoutEvent(string $eventType): bool
    {
        return str_starts_with($eventType, 'payout.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handlePayoutEvent(
        array $payload,
        string $eventType,
        string $raw,
        string $signatureTs,
        string $signatureHex,
    ): JsonResponse {
        $object = $this->extractObject($payload);
        $payoutId = $this->pickPayoutId($object);

        $payoutRequest = null;
        if ($payoutId !== '') {
            $payoutRequest = PayoutRequest::query()
                ->where('cajupay_payout_id', $payoutId)
                ->first();
        }

        $signingSecret = $this->resolveSigningSecret(
            $raw,
            $signatureTs,
            $signatureHex,
            $payoutRequest?->tenant_id
        );
        if ($signingSecret === null) {
            Log::warning('CajuPayWebhook: assinatura inválida em evento de saque', [
                'event' => $eventType,
                'payout_id' => $payoutId,
                'payout_request_id' => $payoutRequest?->id,
            ]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($payoutRequest === null) {
            Log::debug('CajuPayWebhook: saque não encontrado', [
                'event' => $eventType,
                'payout_id' => $payoutId,
            ]);

            return response()->json(['received' => true]);
        }

        if ($payoutRequest->isTerminal()) {
            return response()->json(['received' => true]);
        }

        $statusSource = is_array($object) ? $object : $payload;
        $driver = app(CajuPayDriver::class);
        $normalized = $driver->normalizePayoutStatus($statusSource);
        $payoutService = app(PayoutService::class);

        $payoutRequest->update([
            'cajupay_response' => array_merge(
                is_array($payoutRequest->cajupay_response) ? $payoutRequest->cajupay_response : [],
                ['last_webhook' => $payload]
            ),
            'cajupay_status' => $normalized,
        ]);

        if ($normalized === 'paid') {
            $payoutService->confirmPayoutSuccess($payoutRequest->fresh());
            Log::info('CajuPayWebhook: saque concluído via webhook', [
                'event' => $eventType,
                'payout_request_id' => $payoutRequest->id,
                'cajupay_payout_id' => $payoutId,
            ]);
        } elseif (in_array($normalized, ['failed', 'cancelled'], true)) {
            $payoutService->markPayoutFailed(
                $payoutRequest->fresh(),
                (string) ($statusSource['failure_reason'] ?? $statusSource['message'] ?? 'Saque falhou na CajuPay.')
            );
            Log::info('CajuPayWebhook: saque falhou via webhook', [
                'event' => $eventType,
                'payout_request_id' => $payoutRequest->id,
                'cajupay_payout_id' => $payoutId,
            ]);
        } elseif ($payoutRequest->status === PayoutRequest::STATUS_PROCESSING) {
            $payoutRequest->update(['status' => PayoutRequest::STATUS_AWAITING_PAYOUT]);
        }

        return response()->json(['received' => true]);
    }

    /**
     * @param  array<string, mixed>|null  $object
     */
    private function pickPayoutId(?array $object): string
    {
        if ($object === null) {
            return '';
        }

        foreach (['id', 'payout_id', 'cajupay_payout_id'] as $key) {
            $value = $object[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>|null  $object
     */
    private function findOrderForWebhook(?string $sessionId, string $paymentId, ?array $object): ?Order
    {
        if (is_string($sessionId) && $sessionId !== '') {
            $order = Order::where('gateway', self::SLUG)
                ->where('gateway_id', $sessionId)
                ->first();
            if ($order) {
                return $order;
            }

            $order = Order::where('gateway', self::SLUG)
                ->where('metadata->cajupay_checkout_session_id', $sessionId)
                ->first();
            if ($order) {
                return $order;
            }
        }

        if ($paymentId !== '') {
            $order = Order::where('gateway', self::SLUG)
                ->where('gateway_id', $paymentId)
                ->first();
            if ($order) {
                return $order;
            }

            $order = Order::where('gateway', self::SLUG)
                ->where('metadata->cajupay_payment_id', $paymentId)
                ->first();
            if ($order) {
                return $order;
            }

            $order = Order::where('gateway', self::SLUG)
                ->where('metadata->cajupay_parcelado_first_payment_id', $paymentId)
                ->first();
            if ($order) {
                return $order;
            }
        }

        if (is_array($object)) {
            $planId = $object['plan_id'] ?? ($object['id'] ?? null);
            if (is_string($planId) && $planId !== '') {
                $order = Order::where('gateway', self::SLUG)
                    ->where('metadata->cajupay_parcelado_plan_id', $planId)
                    ->first();
                if ($order) {
                    return $order;
                }
            }
        }

        if (is_array($object)) {
            $clientRefundId = $object['client_refund_id'] ?? null;
            if (is_string($clientRefundId) && $clientRefundId !== '') {
                $refundRequest = RefundRequest::query()
                    ->where('client_refund_id', $clientRefundId)
                    ->first();
                if ($refundRequest?->order) {
                    return $refundRequest->order;
                }
            }

            $subscriptionId = $this->pickSubscriptionId($object);
            if ($subscriptionId !== '') {
                $order = Order::where('gateway', self::SLUG)
                    ->where(function ($q) use ($subscriptionId) {
                        $q->where('gateway_id', $subscriptionId)
                            ->orWhere('metadata->cajupay_subscription_id', $subscriptionId);
                    })
                    ->orderByDesc('id')
                    ->first();
                if ($order) {
                    return $order;
                }
            }

            $correlationId = $object['correlation_id'] ?? ($object['billing_order_id'] ?? null);
            if (is_string($correlationId) && str_starts_with($correlationId, 'order-')) {
                $orderId = (int) substr($correlationId, strlen('order-'));
                if ($orderId > 0) {
                    $order = Order::query()->where('id', $orderId)->where('gateway', self::SLUG)->first();
                    if ($order) {
                        return $order;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $object
     * @param  array<string, mixed>  $payload
     */
    private function handleSubscriptionEvent(
        string $eventType,
        ?array $object,
        ?Order $order,
        array $payload,
        string $paymentId,
        string $subscriptionId,
    ): JsonResponse {
        $object = is_array($object) ? $object : [];
        if ($subscriptionId === '') {
            $subscriptionId = $this->pickSubscriptionId($object);
        }

        if ($order === null) {
            $order = $this->findOrderForWebhook(null, $paymentId, $object);
        }

        $localSub = null;
        if ($subscriptionId !== '') {
            $localSub = Subscription::query()
                ->where('gateway_subscription_id', $subscriptionId)
                ->first();
        }

        $webhookMeta = array_merge($payload, ['webhook_source' => 'cajupay_hmac_verified']);
        $dispatchId = $paymentId !== ''
            ? $paymentId
            : (string) ($order?->gateway_id ?: $subscriptionId);

        switch ($eventType) {
            case 'subscription.approved':
                if ($order) {
                    $meta = is_array($order->metadata) ? $order->metadata : [];
                    $meta['cajupay_subscription_status'] = 'approved';
                    if ($subscriptionId !== '') {
                        $meta['cajupay_subscription_id'] = $subscriptionId;
                    }
                    $order->update(['metadata' => $meta]);
                }
                break;

            case 'subscription.rejected':
                if ($order && $order->status === 'pending' && $dispatchId !== '') {
                    ProcessPaymentWebhook::dispatchSync(self::SLUG, $dispatchId, 'order.rejected', 'rejected', $webhookMeta);
                }
                if ($localSub && $localSub->status !== Subscription::STATUS_CANCELLED) {
                    app(SubscriptionLifecycleService::class)->cancelSubscription($localSub, revokeAccessNow: true);
                }
                break;

            case 'subscription.charge.created':
                if ($order) {
                    $meta = is_array($order->metadata) ? $order->metadata : [];
                    $chargeId = (string) ($object['id'] ?? $object['charge_id'] ?? '');
                    if ($chargeId !== '') {
                        $meta['cajupay_last_charge_id'] = $chargeId;
                    }
                    $order->update(['metadata' => $meta]);
                }
                break;

            case 'subscription.charge.paid':
                if ($order && $order->status === 'pending') {
                    app(CajuPaySubscriptionService::class)->reconcilePendingOrderFromPaidCharge(
                        $order,
                        $object,
                        'cajupay_webhook',
                        'cajupay_hmac_verified'
                    );
                    break;
                }

                if ($localSub) {
                    $this->renewLocalSubscriptionFromCharge($localSub, $object, $paymentId);
                } elseif ($order && $order->status === 'completed') {
                    $sub = Subscription::query()
                        ->where('user_id', $order->user_id)
                        ->where('product_id', $order->product_id)
                        ->where('subscription_plan_id', $order->subscription_plan_id)
                        ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
                        ->first();
                    if ($sub) {
                        if ($subscriptionId !== '' && empty($sub->gateway_subscription_id)) {
                            $sub->update(['gateway_subscription_id' => $subscriptionId]);
                        }
                        $this->renewLocalSubscriptionFromCharge($sub->fresh(), $object, $paymentId);
                    }
                } else {
                    Log::debug('CajuPayWebhook: subscription.charge.paid sem pedido/assinatura', [
                        'subscription_id' => $subscriptionId,
                        'payment_id' => $paymentId,
                    ]);
                }
                break;

            case 'subscription.charge.failed':
                if ($localSub && $localSub->status === Subscription::STATUS_ACTIVE) {
                    app(SubscriptionLifecycleService::class)->markPastDue($localSub);
                }
                break;

            case 'subscription.charge.refunded':
                if ($order && $order->status === 'completed' && $dispatchId !== '') {
                    ProcessPaymentWebhook::dispatchSync(self::SLUG, $dispatchId, 'order.refunded', 'refunded', $webhookMeta);
                }
                break;

            default:
                Log::debug('CajuPayWebhook: subscription event não tratado', ['event' => $eventType]);
                break;
        }

        return response()->json(['received' => true]);
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function renewLocalSubscriptionFromCharge(Subscription $subscription, array $object, string $paymentId): void
    {
        $subscription->loadMissing(['subscriptionPlan', 'user', 'product']);
        $plan = $subscription->subscriptionPlan;
        if (! $plan) {
            return;
        }

        [$periodStart, $periodEnd] = $plan->getCurrentPeriod();
        $subscription->update([
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
            'past_due_at' => null,
        ]);

        event(new \App\Events\SubscriptionRenewed($subscription->fresh()));

        if ($subscription->user_id && $subscription->product_id) {
            $subscription->product?->users()->syncWithoutDetaching([$subscription->user_id]);
        }

        // Pedido de renovação contábil (já liquidado na CajuPay)
        $chargeId = (string) ($object['id'] ?? $object['charge_id'] ?? '');
        $exists = Order::query()
            ->where('gateway', self::SLUG)
            ->where('user_id', $subscription->user_id)
            ->where('subscription_plan_id', $subscription->subscription_plan_id)
            ->where('is_renewal', true)
            ->where(function ($q) use ($chargeId, $paymentId) {
                if ($chargeId !== '') {
                    $q->orWhere('metadata->cajupay_charge_id', $chargeId);
                }
                if ($paymentId !== '') {
                    $q->orWhere('metadata->cajupay_payment_id', $paymentId)
                        ->orWhere('gateway_id', $paymentId);
                }
            })
            ->exists();

        if ($exists) {
            return;
        }

        $amount = (float) $plan->price;
        $order = Order::create([
            'tenant_id' => $subscription->tenant_id,
            'user_id' => $subscription->user_id,
            'product_id' => $subscription->product_id,
            'subscription_plan_id' => $subscription->subscription_plan_id,
            'amount' => $amount,
            'currency' => $plan->getCurrencyOrDefault(),
            'email' => $subscription->user?->email,
            'status' => 'completed',
            'gateway' => self::SLUG,
            'gateway_id' => $paymentId !== '' ? $paymentId : ($chargeId !== '' ? $chargeId : $subscription->gateway_subscription_id),
            'is_renewal' => true,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'metadata' => [
                'checkout_payment_method' => 'pix_auto',
                'cajupay_subscription_id' => $subscription->gateway_subscription_id,
                'cajupay_charge_id' => $chargeId !== '' ? $chargeId : null,
                'cajupay_payment_id' => $paymentId !== '' ? $paymentId : null,
                'auto_renewal_subscription_id' => $subscription->id,
            ],
        ]);

        event(new \App\Events\OrderCompleted($order));
    }

    /**
     * @param  array<string, mixed>|null  $object
     */
    private function pickSubscriptionId(?array $object): string
    {
        if ($object === null) {
            return '';
        }
        foreach (['subscription_id', 'cajupay_subscription_id'] as $key) {
            $value = $object[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        // Em alguns eventos o id do objeto é a própria assinatura
        $typeHint = strtolower((string) ($object['object'] ?? $object['type'] ?? ''));
        if (str_contains($typeHint, 'subscription') || ! isset($object['charge_id'])) {
            $id = $object['id'] ?? null;
            if (is_string($id) && $id !== '' && preg_match('/^[0-9a-f-]{36}$/i', $id)) {
                // Prefer explicit subscription_id; only use id when no payment_id-like fields
                if (! isset($object['amount_cents']) || isset($object['frequency'])) {
                    return $id;
                }
            }
        }

        return '';
    }

    /**
     * @return array<string, string>
     */
    private function parseSignatureHeader(string $header): array
    {
        $out = [];
        if ($header === '') {
            return $out;
        }
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            $out[strtolower(trim($kv[0]))] = trim($kv[1]);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function extractObject(array $payload): ?array
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

    /**
     * @param  array<string, mixed>|null  $object
     */
    private function pickSessionId(?array $object): ?string
    {
        if ($object === null) {
            return null;
        }
        foreach (['checkout_session_id', 'checkout_sessionId', 'session_id'] as $k) {
            $v = $object[$k] ?? null;
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return null;
    }

    private function resolveSigningSecret(string $rawBody, string $timestamp, string $expectedHex, ?int $preferTenantId): ?string
    {
        if ($expectedHex === '' || $timestamp === '') {
            return null;
        }
        $payloadToSign = $timestamp.'.'.$rawBody;

        $query = GatewayCredential::query()->where('gateway_slug', self::SLUG);
        if ($preferTenantId !== null) {
            $query->where('tenant_id', $preferTenantId);
        }
        $candidates = $query->get();

        if ($candidates->isEmpty() && $preferTenantId !== null) {
            $candidates = GatewayCredential::where('gateway_slug', self::SLUG)->get();
        }

        foreach ($candidates as $cred) {
            $creds = $cred->getDecryptedCredentials();
            $secret = is_array($creds) ? trim((string) ($creds['webhook_signing_secret'] ?? '')) : '';
            if ($secret === '') {
                continue;
            }
            $computed = hash_hmac('sha256', $payloadToSign, $secret, false);
            if (hash_equals(strtolower($computed), $expectedHex)) {
                return $secret;
            }
        }

        return null;
    }
}
