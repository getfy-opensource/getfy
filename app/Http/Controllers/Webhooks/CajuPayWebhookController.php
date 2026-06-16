<?php

namespace App\Http\Controllers\Webhooks;

use App\Gateways\CajuPay\CajuPayDriver;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\PayoutRequest;
use App\Models\RefundRequest;
use App\Services\PayoutService;
use App\Services\RefundService;
use App\Support\CajuPayPaymentId;
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

        $order = $this->findOrderForWebhook($sessionId, $paymentId, $object);

        $signingSecret = $this->resolveSigningSecret($raw, (string) $signatureTs, $signatureHex, $order?->tenant_id);
        if ($signingSecret === null) {
            Log::warning('CajuPayWebhook: assinatura inválida ou sem signing_secret', [
                'event' => $eventType,
                'payment_id' => $paymentId,
                'session_id' => $sessionId,
                'order_id' => $order?->id,
            ]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($order === null) {
            if ($this->isPaidEvent($eventType) && is_string($sessionId) && $sessionId !== '') {
                $pollingToken = Cache::get('cajupay_session_by_checkout.'.$sessionId);
                $hasDraft = is_string($pollingToken) && $pollingToken !== ''
                    && Cache::has('cajupay_draft.'.$pollingToken);
                Log::warning('CajuPayWebhook: pagamento aprovado sem pedido no Getfy', [
                    'event' => $eventType,
                    'session_id' => $sessionId,
                    'payment_id' => $paymentId,
                    'draft_still_in_cache' => $hasDraft,
                    'hint' => $hasDraft
                        ? 'Cliente pode ter pago na wallet antes do confirm-order; peça para preencher dados e usar "Tentar novamente".'
                        : 'Verifique se confirm-order foi chamado antes do pagamento.',
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

        if ($paymentId !== '' && $order->gateway_id !== $paymentId) {
            try {
                $order->update(['gateway_id' => $paymentId]);
                $order->refresh();
            } catch (\Throwable $e) {
                Log::debug('CajuPayWebhook: falha ao atualizar gateway_id', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $dispatchId = $paymentId !== ''
            ? $paymentId
            : (string) ($order->gateway_id ?: $sessionId ?: '');
        $refundId = is_array($object) && is_string($object['refund_id'] ?? null) ? $object['refund_id'] : null;
        $webhookMeta = array_merge($payload, ['webhook_source' => 'cajupay_hmac_verified']);

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
        }

        return null;
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
