<?php

namespace App\Http\Controllers;

use App\Events\BoletoGenerated;
use App\Events\OrderCompleted;
use App\Events\OrderPending;
use App\Events\PixGenerated;
use App\Events\SubscriptionCreated;
use App\Gateways\GatewayRegistry;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\Coupon;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\CheckoutSession;
use App\Models\ProductOrderBump;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\GeoIp;
use App\Services\EfiPixRecorrenteService;
use App\Services\StorageService;
use App\Services\PaymentService;
use App\Services\PushinPayPixRecorrenteService;
use App\Support\CheckoutCardContract;
use App\Support\CheckoutTranslations;
use App\Support\FakeConsumerData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CheckoutController extends Controller
{
    private function rollbackFailedOrder(Order $order, \Throwable $originalError): void
    {
        try {
            $order->delete();
            return;
        } catch (\Throwable $deleteError) {
            Log::warning('Checkout: failed to delete order after payment failure', [
                'order_id' => $order->id,
                'delete_error' => $deleteError->getMessage(),
                'original_error' => $originalError->getMessage(),
            ]);
        }

        try {
            $order->update(['status' => 'cancelled']);
        } catch (\Throwable $updateError) {
            Log::warning('Checkout: failed to mark order cancelled after payment failure', [
                'order_id' => $order->id,
                'update_error' => $updateError->getMessage(),
                'original_error' => $originalError->getMessage(),
            ]);
        }
    }

    /**
     * Resolve checkout context by slug: offer first, then plan, then product (legacy).
     *
     * @return array{product: Product, offer: ProductOffer|null, plan: SubscriptionPlan|null, amount: float, currency: string, checkout_slug: string}
     */
    private function resolveCheckoutBySlug(string $slug): array
    {
        $offer = ProductOffer::where('checkout_slug', $slug)->with('product')->first();
        if ($offer && $offer->product && $offer->product->is_active) {
            return [
                'product' => $offer->product,
                'offer' => $offer,
                'plan' => null,
                'amount' => (float) $offer->price,
                'currency' => $offer->getCurrencyOrDefault(),
                'checkout_slug' => $offer->checkout_slug,
            ];
        }

        $plan = SubscriptionPlan::where('checkout_slug', $slug)->with('product')->first();
        if ($plan && $plan->product && $plan->product->is_active) {
            return [
                'product' => $plan->product,
                'offer' => null,
                'plan' => $plan,
                'amount' => (float) $plan->price,
                'currency' => $plan->getCurrencyOrDefault(),
                'checkout_slug' => $plan->checkout_slug,
            ];
        }

        $product = Product::where('checkout_slug', $slug)->where('is_active', true)->first();
        if ($product) {
            return [
                'product' => $product,
                'offer' => null,
                'plan' => null,
                'amount' => (float) $product->price,
                'currency' => $product->currency ?? 'BRL',
                'checkout_slug' => $product->checkout_slug,
            ];
        }

        abort(404);
    }

    public function show(Request $request, string $slug): Response
    {
        $resolved = $this->resolveCheckoutBySlug($slug);
        $product = $resolved['product'];

        // No checkout principal: offer_id ou plan_id na URL pré-seleciona oferta/plano (mesmo checkout, link diferente por oferta/plano).
        // Para assinatura sem plan_id, usa o plano base (menor position) como padrão.
        if ($resolved['offer'] === null && $resolved['plan'] === null && $product->checkout_slug === $slug) {
            $offerId = $request->integer('offer_id', 0) ?: null;
            $planId = $request->integer('plan_id', 0) ?: null;
            if ($offerId) {
                $offer = ProductOffer::where('id', $offerId)->where('product_id', $product->id)->first();
                if ($offer) {
                    $resolved = [
                        'product' => $product,
                        'offer' => $offer,
                        'plan' => null,
                        'amount' => (float) $offer->price,
                        'currency' => $offer->getCurrencyOrDefault(),
                        'checkout_slug' => $product->checkout_slug,
                    ];
                }
            } elseif ($planId) {
                $plan = SubscriptionPlan::where('id', $planId)->where('product_id', $product->id)->first();
                if ($plan) {
                    $resolved = [
                        'product' => $product,
                        'offer' => null,
                        'plan' => $plan,
                        'amount' => (float) $plan->price,
                        'currency' => $plan->getCurrencyOrDefault(),
                        'checkout_slug' => $product->checkout_slug,
                    ];
                }
            } elseif (($product->billing_type ?? Product::BILLING_ONE_TIME) === Product::BILLING_SUBSCRIPTION) {
                $basePlan = SubscriptionPlan::where('product_id', $product->id)->orderBy('position')->first();
                if ($basePlan) {
                    $resolved = [
                        'product' => $product,
                        'offer' => null,
                        'plan' => $basePlan,
                        'amount' => (float) $basePlan->price,
                        'currency' => $basePlan->getCurrencyOrDefault(),
                        'checkout_slug' => $product->checkout_slug,
                    ];
                }
            }
        }

        $product = $resolved['product'];
        $defaults = Product::defaultCheckoutConfig();
        $productConfig = $product->checkout_config ?? [];
        $config = array_replace_recursive($defaults, $productConfig);
        if ($resolved['offer'] && $resolved['offer']->checkout_config !== null && $resolved['offer']->checkout_config !== []) {
            $config = array_replace_recursive($config, $resolved['offer']->checkout_config);
        } elseif ($resolved['plan'] && $resolved['plan']->checkout_config !== null && $resolved['plan']->checkout_config !== []) {
            $config = array_replace_recursive($config, $resolved['plan']->checkout_config);
        }
        $productArray = $this->productToCheckoutArray($product, $resolved['offer'], $resolved['plan'], $resolved['amount'], $resolved['currency'], $resolved['checkout_slug']);
        $payload = [
            'product' => $productArray,
            'config' => $config,
        ];
        $payload['offer'] = $resolved['offer'] ? [
            'id' => $resolved['offer']->id,
            'name' => $resolved['offer']->name,
            'price' => (float) $resolved['offer']->price,
            'currency' => $resolved['offer']->getCurrencyOrDefault(),
            'checkout_slug' => $resolved['offer']->checkout_slug,
        ] : null;
        $payload['subscription_plan'] = $resolved['plan'] ? [
            'id' => $resolved['plan']->id,
            'name' => $resolved['plan']->name,
            'price' => (float) $resolved['plan']->price,
            'currency' => $resolved['plan']->getCurrencyOrDefault(),
            'interval' => $resolved['plan']->interval,
            'checkout_slug' => $resolved['plan']->checkout_slug,
        ] : null;

        $exitPopup = $config['exit_popup'] ?? [];
        if (! empty($exitPopup['enabled']) && ! empty($exitPopup['coupon_id'])) {
            $coupon = Coupon::where('id', $exitPopup['coupon_id'])
                ->where('tenant_id', $product->tenant_id)
                ->whereHas('products', fn ($q) => $q->where('products.id', $product->id))
                ->first();
            $payload['exit_popup_coupon'] = $coupon ? ['code' => $coupon->code, 'id' => $coupon->id] : null;
        } else {
            $payload['exit_popup_coupon'] = null;
        }

        $geo = new GeoIp;
        $suggestions = $geo->getSuggestionsForIp(request()->ip());
        $payload['suggested_locale'] = $suggestions['suggested_locale'];
        $payload['suggested_currency'] = $suggestions['suggested_currency'];
        $payload['suggested_country_code'] = $suggestions['country_code'] ?? null;

        $tenantId = $product->tenant_id;
        $defaultTranslations = config('checkout_translations');
        $checkoutTranslationsRaw = Setting::get('checkout_translations', null, $tenantId);
        $savedTranslations = $checkoutTranslationsRaw
            ? (is_string($checkoutTranslationsRaw) ? json_decode($checkoutTranslationsRaw, true) : $checkoutTranslationsRaw)
            : [];
        $payload['checkout_translations'] = CheckoutTranslations::merge($defaultTranslations, is_array($savedTranslations) ? $savedTranslations : []);

        $currenciesRaw = Setting::get('currencies', null, $tenantId);
        $currencies = $currenciesRaw
            ? (is_string($currenciesRaw) ? json_decode($currenciesRaw, true) : $currenciesRaw)
            : config('products.currencies');
        $payload['currencies'] = is_array($currencies) ? $currencies : config('products.currencies');

        $payload['product'] = $this->addPricesInCurrencies($productArray, $payload['currencies']);
        $payload['available_payment_methods'] = $this->buildAvailablePaymentMethods($product, $resolved['plan'] ?? null, $config);
        $payload['card_payee_code'] = '';
        $payload['card_efi_sandbox'] = false;
        $payload['card_stripe_publishable_key'] = '';
        $payload['card_stripe_sandbox'] = false;
        $payload['card_stripe_link_enabled'] = true;
        $payload['card_mercadopago_public_key'] = '';
        $payload['card_mercadopago_sandbox'] = false;
        foreach ($payload['available_payment_methods'] as $m) {
            if (($m['id'] ?? '') === 'card' && ($m['gateway_slug'] ?? '') === 'efi') {
                $cred = GatewayCredential::forTenant($product->tenant_id)->where('gateway_slug', 'efi')->first();
                if ($cred) {
                    $creds = $cred->getDecryptedCredentials();
                    $payload['card_payee_code'] = (string) ($creds['payee_code'] ?? '');
                    $payload['card_efi_sandbox'] = ! empty($creds['sandbox']);
                }
                break;
            }
        }
        foreach ($payload['available_payment_methods'] as $m) {
            if (($m['id'] ?? '') === 'card' && ($m['gateway_slug'] ?? '') === 'stripe') {
                $cred = GatewayCredential::forTenant($product->tenant_id)->where('gateway_slug', 'stripe')->first();
                if ($cred) {
                    $creds = $cred->getDecryptedCredentials();
                    $payload['card_stripe_publishable_key'] = (string) ($creds['publishable_key'] ?? '');
                    $payload['card_stripe_sandbox'] = ! empty($creds['sandbox']);
                    $payload['card_stripe_link_enabled'] = isset($creds['link_enabled'])
                        ? (bool) $creds['link_enabled']
                        : true;
                }
                break;
            }
        }
        foreach ($payload['available_payment_methods'] as $m) {
            if (($m['id'] ?? '') === 'card' && ($m['gateway_slug'] ?? '') === 'mercadopago') {
                $cred = GatewayCredential::forTenant($product->tenant_id)->where('gateway_slug', 'mercadopago')->first();
                if ($cred) {
                    $creds = $cred->getDecryptedCredentials();
                    $payload['card_mercadopago_public_key'] = (string) ($creds['public_key'] ?? '');
                    $payload['card_mercadopago_sandbox'] = ! empty($creds['sandbox']);
                }
                break;
            }
        }
        $payload['card_gateway_keys'] = [];
        foreach ($payload['available_payment_methods'] as $m) {
            if (($m['id'] ?? '') !== 'card') {
                continue;
            }
            $slug = $m['gateway_slug'] ?? '';
            if ($slug === '') {
                continue;
            }
            $gateway = GatewayRegistry::get($slug);
            $keys = $gateway['checkout_payload_keys'] ?? null;
            if (! is_array($keys) || $keys === []) {
                continue;
            }
            $cred = GatewayCredential::forTenant($product->tenant_id)->where('gateway_slug', $slug)->where('is_connected', true)->first();
            if (! $cred) {
                continue;
            }
            $creds = $cred->getDecryptedCredentials();
            $payload['card_gateway_keys'][$slug] = [];
            foreach ($keys as $key) {
                if (is_string($key) && array_key_exists($key, $creds)) {
                    $payload['card_gateway_keys'][$slug][$key] = $creds[$key];
                }
            }
            if ($slug === 'pagarme') {
                $payload['card_gateway_keys'][$slug]['api_base_url'] = rtrim((string) config('services.pagarme.base_url', 'https://api.pagar.me/core/v5'), '/');
            }
        }
        if (array_key_exists('stripe_link_enabled', $config)) {
            $payload['card_stripe_link_enabled'] = (bool) $config['stripe_link_enabled'];
        }
        $cardInstallmentsConfig = $config['card_installments'] ?? ['enabled' => false, 'max' => 1];
        $payload['card_installments_enabled'] = ! empty($cardInstallmentsConfig['enabled']);
        $payload['card_max_installments'] = min(12, max(1, (int) ($cardInstallmentsConfig['max'] ?? 1)));

        $orderBumps = $product->orderBumps()->with(['targetProduct', 'targetProductOffer'])->get();
        $payload['order_bumps'] = $orderBumps->map(function (ProductOrderBump $b) use ($product) {
            $target = $b->targetProduct;
            $imageUrl = $target && $target->image
                ? (new StorageService($product->tenant_id))->url($target->image)
                : null;
            $effectiveBrl = $b->getEffectiveAmountBrl();
            $originalBrl = $b->getOriginalAmountBrl();
            return [
                'id' => $b->id,
                'title' => $b->title,
                'description' => $b->description,
                'cta_title' => $b->cta_title,
                'amount_brl' => $effectiveBrl,
                'original_amount_brl' => $originalBrl > $effectiveBrl ? $originalBrl : null,
                'target_product_id' => $b->target_product_id,
                'target_product_offer_id' => $b->target_product_offer_id,
                'image_url' => $imageUrl,
                'target_name' => $target?->name,
            ];
        })->values()->all();

        $payload['conversion_pixels'] = $product->conversion_pixels ?? Product::defaultConversionPixels();

        $sessionToken = Str::uuid()->toString();

        // UTMs podem ser perdidos em redirects/navegações internas.
        // Mantemos a origem mais confiável (querystring atual) e, quando ausente,
        // fazemos fallback para extrair do Referer (se vier no formato de URL).
        $utmSource = $request->query('utm_source');
        $utmMedium = $request->query('utm_medium');
        $utmCampaign = $request->query('utm_campaign');
        if (
            (empty($utmSource) || empty($utmMedium) || empty($utmCampaign))
            && is_string($request->headers->get('referer'))
        ) {
            $referer = (string) $request->headers->get('referer');
            try {
                $u = parse_url($referer);
                $query = [];
                if (! empty($u['query'])) {
                    parse_str((string) $u['query'], $query);
                }
                $utmSource = $utmSource ?: ($query['utm_source'] ?? null);
                $utmMedium = $utmMedium ?: ($query['utm_medium'] ?? null);
                $utmCampaign = $utmCampaign ?: ($query['utm_campaign'] ?? null);
            } catch (\Throwable) {
                // fallback silencioso
            }
        }

        CheckoutSession::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'product_offer_id' => $resolved['offer']?->id,
            'subscription_plan_id' => $resolved['plan']?->id,
            'checkout_slug' => $resolved['checkout_slug'],
            'session_token' => $sessionToken,
            'step' => CheckoutSession::STEP_VISIT,
            'customer_ip' => $request->ip(),
            'utm_source' => $utmSource,
            'utm_medium' => $utmMedium,
            'utm_campaign' => $utmCampaign,
        ]);
        $payload['checkout_session_token'] = $sessionToken;

        /** Preview ao vivo no Builder (iframe): o front confia neste flag, não só na query (Inertia pode alterar URL). */
        $payload['checkout_builder_preview'] = $request->query('preview') === '1';

        return Inertia::render('Checkout/Show', $payload);
    }

    public function validateCoupon(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'coupon_code' => ['required', 'string', 'max:64'],
            'product_offer_id' => ['nullable', 'exists:product_offers,id'],
            'subscription_plan_id' => ['nullable', 'exists:subscription_plans,id'],
        ]);
        $product = Product::findOrFail($request->input('product_id'));
        $code = trim((string) $request->input('coupon_code'));
        if ($code === '') {
            return response()->json(['valid' => false, 'message' => 'Código do cupom é obrigatório.']);
        }
        $coupon = Coupon::forTenant($product->tenant_id)
            ->where('code', $code)
            ->whereHas('products', fn ($q) => $q->where('products.id', $product->id))
            ->first();
        if (! $coupon) {
            return response()->json(['valid' => false, 'message' => 'Cupom inválido ou não disponível para este produto.']);
        }
        $price = (float) $product->price;
        $currency = $product->currency ?? 'BRL';
        if ($request->filled('product_offer_id')) {
            $offer = ProductOffer::where('id', $request->input('product_offer_id'))->where('product_id', $product->id)->first();
            if ($offer) {
                $price = (float) $offer->price;
                $currency = $offer->getCurrencyOrDefault();
            }
        } elseif ($request->filled('subscription_plan_id')) {
            $plan = SubscriptionPlan::where('id', $request->input('subscription_plan_id'))->where('product_id', $product->id)->first();
            if ($plan) {
                $price = (float) $plan->price;
                $currency = $plan->getCurrencyOrDefault();
            }
        }
        if ($currency !== 'BRL') {
            $rates = config('products.rates');
            $price = $currency === 'EUR' ? $price / ($rates['brl_eur'] ?? 0.16) : $price / ($rates['brl_usd'] ?? 0.18);
        }
        $result = $coupon->applyTo($product, $price);
        if ($result === null) {
            return response()->json(['valid' => false, 'message' => 'Este cupom não pode ser aplicado (expirado, uso esgotado ou valor mínimo não atingido).']);
        }
        return response()->json([
            'valid' => true,
            'discount_amount' => $result['discount_amount'],
            'final_price' => $result['final_price'],
        ]);
    }

    public function process(Request $request): RedirectResponse|JsonResponse
    {
        $product = Product::findOrFail($request->input('product_id'));
        $customerFields = $product->checkout_config['customer_fields'] ?? [];

        $productOfferIdForRules = $request->filled('product_offer_id') ? (int) $request->input('product_offer_id') : null;
        $subscriptionPlanIdForRules = $request->filled('subscription_plan_id') ? (int) $request->input('subscription_plan_id') : null;
        $offerForRules = $productOfferIdForRules ? ProductOffer::where('id', $productOfferIdForRules)->where('product_id', $product->id)->first() : null;
        $planForRules = $subscriptionPlanIdForRules ? SubscriptionPlan::where('id', $subscriptionPlanIdForRules)->where('product_id', $product->id)->first() : null;
        $effectiveCurrency = strtoupper((string) ($product->currency ?? 'BRL'));
        if ($offerForRules) {
            $effectiveCurrency = strtoupper((string) ($offerForRules->getCurrencyOrDefault() ?? 'BRL'));
        } elseif ($planForRules) {
            $effectiveCurrency = strtoupper((string) ($planForRules->getCurrencyOrDefault() ?? 'BRL'));
        }
        $displayCurrencyInput = $request->input('display_currency');
        $displayCurrency = is_string($displayCurrencyInput) && $displayCurrencyInput !== '' ? strtoupper($displayCurrencyInput) : $effectiveCurrency;

        $paymentService = app(PaymentService::class);
        $paymentMethodForRules = $request->input('payment_method');
        $firstPixGateway = $paymentMethodForRules === 'pix'
            ? $paymentService->getFirstAvailableGatewayForMethod($product->tenant_id, 'pix', $product)
            : null;
        $firstCardGatewayForRules = $paymentMethodForRules === 'card'
            ? $paymentService->getFirstAvailableGatewayForMethod($product->tenant_id, 'card', $product)
            : null;
        $requireCpf = (($customerFields['cpf'] ?? false) && $displayCurrency === 'BRL')
            || ($firstCardGatewayForRules === 'pagarme' && $displayCurrency === 'BRL');
        $phoneRequiredForCheckout = ($customerFields['phone'] ?? false)
            || ($paymentMethodForRules === 'pix' && $firstPixGateway === 'pagarme');

        $rules = [
            'product_id' => ['required', 'exists:products,id'],
            'product_offer_id' => ['nullable', 'exists:product_offers,id'],
            'subscription_plan_id' => ['nullable', 'exists:subscription_plans,id'],
            'order_bump_ids' => ['nullable', 'array'],
            'order_bump_ids.*' => ['integer', 'exists:product_order_bumps,id'],
            'payment_method' => ['required', 'string', 'in:pix,card,boleto,pix_auto'],
            'checkout_session_token' => ['nullable', 'string', 'max:64'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'display_currency' => ['nullable', 'string', 'in:BRL,USD,EUR'],
            'email' => ['required', 'email'],
            'name' => [($customerFields['name'] ?? true) ? 'required' : 'nullable', 'string', 'max:255'],
            'cpf' => [$requireCpf ? 'required' : 'nullable', 'string', 'max:11'],
            'phone' => [$phoneRequiredForCheckout ? 'required' : 'nullable', 'string', 'max:24'],
            'coupon_code' => ['nullable', 'string', 'max:64'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
        ];
        if ($request->input('payment_method') === 'card') {
            $firstCardGateway = $firstCardGatewayForRules ?? $paymentService->getFirstAvailableGatewayForMethod($product->tenant_id, 'card', $product);
            if ($firstCardGateway === 'asaas') {
                $rules['payment_token'] = ['nullable', 'string', 'max:10000'];
                $rules['card_holder_name'] = ['required_without:payment_token', 'string', 'max:255'];
                $rules['card_number'] = ['required_without:payment_token', 'string', 'max:19'];
                $rules['card_expiry_month'] = ['required_without:payment_token', 'string', 'size:2'];
                $rules['card_expiry_year'] = ['required_without:payment_token', 'string', 'max:4'];
                $rules['card_ccv'] = ['required_without:payment_token', 'string', 'max:4'];
                $rules['address_zipcode'] = ['required', 'string', 'max:9'];
                $rules['address_street'] = ['required', 'string', 'max:255'];
                $rules['address_number'] = ['required', 'string', 'max:20'];
                $rules['address_neighborhood'] = ['required', 'string', 'max:255'];
                $rules['address_city'] = ['required', 'string', 'max:255'];
                $rules['address_state'] = ['required', 'string', 'max:2'];
            } elseif ($firstCardGateway === 'pagarme') {
                $rules['payment_token'] = ['required', 'string', 'max:10000'];
                $rules['address_zipcode'] = ['required', 'string', 'max:9'];
                $rules['address_street'] = ['required', 'string', 'max:255'];
                $rules['address_number'] = ['required', 'string', 'max:20'];
                $rules['address_neighborhood'] = ['required', 'string', 'max:255'];
                $rules['address_city'] = ['required', 'string', 'max:255'];
                $rules['address_state'] = ['required', 'string', 'max:2'];
            } else {
                $rules['payment_token'] = ['required', 'string', 'max:10000'];
            }
            $rules['installments'] = ['nullable', 'integer', 'min:1', 'max:12'];
        }
        if ($request->input('payment_method') === 'boleto') {
            $rules['address_zipcode'] = ['required', 'string', 'max:9'];
            $rules['address_street'] = ['required', 'string', 'max:255'];
            $rules['address_number'] = ['required', 'string', 'max:20'];
            $rules['address_neighborhood'] = ['required', 'string', 'max:255'];
            $rules['address_city'] = ['required', 'string', 'max:255'];
            $rules['address_state'] = ['required', 'string', 'max:2'];
        }
        $validated = $request->validate($rules);
        $idempotencyKey = isset($validated['idempotency_key']) && trim((string) $validated['idempotency_key']) !== ''
            ? trim((string) $validated['idempotency_key'])
            : null;

        if ($idempotencyKey !== null) {
            $cached = Cache::get('checkout_idempotency:' . $idempotencyKey);
            if ($cached !== null && is_array($cached)) {
                if (($cached['type'] ?? '') === 'redirect' && ! empty($cached['url'])) {
                    return redirect($cached['url']);
                }
                if (($cached['type'] ?? '') === 'json' && array_key_exists('data', $cached)) {
                    return response()->json($cached['data']);
                }
            }
        }

        $paymentMethod = $validated['payment_method'];

        $productOfferId = $request->filled('product_offer_id') ? (int) $request->input('product_offer_id') : null;
        $subscriptionPlanId = $request->filled('subscription_plan_id') ? (int) $request->input('subscription_plan_id') : null;
        $offer = $productOfferId ? ProductOffer::where('id', $productOfferId)->where('product_id', $product->id)->first() : null;
        $plan = $subscriptionPlanId ? SubscriptionPlan::where('id', $subscriptionPlanId)->where('product_id', $product->id)->first() : null;

        if ($paymentMethod === 'pix_auto' && ! $plan) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'PIX automático está disponível apenas para assinaturas.'], 422);
            }
            return back()->withErrors(['payment_method' => 'PIX automático está disponível apenas para assinaturas.']);
        }

        $amount = (float) $product->price;
        if ($offer) {
            $amount = (float) $offer->price;
        } elseif ($plan) {
            $amount = (float) $plan->price;
        }
        $currency = $product->currency ?? 'BRL';
        if ($offer) {
            $currency = $offer->getCurrencyOrDefault();
        } elseif ($plan) {
            $currency = $plan->getCurrencyOrDefault();
        }
        if ($currency !== 'BRL') {
            $rates = config('products.rates');
            $amount = $currency === 'EUR' ? $amount / ($rates['brl_eur'] ?? 0.16) : $amount / ($rates['brl_usd'] ?? 0.18);
        }

        $orderBumpIds = array_values(array_filter(array_map('intval', $validated['order_bump_ids'] ?? [])));
        $selectedBumps = collect();
        if ($orderBumpIds) {
            $selectedBumps = ProductOrderBump::where('product_id', $product->id)->whereIn('id', $orderBumpIds)->get();
        }
        $bumpAmountTotal = $selectedBumps->sum(fn (ProductOrderBump $b) => $b->getEffectiveAmountBrl());
        $totalAmount = $amount + $bumpAmountTotal;

        $couponCode = isset($validated['coupon_code']) && trim($validated['coupon_code'] ?? '') !== '' ? trim($validated['coupon_code']) : null;
        if ($couponCode !== null) {
            $coupon = Coupon::forTenant($product->tenant_id)
                ->where('code', $couponCode)
                ->whereHas('products', fn ($q) => $q->where('products.id', $product->id))
                ->first();
            if ($coupon) {
                $applied = $coupon->applyTo($product, $amount);
                if ($applied !== null) {
                    $amount = $applied['final_price'];
                }
            }
        }
        $totalAmount = $amount + $bumpAmountTotal;

        $periodStart = null;
        $periodEnd = null;
        if ($plan) {
            [$periodStart, $periodEnd] = $plan->getCurrentPeriod();
        }

        $checkoutSlug = $product->checkout_slug ?? '';
        if ($offer && ! empty($offer->checkout_slug)) {
            $checkoutSlug = $offer->checkout_slug;
        } elseif ($plan && ! empty($plan->checkout_slug)) {
            $checkoutSlug = $plan->checkout_slug;
        }
        $checkoutSlug = (string) $checkoutSlug;
        if ($checkoutSlug === '') {
            $checkoutSlug = (string) ($product->checkout_slug ?? '');
        }

        $tenantId = $product->tenant_id;

        $plainPassword = null;
        if ($product->type === Product::TYPE_AREA_MEMBROS) {
            $loginConfig = $product->member_area_config['login'] ?? [];
            $passwordMode = $loginConfig['password_mode'] ?? 'auto';
            $defaultPassword = trim((string) ($loginConfig['default_password'] ?? ''));
            if ($passwordMode === 'default' && $defaultPassword !== '') {
                $plainPassword = $defaultPassword;
            } else {
                $plainPassword = Str::random(12);
            }
        } else {
            $plainPassword = Str::random(32);
        }
        $passwordHash = bcrypt($plainPassword);

        $user = User::firstOrCreate(
            ['email' => $validated['email']],
            [
                'name' => $validated['name'] ?? $validated['email'],
                'password' => $passwordHash,
                'role' => User::ROLE_ALUNO,
                'tenant_id' => $tenantId,
            ]
        );
        if ($user->wasRecentlyCreated) {
            $user->update(['role' => User::ROLE_ALUNO]);
        }
        $orderMetadata = [];
        if ($product->type === Product::TYPE_AREA_MEMBROS && $plainPassword !== null) {
            if (! $user->wasRecentlyCreated) {
                $user->update(['password' => $passwordHash]);
            }
            Cache::put('access_password.' . $user->id . '.' . $product->id, $plainPassword, now()->addHours(2));
            $orderMetadata['access_password_temp'] = encrypt($plainPassword);
        }

        $orderPayload = [
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_offer_id' => $productOfferId,
            'subscription_plan_id' => $subscriptionPlanId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'is_renewal' => false,
            'amount' => $totalAmount,
            'email' => $validated['email'],
            'cpf' => $validated['cpf'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'customer_ip' => $request->ip(),
            'coupon_code' => $couponCode,
            'metadata' => $orderMetadata,
        ];

        $createOrderAndItems = function (array $payload) use ($product, $amount, $productOfferId, $subscriptionPlanId, $selectedBumps) {
            $order = Order::create($payload);
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_offer_id' => $productOfferId,
                'subscription_plan_id' => $subscriptionPlanId,
                'amount' => $amount,
                'position' => 0,
            ]);
            $pos = 1;
            foreach ($selectedBumps as $bump) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $bump->target_product_id,
                    'product_offer_id' => $bump->target_product_offer_id,
                    'subscription_plan_id' => null,
                    'amount' => $bump->getEffectiveAmountBrl(),
                    'position' => $pos++,
                ]);
            }
            return $order;
        };

        $grantAccessForOrder = function (Order $order) {
            $order->product->users()->syncWithoutDetaching([$order->user_id]);
            foreach ($order->orderItems as $item) {
                $item->product->users()->syncWithoutDetaching([$order->user_id]);
            }
        };

        $updateCheckoutSession = function (Order $order) use ($validated) {
            $utmFromRequest = $this->utmPayloadFromValidated($validated);
            $token = $validated['checkout_session_token'] ?? null;
            if ($token) {
                $session = CheckoutSession::where('session_token', $token)->first();
                if ($session) {
                    $mergedUtms = $this->mergeSessionUtms($session, $utmFromRequest);
                    $session->update(array_merge([
                        'step' => CheckoutSession::STEP_CONVERTED,
                        'order_id' => $order->id,
                    ], $mergedUtms));
                    $this->persistOrderUtms($order, $mergedUtms);

                    return;
                }
            }
            $this->persistOrderUtms($order, $utmFromRequest);
        };

        if ($paymentMethod === 'pix') {
            $order = $createOrderAndItems(array_merge($orderPayload, [
                'status' => 'pending',
                'gateway' => null,
                'gateway_id' => null,
                'metadata' => array_merge($orderMetadata, ['checkout_payment_method' => 'pix']),
            ]));
            $order->load('orderItems');
            event(new OrderPending($order));
            try {
                $paymentService = app(PaymentService::class);
                $fake = FakeConsumerData::getForGateway($order->id);
                $rawDoc = preg_replace('/\D/', '', $validated['cpf'] ?? '');
                $consumer = [
                    'name' => trim((string) ($validated['name'] ?? '')) !== ''
                        ? $validated['name']
                        : $fake['name'],
                    'document' => strlen($rawDoc) >= 11 ? $rawDoc : $fake['document'],
                    'email' => $validated['email'],
                    'phone' => trim((string) ($validated['phone'] ?? '')),
                ];
                $pixResult = $paymentService->createPixPayment($order, $product, $consumer);
                event(new PixGenerated($order, [
                    'qrcode' => $pixResult['qrcode'] ?? null,
                    'copy_paste' => $pixResult['copy_paste'] ?? null,
                    'transaction_id' => $pixResult['transaction_id'] ?? null,
                ]));
                $updateCheckoutSession($order);
                $redirectUrl = $product->checkout_config['redirect_after_purchase'] ?? null;
                $redirectUrl = ! empty($redirectUrl) && is_string($redirectUrl) ? $redirectUrl : null;
                $pixToken = \Illuminate\Support\Str::random(32);
                session()->put('pix_display.' . $pixToken, [
                    'order_id' => $order->id,
                    'qrcode' => $pixResult['qrcode'] ?? null,
                    'copy_paste' => $pixResult['copy_paste'] ?? null,
                    'amount' => $totalAmount,
                    'product_name' => $product->name,
                    'checkout_slug' => $checkoutSlug,
                    'redirect_after_purchase' => $redirectUrl,
                    'customer_name' => $validated['name'] ?? null,
                    'customer_email' => $validated['email'] ?? null,
                    'customer_phone' => $validated['phone'] ?? null,
                    'created_at' => time(),
                ]);
                if ($request->expectsJson()) {
                    return $this->idempotencyReturn($idempotencyKey, response()->json([
                        'success' => true,
                        'payment_method' => 'pix',
                        'order_id' => $order->id,
                        'qrcode' => $pixResult['qrcode'] ?? null,
                        'copy_paste' => $pixResult['copy_paste'] ?? null,
                        'transaction_id' => $pixResult['transaction_id'] ?? null,
                        'redirect_url' => route('checkout.pix', ['token' => $pixToken]),
                    ]));
                }

                return $this->idempotencyReturn($idempotencyKey, redirect()->route('checkout.pix', ['token' => $pixToken]));
            } catch (\Throwable $e) {
                $this->rollbackFailedOrder($order, $e);
                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => $e->getMessage() ?: 'Não foi possível gerar o PIX. Tente novamente.',
                    ], 422);
                }
                return back()->with('error', $e->getMessage() ?: 'Não foi possível gerar o PIX. Tente novamente.');
            }
        }

        if ($paymentMethod === 'pix_auto') {
            $paymentService = app(PaymentService::class);
            $gatewaySlug = $paymentService->getFirstAvailableGatewayForMethod($tenantId, 'pix_auto', $product);
            if ($gatewaySlug === null) {
                if ($request->expectsJson()) {
                    return response()->json(['message' => 'Nenhum gateway PIX automático configurado.'], 422);
                }
                return back()->withErrors(['payment_method' => 'Nenhum gateway PIX automático configurado.']);
            }

            if ($gatewaySlug === 'pushinpay') {
                $credential = GatewayCredential::forTenant($tenantId)
                    ->where('gateway_slug', 'pushinpay')
                    ->where('is_connected', true)
                    ->first();
                if (! $credential) {
                    if ($request->expectsJson()) {
                        return response()->json(['message' => 'Pushin Pay não configurado para PIX automático.'], 422);
                    }
                    return back()->withErrors(['payment_method' => 'Pushin Pay não configurado para PIX automático.']);
                }
                $credentials = $credential->getDecryptedCredentials();
                if (empty($credentials['api_token'])) {
                    if ($request->expectsJson()) {
                        return response()->json(['message' => 'Pushin Pay: API Token não configurado.'], 422);
                    }
                    return back()->withErrors(['payment_method' => 'Pushin Pay: API Token não configurado.']);
                }

                $order = $createOrderAndItems(array_merge($orderPayload, [
                    'status' => 'pending',
                    'gateway' => null,
                    'gateway_id' => null,
                    'metadata' => array_merge($orderMetadata, ['checkout_payment_method' => 'pix_auto']),
                ]));
                $order->load('orderItems');
                event(new OrderPending($order));

                $rawDoc = preg_replace('/\D/', '', $validated['cpf'] ?? '');
                $fake = FakeConsumerData::getForGateway($order->id);
                $consumer = [
                    'name' => trim((string) ($validated['name'] ?? '')) !== '' ? $validated['name'] : $fake['name'],
                    'document' => strlen($rawDoc) >= 11 ? $rawDoc : $fake['document'],
                    'email' => $validated['email'],
                ];

                try {
                    $webhookUrl = route('webhooks.pushinpay');
                    $frequency = PushinPayPixRecorrenteService::intervalToFrequency($plan->interval ?? SubscriptionPlan::INTERVAL_MONTHLY);
                    $subscriptionName = mb_substr(preg_replace('/[^\p{L}\p{N}\s\.\-]/u', '', $product->name ?? 'Assinatura'), 0, 140) ?: 'Assinatura';
                    $pushinpayService = new PushinPayPixRecorrenteService($credentials);
                    $result = $pushinpayService->createSubscription(
                        (float) $totalAmount,
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
                    $updateCheckoutSession($order);

                    if ($request->expectsJson()) {
                        return $this->idempotencyReturn($idempotencyKey, response()->json([
                            'success' => true,
                            'payment_method' => 'pix_auto',
                            'order_id' => $order->id,
                            'qrcode' => $qrcodeImage,
                            'copy_paste' => $copyPaste ?? '',
                            'transaction_id' => $txid,
                        ]));
                    }
                    $redirectUrl = $product->checkout_config['redirect_after_purchase'] ?? null;
                    $redirectUrl = ! empty($redirectUrl) && is_string($redirectUrl) ? $redirectUrl : null;
                    $pixToken = Str::random(32);
                    session()->put('pix_display.' . $pixToken, [
                        'order_id' => $order->id,
                        'qrcode' => $qrcodeImage,
                        'copy_paste' => $copyPaste ?? '',
                        'amount' => $totalAmount,
                        'product_name' => $product->name,
                        'checkout_slug' => $checkoutSlug,
                        'redirect_after_purchase' => $redirectUrl,
                        'customer_name' => $validated['name'] ?? null,
                        'customer_email' => $validated['email'] ?? null,
                        'customer_phone' => $validated['phone'] ?? null,
                        'created_at' => time(),
                    ]);
                    return $this->idempotencyReturn($idempotencyKey, redirect()->route('checkout.pix', ['token' => $pixToken]));
                } catch (\Throwable $e) {
                    $this->rollbackFailedOrder($order, $e);
                    if ($request->expectsJson()) {
                        return response()->json([
                            'success' => false,
                            'message' => $e->getMessage() ?: 'Não foi possível gerar o PIX automático. Tente novamente.',
                        ], 422);
                    }
                    return back()->with('error', $e->getMessage() ?: 'Não foi possível gerar o PIX automático. Tente novamente.');
                }
            }

            if ($gatewaySlug === 'efi') {
                $credential = GatewayCredential::forTenant($tenantId)
                    ->where('gateway_slug', 'efi')
                    ->where('is_connected', true)
                    ->first();
                if (! $credential) {
                    if ($request->expectsJson()) {
                        return response()->json(['message' => 'Gateway Efí não configurado para PIX automático.'], 422);
                    }
                    return back()->withErrors(['payment_method' => 'Gateway Efí não configurado para PIX automático.']);
                }
                $credentials = $credential->getDecryptedCredentials();
                if (empty($credentials['certificate_path']) || empty($credentials['pix_key'])) {
                    if ($request->expectsJson()) {
                        return response()->json(['message' => 'Efí: certificado ou chave PIX não configurados.'], 422);
                    }
                    return back()->withErrors(['payment_method' => 'Efí: certificado ou chave PIX não configurados.']);
                }

                $order = $createOrderAndItems(array_merge($orderPayload, [
                    'status' => 'pending',
                    'gateway' => null,
                    'gateway_id' => null,
                    'metadata' => array_merge($orderMetadata, ['checkout_payment_method' => 'pix_auto']),
                ]));
                $order->load('orderItems');
                event(new OrderPending($order));

                $base = 'pixauto' . $order->id;
                $txid = $base . Str::random(max(26 - strlen($base), 10));
                $txid = substr($txid, 0, 35);
                $rawDoc = preg_replace('/\D/', '', $validated['cpf'] ?? '');
                $fake = FakeConsumerData::getForGateway($order->id);
                $consumer = [
                    'name' => trim((string) ($validated['name'] ?? '')) !== '' ? $validated['name'] : $fake['name'],
                    'document' => strlen($rawDoc) >= 11 ? $rawDoc : $fake['document'],
                    'email' => $validated['email'],
                ];

                try {
                    $efiRecorrente = new EfiPixRecorrenteService($credentials);
                    $locRec = $efiRecorrente->createLocRec();
                    $locId = (int) $locRec['id'];

                    $cob = $efiRecorrente->createCobWithTxid(
                        $txid,
                        (float) $totalAmount,
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
                    $objeto = mb_substr(preg_replace('/[^\p{L}\p{N}\s\.\-]/u', '', $product->name ?? 'Assinatura'), 0, 140) ?: 'Assinatura';
                    $rec = $efiRecorrente->createRecurrence(
                        $locId,
                        $txid,
                        $consumer,
                        (float) $totalAmount,
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
                        } catch (\Throwable $e) {
                            \Log::warning('CheckoutController pix_auto: falha ao obter QR da recorrência', ['idRec' => $idRec, 'error' => $e->getMessage()]);
                        }
                    }

                    event(new PixGenerated($order, [
                        'qrcode' => $qrcodeImage,
                        'copy_paste' => $copyPaste ?? '',
                        'transaction_id' => $txid,
                    ]));
                    $updateCheckoutSession($order);

                    if ($request->expectsJson()) {
                        return $this->idempotencyReturn($idempotencyKey, response()->json([
                            'success' => true,
                            'payment_method' => 'pix_auto',
                            'order_id' => $order->id,
                            'qrcode' => $qrcodeImage,
                            'copy_paste' => $copyPaste ?? '',
                            'transaction_id' => $txid,
                        ]));
                    }
                    $redirectUrl = $product->checkout_config['redirect_after_purchase'] ?? null;
                    $redirectUrl = ! empty($redirectUrl) && is_string($redirectUrl) ? $redirectUrl : null;
                    $pixToken = Str::random(32);
                    session()->put('pix_display.' . $pixToken, [
                        'order_id' => $order->id,
                        'qrcode' => $qrcodeImage,
                        'copy_paste' => $copyPaste ?? '',
                        'amount' => $totalAmount,
                        'product_name' => $product->name,
                        'checkout_slug' => $checkoutSlug,
                        'redirect_after_purchase' => $redirectUrl,
                        'customer_name' => $validated['name'] ?? null,
                        'customer_email' => $validated['email'] ?? null,
                        'customer_phone' => $validated['phone'] ?? null,
                        'created_at' => time(),
                    ]);

                    return $this->idempotencyReturn($idempotencyKey, redirect()->route('checkout.pix', ['token' => $pixToken]));
                } catch (\Throwable $e) {
                    $this->rollbackFailedOrder($order, $e);
                    if ($request->expectsJson()) {
                        return response()->json([
                            'success' => false,
                            'message' => $e->getMessage() ?: 'Não foi possível gerar o PIX automático. Tente novamente.',
                        ], 422);
                    }
                    return back()->with('error', $e->getMessage() ?: 'Não foi possível gerar o PIX automático. Tente novamente.');
                }
            }

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Gateway PIX automático não suportado.'], 422);
            }
            return back()->withErrors(['payment_method' => 'Gateway PIX automático não suportado.']);
        }

        if ($paymentMethod === 'card') {
            $order = $createOrderAndItems(array_merge($orderPayload, [
                'status' => 'pending',
                'gateway' => null,
                'gateway_id' => null,
                'metadata' => array_merge($orderMetadata, ['checkout_payment_method' => 'card']),
            ]));
            $order->load('orderItems');
            event(new OrderPending($order));
            $card = CheckoutCardContract::fromRequest($validated);
            if (isset($validated['card_holder_name'], $validated['card_number'], $validated['card_expiry_month'], $validated['card_expiry_year'], $validated['card_ccv'])) {
                $card['card_holder_name'] = trim((string) $validated['card_holder_name']);
                $card['card_number'] = trim((string) $validated['card_number']);
                $card['card_expiry_month'] = trim((string) $validated['card_expiry_month']);
                $card['card_expiry_year'] = trim((string) $validated['card_expiry_year']);
                $card['card_ccv'] = trim((string) $validated['card_ccv']);
            }
            $checkoutConfig = $offer && $offer->checkout_config !== null && $offer->checkout_config !== []
                ? array_replace_recursive(Product::defaultCheckoutConfig(), $offer->checkout_config)
                : ($plan && $plan->checkout_config !== null && $plan->checkout_config !== []
                    ? array_replace_recursive(Product::defaultCheckoutConfig(), $plan->checkout_config)
                    : ($product->checkout_config ?? []));
            $cardInstallments = $checkoutConfig['card_installments'] ?? ['enabled' => false, 'max' => 1];
            $installmentsEnabled = ! empty($cardInstallments['enabled']);
            $maxInstallments = min(12, max(1, (int) ($cardInstallments['max'] ?? 1)));
            $requestedInstallments = (int) ($validated['installments'] ?? 1);
            $card['installments'] = $installmentsEnabled
                ? min($maxInstallments, max(1, $requestedInstallments))
                : 1;
            $card['currency'] = strtolower($currency);
            if ($checkoutSlug !== '') {
                $card['return_url'] = url()->route('checkout.show', ['slug' => $checkoutSlug]);
            }
            $rawDoc = preg_replace('/\D/', '', $validated['cpf'] ?? '');
            $fake = FakeConsumerData::getForGateway($order->id);
            $consumer = [
                'name' => trim((string) ($validated['name'] ?? '')) !== '' ? $validated['name'] : $fake['name'],
                'document' => strlen($rawDoc) >= 11 ? $rawDoc : $fake['document'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? '',
            ];
            $zipCode = preg_replace('/\D/', '', $validated['address_zipcode'] ?? '');
            if (strlen($zipCode) >= 8) {
                $consumer['address'] = [
                    'zip_code' => substr($zipCode, 0, 8),
                    'street_name' => trim((string) ($validated['address_street'] ?? '')),
                    'street_number' => trim((string) ($validated['address_number'] ?? '')),
                    'neighborhood' => trim((string) ($validated['address_neighborhood'] ?? '')),
                    'city' => trim((string) ($validated['address_city'] ?? '')),
                    'federal_unit' => strtoupper(substr(trim((string) ($validated['address_state'] ?? '')), 0, 2)),
                ];
            }
            try {
                $paymentService = app(PaymentService::class);
                $cardResult = $paymentService->createCardPayment($order, $product, $consumer, $card);
                $status = $cardResult['status'] ?? null;
                if (in_array($status, ['paid', 'settled', 'approved', 'completed'], true)) {
                    $order->update(['status' => 'completed']);
                    $order->load('orderItems');
                    $grantAccessForOrder($order);
                    if ($plan) {
                        $subscription = Subscription::create([
                            'tenant_id' => $tenantId,
                            'user_id' => $user->id,
                            'product_id' => $product->id,
                            'subscription_plan_id' => $plan->id,
                            'status' => Subscription::STATUS_ACTIVE,
                            'current_period_start' => $periodStart,
                            'current_period_end' => $periodEnd,
                        ]);
                        event(new SubscriptionCreated($subscription));
                    }
                    event(new OrderCompleted($order));
                }
                $updateCheckoutSession($order);
                $config = $this->getOrderCheckoutConfigForProcess($order, $product, $offer, $plan);
                $redirectUrl = null;
                $isApproved = in_array($status, ['paid', 'settled', 'approved'], true);
                if ($isApproved) {
                    $upsell = $config['upsell'] ?? [];
                    if (! empty($upsell['enabled']) && ! empty($upsell['products']) && is_array($upsell['products'])) {
                        $upsellToken = Str::random(64);
                        Cache::put('upsell_token.' . $upsellToken, ['order_id' => $order->id, 'gateway' => $order->gateway], now()->addMinutes(60));
                        $redirectUrl = route('checkout.upsell', ['token' => $upsellToken]);
                    } else {
                        $customRedirect = $config['redirect_after_purchase'] ?? null;
                        if (! empty($customRedirect) && is_string($customRedirect)) {
                            $redirectUrl = $customRedirect;
                        } else {
                            $next = ($order->user_id && User::find($order->user_id)) ? 'member-area' : 'login';
                            $redirectUrl = route('checkout.thank-you', ['order_id' => $order->id, 'next' => $next]);
                        }
                    }
                }
                $wantsJson = $request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest';
                if ($wantsJson && $redirectUrl === null) {
                    $next = ($order->user_id && User::find($order->user_id)) ? 'member-area' : 'login';
                    $redirectUrl = route('checkout.thank-you', ['order_id' => $order->id, 'next' => $next]);
                }
                if ($wantsJson) {
                    $json = [
                        'success' => true,
                        'payment_method' => 'card',
                        'order_id' => $order->id,
                        'status' => $status,
                        'message' => $isApproved ? 'Pagamento aprovado.' : 'Pagamento em processamento.',
                        'redirect_url' => $redirectUrl,
                    ];
                    if ($status === 'requires_action' && ! empty($cardResult['client_secret'])) {
                        $json['requires_action'] = true;
                        $json['client_secret'] = $cardResult['client_secret'];
                    }
                    return $this->idempotencyReturn($idempotencyKey, response()->json($json));
                }
                if ($redirectUrl !== null) {
                    if (str_starts_with($redirectUrl, 'http') && ! str_starts_with($redirectUrl, request()->getSchemeAndHttpHost())) {
                        return $this->idempotencyReturn($idempotencyKey, redirect()->away($redirectUrl)->with('success', 'Compra concluída.'));
                    }
                    return $this->idempotencyReturn($idempotencyKey, redirect()->to($redirectUrl)->with('success', $isApproved ? 'Compra concluída.' : 'Pagamento em processamento.'));
                }
                if ($checkoutSlug !== '') {
                    return $this->idempotencyReturn($idempotencyKey, redirect()->route('checkout.show', ['slug' => $checkoutSlug])->with('success', 'Pagamento com cartão recebido. Você receberá a confirmação por e-mail.'));
                }
                return $this->idempotencyReturn($idempotencyKey, back()->with('success', 'Pagamento com cartão recebido. Você receberá a confirmação por e-mail.'));
            } catch (\Throwable $e) {
                $this->rollbackFailedOrder($order, $e);
                if ($request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                    return response()->json([
                        'success' => false,
                        'message' => $e->getMessage() ?: 'Não foi possível processar o pagamento. Tente novamente.',
                    ], 422);
                }
                return back()->with('error', $e->getMessage() ?: 'Não foi possível processar o pagamento. Tente novamente.');
            }
        }

        if ($paymentMethod === 'boleto') {
            $order = $createOrderAndItems(array_merge($orderPayload, [
                'status' => 'pending',
                'gateway' => null,
                'gateway_id' => null,
                'metadata' => array_merge($orderMetadata, ['checkout_payment_method' => 'boleto']),
            ]));
            $order->load('orderItems');
            event(new OrderPending($order));
            try {
                $paymentService = app(PaymentService::class);
                $fake = FakeConsumerData::getForGateway($order->id);
                $rawDoc = preg_replace('/\D/', '', $validated['cpf'] ?? '');
                $consumer = [
                    'name' => trim((string) ($validated['name'] ?? '')) !== ''
                        ? $validated['name']
                        : $fake['name'],
                    'document' => strlen($rawDoc) >= 11 ? $rawDoc : $fake['document'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'] ?? '',
                ];
                $zipCode = preg_replace('/\D/', '', $validated['address_zipcode'] ?? '');
                if (strlen($zipCode) >= 8) {
                    $consumer['address'] = [
                        'zip_code' => substr($zipCode, 0, 8),
                        'street_name' => trim((string) ($validated['address_street'] ?? '')),
                        'street_number' => trim((string) ($validated['address_number'] ?? '')),
                        'neighborhood' => trim((string) ($validated['address_neighborhood'] ?? '')),
                        'city' => trim((string) ($validated['address_city'] ?? '')),
                        'federal_unit' => strtoupper(substr(trim((string) ($validated['address_state'] ?? '')), 0, 2)),
                    ];
                }
                $boletoResult = $paymentService->createBoletoPayment($order, $product, $consumer);
                $boletoData = [
                    'amount' => $boletoResult['amount'] ?? $totalAmount,
                    'expire_at' => $boletoResult['expire_at'] ?? null,
                    'barcode' => $boletoResult['barcode'] ?? null,
                    'pdf_url' => $boletoResult['pdf_url'] ?? null,
                ];
                event(new BoletoGenerated($order, $boletoData));
                $updateCheckoutSession($order);
                $redirectUrl = $product->checkout_config['redirect_after_purchase'] ?? null;
                $redirectUrl = ! empty($redirectUrl) && is_string($redirectUrl) ? $redirectUrl : null;
                $boletoToken = Str::random(32);
                $amountFormatted = 'R$ ' . number_format((float) ($boletoResult['amount'] ?? $totalAmount), 2, ',', '.');
                session()->put('boleto_display.' . $boletoToken, [
                    'order_id' => $order->id,
                    'amount' => $boletoResult['amount'] ?? $totalAmount,
                    'amount_formatted' => $amountFormatted,
                    'expire_at' => $boletoResult['expire_at'] ?? null,
                    'barcode' => $boletoResult['barcode'] ?? null,
                    'pdf_url' => $boletoResult['pdf_url'] ?? null,
                    'product_name' => $product->name,
                    'checkout_slug' => $checkoutSlug,
                    'redirect_after_purchase' => $redirectUrl,
                    'customer_name' => $validated['name'] ?? null,
                    'customer_email' => $validated['email'] ?? null,
                    'customer_phone' => $validated['phone'] ?? null,
                    'created_at' => time(),
                ]);
                if ($request->expectsJson()) {
                    return $this->idempotencyReturn($idempotencyKey, response()->json([
                        'success' => true,
                        'payment_method' => 'boleto',
                        'order_id' => $order->id,
                        'redirect_url' => route('checkout.boleto', ['token' => $boletoToken]),
                    ]));
                }
                return $this->idempotencyReturn($idempotencyKey, redirect()->route('checkout.boleto', ['token' => $boletoToken]));
            } catch (\Throwable $e) {
                $this->rollbackFailedOrder($order, $e);
                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => $e->getMessage() ?: 'Não foi possível gerar o boleto. Tente novamente.',
                    ], 422);
                }
                return back()->with('error', $e->getMessage() ?: 'Não foi possível gerar o boleto. Tente novamente.');
            }
        }

        if ($request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
            return response()->json([
                'message' => 'Método de pagamento inválido ou não disponível.',
                'errors' => ['payment_method' => ['Selecione um método de pagamento válido.']],
            ], 422);
        }

        return back()->withErrors(['payment_method' => 'Selecione um método de pagamento válido.']);
    }

    /**
     * Config de checkout efetiva no process (já temos product, offer, plan).
     */
    private function getOrderCheckoutConfigForProcess(Order $order, Product $product, ?ProductOffer $offer, ?SubscriptionPlan $plan): array
    {
        if ($plan && $plan->checkout_config) {
            return array_replace_recursive(Product::defaultCheckoutConfig(), $plan->checkout_config);
        }
        if ($offer && $offer->checkout_config) {
            return array_replace_recursive(Product::defaultCheckoutConfig(), $offer->checkout_config);
        }
        return $product->checkout_config;
    }

    private const PIX_EXPIRY_SECONDS = 900; // 15 min

    /**
     * Página de PIX gerado (dados vindos da sessão, identificado por token aleatório).
     *
     * @return \Illuminate\Http\RedirectResponse|Response
     */
    public function pixPage(Request $request)
    {
        $token = $request->query('token');
        if (! $token || ! is_string($token)) {
            return redirect()->route('login')->with('error', 'Link inválido ou expirado.');
        }

        $stored = session('pix_display.' . $token);
        if (! is_array($stored)) {
            return redirect()->route('login')->with('error', 'Código PIX expirado ou inválido. Gere um novo PIX.');
        }

        $orderId = (int) ($stored['order_id'] ?? 0);
        $order = Order::with('product', 'productOffer', 'subscriptionPlan')->find($orderId);
        if (! $order || $order->status !== 'pending') {
            session()->forget('pix_display.' . $token);
            $slug = $order ? $order->getCheckoutSlug() : null;
            $redirect = $slug ? redirect()->route('checkout.show', ['slug' => $slug]) : redirect()->route('login');
            return $redirect->with('error', 'Código PIX expirado ou inválido. Gere um novo PIX.');
        }

        $createdAt = (int) ($stored['created_at'] ?? 0);
        if ($createdAt + self::PIX_EXPIRY_SECONDS < time()) {
            session()->forget('pix_display.' . $token);
            return redirect()->route('checkout.show', ['slug' => $order->getCheckoutSlug()])
                ->with('error', 'Código PIX expirado. Gere um novo PIX.');
        }

        $amount = (float) ($stored['amount'] ?? 0);
        $amountFormatted = 'R$ ' . number_format($amount, 2, ',', '.');

        $product = $order->product;
        $conversionPixels = $product ? ($product->conversion_pixels ?? Product::defaultConversionPixels()) : Product::defaultConversionPixels();

        return Inertia::render('Checkout/Pix', [
            'token' => $token,
            'order_id' => $orderId,
            'qrcode' => $stored['qrcode'] ?? null,
            'copy_paste' => $stored['copy_paste'] ?? null,
            'amount' => $amount,
            'amount_formatted' => $amountFormatted,
            'product_name' => $stored['product_name'] ?? $order->product->name,
            'checkout_slug' => $stored['checkout_slug'] ?? $order->getCheckoutSlug(),
            'redirect_after_purchase' => $stored['redirect_after_purchase'] ?? null,
            'customer_name' => $stored['customer_name'] ?? null,
            'customer_email' => $stored['customer_email'] ?? null,
            'customer_phone' => $stored['customer_phone'] ?? null,
            'created_at' => $createdAt,
            'expiry_seconds' => self::PIX_EXPIRY_SECONDS,
            'conversion_pixels' => $conversionPixels,
        ]);
    }

    /**
     * Página de boleto gerado (dados vindos da sessão, identificado por token).
     *
     * @return \Illuminate\Http\RedirectResponse|Response
     */
    public function boletoPage(Request $request)
    {
        $token = $request->query('token');
        if (! $token || ! is_string($token)) {
            return redirect()->route('login')->with('error', 'Link inválido ou expirado.');
        }

        $stored = session('boleto_display.' . $token);
        if (! is_array($stored)) {
            return redirect()->route('login')->with('error', 'Boleto expirado ou inválido. Gere um novo boleto.');
        }

        $orderId = (int) ($stored['order_id'] ?? 0);
        $order = Order::with('product', 'productOffer', 'subscriptionPlan')->find($orderId);
        if (! $order || $order->status !== 'pending') {
            session()->forget('boleto_display.' . $token);
            $slug = $order ? $order->getCheckoutSlug() : null;
            $redirect = $slug ? redirect()->route('checkout.show', ['slug' => $slug]) : redirect()->route('login');
            return $redirect->with('error', 'Boleto expirado ou inválido. Gere um novo boleto.');
        }

        $product = $order->product;
        $conversionPixels = $product ? ($product->conversion_pixels ?? Product::defaultConversionPixels()) : Product::defaultConversionPixels();

        return Inertia::render('Checkout/Boleto', [
            'token' => $token,
            'order_id' => $orderId,
            'amount_formatted' => $stored['amount_formatted'] ?? 'R$ 0,00',
            'expire_at' => $stored['expire_at'] ?? null,
            'barcode' => $stored['barcode'] ?? '',
            'pdf_url' => $stored['pdf_url'] ?? null,
            'product_name' => $stored['product_name'] ?? $order->product->name,
            'checkout_slug' => $stored['checkout_slug'] ?? $order->getCheckoutSlug(),
            'redirect_after_purchase' => $stored['redirect_after_purchase'] ?? null,
            'customer_name' => $stored['customer_name'] ?? null,
            'customer_email' => $stored['customer_email'] ?? null,
            'customer_phone' => $stored['customer_phone'] ?? null,
            'conversion_pixels' => $conversionPixels,
        ]);
    }

    /**
     * Status do pedido para polling na página PIX ou Boleto (identificado por token).
     */
    public function orderStatus(Request $request): JsonResponse
    {
        $token = $request->query('token');
        if (! $token || ! is_string($token)) {
            return response()->json(['status' => 'invalid'], 400);
        }

        $stored = session('pix_display.' . $token);
        if (! is_array($stored)) {
            $stored = session('boleto_display.' . $token);
        }
        if (! is_array($stored)) {
            return response()->json(['status' => 'not_found'], 404);
        }

        $orderId = (int) ($stored['order_id'] ?? 0);
        $order = Order::with('product', 'productOffer', 'subscriptionPlan')->find($orderId);
        if (! $order) {
            return response()->json(['status' => 'not_found'], 404);
        }

        if ($order->status === 'pending' && ! empty($order->gateway) && ! empty($order->gateway_id)) {
            $gatewaySlug = (string) $order->gateway;
            try {
                $credential = GatewayCredential::forTenant($order->tenant_id)
                    ->where('gateway_slug', $gatewaySlug)
                    ->where('is_connected', true)
                    ->first();
                if ($credential) {
                    $credentials = $credential->getDecryptedCredentials();
                    $driver = GatewayRegistry::driver($gatewaySlug);
                    $efiNeedsCert = $gatewaySlug === 'efi' && empty($credentials['certificate_path'] ?? '');
                    if ($driver && $credentials !== [] && ! $efiNeedsCert) {
                        $apiStatus = $driver->getTransactionStatus((string) $order->gateway_id, $credentials);
                        if ($apiStatus === 'paid') {
                            ProcessPaymentWebhook::dispatchSync(
                                $gatewaySlug,
                                (string) $order->gateway_id,
                                'order.paid',
                                'paid',
                                ['source' => 'order_status_poll']
                            );
                            $order->refresh();
                        }
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::debug('CheckoutController orderStatus: falha ao consultar status no gateway', [
                    'order_id' => $order->id,
                    'gateway' => $gatewaySlug,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $status = $order->status;
        $redirectUrl = null;
        if ($status === 'completed') {
            if ($order->api_application_id) {
                $redirectUrl = route('api-checkout.thank-you', ['order_id' => $order->id]);
            } else {
            $config = $this->getOrderCheckoutConfig($order);
            $upsell = $config['upsell'] ?? [];
            if (! empty($upsell['enabled']) && ! empty($upsell['products']) && is_array($upsell['products'])) {
                $upsellToken = Str::random(64);
                Cache::put('upsell_token.' . $upsellToken, [
                    'order_id' => $order->id,
                    'gateway' => 'pix',
                ], now()->addMinutes(60));
                $redirectUrl = route('checkout.upsell', ['token' => $upsellToken]);
            } else {
                $customRedirect = $config['redirect_after_purchase'] ?? null;
                if (! empty($customRedirect) && is_string($customRedirect)) {
                    $redirectUrl = $customRedirect;
                } else {
                    $next = ($order->user_id && User::find($order->user_id)) ? 'member-area' : 'login';
                    $redirectUrl = route('checkout.thank-you', ['order_id' => $order->id, 'next' => $next]);
                }
            }
            }
        }

        return response()->json([
            'status' => $status,
            'redirect_url' => $redirectUrl,
        ]);
    }

    /**
     * Config de checkout efetiva do pedido (plano > oferta > produto).
     */
    private function getOrderCheckoutConfig(Order $order): array
    {
        if ($order->subscriptionPlan && $order->subscriptionPlan->checkout_config) {
            return array_replace_recursive(Product::defaultCheckoutConfig(), $order->subscriptionPlan->checkout_config);
        }
        if ($order->productOffer && $order->productOffer->checkout_config) {
            return array_replace_recursive(Product::defaultCheckoutConfig(), $order->productOffer->checkout_config);
        }
        return $order->product ? $order->product->checkout_config : [];
    }

    /**
     * Métodos de pagamento disponíveis para o produto (PIX, Cartão, Boleto, PIX automático para assinatura).
     *
     * @param  array<string, mixed>|null  $effectiveConfig  Config efetiva (product + plan/offer) para payment_gateways.
     * @return array<int, array{id: string, label: string, gateway_name?: string, gateway_slug?: string}>
     */
    private function buildAvailablePaymentMethods(Product $product, ?SubscriptionPlan $plan = null, ?array $effectiveConfig = null): array
    {
        $tenantId = $product->tenant_id;
        $config = $effectiveConfig ?? $product->checkout_config ?? [];
        $pg = $config['payment_gateways'] ?? [];
        $orderRaw = Setting::get('gateway_order', null, $tenantId);
        if (is_string($orderRaw)) {
            $orderRaw = json_decode($orderRaw, true);
        }
        $defaultOrder = config('gateways.default_order', ['pix' => [], 'card' => [], 'boleto' => [], 'pix_auto' => []]);
        $order = is_array($orderRaw) ? $orderRaw : $defaultOrder;
        $order = [
            'pix' => $order['pix'] ?? $defaultOrder['pix'] ?? [],
            'card' => $order['card'] ?? $defaultOrder['card'] ?? [],
            'boleto' => $order['boleto'] ?? $defaultOrder['boleto'] ?? [],
            'pix_auto' => $order['pix_auto'] ?? $defaultOrder['pix_auto'] ?? [],
        ];

        $credentialBySlug = GatewayCredential::forTenant($tenantId)
            ->where('is_connected', true)
            ->get()
            ->keyBy('gateway_slug');

        $methods = [];
        $methodConfig = [
            'pix' => ['id' => 'pix', 'label' => 'PIX'],
            'card' => ['id' => 'card', 'label' => 'Cartão'],
            'boleto' => ['id' => 'boleto', 'label' => 'Boleto'],
            'pix_auto' => ['id' => 'pix_auto', 'label' => 'PIX automático'],
        ];

        foreach ($methodConfig as $methodKey => $meta) {
            if ($methodKey === 'pix_auto' && $plan === null) {
                continue;
            }
            $productSlug = isset($pg[$methodKey]) ? trim((string) $pg[$methodKey]) : null;
            if ($productSlug === null || $productSlug === '') {
                continue;
            }
            if ($productSlug === '__default__') {
                $slugsToCheck = is_array($order[$methodKey] ?? null) ? $order[$methodKey] : [];
            } else {
                $redundancy = $pg[$methodKey . '_redundancy'] ?? [];
                $redundancy = is_array($redundancy) ? $redundancy : [];
                $slugsToCheck = array_merge([$productSlug], $redundancy);
            }

            foreach ($slugsToCheck as $slug) {
                $cred = $credentialBySlug->get($slug);
                if (! $cred) {
                    continue;
                }
                $gateway = GatewayRegistry::get($slug);
                if (! $gateway || ! in_array($methodKey, $gateway['methods'] ?? [], true)) {
                    continue;
                }
                $methods[] = [
                    'id' => $meta['id'],
                    'label' => $meta['label'],
                    'gateway_slug' => $slug,
                    'gateway_name' => $gateway['name'] ?? $slug,
                ];
                break;
            }
        }

        return $methods;
    }

    /**
     * @param  float|null  $effectiveAmount  When offer/plan present, use this as price.
     * @param  string|null  $effectiveCurrency  When offer/plan present, use this.
     * @param  string|null  $effectiveCheckoutSlug  When offer/plan present, use this for redirects.
     */
    private function productToCheckoutArray(Product $product, ?ProductOffer $offer = null, ?SubscriptionPlan $plan = null, ?float $effectiveAmount = null, ?string $effectiveCurrency = null, ?string $effectiveCheckoutSlug = null): array
    {
        $imageUrl = $product->image
            ? (new StorageService($product->tenant_id))->url($product->image)
            : null;
        $summary = $product->checkout_config['summary'] ?? [];
        $previousPrice = $summary['previous_price'] ?? null;
        if ($previousPrice !== null) {
            $previousPrice = (float) $previousPrice;
        }

        $price = $effectiveAmount !== null ? $effectiveAmount : (float) $product->price;
        $currency = $effectiveCurrency ?? $product->currency ?? 'BRL';
        $checkoutSlug = $effectiveCheckoutSlug ?? $product->checkout_slug;

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'checkout_slug' => $checkoutSlug,
            'description' => $product->description,
            'type' => $product->type,
            'billing_type' => $product->billing_type ?? Product::BILLING_ONE_TIME,
            'price' => $price,
            'currency' => $currency,
            'image_url' => $imageUrl,
            'previous_price' => $previousPrice,
            'product_offer_id' => $offer?->id,
            'subscription_plan_id' => $plan?->id,
        ];
    }

    /**
     * Adiciona price_brl ao array do produto (preço normalizado em BRL) para conversão no front.
     *
     * @param  array<string, mixed>  $productArray
     * @param  array<int, array{code: string, rate_to_brl: float}>  $currencies
     * @return array<string, mixed>
     */
    private function addPricesInCurrencies(array $productArray, array $currencies): array
    {
        $price = (float) ($productArray['price'] ?? 0);
        $currency = $productArray['currency'] ?? 'BRL';
        $rates = [];
        foreach ($currencies as $c) {
            $code = $c['code'] ?? '';
            $rates[$code] = (float) ($c['rate_to_brl'] ?? 0);
        }
        $brlEur = $rates['EUR'] ?? config('products.rates.brl_eur', 0.16);
        $brlUsd = $rates['USD'] ?? config('products.rates.brl_usd', 0.18);
        $priceBrl = $currency === 'BRL' ? $price : ($currency === 'EUR' ? $price / $brlEur : $price / $brlUsd);
        $productArray['price_brl'] = round($priceBrl, 2);
        return $productArray;
    }

    /**
     * Armazena resposta de sucesso no cache de idempotência (24h) e retorna a resposta.
     * Evita pedidos duplicados em caso de duplo clique ou replay.
     */
    private function idempotencyReturn(?string $key, RedirectResponse|JsonResponse $response): RedirectResponse|JsonResponse
    {
        if ($key === null || $key === '' || strlen($key) > 128) {
            return $response;
        }
        if ($response instanceof RedirectResponse) {
            Cache::put('checkout_idempotency:' . $key, [
                'type' => 'redirect',
                'url' => $response->getTargetUrl(),
            ], now()->addMinutes(1440));
        }
        if ($response instanceof JsonResponse && $response->getStatusCode() === 200) {
            Cache::put('checkout_idempotency:' . $key, [
                'type' => 'json',
                'data' => json_decode($response->getContent(), true),
            ], now()->addMinutes(1440));
        }
        return $response;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{utm_source: ?string, utm_medium: ?string, utm_campaign: ?string}
     */
    private function utmPayloadFromValidated(array $validated): array
    {
        $out = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $k) {
            $v = isset($validated[$k]) ? trim((string) $validated[$k]) : '';
            $out[$k] = $v !== '' ? $v : null;
        }

        return $out;
    }

    /**
     * @param  array{utm_source: ?string, utm_medium: ?string, utm_campaign: ?string}  $fromRequest
     * @return array{utm_source: ?string, utm_medium: ?string, utm_campaign: ?string}
     */
    private function mergeSessionUtms(CheckoutSession $session, array $fromRequest): array
    {
        $out = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $k) {
            $req = $fromRequest[$k] ?? null;
            $sess = $session->{$k} ?? null;
            $reqN = is_string($req) && trim($req) !== '' ? trim($req) : null;
            $sessN = is_string($sess) && trim($sess) !== '' ? trim($sess) : null;
            $out[$k] = $reqN ?? $sessN;
        }

        return $out;
    }

    /**
     * @param  array{utm_source: ?string, utm_medium: ?string, utm_campaign: ?string}  $utmTriple
     */
    private function persistOrderUtms(Order $order, array $utmTriple): void
    {
        $meta = $order->metadata ?? [];
        $changed = false;
        foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $k) {
            $v = $utmTriple[$k] ?? null;
            if (! is_string($v) || trim($v) === '') {
                continue;
            }
            $v = trim($v);
            if (($meta[$k] ?? null) !== $v) {
                $meta[$k] = $v;
                $changed = true;
            }
        }
        if ($changed) {
            $order->update(['metadata' => $meta]);
        }
    }
}
