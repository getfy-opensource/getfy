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
use App\Services\EfiPixRecorrenteService;
use App\Services\CajuPayApiCheckoutService;
use App\Services\CheckoutAbuseGuard;
use App\Services\PaymentService;
use App\Services\PushinPayPixRecorrenteService;
use App\Services\StorageService;
use App\Support\FakeConsumerData;
use App\Support\MetaPurchaseTracking;
use App\Support\CheckoutCardCredentialsPayload;
use App\Support\CheckoutPaymentMethodsBuilder;
use Illuminate\Http\JsonResponse;
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

        // Persist tracking params (UTMs/src/sck) from querystring into metadata.
        // We only fill missing keys to avoid overwriting integrator-provided values.
        $meta = is_array($session->metadata) ? $session->metadata : [];
        $incoming = [];
        foreach (['utm_source', 'utm_campaign', 'utm_medium', 'utm_content', 'utm_term', 'src', 'sck'] as $k) {
            $v = $request->query($k);
            if (! is_string($v)) {
                continue;
            }
            $v = trim($v);
            if ($v === '') {
                continue;
            }
            $incoming[$k] = mb_substr($v, 0, 255);
        }
        if (! empty($incoming)) {
            $next = $meta;
            $changed = false;
            foreach ($incoming as $k => $v) {
                if (! array_key_exists($k, $next) || trim((string) ($next[$k] ?? '')) === '') {
                    $next[$k] = $v;
                    $changed = true;
                }
            }
            if ($changed) {
                $session->update(['metadata' => $next]);
            }
        }

        $pg = $app->payment_gateways ?? ApiApplication::defaultPaymentGateways();
        $pg = is_array($pg) ? $pg : ApiApplication::defaultPaymentGateways();

        $productModel = null;
        $subscriptionPlan = null;
        $productName = null;
        $productImageUrl = null;
        if ($session->product_id) {
            $productModel = Product::where('id', $session->product_id)->where('tenant_id', $app->tenant_id)->first();
        } elseif ($session->subscription_plan_id) {
            $subscriptionPlan = SubscriptionPlan::with('product')->find($session->subscription_plan_id);
            if ($subscriptionPlan && $subscriptionPlan->product && (int) $subscriptionPlan->product->tenant_id === (int) $app->tenant_id) {
                $productModel = $subscriptionPlan->product;
            } else {
                $subscriptionPlan = null;
            }
        } elseif ($session->product_offer_id) {
            $offer = ProductOffer::with('product')->find($session->product_offer_id);
            if ($offer && $offer->product && (int) $offer->product->tenant_id === (int) $app->tenant_id) {
                $productModel = $offer->product;
            }
        }
        if ($productModel) {
            $productName = $productModel->name;
            if ($productModel->image) {
                $productImageUrl = (new StorageService($productModel->tenant_id))->url($productModel->image);
            }
        }

        $tenantId = $app->tenant_id;
        $checkoutPaymentMethods = CheckoutPaymentMethodsBuilder::build($tenantId, $pg, $subscriptionPlan);
        $availableMethods = CheckoutPaymentMethodsBuilder::methodIds($checkoutPaymentMethods);
        if (empty($availableMethods)) {
            abort(422, 'Nenhum método de pagamento configurado para esta aplicação.');
        }

        $cardCredentials = CheckoutCardCredentialsPayload::forMethods($tenantId, $checkoutPaymentMethods);

        $cardInstallmentsConfig = ['enabled' => false, 'max' => 1];
        if ($productModel) {
            $checkoutConfig = array_replace_recursive(
                Product::defaultCheckoutConfig(),
                is_array($productModel->checkout_config) ? $productModel->checkout_config : []
            );
            $cardInstallmentsConfig = $checkoutConfig['card_installments'] ?? ['enabled' => false, 'max' => 1];
        }

        $customer = $session->customer ?? [];
        $appLogoUrl = $app->logo
            ? (new StorageService($app->tenant_id))->url($app->logo)
            : null;

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
            'conversion_pixels' => $app->conversion_pixels ?? [],
            'customer_email' => $customer['email'] ?? null,
            'customer_name' => $customer['name'] ?? null,
            'customer_cpf' => $customer['cpf'] ?? null,
            'amount' => (float) $session->amount,
            'currency' => $session->currency ?? 'BRL',
            'currencies' => $currencies,
            'product_name' => $productName,
            'product_image_url' => $productImageUrl,
            'available_methods' => $availableMethods,
            'checkout_payment_methods' => $checkoutPaymentMethods,
            'return_url' => $session->return_url,
            'card_gateway_slug' => $cardCredentials['card_gateway_slug'],
            'card_payee_code' => $cardCredentials['card_payee_code'],
            'card_efi_sandbox' => $cardCredentials['card_efi_sandbox'],
            'card_stripe_publishable_key' => $cardCredentials['card_stripe_publishable_key'],
            'card_stripe_sandbox' => $cardCredentials['card_stripe_sandbox'],
            'card_stripe_link_enabled' => $cardCredentials['card_stripe_link_enabled'],
            'card_mercadopago_public_key' => $cardCredentials['card_mercadopago_public_key'],
            'card_mercadopago_sandbox' => $cardCredentials['card_mercadopago_sandbox'],
            'card_pagarme_public_key' => $cardCredentials['card_pagarme_public_key'],
            'card_pagarme_api_base_url' => $cardCredentials['card_pagarme_api_base_url'],
            'card_paypal_client_id' => $cardCredentials['card_paypal_client_id'] ?? '',
            'card_paypal_sandbox' => $cardCredentials['card_paypal_sandbox'] ?? false,
            'card_paypal_checkout_mode' => $cardCredentials['card_paypal_checkout_mode'] ?? 'auto',
            'card_gateway_keys' => $cardCredentials['card_gateway_keys'],
            'card_installments_enabled' => ! empty($cardInstallmentsConfig['enabled']),
            'card_max_installments' => min(12, max(1, (int) ($cardInstallmentsConfig['max'] ?? 1))),
        ]);
    }

    /**
     * Process payment for the session (PIX, boleto, card with token).
     */
    public function process(Request $request): RedirectResponse
    {
        $rules = [
            'session_token' => ['required', 'string', 'max:64'],
            'payment_method' => ['required', 'string', 'in:pix,pix_auto,boleto,card'],
        ];
        if ($request->input('payment_method') === 'pix_auto') {
            $rules['cpf'] = ['required', 'string', 'max:14'];
        }
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

        $tenantId = $app->tenant_id;
        $gatewayConfig = $app->payment_gateways ?? ApiApplication::defaultPaymentGateways();
        $gatewayConfig = is_array($gatewayConfig) ? $gatewayConfig : ApiApplication::defaultPaymentGateways();
        $pg = $gatewayConfig;
        $method = $validated['payment_method'];
        if ($method === 'pix' && empty($pg['pix'])) {
            return redirect()->back()->with('error', 'Método de pagamento não disponível.');
        }
        if ($method === 'pix_auto' && empty($pg['pix_auto'])) {
            return redirect()->back()->with('error', 'Método de pagamento não disponível.');
        }
        if ($method === 'card' && empty($pg['card'])) {
            return redirect()->back()->with('error', 'Método de pagamento não disponível.');
        }
        if ($method === 'boleto' && empty($pg['boleto'])) {
            return redirect()->back()->with('error', 'Método de pagamento não disponível.');
        }
        $customer = is_array($session->customer) ? $session->customer : [];
        if ($method === 'card') {
            $cardGw = strtolower((string) ($pg['card'] ?? ''));
            if ($cardGw === 'pagarme' && strtoupper((string) ($session->currency ?? 'BRL')) === 'BRL') {
                $cpfDigits = preg_replace('/\D/', '', (string) ($customer['cpf'] ?? ''));
                if (strlen($cpfDigits) < 11) {
                    return redirect()->back()->with('error', 'CPF do comprador é obrigatório para cartão Pagar.me em BRL. Inclua `cpf` no customer ao criar a sessão de checkout.');
                }
            }
        }
        if ($method === 'pix_auto') {
            $cpf = (string) ($validated['cpf'] ?? '');
            $customer['cpf'] = $cpf;
            $session->update([
                'customer' => array_merge(is_array($session->customer) ? $session->customer : [], ['cpf' => $cpf]),
            ]);
        }
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
            'phone' => trim((string) ($customer['phone'] ?? '')),
        ];

        $product = null;
        if ($session->product_id) {
            $product = Product::where('id', $session->product_id)->where('tenant_id', $tenantId)->where('is_active', true)->first();
        }
        $amount = (float) $session->amount;
        $productOfferId = $session->product_offer_id;
        $subscriptionPlanId = $session->subscription_plan_id;
        $plan = null;
        $periodStart = null;
        $periodEnd = null;
        if (! $product && $subscriptionPlanId) {
            $plan = SubscriptionPlan::with('product')->find($subscriptionPlanId);
            if ($plan && $plan->product && (int) $plan->product->tenant_id === (int) $tenantId) {
                $product = $plan->product;
            } else {
                $plan = null;
            }
        }
        if (! $product && $productOfferId) {
            $offer = ProductOffer::with('product')->find($productOfferId);
            if ($offer && $offer->product && (int) $offer->product->tenant_id === (int) $tenantId) {
                $product = $offer->product;
            }
        }
        if ($method === 'pix_auto' && $product && ! $subscriptionPlanId) {
            $plan = SubscriptionPlan::where('product_id', $product->id)->orderBy('position')->first();
            if ($plan) {
                $subscriptionPlanId = $plan->id;
            }
        }
        if ($product && $subscriptionPlanId) {
            if (! $plan) {
                $plan = SubscriptionPlan::find($subscriptionPlanId);
            }
            if ($plan && $plan->product_id === $product->id) {
                [$periodStart, $periodEnd] = $plan->getCurrentPeriod();
            } else {
                $plan = null;
            }
        }
        if ($method === 'pix_auto' && strlen($rawDoc) < 11) {
            return redirect()->back()->with('error', 'CPF do comprador é obrigatório para PIX automático.');
        }

        if ($product && ! $product->is_active) {
            return redirect()->back()->with('error', 'Produto indisponível.');
        }

        $request->merge([
            'email' => $email,
            'product_id' => $product?->id,
            'payment_method' => $method,
        ]);
        if ($product) {
            app(CheckoutAbuseGuard::class)->assertCanCreateCheckout($request, $product);
        } else {
            app(CheckoutAbuseGuard::class)->assertPendingLimits($request, $product);
        }

        $paymentService = app(PaymentService::class);
        $availableGateway = $paymentService->getFirstAvailableGatewayForMethod($tenantId, $method, $product, $gatewayConfig);
        if ($availableGateway === null) {
            return redirect()->back()->with('error', 'Método de pagamento não disponível.');
        }

        if ($method === 'pix') {
            $pixGw = $paymentService->getFirstAvailableGatewayForMethod($tenantId, 'pix', $product, $gatewayConfig);
            if ($pixGw === 'pagarme' && trim((string) ($customer['phone'] ?? '')) === '') {
                return redirect()->back()->with('error', 'Telefone do comprador é obrigatório para PIX (Pagar.me). Inclua phone ao criar a sessão de checkout.');
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
            'metadata' => array_merge($session->metadata ?? [], [
                'source' => 'api_checkout_pro',
                'checkout_payment_method' => $method,
            ]),
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
        }

        $session->update(['order_id' => $order->id]);

        if ($method === 'pix_auto') {
            try {
                event(new OrderPending($order));
                $gatewaySlug = (string) ($pg['pix_auto'] ?? '');

                if ($gatewaySlug === 'pushinpay') {
                    $credential = GatewayCredential::forTenant($tenantId)
                        ->where('gateway_slug', 'pushinpay')
                        ->where('is_connected', true)
                        ->first();
                    if (! $credential) {
                        throw new \RuntimeException('Pushin Pay não configurado para PIX automático.');
                    }
                    $credentials = $credential->getDecryptedCredentials();
                    if (empty($credentials['api_token'])) {
                        throw new \RuntimeException('Pushin Pay: API Token não configurado.');
                    }

                    $webhookUrl = route('webhooks.pushinpay');
                    $frequency = PushinPayPixRecorrenteService::intervalToFrequency($plan?->interval ?? SubscriptionPlan::INTERVAL_MONTHLY);
                    $subscriptionName = mb_substr(preg_replace('/[^\p{L}\p{N}\s\.\-]/u', '', $product?->name ?? 'Assinatura'), 0, 140) ?: 'Assinatura';
                    $pushinpayService = new PushinPayPixRecorrenteService($credentials);
                    $result = $pushinpayService->createSubscription(
                        (float) $amount,
                        $consumer,
                        $webhookUrl,
                        $frequency,
                        $subscriptionName,
                        'Assinatura PIX automático - Pedido #' . $order->id
                    );

                    $txid = $result['transaction_id'];
                    $qrcodeImage = $result['qrcode'] ?? null;
                    $copyPaste = $result['copy_paste'] ?? null;
                    $subscriptionId = $result['subscription_id'] ?? '';

                    $order->update([
                        'gateway' => 'pushinpay',
                        'gateway_id' => $txid,
                        'metadata' => array_merge($order->metadata ?? [], ['pushinpay_subscription_id' => $subscriptionId]),
                    ]);

                    event(new PixGenerated($order, [
                        'qrcode' => $qrcodeImage,
                        'copy_paste' => $copyPaste ?? '',
                        'transaction_id' => $txid,
                    ]));

                    $pixToken = PixCheckoutDisplay::persistAndStoreSession($order, [
                        'qrcode' => $qrcodeImage,
                        'copy_paste' => $copyPaste ?? '',
                    ], [
                        'amount' => $amount,
                        'product_name' => $product?->name ?? 'Pagamento',
                        'redirect_after_purchase' => route('api-checkout.thank-you', ['order_id' => $order->id]),
                    ]);
                    return redirect()->route('checkout.pix', ['token' => $pixToken]);
                }

                if ($gatewaySlug === 'efi') {
                    $credential = GatewayCredential::forTenant($tenantId)
                        ->where('gateway_slug', 'efi')
                        ->where('is_connected', true)
                        ->first();
                    if (! $credential) {
                        throw new \RuntimeException('Gateway Efí não configurado para PIX automático.');
                    }
                    $credentials = $credential->getDecryptedCredentials();
                    if (empty($credentials['certificate_path']) || empty($credentials['pix_key'])) {
                        throw new \RuntimeException('Efí: certificado ou chave PIX não configurados.');
                    }

                    $base = 'pixauto' . $order->id;
                    $txid = $base . Str::random(max(26 - strlen($base), 10));
                    $txid = substr($txid, 0, 35);

                    $efiRecorrente = new EfiPixRecorrenteService($credentials);
                    $locRec = $efiRecorrente->createLocRec();
                    $locId = (int) $locRec['id'];

                    $cob = $efiRecorrente->createCobWithTxid(
                        $txid,
                        (float) $amount,
                        $consumer,
                        $credentials['pix_key'],
                        'Assinatura PIX automático - Pedido #' . $order->id
                    );

                    $criacao = now();
                    $dataInicial = $periodEnd
                        ? $periodEnd->format('Y-m-d')
                        : $criacao->copy()->addMonth()->format('Y-m-d');
                    if ($dataInicial === $criacao->format('Y-m-d')) {
                        $dataInicial = $criacao->copy()->addDay()->format('Y-m-d');
                    }
                    $dataFinal = $periodEnd
                        ? $periodEnd->copy()->addYears(10)->format('Y-m-d')
                        : now()->addYears(10)->format('Y-m-d');

                    $contrato = str_pad((string) $order->id, 8, '0', STR_PAD_LEFT);
                    $objeto = mb_substr(preg_replace('/[^\p{L}\p{N}\s\.\-]/u', '', $product?->name ?? 'Assinatura'), 0, 140) ?: 'Assinatura';
                    $rec = $efiRecorrente->createRecurrence(
                        $locId,
                        $txid,
                        $consumer,
                        (float) $amount,
                        $dataInicial,
                        $dataFinal,
                        $contrato,
                        $objeto
                    );
                    $idRec = $rec['idRec'] ?? null;

                    $order->update([
                        'gateway' => 'efi',
                        'gateway_id' => $txid,
                        'metadata' => array_merge($order->metadata ?? [], ['efi_pix_auto_id_rec' => $idRec]),
                    ]);

                    $copyPaste = $cob['copy_paste'] ?? null;
                    $qrcodeImage = $cob['qrcode'] ?? null;
                    if ($idRec !== null) {
                        try {
                            $recData = $efiRecorrente->getRecurrence($idRec, $txid);
                            $dadosQR = $recData['dadosQR'] ?? [];
                            $recCopyPaste = $dadosQR['pixCopiaECola'] ?? null;
                            if ($recCopyPaste !== null && $recCopyPaste !== '') {
                                $copyPaste = $recCopyPaste;
                                $recImagem = $dadosQR['imagemQrcode'] ?? null;
                                if ($recImagem !== null && $recImagem !== '') {
                                    $qrcodeImage = $recImagem;
                                } else {
                                    $qrcodeImage = null;
                                }
                            }
                        } catch (\Throwable) {
                        }
                    }

                    event(new PixGenerated($order, [
                        'qrcode' => $qrcodeImage,
                        'copy_paste' => $copyPaste ?? '',
                        'transaction_id' => $txid,
                    ]));

                    $pixToken = PixCheckoutDisplay::persistAndStoreSession($order, [
                        'qrcode' => $qrcodeImage,
                        'copy_paste' => $copyPaste ?? '',
                    ], [
                        'amount' => $amount,
                        'product_name' => $product?->name ?? 'Pagamento',
                        'redirect_after_purchase' => route('api-checkout.thank-you', ['order_id' => $order->id]),
                    ]);
                    return redirect()->route('checkout.pix', ['token' => $pixToken]);
                }

                throw new \RuntimeException('Gateway PIX automático não suportado.');
            } catch (\Throwable $e) {
                $order->delete();
                return redirect()->back()->with('error', $e->getMessage() ?: 'Não foi possível gerar o PIX automático.');
            }
        }

        if ($method === 'pix') {
            $pixGatewayConfig = $gatewayConfig;
            try {
                event(new OrderPending($order));
                $result = $paymentService->createPixPayment($order, $product, $consumer, $pixGatewayConfig);
                event(new PixGenerated($order, [
                    'qrcode' => $result['qrcode'] ?? null,
                    'copy_paste' => $result['copy_paste'] ?? null,
                    'transaction_id' => $result['transaction_id'] ?? null,
                ]));
                $pixToken = PixCheckoutDisplay::persistAndStoreSession($order, $result, [
                    'amount' => $amount,
                    'product_name' => $product?->name ?? 'Pagamento',
                    'redirect_after_purchase' => route('api-checkout.thank-you', ['order_id' => $order->id]),
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
                'return_url' => route('api-checkout.thank-you', ['order_id' => $order->id]),
            ];
            try {
                event(new OrderPending($order));
                $cardGatewayConfig = $gatewayConfig;
                $cardGatewayConfig['card_redundancy'] = [];
                $result = $paymentService->createCardPayment($order, $product, $consumer, $card, $cardGatewayConfig);
                $status = $result['status'] ?? 'pending';
                $requiresAction = ($status === 'requires_action') || ! empty($result['client_secret']);
                $isApproved = in_array($status, ['paid', 'settled', 'approved', 'completed'], true);
                $asyncPendingStatuses = ['pending', 'in_process', 'processing', 'authorized', 'in_mediation'];
                $statusNormalized = strtolower((string) $status);
                if (! $isApproved && ! $requiresAction && ! in_array($statusNormalized, $asyncPendingStatuses, true)) {
                    $order->update([
                        'status' => 'cancelled',
                        'metadata' => array_merge($order->metadata ?? [], ['cancelled_reason' => 'card_payment_declined']),
                    ]);

                    return redirect()->back()->with('error', 'Pagamento recusado. Verifique os dados do cartão ou tente outro método.');
                }
                if ($status === 'paid' || $status === 'approved' || $status === 'completed') {
                    $order->update(['status' => 'completed']);
                    $order->grantPurchasedProductAccessToBuyer();
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

    public function cajupaySession(Request $request, CajuPayApiCheckoutService $cajuPay): JsonResponse
    {
        $validated = $request->validate([
            'session_token' => ['required', 'string', 'max:64'],
            'payment_method' => ['required', 'string', 'in:card,apple_pay,google_pay'],
            'display_currency' => ['nullable', 'string', 'max:8'],
        ]);

        try {
            $session = $cajuPay->resolveSession($validated['session_token']);
            $result = $cajuPay->createSdkSession(
                $session,
                $validated['payment_method'],
                $validated['display_currency'] ?? null
            );

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage() ?: 'Falha ao iniciar pagamento na CajuPay.'], 422);
        }
    }

    public function cajupayConfirmOrder(Request $request, CajuPayApiCheckoutService $cajuPay): JsonResponse
    {
        $validated = $request->validate([
            'session_token' => ['required', 'string', 'max:64'],
            'polling_token' => ['required', 'string', 'size:32'],
            'email' => ['nullable', 'email'],
            'name' => ['nullable', 'string', 'max:255'],
            'cpf' => ['nullable', 'string', 'max:14'],
            'phone' => ['nullable', 'string', 'max:24'],
        ]);

        try {
            $session = $cajuPay->resolveSession($validated['session_token']);
            $customer = array_filter([
                'email' => $validated['email'] ?? null,
                'name' => $validated['name'] ?? null,
                'cpf' => $validated['cpf'] ?? null,
                'phone' => $validated['phone'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');

            $result = $cajuPay->confirmOrder($request, $session, $validated['polling_token'], $customer);

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage() ?: 'Falha ao registrar o pedido.'], 422);
        }
    }

    public function orderStatus(Request $request, CheckoutController $checkout): JsonResponse
    {
        return $checkout->orderStatus($request);
    }

    public function thankYou(Request $request): Response|RedirectResponse
    {
        $orderId = $request->integer('order_id', 0);
        if ($orderId <= 0) {
            return redirect('/')->with('error', 'Pedido inválido.');
        }

        $order = Order::with('apiApplication', 'orderItems')->find($orderId);
        if (! $order || ! $order->api_application_id) {
            return redirect('/')->with('error', 'Pedido inválido.');
        }

        $returnUrl = null;
        $currency = 'BRL';
        if ($order->api_checkout_session_id) {
            $session = ApiCheckoutSession::find($order->api_checkout_session_id);
            $returnUrl = $session?->return_url;
            $currency = $session?->currency ?: 'BRL';
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
            'order_amount' => (float) ($order->amount ?? 0),
            'order_currency' => strtoupper((string) $currency),
            'conversion_pixels' => $order->apiApplication?->conversion_pixels ?? [],
            'return_url' => $returnUrl,
            'seconds' => 5,
            'meta_purchase_event_id' => MetaPurchaseTracking::purchaseEventId($order->id),
            'purchase_contents' => MetaPurchaseTracking::purchaseContentsFromOrder($order, false),
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
