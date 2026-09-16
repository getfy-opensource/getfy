<?php

namespace App\Services;

use App\Gateways\CajuPay\CajuPayDriver;
use App\Gateways\GatewayRegistry;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Support\CajuPayPaymentId;
use App\Support\FakeConsumerData;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Support\MoneyMinorUnits;
use Illuminate\Support\Facades\Log;

class CajuPaySubscriptionService
{
    /**
     * Intervalos Getfy suportados pela API CajuPay PIX Automático.
     *
     * @var array<string, string>
     */
    public const INTERVAL_FREQUENCY_MAP = [
        SubscriptionPlan::INTERVAL_WEEKLY => 'WEEKLY',
        SubscriptionPlan::INTERVAL_MONTHLY => 'MONTHLY',
        SubscriptionPlan::INTERVAL_QUARTERLY => 'QUARTERLY',
        SubscriptionPlan::INTERVAL_SEMI_ANNUAL => 'SEMIANNUALLY',
        SubscriptionPlan::INTERVAL_ANNUAL => 'ANNUALLY',
    ];

    public function credentialsForTenant(?int $tenantId): ?array
    {
        $credential = GatewayCredential::forTenant($tenantId)
            ->where('gateway_slug', 'cajupay')
            ->where('is_connected', true)
            ->first();
        if (! $credential) {
            return null;
        }

        return $credential->getDecryptedCredentials();
    }

    public static function supportsPlanInterval(?string $interval): bool
    {
        $interval = strtolower(trim((string) $interval));

        return $interval !== '' && isset(self::INTERVAL_FREQUENCY_MAP[$interval]);
    }

    public static function mapFrequency(string $interval): string
    {
        $interval = strtolower(trim($interval));
        if (! isset(self::INTERVAL_FREQUENCY_MAP[$interval])) {
            throw new \InvalidArgumentException('Intervalo de plano não suportado pelo PIX Automático CajuPay: '.$interval);
        }

        return self::INTERVAL_FREQUENCY_MAP[$interval];
    }

    /**
     * @param  array{name: string, email: string, document: string, phone?: string, address: array<string, string>}  $consumer
     * @return array{subscription_id: string, correlation_id: string, status: string, pix_emv: ?string, pix_copy_paste: ?string, raw: array<string, mixed>}
     */
    public function createPixAutomatic(
        Order $order,
        SubscriptionPlan $plan,
        array $consumer,
        float $amountBrl,
        ?string $subscriptionName = null,
    ): array {
        $credentials = $this->credentialsForTenant($order->tenant_id);
        if (! $credentials) {
            throw new \RuntimeException('CajuPay não configurada para este tenant.');
        }

        $frequency = self::mapFrequency((string) $plan->interval);
        $chargeDay = max(1, min(28, (int) now()->day));
        $paymentDueDays = 7;
        $name = trim((string) ($subscriptionName ?: ($plan->name ?: 'Assinatura')));
        if ($name === '') {
            $name = 'Assinatura';
        }
        $name = mb_substr($name, 0, 120);

        $correlationId = 'order-'.$order->id;
        $idempotencyKey = 'getfy-sub-'.$order->id.'-pix-auto';

        $address = self::resolveBrazilAddress($consumer['address'] ?? []);
        $body = [
            'method' => 'pix_automatic',
            'name' => $name,
            'value_cents' => MoneyMinorUnits::toMinorUnits($amountBrl, 'BRL'),
            'frequency' => $frequency,
            'journey' => 'PAYMENT_ON_APPROVAL',
            'retry_policy' => 'THREE_RETRIES_7_DAYS',
            'correlation_id' => $correlationId,
            'day_generate_charge' => $chargeDay,
            'day_due' => $paymentDueDays,
            'customer' => [
                'name' => trim((string) ($consumer['name'] ?? 'Cliente')),
                'tax_id' => preg_replace('/\D/', '', (string) ($consumer['document'] ?? '')) ?: '',
                'email' => trim((string) ($consumer['email'] ?? '')),
                'phone' => $this->normalizePhone((string) ($consumer['phone'] ?? '')),
                'address' => array_merge($address, ['country' => 'BR']),
            ],
        ];

        if ($body['customer']['phone'] === '') {
            unset($body['customer']['phone']);
        }

        $data = $this->driver()->createSubscription($credentials, $body, $idempotencyKey);

        $subscriptionId = (string) ($data['subscription_id'] ?? $data['id'] ?? '');
        if ($subscriptionId === '') {
            throw new \RuntimeException('CajuPay: subscription_id ausente na resposta.');
        }

        $pixEmv = null;
        foreach (['pix_emv', 'pix_copy_paste', 'pixCopiaECola'] as $key) {
            $v = $data[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                $pixEmv = trim($v);
                break;
            }
        }

        return [
            'subscription_id' => $subscriptionId,
            'correlation_id' => (string) ($data['correlation_id'] ?? $correlationId),
            'status' => (string) ($data['status'] ?? 'pending_approval'),
            'pix_emv' => $pixEmv,
            'pix_copy_paste' => $pixEmv,
            'raw' => $data,
        ];
    }

