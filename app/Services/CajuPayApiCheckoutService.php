<?php

namespace App\Services;

use App\Events\OrderPending;
use App\Gateways\GatewayRegistry;
use App\Models\ApiApplication;
use App\Models\ApiCheckoutSession;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\CajuPayLocale;
use App\Support\CajuPayPartnerCheckoutUrl;
use App\Support\CheckoutPaymentMethodsBuilder;
use App\Support\MoneyMinorUnits;
use App\Support\OrderReportingAmounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CajuPayApiCheckoutService
{
    public const DRAFT_PREFIX = 'api_cajupay_draft.';

    /**
     * @return array{success: bool, token: string, checkout_session_id: string, polling_token: string, methods_available: array, method_supported: bool|null}
     */
    public function createSdkSession(ApiCheckoutSession $session, string $paymentMethod, ?string $displayCurrency = null): array
    {
        $this->assertSessionActive($session);
        $app = $session->apiApplication;
        if (! $app) {
            throw new \RuntimeException('Aplicação indisponível.');
        }

        $pg = is_array($app->payment_gateways) ? $app->payment_gateways : ApiApplication::defaultPaymentGateways();
        $plan = $this->resolveSubscriptionPlan($session);
        $methods = CheckoutPaymentMethodsBuilder::build($app->tenant_id, $pg, $plan);
        $methodEntry = CheckoutPaymentMethodsBuilder::findMethod($methods, $paymentMethod);
        if (! $methodEntry || ($methodEntry['gateway_slug'] ?? '') !== 'cajupay') {
            throw new \RuntimeException('CajuPay não está configurado para este método.');
        }

        $credential = GatewayCredential::forTenant($app->tenant_id)
            ->where('gateway_slug', 'cajupay')
            ->where('is_connected', true)
            ->first();
        if (! $credential) {
            throw new \RuntimeException('CajuPay não está conectado.');
        }
        $credentials = $credential->getDecryptedCredentials();
        if (empty($credentials['public_key']) || empty($credentials['secret_key'])) {
            throw new \RuntimeException('CajuPay: chaves de API não configuradas.');
        }

        [$chargeCurrency, $chargeAmount] = $this->resolveCharge($session, $displayCurrency);
        $customer = is_array($session->customer) ? $session->customer : [];
        $consumer = $this->buildConsumer($customer);

        $defaultMethodMap = [
            'card' => 'card',
            'apple_pay' => 'apple_pay',
            'google_pay' => 'google_pay',
        ];
        $allowedMethods = [$paymentMethod];
        if ($paymentMethod === 'apple_pay' || $paymentMethod === 'google_pay') {
            $allowedMethods[] = 'card';
        }
        $allowedMethods = array_values(array_unique($allowedMethods));

        $description = $this->sessionDescription($session, $app);
        $externalRef = (string) Str::uuid();

        $driver = GatewayRegistry::driver('cajupay');
        if (! $driver) {
            throw new \RuntimeException('Driver CajuPay não disponível.');
        }
        /** @var \App\Gateways\CajuPay\CajuPayDriver $driver */
        $sessionResult = $driver->createSdkCheckoutSession(
            $credentials,
            MoneyMinorUnits::toMinorUnits($chargeAmount, $chargeCurrency),
            $chargeCurrency,
            $description,
            $externalRef,
            $consumer,
            $allowedMethods,
            $defaultMethodMap[$paymentMethod] ?? 'card',
            CajuPayLocale::fromCheckoutLocale('pt_BR'),
            CajuPayPartnerCheckoutUrl::forApiCheckoutSession($session)
        );

        $availableMethods = $driver->getSessionAvailableMethods($sessionResult['token'], $credentials);
        $pollingToken = Str::random(32);
        $checkoutSessionId = $sessionResult['checkout_session_id'];
        if (is_string($checkoutSessionId) && $checkoutSessionId !== '') {
            Cache::put('cajupay_session_by_checkout.' . $checkoutSessionId, $pollingToken, now()->addMinutes(30));
        }

        Cache::put(self::DRAFT_PREFIX . $pollingToken, [
            'api_checkout_session_id' => $session->id,
            'session_token' => $session->session_token,
            'tenant_id' => $app->tenant_id,
            'api_application_id' => $app->id,
            'product_id' => $session->product_id,
            'product_offer_id' => $session->product_offer_id,
            'subscription_plan_id' => $session->subscription_plan_id,
            'payment_method' => $paymentMethod,
            'charge_currency' => $chargeCurrency,
            'charge_amount' => $chargeAmount,
            'display_currency' => strtoupper((string) ($displayCurrency ?: $session->currency ?: 'BRL')),
            'display_amount' => (float) $session->amount,
            'cajupay_token' => $sessionResult['token'],
            'checkout_session_id' => $sessionResult['checkout_session_id'],
            'external_id' => $externalRef,
            'methods_available' => $availableMethods,
            'metadata' => $session->metadata ?? [],
            'created_at' => time(),
        ], now()->addMinutes(30));

        return [
            'success' => true,
            'token' => $sessionResult['token'],
            'checkout_session_id' => $sessionResult['checkout_session_id'],
            'polling_token' => $pollingToken,
            'methods_available' => $availableMethods,
            'method_supported' => $availableMethods === [] ? null : in_array($paymentMethod, $availableMethods, true),
        ];
    }

    /**
     * @param  array<string, mixed>  $customerOverrides
     * @return array{success: bool, order_id: int, polling_token: string, polling_url: string, idempotent?: bool}
     */
    public function confirmOrder(Request $request, ApiCheckoutSession $session, string $pollingToken, array $customerOverrides = []): array
    {
        $this->assertSessionActive($session);
        $draftKey = self::DRAFT_PREFIX . $pollingToken;
        $draft = Cache::get($draftKey);
        if (! is_array($draft)) {
            $existingDisplay = session('cajupay_display.' . $pollingToken);
            if (is_array($existingDisplay) && ! empty($existingDisplay['order_id'])) {
                return [
                    'success' => true,
                    'order_id' => (int) $existingDisplay['order_id'],
                    'polling_token' => $pollingToken,
                    'polling_url' => route('checkout.order-status', ['token' => $pollingToken]),
                    'idempotent' => true,
                ];
            }

            throw new \RuntimeException('Sessão CajuPay expirada. Recarregue a página.');
        }

        if ((int) ($draft['api_checkout_session_id'] ?? 0) !== (int) $session->id) {
            throw new \RuntimeException('Sessão inválida.');
        }

        $customer = array_merge(is_array($session->customer) ? $session->customer : [], $customerOverrides);
        $email = trim((string) ($customer['email'] ?? ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('E-mail do comprador é obrigatório.');
        }

        $chargeCurrency = strtoupper((string) ($draft['charge_currency'] ?? 'BRL'));
        $cpfDigits = preg_replace('/\D/', '', (string) ($customer['cpf'] ?? ''));
        if ($chargeCurrency === 'BRL' && strlen($cpfDigits) !== 11) {
            throw new \RuntimeException('CPF do comprador é obrigatório para pagamento em BRL. Inclua `customer.cpf` ao criar a sessão de checkout.');
        }

        $validated = [
            'email' => $email,
            'name' => trim((string) ($customer['name'] ?? '')) ?: $email,
            'cpf' => $cpfDigits,
            'phone' => trim((string) ($customer['phone'] ?? '')),
        ];

        $order = $this->createOrderFromDraft($request, $session, $draft, $validated);
        event(new OrderPending($order->fresh()));

        $session->update(['order_id' => $order->id]);

        session()->put('cajupay_display.' . $pollingToken, [
            'order_id' => $order->id,
            'checkout_session_id' => $draft['checkout_session_id'],
            'session_token' => $draft['cajupay_token'],
            'payment_method' => $draft['payment_method'],
            'amount' => (float) $draft['charge_amount'],
            'product_name' => $this->sessionDescription($session, $session->apiApplication),
            'redirect_after_purchase' => route('api-checkout.thank-you', ['order_id' => $order->id]),
            'customer_name' => $validated['name'],
            'customer_email' => $validated['email'],
            'customer_phone' => $validated['phone'] ?? null,
            'created_at' => time(),
        ]);

        Cache::forget($draftKey);

        return [
            'success' => true,
            'order_id' => $order->id,
            'polling_token' => $pollingToken,
            'polling_url' => route('checkout.order-status', ['token' => $pollingToken]),
        ];
    }

    public function resolveSession(string $sessionToken): ApiCheckoutSession
    {
        $session = ApiCheckoutSession::where('session_token', $sessionToken)->with('apiApplication')->first();
        if (! $session || $session->isExpired()) {
            throw new \RuntimeException('Sessão inválida ou expirada.');
        }
        $this->assertSessionActive($session);

        return $session;
    }

    private function assertSessionActive(ApiCheckoutSession $session): void
    {
        if ($session->isExpired()) {
            throw new \RuntimeException('Sessão inválida ou expirada.');
        }
        $app = $session->apiApplication;
        if (! $app || ! $app->is_active) {
            throw new \RuntimeException('Aplicação indisponível.');
        }
    }

    /**
     * @return array{0: string, 1: float}
     */
    private function resolveCharge(ApiCheckoutSession $session, ?string $displayCurrency): array
    {
        $currency = strtoupper((string) ($session->currency ?? 'BRL'));
        $amount = (float) $session->amount;
        if ($amount < 0.01) {
            throw new \RuntimeException('Valor inválido.');
        }

        return [$currency, $amount];
    }

    /**
     * @param  array<string, mixed>  $customer
     * @return array{name: string, email: string, document: string, phone?: string}
     */
    private function buildConsumer(array $customer): array
    {
        $email = trim((string) ($customer['email'] ?? ''));
        $name = trim((string) ($customer['name'] ?? ''));
        $document = preg_replace('/\D/', '', (string) ($customer['cpf'] ?? ''));
        $phone = trim((string) ($customer['phone'] ?? ''));

        $consumer = [
            'name' => $name !== '' ? $name : $email,
            'email' => $email,
            'document' => $document,
        ];
        if ($phone !== '') {
            $consumer['phone'] = $phone;
        }

        return $consumer;
    }

    private function sessionDescription(ApiCheckoutSession $session, ?ApiApplication $app): string
    {
        if ($session->product_id) {
            $product = Product::find($session->product_id);
            if ($product) {
                return $product->name;
            }
        }
        $meta = is_array($session->metadata) ? $session->metadata : [];
        $title = trim((string) ($meta['title'] ?? $meta['product_name'] ?? ''));
        if ($title !== '') {
            return $title;
        }

        return $app?->name ?: 'Pagamento';
    }

    private function resolveSubscriptionPlan(ApiCheckoutSession $session): ?SubscriptionPlan
    {
        if (! $session->subscription_plan_id) {
            return null;
        }
        $plan = SubscriptionPlan::with('product')->find($session->subscription_plan_id);
        if (! $plan || ! $plan->product) {
            return null;
        }
        $app = $session->apiApplication;
        if ($app && (int) $plan->product->tenant_id !== (int) $app->tenant_id) {
            return null;
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $validated
     */
    private function createOrderFromDraft(Request $request, ApiCheckoutSession $session, array $draft, array $validated): Order
    {
        $app = $session->apiApplication;
        $tenantId = (int) $app->tenant_id;
        $chargeCurrency = strtoupper((string) ($draft['charge_currency'] ?? 'BRL'));
        $totalAmount = (float) ($draft['charge_amount'] ?? $session->amount);

        $product = null;
        $productOfferId = $session->product_offer_id;
        $subscriptionPlanId = $session->subscription_plan_id;
        $plan = null;
        $periodStart = null;
        $periodEnd = null;

        if ($session->product_id) {
            $product = Product::where('id', $session->product_id)->where('tenant_id', $tenantId)->where('is_active', true)->first();
        }
        if (! $product && $subscriptionPlanId) {
            $plan = SubscriptionPlan::with('product')->find($subscriptionPlanId);
            if ($plan && $plan->product && (int) $plan->product->tenant_id === $tenantId) {
                $product = $plan->product;
            } else {
                $plan = null;
            }
        }
        if (! $product && $productOfferId) {
            $offer = ProductOffer::with('product')->find($productOfferId);
            if ($offer && $offer->product && (int) $offer->product->tenant_id === $tenantId) {
                $product = $offer->product;
            }
        }
        if ($product && $plan) {
            [$periodStart, $periodEnd] = $plan->getCurrentPeriod();
        }

        $user = User::firstOrCreate(
            ['email' => $validated['email']],
            [
                'name' => $validated['name'],
                'password' => bcrypt(Str::random(32)),
                'role' => User::ROLE_ALUNO,
                'tenant_id' => $tenantId,
            ]
        );

        $orderMetadata = array_merge(is_array($session->metadata) ? $session->metadata : [], [
            'source' => 'api_checkout_pro',
            'checkout_payment_method' => $draft['payment_method'],
            'cajupay_session_token' => $draft['cajupay_token'] ?? null,
            'cajupay_checkout_session_id' => $draft['checkout_session_id'] ?? null,
        ]);
        if ($chargeCurrency !== 'BRL') {
            $amountBrl = OrderReportingAmounts::estimateAmountBrl($totalAmount, $chargeCurrency, $tenantId);
            if ($amountBrl !== null && $amountBrl > 0) {
                $orderMetadata['amount_brl'] = $amountBrl;
            }
        }

        $order = Order::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'product_id' => $product?->id,
            'product_offer_id' => $productOfferId,
            'subscription_plan_id' => $subscriptionPlanId,
            'api_application_id' => $app->id,
            'api_checkout_session_id' => $session->id,
            'status' => 'pending',
            'amount' => $totalAmount,
            'currency' => $chargeCurrency,
            'email' => $validated['email'],
            'cpf' => strlen((string) ($validated['cpf'] ?? '')) >= 11 ? $validated['cpf'] : null,
            'phone' => $validated['phone'] ?? null,
            'customer_ip' => $request->ip(),
            'coupon_code' => null,
            'metadata' => $orderMetadata,
            'gateway' => 'cajupay',
            'gateway_id' => $draft['checkout_session_id'] ?? null,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'is_renewal' => false,
        ]);

        if ($product) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_offer_id' => $productOfferId,
                'subscription_plan_id' => $subscriptionPlanId,
                'amount' => $totalAmount,
                'position' => 0,
            ]);
        }

        return $order;
    }
}
