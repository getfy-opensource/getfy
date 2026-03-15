<?php

namespace App\Http\Controllers;

use App\Events\BoletoGenerated;
use App\Events\OrderCompleted;
use App\Events\OrderPending;
use App\Events\PixGenerated;
use App\Models\ApiApplication;
use App\Models\ApiCheckoutSession;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\GatewayCredential;
use App\Models\Setting;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\StorageService;
use App\Support\FakeConsumerData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ApiCheckoutController extends Controller
{
    /**
     * Show hosted checkout page (payment method selection only; customer data from session).
     */
    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $session = ApiCheckoutSession::where('session_token', $token)->with('apiApplication')->first();
        if (! $session || $session->isExpired()) {
            abort(404, 'Sessão inválida ou expirada.');
        }
        $app = $session->apiApplication;
        if (! $app || ! $app->is_active) {
            abort(404, 'Aplicação indisponível.');
        }

        $pg = $app->payment_gateways ?? [];
        $availableMethods = [];
        if (! empty($pg['pix'])) {
            $availableMethods[] = 'pix';
        }
        if (! empty($pg['card'])) {
            $availableMethods[] = 'card';
        }
        if (! empty($pg['boleto'])) {
            $availableMethods[] = 'boleto';
        }
        if (empty($availableMethods)) {
            abort(422, 'Nenhum método de pagamento configurado para esta aplicação.');
        }

        $cardGatewaySlug = ! empty($pg['card']) ? (string) $pg['card'] : null;
        $cardStripePublishableKey = '';
        $cardStripeSandbox = false;
        $cardStripeLinkEnabled = true;
        if ($cardGatewaySlug === 'stripe') {
            $cred = GatewayCredential::forTenant($app->tenant_id)->where('gateway_slug', 'stripe')->where('is_connected', true)->first();
            if ($cred) {
                $creds = $cred->getDecryptedCredentials();
                $cardStripePublishableKey = (string) ($creds['publishable_key'] ?? '');
                $cardStripeSandbox = ! empty($creds['sandbox']);
                $cardStripeLinkEnabled = isset($creds['link_enabled']) ? (bool) $creds['link_enabled'] : true;
            }
        }

        $productName = null;
        $productImageUrl = null;
        if ($session->product_id) {
            $product = Product::find($session->product_id);
            if ($product) {
                $productName = $product->name;
                if ($product->image) {
                    $productImageUrl = (new StorageService($product->tenant_id))->url($product->image);
                }
            }
        }

        $customer = $session->customer ?? [];
        $appLogoUrl = $app->logo
            ? (new StorageService($app->tenant_id))->url($app->logo)
            : null;

        $tenantId = $app->tenant_id;
        $currenciesRaw = Setting::get('currencies', null, $tenantId);
        $currencies = $currenciesRaw
            ? (is_string($currenciesRaw) ? json_decode($currenciesRaw, true) : $currenciesRaw)
            : config('products.currencies');
        $currencies = is_array($currencies) ? $currencies : config('products.currencies');

        return Inertia::render('ApiCheckout/Show', [
            'session_token' => $token,
            'app_name' => $app->name,
            'app_logo_url' => $appLogoUrl,
            'app_sidebar_bg_color' => $app->checkout_sidebar_bg ?? '#18181b',
            'customer_email' => $customer['email'] ?? null,
            'customer_name' => $customer['name'] ?? null,
            'amount' => (float) $session->amount,
            'currency' => $session->currency ?? 'BRL',
            'currencies' => $currencies,
            'product_name' => $productName,
            'product_image_url' => $productImageUrl,
            'available_methods' => $availableMethods,
            'return_url' => $session->return_url,
            'card_gateway_slug' => $cardGatewaySlug,
            'card_stripe_publishable_key' => $cardStripePublishableKey,
            'card_stripe_sandbox' => $cardStripeSandbox,
            'card_stripe_link_enabled' => $cardStripeLinkEnabled,
        ]);
    }

    /**
     * Process payment for the session (PIX, boleto, card with token).
     */
    public function process(Request $request): RedirectResponse
    {
        $rules = [
            'session_token' => ['required', 'string', 'max:64'],
            'payment_method' => ['required', 'string', 'in:pix,boleto,card'],
        ];
        if ($request->input('payment_method') === 'card') {
            $rules['payment_token'] = ['required', 'string', 'max:10000'];
            $rules['card_mask'] = ['nullable', 'string', 'max:32'];
        }
        $validated = $request->validate($rules);

        $session = ApiCheckoutSession::where('session_token', $validated['session_token'])->with('apiApplication')->first();
        if (! $session || $session->isExpired()) {
            return redirect()->back()->with('error', 'Sessão inválida ou expirada.');
        }
        $app = $session->apiApplication;
        if (! $app || ! $app->is_active) {
            return redirect()->back()->with('error', 'Aplicação indisponível.');
        }

        $pg = $app->payment_gateways ?? [];
        $method = $validated['payment_method'];
        if (empty($pg[$method])) {
            return redirect()->back()->with('error', 'Método de pagamento não disponível.');
        }

        $tenantId = $app->tenant_id;
        $customer = $session->customer;
        $email = $customer['email'] ?? '';
        $name = trim((string) ($customer['name'] ?? ''));
        if ($name === '') {
            $name = $email;
        }
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => bcrypt(Str::random(32)),
                'role' => User::ROLE_ALUNO,
                'tenant_id' => $tenantId,
            ]
        );

        $rawDoc = preg_replace('/\D/', '', (string) ($customer['cpf'] ?? ''));
        $fake = FakeConsumerData::getForGateway($session->id);
        $consumer = [
            'name' => $name ?: $fake['name'],
            'document' => strlen($rawDoc) >= 11 ? $rawDoc : $fake['document'],
            'email' => $email,
        ];

        $product = $session->product_id ? Product::find($session->product_id) : null;
        $amount = (float) $session->amount;
        $productOfferId = $session->product_offer_id;
        $subscriptionPlanId = $session->subscription_plan_id;
        $periodStart = null;
        $periodEnd = null;
        if ($product && $subscriptionPlanId) {
            $plan = SubscriptionPlan::find($subscriptionPlanId);
            if ($plan && $plan->product_id === $product->id) {
                [$periodStart, $periodEnd] = $plan->getCurrentPeriod();
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
            'amount' => $amount,
            'email' => $email,
            'cpf' => $customer['cpf'] ?? null,
            'phone' => $customer['phone'] ?? null,
            'customer_ip' => $request->ip(),
            'coupon_code' => null,
            'gateway' => null,
            'gateway_id' => null,
            'metadata' => array_merge($session->metadata ?? [], ['source' => 'api_checkout_pro']),
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
                'amount' => $amount,
                'position' => 0,
            ]);
            $product->users()->syncWithoutDetaching([$user->id]);
        }

        $session->update(['order_id' => $order->id]);
        $gatewayConfig = $app->payment_gateways ?? ApiApplication::defaultPaymentGateways();
        $paymentService = app(PaymentService::class);

        if ($method === 'pix') {
            try {
                event(new OrderPending($order));
                $result = $paymentService->createPixPayment($order, $product, $consumer, $gatewayConfig);
                event(new PixGenerated($order, [
                    'qrcode' => $result['qrcode'] ?? null,
                    'copy_paste' => $result['copy_paste'] ?? null,
                    'transaction_id' => $result['transaction_id'] ?? null,
                ]));
                $pixToken = Str::random(32);
                session()->put('pix_display.' . $pixToken, [
                    'order_id' => $order->id,
                    'qrcode' => $result['qrcode'] ?? null,
                    'copy_paste' => $result['copy_paste'] ?? null,
                    'amount' => $amount,
                    'product_name' => $product?->name ?? 'Pagamento',
                    'redirect_after_purchase' => route('api-checkout.thank-you', ['order_id' => $order->id]),
                    'created_at' => time(),
                ]);
                return redirect()->route('checkout.pix', ['token' => $pixToken]);
            } catch (\Throwable $e) {
                $order->delete();
                return redirect()->back()->with('error', $e->getMessage() ?: 'Não foi possível gerar o PIX.');
            }
        }

        if ($method === 'boleto') {
            try {
                event(new OrderPending($order));
                $result = $paymentService->createBoletoPayment($order, $product, $consumer, $gatewayConfig);
                $boletoData = [
                    'amount' => $result['amount'] ?? $amount,
                    'expire_at' => $result['expire_at'] ?? null,
                    'barcode' => $result['barcode'] ?? null,
                    'pdf_url' => $result['pdf_url'] ?? null,
                ];
                event(new BoletoGenerated($order, $boletoData));
                $boletoToken = Str::random(32);
                $amountFormatted = 'R$ ' . number_format($result['amount'] ?? $amount, 2, ',', '.');
                session()->put('boleto_display.' . $boletoToken, [
                    'order_id' => $order->id,
                    'amount_formatted' => $amountFormatted,
                    'expire_at' => $result['expire_at'] ?? null,
                    'barcode' => $result['barcode'] ?? '',
                    'pdf_url' => $result['pdf_url'] ?? null,
                    'product_name' => $product?->name ?? 'Pagamento',
                    'redirect_after_purchase' => route('api-checkout.thank-you', ['order_id' => $order->id]),
                    'customer_name' => $customer['name'] ?? null,
                    'customer_email' => $email,
                    'customer_phone' => $customer['phone'] ?? null,
                ]);
                return redirect()->route('checkout.boleto', ['token' => $boletoToken]);
            } catch (\Throwable $e) {
                $order->delete();
                return redirect()->back()->with('error', $e->getMessage() ?: 'Não foi possível gerar o boleto.');
            }
        }

        if ($method === 'card') {
            $card = [
                'payment_token' => $validated['payment_token'],
                'card_mask' => $validated['card_mask'] ?? null,
            ];
            try {
                event(new OrderPending($order));
                $result = $paymentService->createCardPayment($order, $product, $consumer, $card, $gatewayConfig);
                $status = $result['status'] ?? 'pending';
                if ($status === 'paid' || $status === 'approved' || $status === 'completed') {
                    $order->update(['status' => 'completed']);
                    event(new OrderCompleted($order));
                }
                if (isset($result['client_secret']) && ($result['client_secret'] ?? '') !== '') {
                    $stripeKey = '';
                    $cardSlug = $pg['card'] ?? '';
                    if ($cardSlug === 'stripe') {
                        $cred = GatewayCredential::forTenant($tenantId)->where('gateway_slug', 'stripe')->where('is_connected', true)->first();
                        if ($cred) {
                            $creds = $cred->getDecryptedCredentials();
                            $stripeKey = (string) ($creds['publishable_key'] ?? '');
                        }
                    }
                    session()->put('api_checkout_card_confirm', [
                        'client_secret' => $result['client_secret'],
                        'return_url' => route('api-checkout.thank-you', ['order_id' => $order->id]),
                        'stripe_publishable_key' => $stripeKey,
                    ]);
                    return redirect()->route('api-checkout.card-confirm');
                }
                return redirect()
                    ->route('api-checkout.thank-you', ['order_id' => $order->id])
                    ->with('success', 'Pagamento com cartão recebido. Você receberá a confirmação por e-mail.');
            } catch (\Throwable $e) {
                $order->delete();
                return redirect()->back()->with('error', $e->getMessage() ?: 'Não foi possível processar o cartão.');
            }
        }

        return redirect()->back()->with('error', 'Método não implementado.');
    }

    public function thankYou(Request $request): Response|RedirectResponse
    {
        $orderId = $request->integer('order_id', 0);
        if ($orderId <= 0) {
            return redirect('/')->with('error', 'Pedido inválido.');
        }

        $order = Order::with('apiApplication')->find($orderId);
        if (! $order || ! $order->api_application_id) {
            return redirect('/')->with('error', 'Pedido inválido.');
        }

        $returnUrl = null;
        if ($order->api_checkout_session_id) {
            $session = ApiCheckoutSession::find($order->api_checkout_session_id);
            $returnUrl = $session?->return_url;
        }
        if (! is_string($returnUrl) || trim($returnUrl) === '') {
            $returnUrl = $order->apiApplication?->default_return_url;
        }
        $returnUrl = is_string($returnUrl) ? trim($returnUrl) : '';
        if ($returnUrl === '' || ! filter_var($returnUrl, FILTER_VALIDATE_URL)) {
            $returnUrl = url('/');
        }

        return Inertia::render('ApiCheckout/ThankYou', [
            'order_id' => $order->id,
            'return_url' => $returnUrl,
            'seconds' => 5,
        ]);
    }

    /**
     * Page to complete 3DS / SCA for card payment (Stripe). Reads session and runs confirmCardPayment then redirects.
     */
    public function cardConfirm(Request $request): Response|RedirectResponse
    {
        $data = session()->get('api_checkout_card_confirm');
        if (! is_array($data) || empty($data['client_secret'])) {
            return redirect()->to('/')->with('error', 'Sessão de confirmação inválida.');
        }
        session()->forget('api_checkout_card_confirm');
        return Inertia::render('ApiCheckout/CardConfirm', [
            'client_secret' => $data['client_secret'],
            'return_url' => $data['return_url'] ?? url('/'),
            'stripe_publishable_key' => $data['stripe_publishable_key'] ?? '',
        ]);
    }
}