    /**
     * Assinatura cartão CajuPay (módulo 26 — method: card + card_token).
     *
     * @param  array{name: string, email: string, document?: string, phone?: string}  $consumer
     * @return array{subscription_id: string, correlation_id: string, status: string, card_brand: ?string, card_last4: ?string, raw: array<string, mixed>}
     */
    public function createCard(
        Order $order,
        SubscriptionPlan $plan,
        array $consumer,
        float $amount,
        string $cardToken,
        ?string $cardBrand = null,
        ?string $securityCode = null,
        ?string $subscriptionName = null,
        string $currency = 'BRL',
    ): array {
        $credentials = $this->credentialsForTenant($order->tenant_id);
        if (! $credentials) {
            throw new \RuntimeException('CajuPay não configurada para este tenant.');
        }

        $cardToken = trim($cardToken);
        if ($cardToken === '') {
            throw new \RuntimeException('CajuPay: card_token obrigatório para assinatura com cartão.');
        }

        $frequency = self::mapFrequency((string) $plan->interval);
        $name = trim((string) ($subscriptionName ?: ($plan->name ?: 'Assinatura')));
        if ($name === '') {
            $name = 'Assinatura';
        }
        $name = mb_substr($name, 0, 120);
        $correlationId = 'order-'.$order->id;
        $idempotencyKey = 'getfy-sub-'.$order->id.'-card';

        $body = [
            'method' => 'card',
            'name' => $name,
            'value_cents' => MoneyMinorUnits::toMinorUnits($amount, $currency),
            'frequency' => $frequency,
            'correlation_id' => $correlationId,
            'card_token' => $cardToken,
            'customer' => array_filter([
                'name' => trim((string) ($consumer['name'] ?? 'Cliente')),
                'tax_id' => preg_replace('/\D/', '', (string) ($consumer['document'] ?? '')) ?: null,
                'email' => trim((string) ($consumer['email'] ?? '')),
                'phone' => $this->normalizePhone((string) ($consumer['phone'] ?? '')),
            ], static fn ($v) => $v !== null && $v !== ''),
        ];
        if (is_string($cardBrand) && trim($cardBrand) !== '') {
            $body['card_brand'] = trim($cardBrand);
        }
        if (is_string($securityCode) && trim($securityCode) !== '') {
            $body['security_code'] = trim($securityCode);
        }

        $data = $this->driver()->createSubscription($credentials, $body, $idempotencyKey);

        $subscriptionId = (string) ($data['subscription_id'] ?? $data['id'] ?? '');
        if ($subscriptionId === '') {
            throw new \RuntimeException('CajuPay: subscription_id ausente na resposta (cartão).');
        }

        return [
            'subscription_id' => $subscriptionId,
            'correlation_id' => (string) ($data['correlation_id'] ?? $correlationId),
            'status' => (string) ($data['status'] ?? 'active'),
            'card_brand' => isset($data['card_brand']) ? (string) $data['card_brand'] : null,
            'card_last4' => isset($data['card_last4']) ? (string) $data['card_last4'] : null,
            'raw' => $data,
        ];
    }

    /**
     * Extrai card_token / brand / last4 de payload de webhook ou sessão.
     *
     * @param  array<string, mixed>  $payload
     * @return array{card_token: ?string, card_brand: ?string, card_last4: ?string}
     */
    public static function extractCardTokenPayload(array $payload): array
    {
        $candidates = [$payload];
        foreach (['object', 'data', 'charge', 'payment', 'session'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $candidates[] = $payload[$key];
            }
        }

        $token = null;
        $brand = null;
        $last4 = null;
        foreach ($candidates as $row) {
            foreach (['card_token', 'payment_method_token', 'saved_card_token'] as $tk) {
                $v = $row[$tk] ?? null;
                if (is_string($v) && trim($v) !== '') {
                    $token = trim($v);
                    break 2;
                }
            }
        }
        foreach ($candidates as $row) {
            if ($brand === null) {
                $b = $row['card_brand'] ?? ($row['brand'] ?? null);
                if (is_string($b) && trim($b) !== '') {
                    $brand = trim($b);
                }
            }
            if ($last4 === null) {
                $l = $row['card_last4'] ?? ($row['last4'] ?? ($row['last_four'] ?? null));
                if (is_string($l) && trim($l) !== '') {
                    $last4 = substr(preg_replace('/\D/', '', $l) ?: trim($l), -4);
                } elseif (is_int($l)) {
                    $last4 = substr((string) $l, -4);
                }
            }
        }

        return [
            'card_token' => $token,
            'card_brand' => $brand,
            'card_last4' => $last4,
        ];
    }

    /**
     * Persiste card_token no pedido + SavedPaymentMethod e tenta registrar assinatura remota method:card.
     *
     * @param  array<string, mixed>  $payload
     */
    public function persistCardTokenAndMaybeCreateRemote(Order $order, array $payload): void
    {
        $extracted = self::extractCardTokenPayload($payload);
        $cardToken = $extracted['card_token'];
        if ($cardToken === null) {
            $meta = is_array($order->metadata) ? $order->metadata : [];
            $fromMeta = trim((string) ($meta['cajupay_card_token'] ?? ''));
            $cardToken = $fromMeta !== '' ? $fromMeta : null;
        }
        if ($cardToken === null) {
            return;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $meta['cajupay_card_token'] = $cardToken;
        if ($extracted['card_brand']) {
            $meta['cajupay_card_brand'] = $extracted['card_brand'];
        }
        if ($extracted['card_last4']) {
            $meta['cajupay_card_last4'] = $extracted['card_last4'];
        }
        $order->update(['metadata' => $meta]);

        $saved = null;
        if ($order->user_id) {
            $saved = \App\Models\SavedPaymentMethod::query()->firstOrCreate(
                [
                    'tenant_id' => $order->tenant_id,
                    'user_id' => $order->user_id,
                    'gateway' => 'cajupay',
                    'gateway_payment_method_id' => $cardToken,
                ],
                [
                    'last_four' => $extracted['card_last4'] ?? ($meta['cajupay_card_last4'] ?? null),
                    'brand' => $extracted['card_brand'] ?? ($meta['cajupay_card_brand'] ?? null),
                    'type' => 'card',
                ]
            );
        }

        if (! $order->subscription_plan_id || ! $order->user_id) {
            return;
        }

        $plan = $order->subscriptionPlan;
        if (! $plan || ! self::supportsPlanInterval((string) $plan->interval)) {
            return;
        }

        $paymentMethod = strtolower((string) ($meta['checkout_payment_method'] ?? ''));
        if ($paymentMethod !== '' && ! in_array($paymentMethod, ['card', 'apple_pay', 'google_pay'], true)) {
            return;
        }

        // Já tem assinatura remota CajuPay vinculada.
        $existingSub = Subscription::query()
            ->where('user_id', $order->user_id)
            ->where('product_id', $order->product_id)
            ->where('subscription_plan_id', $plan->id)
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
            ->orderByDesc('id')
            ->first();
        if ($existingSub && trim((string) $existingSub->gateway_subscription_id) !== '') {
            if ($saved && empty($existingSub->saved_payment_method_id)) {
                $existingSub->update(['saved_payment_method_id' => $saved->id]);
            }

            return;
        }

        try {
            $user = $order->user;
            $result = $this->createCard(
                $order,
                $plan,
                [
                    'name' => (string) ($user?->name ?? 'Cliente'),
                    'email' => (string) ($user?->email ?? ''),
                    'document' => (string) ($meta['customer_document'] ?? ''),
                    'phone' => (string) ($meta['customer_phone'] ?? ''),
                ],
                (float) $order->amount,
                $cardToken,
                $extracted['card_brand'] ?? ($meta['cajupay_card_brand'] ?? null),
                null,
                $plan->name,
                $order->getCurrencyOrDefault()
            );

            $meta['cajupay_subscription_id'] = $result['subscription_id'];
            $meta['cajupay_subscription_status'] = $result['status'];
            $meta['cajupay_subscription_correlation_id'] = $result['correlation_id'];
            $order->update(['metadata' => $meta]);

            if ($existingSub) {
                $existingSub->update([
                    'gateway_subscription_id' => $result['subscription_id'],
                    'saved_payment_method_id' => $saved?->id ?? $existingSub->saved_payment_method_id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('CajuPaySubscriptionService: falha ao criar assinatura cartão remota', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelRemote(Subscription $subscription): array
    {
        $subscriptionId = trim((string) $subscription->gateway_subscription_id);
        if ($subscriptionId === '') {
            throw new \RuntimeException('Assinatura sem ID CajuPay.');
        }

        $credentials = $this->credentialsForTenant($subscription->tenant_id);
        if (! $credentials) {
            throw new \RuntimeException('CajuPay não configurada para este tenant.');
        }

        return $this->driver()->cancelSubscription($credentials, $subscriptionId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRemote(Subscription $subscription): ?array
    {
        $subscriptionId = trim((string) $subscription->gateway_subscription_id);
        if ($subscriptionId === '') {
            return null;
        }

        $credentials = $this->credentialsForTenant($subscription->tenant_id);
        if (! $credentials) {
            return null;
        }

        try {
            return $this->driver()->getSubscription($credentials, $subscriptionId);
        } catch (\Throwable $e) {
            Log::debug('CajuPaySubscriptionService getRemote', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCharges(Subscription $subscription): array
    {
        $subscriptionId = trim((string) $subscription->gateway_subscription_id);
        if ($subscriptionId === '') {
            return [];
        }

        $credentials = $this->credentialsForTenant($subscription->tenant_id);
        if (! $credentials) {
            return [];
        }

        try {
            return $this->driver()->listSubscriptionCharges($credentials, $subscriptionId);
        } catch (\Throwable $e) {
            Log::warning('CajuPaySubscriptionService listCharges', [
                'subscription_id' => $subscription->id,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $charges
     * @return list<array{id: string, status: string, amount_cents: int, due_at: mixed, paid_at: mixed, payment_id: mixed}>
     */
    public function mapChargesForPanel(array $charges): array
    {
        $mapped = [];
        foreach ($charges as $charge) {
            if (! is_array($charge)) {
                continue;
            }
            $mapped[] = [
                'id' => (string) ($charge['id'] ?? $charge['charge_id'] ?? ''),
                'status' => (string) ($charge['status'] ?? ''),
                'amount_cents' => (int) ($charge['amount_cents'] ?? $charge['value_cents'] ?? 0),
                'due_at' => $charge['due_at'] ?? $charge['due_date'] ?? null,
                'paid_at' => $charge['paid_at'] ?? null,
                'payment_id' => $charge['payment_id'] ?? $charge['cajupay_payment_id'] ?? null,
            ];
        }

        return $mapped;
    }

    public function subscriptionIdFromOrder(Order $order): ?string
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $fromMeta = trim((string) ($meta['cajupay_subscription_id'] ?? ''));
        if ($fromMeta !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $fromMeta)) {
            return $fromMeta;
        }

        if (($meta['checkout_payment_method'] ?? '') !== 'pix_auto') {
            return null;
        }

        $gatewayId = trim((string) ($order->gateway_id ?? ''));
        if ($gatewayId !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $gatewayId)) {
            return $gatewayId;
        }

        return null;
    }

    public function isChargePaidStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), ['paid', 'succeeded', 'completed', 'approved', 'settled'], true);
    }

    /**
     * Consulta a CajuPay (sync + cobranças) para detectar parcela paga de um pedido pendente.
     *
     * @return array<string, mixed>|null
     */
    public function findPaidChargeForOrder(Order $order): ?array
    {
        $subscriptionId = $this->subscriptionIdFromOrder($order);
        if ($subscriptionId === null) {
            return null;
        }

        $credentials = $this->credentialsForTenant($order->tenant_id);
        if (! $credentials) {
            return null;
        }

        try {
            $this->driver()->syncSubscription($credentials, $subscriptionId);
        } catch (\Throwable $e) {
            Log::debug('CajuPaySubscriptionService findPaidChargeForOrder sync', [
                'order_id' => $order->id,
                'subscription_id' => $subscriptionId,
                'message' => $e->getMessage(),
            ]);
        }

        try {
            $charges = $this->driver()->listSubscriptionCharges($credentials, $subscriptionId);
        } catch (\Throwable $e) {
            Log::debug('CajuPaySubscriptionService findPaidChargeForOrder charges', [
                'order_id' => $order->id,
                'subscription_id' => $subscriptionId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        foreach ($charges as $charge) {
            if (! is_array($charge)) {
                continue;
            }
            if ($this->isChargePaidStatus((string) ($charge['status'] ?? ''))) {
                return $charge;
            }
        }

        return null;
    }

    /**
     * Completa pedido pendente de PIX Automático quando a CajuPay já liquidou a cobrança.
     *
     * @param  array<string, mixed>  $charge
     */
    public function reconcilePendingOrderFromPaidCharge(
        Order $order,
        array $charge,
        string $source = 'subscription_reconcile',
        string $webhookSource = 'cajupay_subscription_verified',
    ): bool {
        if ($order->status !== 'pending') {
            return false;
        }

        $paymentId = trim((string) ($charge['cajupay_payment_id'] ?? $charge['payment_id'] ?? ''));
        if ($paymentId !== '') {
            CajuPayPaymentId::persistOnOrder($order, $paymentId);
            $order->refresh();
        }

        $subscriptionId = $this->subscriptionIdFromOrder($order);
        $dispatchId = $paymentId !== ''
            ? $paymentId
            : ($subscriptionId ?? trim((string) ($order->gateway_id ?? '')));

        if ($dispatchId === '') {
            return false;
        }

        ProcessPaymentWebhook::dispatchSync('cajupay', $dispatchId, 'order.paid', 'paid', [
            'source' => $source,
            'webhook_source' => $webhookSource,
            'data' => ['object' => $charge],
        ]);

        return $order->fresh()?->status === 'completed';
    }

    public function reconcilePendingOrderFromRemote(Order $order, string $source = 'subscription_reconcile'): bool
    {
        if ($order->status !== 'pending') {
            return false;
        }

        $charge = $this->findPaidChargeForOrder($order);
        if ($charge === null) {
            return false;
        }

        return $this->reconcilePendingOrderFromPaidCharge($order, $charge, $source);
    }

    /**
     * @return array<string, mixed>
     */
    public function syncRemote(Subscription $subscription): array
    {
        $subscriptionId = trim((string) $subscription->gateway_subscription_id);
        if ($subscriptionId === '') {
            throw new \RuntimeException('Assinatura sem ID CajuPay.');
        }

        $credentials = $this->credentialsForTenant($subscription->tenant_id);
        if (! $credentials) {
            throw new \RuntimeException('CajuPay não configurada para este tenant.');
        }

        return $this->driver()->syncSubscription($credentials, $subscriptionId);
    }

    /**
     * @return array<string, mixed>
     */
    public function retryCharge(Subscription $subscription, string $chargeId): array
    {
        $subscriptionId = trim((string) $subscription->gateway_subscription_id);
        if ($subscriptionId === '') {
            throw new \RuntimeException('Assinatura sem ID CajuPay.');
        }

        $credentials = $this->credentialsForTenant($subscription->tenant_id);
        if (! $credentials) {
            throw new \RuntimeException('CajuPay não configurada para este tenant.');
        }

        return $this->driver()->retrySubscriptionCharge($credentials, $subscriptionId, $chargeId);
    }

    /**
     * @return array<string, mixed>
     */
    public function refundCharge(Subscription $subscription, string $chargeId): array
    {
        $subscriptionId = trim((string) $subscription->gateway_subscription_id);
        if ($subscriptionId === '') {
            throw new \RuntimeException('Assinatura sem ID CajuPay.');
        }

        $credentials = $this->credentialsForTenant($subscription->tenant_id);
        if (! $credentials) {
            throw new \RuntimeException('CajuPay não configurada para este tenant.');
        }

        return $this->driver()->refundSubscriptionCharge($credentials, $subscriptionId, $chargeId);
    }

    public function isCajuPayManaged(Subscription $subscription): bool
    {
        $id = trim((string) $subscription->gateway_subscription_id);
        if ($id === '' || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            return false;
        }

        return Order::query()
            ->where('gateway', 'cajupay')
            ->where(function ($q) use ($id, $subscription) {
                $q->where('gateway_id', $id)
                    ->orWhere('metadata->cajupay_subscription_id', $id)
                    ->orWhere(function ($q2) use ($subscription) {
                        $q2->where('user_id', $subscription->user_id)
                            ->where('product_id', $subscription->product_id)
                            ->where('subscription_plan_id', $subscription->subscription_plan_id)
                            ->where('metadata->checkout_payment_method', 'pix_auto');
                    });
            })
            ->exists();
    }

    /**
     * Preenche endereço BR com valores padrão quando o checkout não coleta endereço.
     *
     * @param  array<string, mixed>  $address
     * @return array{zipcode: string, street: string, number: string, neighborhood: string, city: string, state: string}
     */
    public static function resolveBrazilAddress(array $address): array
    {
        $defaults = FakeConsumerData::defaultBrazilAddress();
        $zip = preg_replace('/\D/', '', (string) ($address['zipcode'] ?? $address['zip_code'] ?? ''));
        $street = trim((string) ($address['street'] ?? $address['street_name'] ?? ''));
        $number = trim((string) ($address['number'] ?? $address['street_number'] ?? ''));
        $neighborhood = trim((string) ($address['neighborhood'] ?? ''));
        $city = trim((string) ($address['city'] ?? ''));
        $state = strtoupper(substr(trim((string) ($address['state'] ?? $address['federal_unit'] ?? '')), 0, 2));

        return [
            'zipcode' => strlen($zip) >= 8 ? substr($zip, 0, 8) : $defaults['zipcode'],
            'street' => $street !== '' ? $street : $defaults['street'],
            'number' => $number !== '' ? $number : $defaults['number'],
            'neighborhood' => $neighborhood !== '' ? $neighborhood : $defaults['neighborhood'],
            'city' => $city !== '' ? $city : $defaults['city'],
            'state' => strlen($state) === 2 ? $state : $defaults['state'],
        ];
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (! is_string($digits) || strlen($digits) < 10) {
            return '';
        }

        if (! str_starts_with($digits, '55') && strlen($digits) <= 11) {
            $digits = '55'.$digits;
        }

        return $digits;
    }

    private function driver(): CajuPayDriver
    {
        $driver = GatewayRegistry::driver('cajupay');
        if (! $driver instanceof CajuPayDriver) {
            throw new \RuntimeException('Driver CajuPay indisponível.');
        }

        return $driver;
    }
}
