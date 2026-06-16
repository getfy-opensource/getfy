<?php

namespace App\Http\Controllers;

use App\Events\BoletoGenerated;
use App\Events\CheckoutBeforeProcess;
use App\Events\CheckoutPageLoading;
use App\Events\OrderCompleted;
use App\Events\OrderPending;
use App\Events\PixGenerated;
use App\Events\SubscriptionCreated;
use App\Gateways\GatewayRegistry;
use App\Plugins\PluginCheckoutExtensionRegistry;
use App\Plugins\PluginHookBus;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\Coupon;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Support\CheckoutQueryResolver;
use App\Models\CheckoutSession;
use App\Models\ProductOrderBump;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\GeoIp;
use App\Services\EfiPixRecorrenteService;
use App\Services\StorageService;
use App\Services\CajuPayPixParceladoService;
use App\Services\CheckoutAbuseGuard;
use App\Services\PaymentService;
use App\Services\PushinPayPixRecorrenteService;
use App\Support\CajuPayLocale;
use App\Support\CheckoutCardContract;
use App\Support\CheckoutConfigNormalizer;
use App\Support\CheckoutCurrencyCatalog;
use App\Support\CheckoutCurrencyMode;
use App\Support\CheckoutCustomPriceByCurrency;
use App\Support\OrderReportingAmounts;
use App\Support\CheckoutPaymentMethodsBuilder;
use App\Support\CheckoutPaymentMethodOrder;
use App\Support\CheckoutTranslations;
use App\Support\FakeConsumerData;
use App\Support\MoneyMinorUnits;
use App\Support\MetaPurchaseTracking;
use App\Support\PendingPixCheckoutResolver;
use App\Support\PixCheckoutDisplay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            $offer = CheckoutQueryResolver::resolveOffer($product, $request);
            $plan = CheckoutQueryResolver::resolvePlan($product, $request);
            if ($offer) {
                $resolved = [
                    'product' => $product,
                    'offer' => $offer,
                    'plan' => null,
                    'amount' => (float) $offer->price,
                    'currency' => $offer->getCurrencyOrDefault(),
                    'checkout_slug' => $product->checkout_slug,
                ];
            } elseif ($plan) {
                $resolved = [
                    'product' => $product,
                    'offer' => null,
                    'plan' => $plan,
                    'amount' => (float) $plan->price,
                    'currency' => $plan->getCurrencyOrDefault(),
                    'checkout_slug' => $product->checkout_slug,
                ];
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
        $config = CheckoutConfigNormalizer::normalize($config);
        // Cobrança Pagar.me (modo empresa) vem só do produto — não herdar de oferta/plano.
        $productPagarmeBilling = $product->checkout_config['pagarme_billing'] ?? [];
        $config['pagarme_billing'] = array_replace_recursive(
            $defaults['pagarme_billing'] ?? [],
            is_array($productPagarmeBilling) ? $productPagarmeBilling : []
        );
        // Gateways, parcelas no cartão e regras PIX Parcelado vêm só do produto (oferta/plano não persistem essas chaves).
        $config['payment_gateways'] = array_replace_recursive(
            $defaults['payment_gateways'] ?? [],
            is_array($productConfig['payment_gateways'] ?? null) ? $productConfig['payment_gateways'] : []
        );
        $config['card_installments'] = array_replace_recursive(
            $defaults['card_installments'] ?? [],
            is_array($productConfig['card_installments'] ?? null) ? $productConfig['card_installments'] : []
        );
        $config['pix_parcelado'] = array_replace_recursive(
            $defaults['pix_parcelado'] ?? [],
            is_array($productConfig['pix_parcelado'] ?? null) ? $productConfig['pix_parcelado'] : []
        );
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
        $suggestions = $geo->getSuggestionsForRequest($request);
        $payload['suggested_locale'] = $suggestions['suggested_locale'];
        $payload['suggested_currency'] = $suggestions['suggested_currency'];
        $payload['suggested_country_code'] = $suggestions['country_code'] ?? null;

        $force = $config['checkout_force'] ?? [];
        $currencyMode = CheckoutCurrencyMode::resolve($config);
        if (! empty($force['enabled']) && is_array($force)) {
            $fl = isset($force['locale']) ? trim((string) $force['locale']) : '';
            if (in_array($fl, ['pt_BR', 'en', 'es'], true)) {
                $payload['suggested_locale'] = $fl;
            }
        }
        if ($currencyMode['mode'] === 'fixed') {
            $payload['suggested_currency'] = $currencyMode['currency'];
        }

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
        $payload['currencies'] = CheckoutCurrencyMode::filterCurrenciesForCheckout(
            $config,
            CheckoutCurrencyCatalog::currenciesForCheckout(
                is_array($currencies) ? $currencies : (array) config('products.currencies')
            )
        );

        $payload['product'] = $this->addPricesInCurrencies($productArray, $payload['currencies']);
        $paymentOrderCountry = $payload['suggested_country_code'] ?? null;
        $forcedCur = $currencyMode['mode'] === 'fixed' ? $currencyMode['currency'] : '';
        if ($forcedCur !== '' && $forcedCur !== 'BRL') {
            $paymentOrderCountry = 'US';
        }
        $paymentMethodsPlan = ($product->billing_type ?? Product::BILLING_ONE_TIME) === Product::BILLING_SUBSCRIPTION
            ? ($resolved['plan'] ?? null)
            : null;
        $payload['available_payment_methods'] = CheckoutPaymentMethodOrder::applyForCountry(
            CheckoutPaymentMethodsBuilder::build($product->tenant_id, $config['payment_gateways'] ?? [], $paymentMethodsPlan),
            $paymentOrderCountry
        );
        $payload['product']['custom_display_prices_by_currency'] = $this->customDisplayPricesMap($product);
        $productCheckout = $product->checkout_config ?? [];
        $defaultsCfg = Product::defaultCheckoutConfig();
        $payload['product']['checkout_config'] = [
            'checkout_force' => array_replace_recursive(
                $defaultsCfg['checkout_force'] ?? ['enabled' => false, 'locale' => null, 'currency' => null],
                is_array($productCheckout['checkout_force'] ?? null) ? $productCheckout['checkout_force'] : []
            ),
            'checkout_currency' => array_replace_recursive(
                $defaultsCfg['checkout_currency'] ?? ['mode' => 'global', 'currency' => 'BRL'],
                is_array($productCheckout['checkout_currency'] ?? null) ? $productCheckout['checkout_currency'] : []
            ),
        ];
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

        $payload['cajupay_pay_account_id'] = null;
        $payload['cajupay_public_key'] = '';
        $payload['pix_parcelado_rules'] = null;
        $payload['parcelado_sdk_options'] = [];
        $hasPixParcelado = false;
        foreach ($payload['available_payment_methods'] as $m) {
            if (($m['id'] ?? '') === 'pix_parcelado') {
                $hasPixParcelado = true;
                break;
            }
        }
        if ($hasPixParcelado && ($product->billing_type ?? Product::BILLING_ONE_TIME) === Product::BILLING_ONE_TIME) {
            $parceladoService = app(CajuPayPixParceladoService::class);
            $creds = $parceladoService->credentialsForTenant($product->tenant_id);
            if ($creds) {
                $payload['cajupay_public_key'] = (string) ($creds['public_key'] ?? '');
                $payAccountId = $parceladoService->resolvePayAccountIdForTenant($product->tenant_id);
                $payload['cajupay_pay_account_id'] = $payAccountId;
                $productRules = $parceladoService->productRulesFromConfig($config);
                $priceBrl = (float) $product->price;
                if ($resolved['offer'] ?? null) {
                    $priceBrl = (float) $resolved['offer']->price;
                }
                $platformRules = $parceladoService->platformRules($creds);
                $totalCents = MoneyMinorUnits::toMinorUnits($priceBrl, 'BRL');
                $merged = $parceladoService->mergeProductRulesWithPlatform($totalCents, $productRules, $platformRules);
                $payload['pix_parcelado_rules'] = $merged;
                $payload['parcelado_sdk_options'] = $parceladoService->sdkOptionsFromRules($merged);
            }
        }

        $orderBumps = $product->orderBumps()->with(['targetProduct', 'targetProductOffer', 'targetSubscriptionPlan'])->get();
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

        $payload['conversion_pixels'] = \App\Support\AffiliateAttribution::conversionPixelsForCheckout(
            $product,
            \App\Support\AffiliateAttribution::refFromRequest($request)
        );

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

        $trackingMeta = $this->extractTrackingMetadataFromRequest($request);
        $affiliateRef = \App\Support\AffiliateAttribution::refFromRequest($request);
        $trackingMeta = \App\Support\AffiliateAttribution::mergeIntoTrackingMetadata($trackingMeta, $affiliateRef);

        $geoSuggestions = app(\App\Services\GeoIp::class)->getSuggestionsForRequest($request);
        $sessionCountryCode = $geoSuggestions['country_code'] ?? null;

        CheckoutSession::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'product_offer_id' => $resolved['offer']?->id,
            'subscription_plan_id' => $resolved['plan']?->id,
            'checkout_slug' => $resolved['checkout_slug'],
            'session_token' => $sessionToken,
            'step' => CheckoutSession::STEP_VISIT,
            'customer_ip' => $request->ip(),
            'country_code' => is_string($sessionCountryCode) && strlen($sessionCountryCode) === 2
                ? strtoupper($sessionCountryCode)
                : null,
            'utm_source' => $utmSource,
            'utm_medium' => $utmMedium,
            'utm_campaign' => $utmCampaign,
            'tracking_metadata' => $trackingMeta === [] ? null : $trackingMeta,
        ]);
        $payload['checkout_session_token'] = $sessionToken;
        $payload['affiliate_ref'] = $affiliateRef;

        /** Preview ao vivo no Builder (iframe): o front confia neste flag, não só na query (Inertia pode alterar URL). */
        $payload['checkout_builder_preview'] = $request->query('preview') === '1';

        $checkoutEventData = new \ArrayObject([
            'product' => $payload['product'] ?? [],
            'config' => $config,
            'available_payment_methods' => $payload['available_payment_methods'] ?? [],
        ]);
        event(new CheckoutPageLoading($product, $checkoutEventData));
        foreach ($checkoutEventData->getArrayCopy() as $key => $value) {
            $payload[$key] = $value;
        }
        $payload = PluginHookBus::applyFilters('checkout.payload', $payload, $product, $request);

        $payload['plugin_checkout_extensions'] = PluginCheckoutExtensionRegistry::activeForProduct($product, 'standard');

        return Inertia::render('Checkout/Show', $payload)
            ->withViewData([
                'openGraph' => \App\Support\CheckoutOpenGraph::forProduct($product, $config, $request),
            ]);
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
            $price = CheckoutCurrencyCatalog::brlFromForeignAmount(
                $price,
                $currency,
                $this->tenantCurrenciesListFor($product->tenant_id)
            );
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
        $product = Product::where('id', $request->input('product_id'))->where('is_active', true)->firstOrFail();
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
        $firstBoletoGatewayForRules = $paymentMethodForRules === 'boleto'
            ? $paymentService->getFirstAvailableGatewayForMethod($product->tenant_id, 'boleto', $product)
            : null;
        $pagarmeBillingForRules = array_replace_recursive(
            Product::defaultCheckoutConfig()['pagarme_billing'] ?? [],
            is_array($product->checkout_config['pagarme_billing'] ?? null) ? $product->checkout_config['pagarme_billing'] : []
        );
        $skipPagarmeAddressValidation = ($pagarmeBillingForRules['mode'] ?? 'customer') === 'company'
            && (
                ($paymentMethodForRules === 'card' && in_array($firstCardGatewayForRules, ['pagarme', 'efi'], true))
                || ($paymentMethodForRules === 'boleto' && in_array($firstBoletoGatewayForRules, ['pagarme', 'efi'], true))
            );
        $requireCpf = (($customerFields['cpf'] ?? false) && $displayCurrency === 'BRL')
            || ($firstCardGatewayForRules === 'pagarme' && $displayCurrency === 'BRL');
        $phoneRequiredForCheckout = ($customerFields['phone'] ?? false)
            || ($paymentMethodForRules === 'pix' && $firstPixGateway === 'pagarme');

        $allowedDisplayCurrencies = CheckoutCustomPriceByCurrency::currencyCodesFromTenantSettings(
            $this->tenantCurrenciesListFor($product->tenant_id)
        );

        $rules = [
            'product_id' => ['required', 'exists:products,id'],
            'product_offer_id' => ['nullable', 'exists:product_offers,id'],
            'subscription_plan_id' => ['nullable', 'exists:subscription_plans,id'],
            'order_bump_ids' => ['nullable', 'array'],
            'order_bump_ids.*' => ['integer', 'exists:product_order_bumps,id'],
            'payment_method' => ['required', 'string', 'in:pix,card,boleto,pix_auto,apple_pay,google_pay'],
            'checkout_session_token' => ['nullable', 'string', 'max:64'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'display_currency' => ['nullable', 'string', 'max:8', Rule::in($allowedDisplayCurrencies)],
            'email' => ['required', 'email'],
            'name' => [($customerFields['name'] ?? true) ? 'required' : 'nullable', 'string', 'max:255'],
            'cpf' => [$requireCpf ? 'required' : 'nullable', 'string', 'max:11'],
            'phone' => [$phoneRequiredForCheckout ? 'required' : 'nullable', 'string', 'max:24'],
            'coupon_code' => ['nullable', 'string', 'max:64'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'affiliate_ref' => ['nullable', 'string', 'max:32'],
            'plugin_checkout_data' => ['nullable', 'string', 'max:65535'],
        ];
        $rules = array_merge($rules, $this->checkoutAttributionValidationRules());
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
            } elseif ($firstCardGateway === 'pagarme' || $firstCardGateway === 'efi') {
                $rules['payment_token'] = ['required', 'string', 'max:10000'];
                $addrRule = $skipPagarmeAddressValidation ? 'nullable' : 'required';
                $rules['address_zipcode'] = [$addrRule, 'string', 'max:9'];
                $rules['address_street'] = [$addrRule, 'string', 'max:255'];
                $rules['address_number'] = [$addrRule, 'string', 'max:20'];
                $rules['address_neighborhood'] = [$addrRule, 'string', 'max:255'];
                $rules['address_city'] = [$addrRule, 'string', 'max:255'];
                $rules['address_state'] = [$addrRule, 'string', 'max:2'];
            } else {
                $rules['payment_token'] = ['required', 'string', 'max:10000'];
            }
            $rules['installments'] = ['nullable', 'integer', 'min:1', 'max:12'];
        }
        if ($request->input('payment_method') === 'boleto') {
            $addrRule = $skipPagarmeAddressValidation ? 'nullable' : 'required';
            $rules['address_zipcode'] = [$addrRule, 'string', 'max:9'];
            $rules['address_street'] = [$addrRule, 'string', 'max:255'];
            $rules['address_number'] = [$addrRule, 'string', 'max:20'];
            $rules['address_neighborhood'] = [$addrRule, 'string', 'max:255'];
            $rules['address_city'] = [$addrRule, 'string', 'max:255'];
            $rules['address_state'] = [$addrRule, 'string', 'max:2'];
        }
        $validated = $request->validate($rules);
        $validated = $this->applyPagarmeCompanyAddressToValidated($validated, $product, $paymentService);

        $pluginCheckoutData = PluginCheckoutExtensionRegistry::decodeCheckoutDataFromRequest($request);

        app(CheckoutAbuseGuard::class)->assertCanCreateCheckout($request, $product);

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
            $amount = CheckoutCurrencyCatalog::brlFromForeignAmount(
                $amount,
                $currency,
                $this->tenantCurrenciesListFor($product->tenant_id)
            );
        }

        $displayCurrency = is_string($validated['display_currency'] ?? null) && $validated['display_currency'] !== ''
            ? strtoupper((string) $validated['display_currency'])
            : $effectiveCurrency;
        $amount = CheckoutCustomPriceByCurrency::amountBrlFromCustomIfApplicable(
            $product,
            $offer,
            $plan,
            $amount,
            $displayCurrency,
            $this->tenantCurrenciesListFor($product->tenant_id)
        );

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

        $beforeProcess = new CheckoutBeforeProcess($product, $validated, null, $pluginCheckoutData);
        PluginCheckoutExtensionRegistry::invokeProcessHandlers($product, $validated, $pluginCheckoutData, $beforeProcess);
        PluginHookBus::doAction('checkout.before_process', $beforeProcess, $product, $validated, $pluginCheckoutData);
        event($beforeProcess);
        if ($beforeProcess->abort !== null && $beforeProcess->abort !== '') {
            if ($request->expectsJson()) {
                return response()->json(['message' => $beforeProcess->abort], 422);
            }

            return redirect()->back()->withErrors(['checkout' => $beforeProcess->abort]);
        }
        if ($beforeProcess->amountAdjustment > 0) {
            $totalAmount += $beforeProcess->amountAdjustment;
        }

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

        $checkoutSessionForAttribution = null;
        $sessionTokenForAttribution = trim((string) ($validated['checkout_session_token'] ?? ''));
        if ($sessionTokenForAttribution !== '') {
            $checkoutSessionForAttribution = CheckoutSession::where('session_token', $sessionTokenForAttribution)->first();
        }
        $affiliateRefForOrder = \App\Support\AffiliateAttribution::resolveRefForOrder(
            $validated['affiliate_ref'] ?? null,
            $checkoutSessionForAttribution
        );
        $orderMetadata = array_merge(
            $orderMetadata,
            \App\Support\AffiliateAttribution::metadataAttributes($product, $affiliateRefForOrder)
        );
        if ($beforeProcess->orderMetadata !== []) {
            $orderMetadata = array_merge($orderMetadata, $beforeProcess->orderMetadata);
        }

        $orderCountryCode = $this->resolveBillingCountryForCheckout($request, $validated);

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
            'currency' => 'BRL',
            'email' => $validated['email'],
            'cpf' => $validated['cpf'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'customer_ip' => $request->ip(),
            'country_code' => $orderCountryCode,
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
                    'subscription_plan_id' => $bump->target_subscription_plan_id,
                    'amount' => $bump->getEffectiveAmountBrl(),
                    'position' => $pos++,
                ]);
            }
            return $order;
        };

        $grantAccessForOrder = function (Order $order) {
            $order->grantPurchasedProductAccessToBuyer();
        };

        $updateCheckoutSession = function (Order $order) use ($validated, $product, $request) {
            $utmFromRequest = $this->utmPayloadFromValidated($validated);
            $trackingFromRequest = $this->trackingPayloadFromValidated($validated);
            $token = $validated['checkout_session_token'] ?? null;
            if ($token) {
                $session = CheckoutSession::where('session_token', $token)->first();
                if ($session) {
                    $mergedUtms = $this->mergeSessionUtms($session, $utmFromRequest);
                    $mergedTracking = $this->mergeSessionTrackingMetadata($session, $trackingFromRequest);
                    $sessionCountry = $order->country_code
                        ?? $session->country_code
                        ?? $this->resolveBillingCountryForCheckout($request, $validated);
                    $session->update(array_merge([
                        'step' => CheckoutSession::STEP_CONVERTED,
                        'order_id' => $order->id,
                        'country_code' => $sessionCountry,
                    ], $mergedUtms, ['tracking_metadata' => $mergedTracking]));
                    $this->persistOrderUtms($order, $mergedUtms);
                    $this->persistOrderTrackingMetadata($order, $mergedTracking);
                    $order->syncUtmMetadataFromCheckoutSession();
                    \App\Support\AffiliateAttribution::persistOnOrder(
                        $order,
                        $product,
                        \App\Support\AffiliateAttribution::resolveRefForOrder(
                            $validated['affiliate_ref'] ?? null,
                            $session
                        )
                    );

                    return;
                }
            }
            $this->persistOrderUtms($order, $utmFromRequest);
            $this->persistOrderTrackingMetadata($order, $trackingFromRequest);
            \App\Support\AffiliateAttribution::persistOnOrder(
                $order,
                $product,
                \App\Support\AffiliateAttribution::resolveRefForOrder(
                    $validated['affiliate_ref'] ?? null,
                    null
                )
            );
        };

        if ($paymentMethod === 'pix') {
            $pixLockSuffix = $idempotencyKey
                ?? (isset($validated['checkout_session_token']) && trim((string) $validated['checkout_session_token']) !== ''
                    ? 'sess:'.trim((string) $validated['checkout_session_token'])
                    : 'fp:'.hash('sha256', ($request->ip() ?? '').'|'.($validated['email'] ?? '').'|'.$product->id));
            $pixLockKey = 'checkout_pix_generation_lock:'.$pixLockSuffix;
            // Lock antes de criar o pedido: evita dois pedidos/PIX no duplo clique ou retry paralelo.
            if (! Cache::add($pixLockKey, 1, now()->addSeconds(60))) {
                $reusable = PendingPixCheckoutResolver::findReusable($request);
                if ($reusable) {
                    return PendingPixCheckoutResolver::redirectToPixPage($reusable, $request);
                }
                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Aguarde: estamos gerando seu PIX. Não clique novamente.',
                    ], 409);
                }

                return back()->with('error', 'Aguarde: estamos gerando seu PIX. Não clique novamente.');
            }

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
                $pixStart = microtime(true);
                $pixResult = $paymentService->createPixPayment($order, $product, $consumer);
                $pixMs = (int) round((microtime(true) - $pixStart) * 1000);
                if ($pixMs >= 1500) {
                    Log::info('Checkout: PIX generation slow', [
                        'order_id' => $order->id,
                        'tenant_id' => $order->tenant_id,
                        'gateway' => $pixResult['gateway'] ?? null,
                        'duration_ms' => $pixMs,
                    ]);
                }
                event(new PixGenerated($order, [
                    'qrcode' => $pixResult['qrcode'] ?? null,
                    'copy_paste' => $pixResult['copy_paste'] ?? null,
                    'transaction_id' => $pixResult['transaction_id'] ?? null,
                ]));
                $updateCheckoutSession($order);
                $redirectUrl = $product->checkout_config['redirect_after_purchase'] ?? null;
                $redirectUrl = ! empty($redirectUrl) && is_string($redirectUrl) ? $redirectUrl : null;
                $pixToken = PixCheckoutDisplay::persistAndStoreSession($order, $pixResult, [
                    'amount' => $totalAmount,
                    'product_name' => $product->name,
                    'checkout_slug' => $checkoutSlug,
                    'redirect_after_purchase' => $redirectUrl,
                    'customer_name' => $validated['name'] ?? null,
                    'customer_email' => $validated['email'] ?? null,
                    'customer_phone' => $validated['phone'] ?? null,
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
            } finally {
                try {
                    Cache::forget($pixLockKey ?? '');
                } catch (\Throwable) {
                    // ignore
                }
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
                    $pixToken = PixCheckoutDisplay::persistAndStoreSession($order, [
                        'qrcode' => $qrcodeImage,
                        'copy_paste' => $copyPaste ?? '',
                    ], [
                        'amount' => $totalAmount,
                        'product_name' => $product->name,
                        'checkout_slug' => $checkoutSlug,
                        'redirect_after_purchase' => $redirectUrl,
                        'customer_name' => $validated['name'] ?? null,
                        'customer_email' => $validated['email'] ?? null,
                        'customer_phone' => $validated['phone'] ?? null,
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
                    $pixToken = PixCheckoutDisplay::persistAndStoreSession($order, [
                        'qrcode' => $qrcodeImage,
                        'copy_paste' => $copyPaste ?? '',
                    ], [
                        'amount' => $totalAmount,
                        'product_name' => $product->name,
                        'checkout_slug' => $checkoutSlug,
                        'redirect_after_purchase' => $redirectUrl,
                        'customer_name' => $validated['name'] ?? null,
                        'customer_email' => $validated['email'] ?? null,
                        'customer_phone' => $validated['phone'] ?? null,
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
                $requiresAction = ($status === 'requires_action') || ! empty($cardResult['client_secret']);
                $isApproved = in_array($status, ['paid', 'settled', 'approved', 'completed'], true);
                $asyncPendingStatuses = ['pending', 'in_process', 'processing', 'authorized', 'in_mediation'];
                $statusNormalized = strtolower((string) $status);
                if (! $isApproved && ! $requiresAction && ! in_array($statusNormalized, $asyncPendingStatuses, true)) {
                    $this->rollbackFailedOrder($order, new \RuntimeException('card_payment_declined'));
                    if ($request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                        return response()->json([
                            'success' => false,
                            'message' => 'Pagamento recusado. Verifique os dados do cartão ou tente outro método.',
                        ], 422);
                    }

                    return back()->with('error', 'Pagamento recusado. Verifique os dados do cartão ou tente outro método.');
                }
                if ($isApproved) {
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
                    if ($isApproved) {
                        $json['amount'] = $order->lineItemsTotalAmount();
                        $json['currency'] = $order->getCurrencyOrDefault();
                        $json['meta_purchase_event_id'] = MetaPurchaseTracking::purchaseEventId($order->id);
                        $json['purchase_contents'] = MetaPurchaseTracking::purchaseContentsFromOrder($order, false);
                    }
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
     * Cria sessão de checkout SDK na CajuPay e devolve o token público para o frontend.
     * Fluxo paralelo a process() usado para Cartão / Apple Pay / Google Pay quando
     * o gateway selecionado é CajuPay.
     */
    /**
     * Cria APENAS a sessão pública na CajuPay (sem Order/User no nosso lado). A Order é
     * criada depois em cajupayConfirmOrder() — quando o cliente clica em "Pagar" e a
     * gente já tem todos os dados de cliente exigidos pelo checkout. Isso permite que o
     * widget do SDK monte imediatamente ao selecionar o método, sem exigir email primeiro.
     */
    public function cajupaySession(Request $request): JsonResponse
    {
        $product = Product::where('id', $request->input('product_id'))->where('is_active', true)->first();
        if (! $product) {
            return response()->json(['message' => 'Produto não encontrado.'], 404);
        }

        $allowedDisplayCurrencies = CheckoutCustomPriceByCurrency::currencyCodesFromTenantSettings(
            $this->tenantCurrenciesListFor($product->tenant_id)
        );

        $rules = [
            'product_id' => ['required', 'exists:products,id'],
            'product_offer_id' => ['nullable', 'exists:product_offers,id'],
            'subscription_plan_id' => ['nullable', 'exists:subscription_plans,id'],
            'order_bump_ids' => ['nullable', 'array'],
            'order_bump_ids.*' => ['integer', 'exists:product_order_bumps,id'],
            'payment_method' => ['required', 'string', 'in:card,apple_pay,google_pay'],
            'checkout_session_token' => ['nullable', 'string', 'max:64'],
            'display_currency' => ['nullable', 'string', 'max:8', Rule::in($allowedDisplayCurrencies)],
            'billing_country' => ['nullable', 'string', 'size:2'],
            'currency_user_selected' => ['nullable', 'boolean'],
            'coupon_code' => ['nullable', 'string', 'max:64'],
            'checkout_locale' => ['nullable', 'string', Rule::in(['pt_BR', 'en', 'es'])],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
        ];
        $rules = array_merge($rules, $this->checkoutAttributionValidationRules());
        $validated = $request->validate($rules);

        $checkoutLocaleKey = trim((string) ($validated['checkout_locale'] ?? ''));
        if ($checkoutLocaleKey === '') {
            $productConfig = is_array($product->checkout_config) ? $product->checkout_config : [];
            $force = $productConfig['checkout_force'] ?? [];
            $forcedLocale = is_array($force) ? trim((string) ($force['locale'] ?? '')) : '';
            if ($forcedLocale !== '' && in_array($forcedLocale, ['pt_BR', 'en', 'es'], true)) {
                $checkoutLocaleKey = $forcedLocale;
            } else {
                $checkoutLocaleKey = 'pt_BR';
            }
        }
        $cajupayLocale = CajuPayLocale::fromCheckoutLocale($checkoutLocaleKey);

        $displayCurrency = strtoupper((string) ($validated['display_currency'] ?? 'BRL'));
        if ($displayCurrency === '') {
            $displayCurrency = 'BRL';
        }

        $billingCountry = $this->resolveBillingCountryForCheckout($request, $validated);
        $currencyUserSelected = filter_var($validated['currency_user_selected'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Caju Global: cobrança na moeda de vitrine só fora do BR ou se o cliente escolheu moeda manualmente.
        $chargeInDisplayCurrency = $this->shouldChargeCajuPayInDisplayCurrency(
            $displayCurrency,
            $billingCountry,
            $currencyUserSelected
        );

        try {
            $context = $this->calculateOrderContext(
                $request,
                $product,
                $validated,
                $chargeInDisplayCurrency
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage() ?: 'Não foi possível calcular o pedido.'], 422);
        }

        $chargeCurrency = strtoupper((string) ($context['charge_currency'] ?? 'BRL'));
        $totalAmount = (float) ($chargeInDisplayCurrency ? $context['charge_amount'] : $context['total_amount']);
        $baseAmount = (float) $context['base_amount'];
        $displayAmount = $chargeInDisplayCurrency
            ? $totalAmount
            : (float) $context['total_amount'];

        $credential = GatewayCredential::forTenant($product->tenant_id)
            ->where('gateway_slug', 'cajupay')
            ->where('is_connected', true)
            ->first();
        if (! $credential) {
            return response()->json(['message' => 'CajuPay não está conectado.'], 422);
        }
        $credentials = $credential->getDecryptedCredentials();
        if (empty($credentials['public_key']) || empty($credentials['secret_key'])) {
            return response()->json(['message' => 'CajuPay: chaves de API não configuradas.'], 422);
        }

        $method = $validated['payment_method'];
        // Deve bater com session.methods_available retornado pela CajuPay (usa underscore:
        // 'apple_pay'/'google_pay'). Se o SDK receber slug sem underscore, ignora e cai no
        // primeiro item de methods_available — que com a promoção automática "wallets
        // implicam cartão" é sempre 'card', resultando em formulário de cartão sendo montado
        // mesmo quando o cliente escolheu wallet. Doc CajuPay seção "defaultMethod é
        // obrigatório quando embeddedOnly: true".
        $defaultMethodMap = [
            'card' => 'card',
            'apple_pay' => 'apple_pay',
            'google_pay' => 'google_pay',
        ];

        // Wallets (Apple/Google Pay) rodam sobre o rail de cartão (PaymentRequest). Por
        // doc CajuPay (seção "Wallets implicam cartão"), pedir allow_apple_pay/allow_google_pay
        // SEM allow_card faz a sessão promover allow_card automaticamente — mas a sessão
        // fica frágil: se a wallet do pagador estiver indisponível (contexto inseguro,
        // navegador sem wallet, sheet cancelada), o /confirm devolve method_not_available
        // e a UX trava. Mandamos card explicitamente junto e usamos default_method pra
        // indicar qual wallet o cliente escolheu — assim o SDK pode cair em cartão como
        // fallback dentro da própria sessão.
        $allowedMethods = [$method];
        if ($method === 'apple_pay' || $method === 'google_pay') {
            $allowedMethods[] = 'card';
        }
        $allowedMethods = array_values(array_unique($allowedMethods));

        $externalRef = (string) Str::uuid();

        try {
            $driver = GatewayRegistry::driver('cajupay');
            if (! $driver) {
                throw new \RuntimeException('Driver CajuPay não disponível.');
            }
            /** @var \App\Gateways\CajuPay\CajuPayDriver $driver */
            $sessionResult = $driver->createSdkCheckoutSession(
                $credentials,
                \App\Support\MoneyMinorUnits::toMinorUnits($totalAmount, $chargeCurrency),
                $chargeCurrency,
                $product->name . ' (draft ' . substr($externalRef, 0, 8) . ')',
                $externalRef,
                [], // sem consumer; será informado no confirm via initialPayer ou colhido pelo SDK
                $allowedMethods,
                $defaultMethodMap[$method] ?? 'card',
                $cajupayLocale,
                \App\Support\CajuPayPartnerCheckoutUrl::forProductCheckout(
                    $product,
                    $context['offer'] ?? null,
                    $context['plan'] ?? null
                )
            );
        } catch (\Throwable $e) {
            Log::warning('CajuPaySession: falha ao criar sessão SDK', [
                'product_id' => $product->id,
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => $e->getMessage() ?: 'Falha ao iniciar pagamento na CajuPay.'], 422);
        }

        // Lê methods_available da sessão pública: interseção das flags da sessão
        // (allow_*) com o que a conta CajuPay tem liberado no PSP. Se o método pedido
        // não estiver aí, o confirm devolve `method_not_available` — então a gente
        // devolve essa lista pro frontend filtrar a UX antes de o cliente clicar.
        $availableMethods = $driver->getSessionAvailableMethods($sessionResult['token'], $credentials);

        $pollingToken = Str::random(32);
        $checkoutSessionId = $sessionResult['checkout_session_id'];
        if (is_string($checkoutSessionId) && $checkoutSessionId !== '') {
            Cache::put('cajupay_session_by_checkout.' . $checkoutSessionId, $pollingToken, now()->addMinutes(30));
        }

        Cache::put('cajupay_draft.' . $pollingToken, [
            'product_id' => $product->id,
            'product_offer_id' => $context['offer']?->id,
            'subscription_plan_id' => $context['plan']?->id,
            'order_bump_ids' => $context['order_bump_ids'],
            'payment_method' => $method,
            'coupon_code' => $context['coupon_code'],
            'total_amount' => $totalAmount,
            'base_amount' => $baseAmount,
            'charge_currency' => $chargeCurrency,
            'charge_amount' => $totalAmount,
            'checkout_session_token' => $validated['checkout_session_token'] ?? null,
            'display_currency' => $displayCurrency,
            'display_amount' => $displayAmount,
            'cajupay_token' => $sessionResult['token'],
            'checkout_session_id' => $sessionResult['checkout_session_id'],
            'tenant_id' => $product->tenant_id,
            'external_id' => $externalRef,
            'methods_available' => $availableMethods,
            'created_at' => time(),
        ], now()->addMinutes(30));

        return response()->json([
            'success' => true,
            'token' => $sessionResult['token'],
            'checkout_session_id' => $sessionResult['checkout_session_id'],
            'polling_token' => $pollingToken,
            'methods_available' => $availableMethods,
            'method_supported' => $availableMethods === [] ? null : in_array($method, $availableMethods, true),
        ]);
    }

    /**
     * Materializa a Order (User + Order pending) a partir do draft em cache, vinculando
     * gateway_id ao checkout_session_id da CajuPay. Chamado pelo frontend no clique do
     * "Pagar" (logo antes de controller.confirm()).
     */
    public function cajupayConfirmOrder(Request $request): JsonResponse
    {
        $validated = $request->validate(array_merge([
            'polling_token' => ['required', 'string', 'size:32'],
            'email' => ['required', 'email'],
            'name' => ['nullable', 'string', 'max:255'],
            'cpf' => ['nullable', 'string', 'max:11'],
            'phone' => ['nullable', 'string', 'max:24'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
        ], $this->checkoutAttributionValidationRules()));

        $draftKey = 'cajupay_draft.' . $validated['polling_token'];
        $draft = Cache::get($draftKey);
        if (! is_array($draft)) {
            // Pedido já materializado (ex.: Apple/Google Pay — confirm-order antes do 1º confirm do SDK);
            // mesmo polling_token: devolve sucesso idempotente para o front continuar o fluxo.
            $existingDisplay = session('cajupay_display.' . $validated['polling_token']);
            if (is_array($existingDisplay) && ! empty($existingDisplay['order_id'])) {
                return response()->json([
                    'success' => true,
                    'order_id' => (int) $existingDisplay['order_id'],
                    'polling_token' => $validated['polling_token'],
                    'polling_url' => route('checkout.order-status', ['token' => $validated['polling_token']]),
                    'idempotent' => true,
                ]);
            }

            return response()->json(['message' => 'Sessão CajuPay expirada. Recarregue a página.'], 404);
        }

        // product_id no draft é UUID string (Product::$keyType = 'string'); nunca usar (int).
        $product = Product::where('id', $draft['product_id'])->where('is_active', true)->first();
        if (! $product) {
            Cache::forget($draftKey);
            return response()->json(['message' => 'Produto não encontrado.'], 404);
        }

        // Validação config-aware dos campos do cliente (mesma regra de process(): CPF só em BRL).
        $defaultsConfig = Product::defaultCheckoutConfig();
        $effectiveConfigBase = array_replace_recursive($defaultsConfig, $product->checkout_config ?? []);
        $customerFields = $effectiveConfigBase['customer_fields'] ?? ($defaultsConfig['customer_fields'] ?? []);
        $displayCurrencyForCpf = strtoupper((string) ($draft['display_currency'] ?? $draft['charge_currency'] ?? 'BRL'));

        $errors = [];
        if (($customerFields['name'] ?? true) && trim((string) ($validated['name'] ?? '')) === '') {
            $errors['name'] = 'Informe seu nome.';
        }
        $cpfDigits = preg_replace('/\D/', '', (string) ($validated['cpf'] ?? ''));
        $requireCpf = ($customerFields['cpf'] ?? false) && $displayCurrencyForCpf === 'BRL';
        if ($requireCpf && strlen((string) $cpfDigits) !== 11) {
            $errors['cpf'] = 'CPF obrigatório.';
        }
        if (($customerFields['phone'] ?? false) && trim((string) ($validated['phone'] ?? '')) === '') {
            $errors['phone'] = 'Telefone obrigatório.';
        }
        if (! empty($errors)) {
            return response()->json(['message' => 'Dados do cliente incompletos.', 'errors' => $errors], 422);
        }

        try {
            $context = $this->createUserAndOrderFromDraft($request, $product, $draft, $validated);
        } catch (\Throwable $e) {
            Log::warning('CajuPayConfirmOrder: falha ao criar Order do draft', [
                'product_id' => $product->id,
                'polling_token' => $validated['polling_token'],
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => $e->getMessage() ?: 'Falha ao registrar o pedido.'], 422);
        }

        /** @var Order $order */
        $order = $context['order'];
        $totalAmount = (float) $context['total_amount'];

        $this->updateCheckoutSessionForOrder($order, array_merge($validated, [
            'checkout_session_token' => $draft['checkout_session_token'] ?? null,
        ]));

        event(new OrderPending($order->fresh()));

        $redirectUrl = $product->checkout_config['redirect_after_purchase'] ?? null;
        $redirectUrl = ! empty($redirectUrl) && is_string($redirectUrl) ? $redirectUrl : null;

        session()->put('cajupay_display.' . $validated['polling_token'], [
            'order_id' => $order->id,
            'checkout_session_id' => $draft['checkout_session_id'],
            'session_token' => $draft['cajupay_token'],
            'payment_method' => $draft['payment_method'],
            'amount' => $totalAmount,
            'product_name' => $product->name,
            'checkout_slug' => $context['checkout_slug'],
            'redirect_after_purchase' => $redirectUrl,
            'customer_name' => $validated['name'] ?? null,
            'customer_email' => $validated['email'],
            'customer_phone' => $validated['phone'] ?? null,
            'created_at' => time(),
        ]);

        Cache::forget($draftKey);

        return response()->json([
            'success' => true,
            'order_id' => $order->id,
            'polling_token' => $validated['polling_token'],
            'polling_url' => route('checkout.order-status', ['token' => $validated['polling_token']]),
        ]);
    }

    /**
     * Atualiza CheckoutSession (UTMs / step / order_id) — extraído de process().
     *
     * @param  array<string, mixed>  $validated
     */
    private function updateCheckoutSessionForOrder(Order $order, array $validated): void
    {
        $utmFromRequest = $this->utmPayloadFromValidated($validated);
        $trackingFromRequest = $this->trackingPayloadFromValidated($validated);
        $token = $validated['checkout_session_token'] ?? null;
        if ($token) {
            $session = CheckoutSession::where('session_token', $token)->first();
            if ($session) {
                $mergedUtms = $this->mergeSessionUtms($session, $utmFromRequest);
                $mergedTracking = $this->mergeSessionTrackingMetadata($session, $trackingFromRequest);
                $session->update(array_merge([
                    'step' => CheckoutSession::STEP_CONVERTED,
                    'order_id' => $order->id,
                ], $mergedUtms, ['tracking_metadata' => $mergedTracking]));
                $this->persistOrderUtms($order, $mergedUtms);
                $this->persistOrderTrackingMetadata($order, $mergedTracking);
                $order->syncUtmMetadataFromCheckoutSession();
                return;
            }
        }
        $this->persistOrderUtms($order, $utmFromRequest);
        $this->persistOrderTrackingMetadata($order, $trackingFromRequest);
    }

    /**
     * Calcula offer/plan/amount/cupom/bumps SEM criar User/Order. Usado por cajupaySession()
     * para montar o draft em cache antes de criar a sessão na CajuPay.
     *
     * @param  array<string, mixed>  $validated
     * @return array{offer: ?ProductOffer, plan: ?SubscriptionPlan, total_amount: float, base_amount: float, charge_currency: string, charge_amount: float, order_bump_ids: array<int, int>, coupon_code: ?string, checkout_slug: string, period_start: ?\Carbon\Carbon, period_end: ?\Carbon\Carbon}
     */
    private function calculateOrderContext(Request $request, Product $product, array $validated, bool $chargeInDisplayCurrency = false): array
    {
        $productOfferId = $request->filled('product_offer_id') ? (int) $request->input('product_offer_id') : null;
        $subscriptionPlanId = $request->filled('subscription_plan_id') ? (int) $request->input('subscription_plan_id') : null;
        $offer = $productOfferId ? ProductOffer::where('id', $productOfferId)->where('product_id', $product->id)->first() : null;
        $plan = $subscriptionPlanId ? SubscriptionPlan::where('id', $subscriptionPlanId)->where('product_id', $product->id)->first() : null;

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
            $amount = CheckoutCurrencyCatalog::brlFromForeignAmount(
                $amount,
                $currency,
                $this->tenantCurrenciesListFor($product->tenant_id)
            );
        }

        $effectiveCurrency = strtoupper((string) ($product->currency ?? 'BRL'));
        if ($offer) {
            $effectiveCurrency = strtoupper((string) ($offer->getCurrencyOrDefault() ?? 'BRL'));
        } elseif ($plan) {
            $effectiveCurrency = strtoupper((string) ($plan->getCurrencyOrDefault() ?? 'BRL'));
        }
        $displayCurrencyInput = $validated['display_currency'] ?? null;
        $displayCurrency = is_string($displayCurrencyInput) && $displayCurrencyInput !== ''
            ? strtoupper((string) $displayCurrencyInput)
            : $effectiveCurrency;
        $tenantCurrencies = $this->tenantCurrenciesListFor($product->tenant_id);
        $amountBrlBeforeCustom = $amount;

        if ($chargeInDisplayCurrency) {
            $chargeCurrency = $displayCurrency !== '' ? $displayCurrency : 'BRL';
            $amount = CheckoutCustomPriceByCurrency::amountInChargeCurrency(
                $product,
                $offer,
                $plan,
                $amountBrlBeforeCustom,
                $chargeCurrency,
                $tenantCurrencies
            );
        } else {
            $chargeCurrency = 'BRL';
            $amount = CheckoutCustomPriceByCurrency::amountBrlFromCustomIfApplicable(
                $product,
                $offer,
                $plan,
                $amountBrlBeforeCustom,
                $displayCurrency,
                $tenantCurrencies
            );
        }

        $orderBumpIds = array_values(array_filter(array_map('intval', $validated['order_bump_ids'] ?? [])));
        $selectedBumps = collect();
        if ($orderBumpIds) {
            $selectedBumps = ProductOrderBump::where('product_id', $product->id)->whereIn('id', $orderBumpIds)->get();
        }
        if ($chargeInDisplayCurrency) {
            $bumpAmountTotal = $selectedBumps->sum(fn (ProductOrderBump $b) => CheckoutCustomPriceByCurrency::bumpBrlToChargeCurrency(
                $b->getEffectiveAmountBrl(),
                $chargeCurrency,
                $tenantCurrencies
            ));
        } else {
            $bumpAmountTotal = $selectedBumps->sum(fn (ProductOrderBump $b) => $b->getEffectiveAmountBrl());
        }

        $couponCode = isset($validated['coupon_code']) && trim((string) ($validated['coupon_code'] ?? '')) !== ''
            ? trim((string) $validated['coupon_code'])
            : null;
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
        $chargeAmount = $totalAmount;

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

        return [
            'offer' => $offer,
            'plan' => $plan,
            'total_amount' => $totalAmount,
            'base_amount' => $amount,
            'charge_currency' => $chargeCurrency,
            'charge_amount' => $chargeAmount,
            'order_bump_ids' => $orderBumpIds,
            'coupon_code' => $couponCode,
            'checkout_slug' => (string) $checkoutSlug,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ];
    }

    /**
     * Cria User + Order + OrderItems a partir de um draft CajuPay em cache (gerado em
     * cajupaySession()). Vincula gateway_id ao checkout_session_id da CajuPay para o
     * webhook poder localizar a Order quando o pagamento for processado.
     *
     * @param  array<string, mixed>  $draft  payload em cajupay_draft.<token>
     * @param  array<string, mixed>  $validated  email/name/cpf/phone validados em cajupayConfirmOrder
     * @return array{order: Order, total_amount: float, checkout_slug: string}
     */
    private function createUserAndOrderFromDraft(Request $request, Product $product, array $draft, array $validated): array
    {
        $offer = ! empty($draft['product_offer_id'])
            ? ProductOffer::where('id', (int) $draft['product_offer_id'])->where('product_id', $product->id)->first()
            : null;
        $plan = ! empty($draft['subscription_plan_id'])
            ? SubscriptionPlan::where('id', (int) $draft['subscription_plan_id'])->where('product_id', $product->id)->first()
            : null;

        $displayCurrency = strtoupper((string) ($draft['display_currency'] ?? 'BRL'));
        $chargeCurrency = strtoupper((string) ($draft['charge_currency'] ?? 'BRL'));
        $totalAmount = (float) ($draft['charge_amount'] ?? $draft['total_amount']);
        $baseAmount = (float) $draft['base_amount'];
        $tenantCurrencies = $this->tenantCurrenciesListFor($product->tenant_id);

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

        $tenantId = $product->tenant_id;

        $plainPassword = null;
        if ($product->type === Product::TYPE_AREA_MEMBROS) {
            $loginConfig = $product->member_area_config['login'] ?? [];
            $passwordMode = $loginConfig['password_mode'] ?? 'auto';
            $defaultPassword = trim((string) ($loginConfig['default_password'] ?? ''));
            $plainPassword = ($passwordMode === 'default' && $defaultPassword !== '') ? $defaultPassword : Str::random(12);
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
        } elseif (! empty($validated['name']) && trim((string) $user->name) !== trim((string) $validated['name'])) {
            $user->update(['name' => trim((string) $validated['name'])]);
        }

        $orderMetadata = [
            'checkout_payment_method' => $draft['payment_method'],
            'cajupay_session_token' => $draft['cajupay_token'] ?? null,
            'cajupay_checkout_session_id' => $draft['checkout_session_id'] ?? null,
        ];
        if ($chargeCurrency !== 'BRL') {
            $amountBrl = OrderReportingAmounts::estimateAmountBrl($totalAmount, $chargeCurrency, $tenantId);
            if ($amountBrl !== null && $amountBrl > 0) {
                $orderMetadata['amount_brl'] = $amountBrl;
            }
        }
        if ($displayCurrency !== '' && $displayCurrency !== $chargeCurrency) {
            $orderMetadata['display_currency'] = $displayCurrency;
            if (isset($draft['display_amount']) && is_numeric($draft['display_amount'])) {
                $orderMetadata['display_amount'] = (float) $draft['display_amount'];
            }
        }
        if ($product->type === Product::TYPE_AREA_MEMBROS && $plainPassword !== null) {
            if (! $user->wasRecentlyCreated) {
                $user->update(['password' => $passwordHash]);
            }
            Cache::put('access_password.' . $user->id . '.' . $product->id, $plainPassword, now()->addHours(2));
            $orderMetadata['access_password_temp'] = encrypt($plainPassword);
        }

        $cpfDigits = preg_replace('/\D/', '', (string) ($validated['cpf'] ?? '')) ?: null;
        $phone = ($validated['phone'] ?? null) ?: null;

        $order = Order::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_offer_id' => $offer?->id,
            'subscription_plan_id' => $plan?->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'is_renewal' => false,
            'amount' => $totalAmount,
            'currency' => $chargeCurrency,
            'email' => $validated['email'],
            'cpf' => $cpfDigits,
            'phone' => $phone,
            'customer_ip' => $request->ip(),
            'country_code' => $this->resolveBillingCountryForCheckout($request, $validated),
            'coupon_code' => $draft['coupon_code'] ?? null,
            'metadata' => $orderMetadata,
            'status' => 'pending',
            'gateway' => 'cajupay',
            'gateway_id' => $draft['checkout_session_id'] ?? null,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_offer_id' => $offer?->id,
            'subscription_plan_id' => $plan?->id,
            'amount' => $baseAmount,
            'position' => 0,
        ]);
        $bumpIds = is_array($draft['order_bump_ids'] ?? null) ? $draft['order_bump_ids'] : [];
        if ($bumpIds) {
            $bumps = ProductOrderBump::where('product_id', $product->id)->whereIn('id', $bumpIds)->get();
            $pos = 1;
            foreach ($bumps as $bump) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $bump->target_product_id,
                    'product_offer_id' => $bump->target_product_offer_id,
                    'subscription_plan_id' => $bump->target_subscription_plan_id,
                    'amount' => CheckoutCustomPriceByCurrency::bumpBrlToChargeCurrency(
                        $bump->getEffectiveAmountBrl(),
                        $chargeCurrency,
                        $tenantCurrencies
                    ),
                    'position' => $pos++,
                ]);
            }
        }

        $order->load('orderItems');

        return [
            'order' => $order,
            'total_amount' => $totalAmount,
            'checkout_slug' => $checkoutSlug,
        ];
    }

    /**
     * Resolve produto/oferta/plano, calcula amount + cupom + bumps, cria User e Order pendente.
     * Helper compartilhado pelo fluxo SDK CajuPay (cajupaySession()) — refator parcial de process().
     *
     * @param  array<string, mixed>  $validated
     * @return array{order: Order, total_amount: float, base_amount: float, plain_password: ?string, checkout_slug: string, offer: ?ProductOffer, plan: ?SubscriptionPlan}
     */
    private function prepareOrderForPayment(Request $request, Product $product, array $validated, string $gatewaySlug, string $paymentMethod): array
    {
        $productOfferId = $request->filled('product_offer_id') ? (int) $request->input('product_offer_id') : null;
        $subscriptionPlanId = $request->filled('subscription_plan_id') ? (int) $request->input('subscription_plan_id') : null;
        $offer = $productOfferId ? ProductOffer::where('id', $productOfferId)->where('product_id', $product->id)->first() : null;
        $plan = $subscriptionPlanId ? SubscriptionPlan::where('id', $subscriptionPlanId)->where('product_id', $product->id)->first() : null;

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
            $amount = CheckoutCurrencyCatalog::brlFromForeignAmount(
                $amount,
                $currency,
                $this->tenantCurrenciesListFor($product->tenant_id)
            );
        }

        $effectiveCurrency = strtoupper((string) ($product->currency ?? 'BRL'));
        if ($offer) {
            $effectiveCurrency = strtoupper((string) ($offer->getCurrencyOrDefault() ?? 'BRL'));
        } elseif ($plan) {
            $effectiveCurrency = strtoupper((string) ($plan->getCurrencyOrDefault() ?? 'BRL'));
        }
        $displayCurrencyInput = $validated['display_currency'] ?? null;
        $displayCurrency = is_string($displayCurrencyInput) && $displayCurrencyInput !== ''
            ? strtoupper((string) $displayCurrencyInput)
            : $effectiveCurrency;
        $amount = CheckoutCustomPriceByCurrency::amountBrlFromCustomIfApplicable(
            $product,
            $offer,
            $plan,
            $amount,
            $displayCurrency,
            $this->tenantCurrenciesListFor($product->tenant_id)
        );

        $orderBumpIds = array_values(array_filter(array_map('intval', $validated['order_bump_ids'] ?? [])));
        $selectedBumps = collect();
        if ($orderBumpIds) {
            $selectedBumps = ProductOrderBump::where('product_id', $product->id)->whereIn('id', $orderBumpIds)->get();
        }
        $bumpAmountTotal = $selectedBumps->sum(fn (ProductOrderBump $b) => $b->getEffectiveAmountBrl());

        $couponCode = isset($validated['coupon_code']) && trim((string) ($validated['coupon_code'] ?? '')) !== ''
            ? trim((string) $validated['coupon_code'])
            : null;
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

        $tenantId = $product->tenant_id;

        $plainPassword = null;
        if ($product->type === Product::TYPE_AREA_MEMBROS) {
            $loginConfig = $product->member_area_config['login'] ?? [];
            $passwordMode = $loginConfig['password_mode'] ?? 'auto';
            $defaultPassword = trim((string) ($loginConfig['default_password'] ?? ''));
            $plainPassword = ($passwordMode === 'default' && $defaultPassword !== '') ? $defaultPassword : Str::random(12);
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

        $orderMetadata = ['checkout_payment_method' => $paymentMethod];
        if ($product->type === Product::TYPE_AREA_MEMBROS && $plainPassword !== null) {
            if (! $user->wasRecentlyCreated) {
                $user->update(['password' => $passwordHash]);
            }
            Cache::put('access_password.' . $user->id . '.' . $product->id, $plainPassword, now()->addHours(2));
            $orderMetadata['access_password_temp'] = encrypt($plainPassword);
        }

        $order = Order::create([
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
            'country_code' => $this->resolveBillingCountryForCheckout($request, $validated),
            'coupon_code' => $couponCode,
            'metadata' => $orderMetadata,
            'status' => 'pending',
            'gateway' => $gatewaySlug,
            'gateway_id' => null,
        ]);

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
                'subscription_plan_id' => $bump->target_subscription_plan_id,
                'amount' => $bump->getEffectiveAmountBrl(),
                'position' => $pos++,
            ]);
        }

        $order->load('orderItems');

        return [
            'order' => $order,
            'total_amount' => $totalAmount,
            'base_amount' => $amount,
            'plain_password' => $plainPassword,
            'checkout_slug' => $checkoutSlug,
            'offer' => $offer,
            'plan' => $plan,
        ];
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

    private const PIX_EXPIRY_SECONDS = PixCheckoutDisplay::EXPIRY_SECONDS;

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
        $order = Order::with('product', 'productOffer', 'subscriptionPlan', 'orderItems')->find($orderId);
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
        $conversionPixels = $product
            ? \App\Support\AffiliateAttribution::conversionPixelsForCheckout(
                $product,
                \App\Support\AffiliateAttribution::affiliateCodeFromOrderMetadata(is_array($order->metadata) ? $order->metadata : null)
            )
            : Product::defaultConversionPixels();
        if ($order->api_application_id) {
            $order->loadMissing('apiApplication');
            if ($order->apiApplication?->conversion_pixels) {
                $conversionPixels = $order->apiApplication->conversion_pixels;
            }
        }

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
            'meta_purchase_event_id' => MetaPurchaseTracking::purchaseEventId($order->id),
            'purchase_contents' => MetaPurchaseTracking::purchaseContentsFromOrder($order, false),
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
        $order = Order::with('product', 'productOffer', 'subscriptionPlan', 'orderItems')->find($orderId);
        if (! $order || $order->status !== 'pending') {
            session()->forget('boleto_display.' . $token);
            $slug = $order ? $order->getCheckoutSlug() : null;
            $redirect = $slug ? redirect()->route('checkout.show', ['slug' => $slug]) : redirect()->route('login');
            return $redirect->with('error', 'Boleto expirado ou inválido. Gere um novo boleto.');
        }

        $product = $order->product;
        $conversionPixels = $product
            ? \App\Support\AffiliateAttribution::conversionPixelsForCheckout(
                $product,
                \App\Support\AffiliateAttribution::affiliateCodeFromOrderMetadata(is_array($order->metadata) ? $order->metadata : null)
            )
            : Product::defaultConversionPixels();
        if ($order->api_application_id) {
            $order->loadMissing('apiApplication');
            if ($order->apiApplication?->conversion_pixels) {
                $conversionPixels = $order->apiApplication->conversion_pixels;
            }
        }
        $amount = (float) ($stored['amount'] ?? $order->amount ?? 0);

        return Inertia::render('Checkout/Boleto', [
            'token' => $token,
            'order_id' => $orderId,
            'amount' => $amount,
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
            'meta_purchase_event_id' => MetaPurchaseTracking::purchaseEventId($order->id),
            'purchase_contents' => MetaPurchaseTracking::purchaseContentsFromOrder($order, false),
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
            $stored = session('cajupay_display.' . $token);
        }
        if (! is_array($stored)) {
            return response()->json(['status' => 'not_found'], 404);
        }

        $orderId = (int) ($stored['order_id'] ?? 0);
        $order = Order::with('product', 'productOffer', 'subscriptionPlan', 'orderItems')->find($orderId);
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
                        // CajuPay: gateway_id costuma ser checkout_session_id (UUID); o status na
                        // API pública usa o token opaco salvo em metadata (ver createUserAndOrderFromDraft).
                        $statusLookupId = (string) $order->gateway_id;
                        if ($gatewaySlug === 'cajupay') {
                            $meta = $order->metadata;
                            $sessionTok = is_array($meta) ? ($meta['cajupay_session_token'] ?? null) : null;
                            if (is_string($sessionTok) && $sessionTok !== '') {
                                $statusLookupId = $sessionTok;
                            }
                        }
                        $apiStatus = $driver->getTransactionStatus($statusLookupId, $credentials);
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

        $payload = [
            'status' => $status,
            'redirect_url' => $redirectUrl,
        ];
        if ($status === 'completed') {
            $payload['order_id'] = $order->id;
            $payload['currency'] = $order->getCurrencyOrDefault();
            $payload['amount'] = $order->lineItemsTotalAmount();
            $payload['meta_purchase_event_id'] = MetaPurchaseTracking::purchaseEventId($order->id);
            $payload['purchase_contents'] = MetaPurchaseTracking::purchaseContentsFromOrder($order, false);
        }

        return response()->json($payload);
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
     * @deprecated Use CheckoutPaymentMethodsBuilder::build()
     */
    private function buildAvailablePaymentMethods(Product $product, ?SubscriptionPlan $plan = null, ?array $effectiveConfig = null): array
    {
        $config = $effectiveConfig ?? $product->checkout_config ?? [];

        return CheckoutPaymentMethodsBuilder::build(
            $product->tenant_id,
            $config['payment_gateways'] ?? [],
            $plan
        );
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
        $tenantList = is_array($currencies) ? $currencies : [];
        $priceBrl = $currency === 'BRL'
            ? $price
            : CheckoutCurrencyCatalog::brlFromForeignAmount($price, $currency, $tenantList);
        $productArray['price_brl'] = round($priceBrl, 2);
        return $productArray;
    }

    /**
     * @return array<string, float>
     */
    private function customDisplayPricesMap(Product $product): array
    {
        $cfg = $product->checkout_config['custom_prices_by_currency'] ?? [];
        if (! is_array($cfg) || empty($cfg['enabled'])) {
            return [];
        }
        $amounts = $cfg['amounts'] ?? [];
        if (! is_array($amounts)) {
            return [];
        }
        $out = [];
        foreach ($amounts as $k => $v) {
            $code = strtoupper(trim((string) $k));
            if ($code === '' || $code === 'BRL') {
                continue;
            }
            $f = (float) $v;
            if ($f < 0.01) {
                continue;
            }
            $out[$code] = round($f, 2);
        }

        return $out;
    }

    /**
     * Caju Global: cobrança na moeda de vitrine apenas se não for BRL e (comprador fora do BR ou escolha manual).
     */
    private function shouldChargeCajuPayInDisplayCurrency(
        string $displayCurrency,
        ?string $billingCountry,
        bool $currencyUserSelected
    ): bool {
        $display = strtoupper(trim($displayCurrency));
        if ($display === '' || $display === 'BRL') {
            return false;
        }

        $country = $billingCountry !== null ? strtoupper(trim($billingCountry)) : null;
        if ($country === 'BR' && ! $currencyUserSelected) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveBillingCountryForCheckout(Request $request, array $validated): ?string
    {
        $fromRequest = isset($validated['billing_country']) ? strtoupper(trim((string) $validated['billing_country'])) : '';
        if (strlen($fromRequest) === 2) {
            return $fromRequest;
        }

        $token = trim((string) ($validated['checkout_session_token'] ?? ''));
        if ($token !== '') {
            $sessionCountry = CheckoutSession::where('session_token', $token)->value('country_code');
            if (is_string($sessionCountry) && strlen($sessionCountry) === 2) {
                return strtoupper($sessionCountry);
            }
        }

        $geo = app(GeoIp::class);
        $suggestions = $geo->getSuggestionsForRequest($request);
        $code = $suggestions['country_code'] ?? null;

        return is_string($code) && strlen($code) === 2 ? strtoupper($code) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tenantCurrenciesListFor(?int $tenantId): array
    {
        $currenciesRaw = Setting::get('currencies', null, $tenantId);
        $currencies = $currenciesRaw
            ? (is_string($currenciesRaw) ? json_decode($currenciesRaw, true) : $currenciesRaw)
            : config('products.currencies');

        $list = is_array($currencies) ? $currencies : (array) config('products.currencies');

        return CheckoutCurrencyCatalog::currenciesForCheckout($list);
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
     * Campos extras de atribuição (UTMify / Meta / ads).
     *
     * @return array<string, array<int, string>>
     */
    private function checkoutAttributionValidationRules(): array
    {
        return [
            'utm_content' => ['nullable', 'string', 'max:512'],
            'utm_term' => ['nullable', 'string', 'max:512'],
            'fbclid' => ['nullable', 'string', 'max:512'],
            'gclid' => ['nullable', 'string', 'max:512'],
            'msclkid' => ['nullable', 'string', 'max:512'],
            'src' => ['nullable', 'string', 'max:512'],
            'sck' => ['nullable', 'string', 'max:512'],
            'fbp' => ['nullable', 'string', 'max:512'],
            'fbc' => ['nullable', 'string', 'max:512'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function trackingPayloadFromValidated(array $validated): array
    {
        $out = [];
        foreach (array_keys($this->checkoutAttributionValidationRules()) as $k) {
            $v = isset($validated[$k]) ? trim((string) $validated[$k]) : '';
            if ($v !== '') {
                $out[$k] = mb_substr($v, 0, 512);
            }
        }

        return $out;
    }

    /**
     * Primeira visita ao checkout: captura parâmetros da URL e do referer.
     *
     * @return array<string, string>
     */
    private function extractTrackingMetadataFromRequest(Request $request): array
    {
        $keys = array_keys($this->checkoutAttributionValidationRules());
        $out = [];
        foreach ($keys as $k) {
            $v = $request->query($k);
            if (is_string($v) && trim($v) !== '') {
                $out[$k] = mb_substr(trim($v), 0, 512);
            }
        }

        $referer = $request->headers->get('referer');
        if (is_string($referer) && $referer !== '') {
            try {
                $u = parse_url($referer);
                $query = [];
                if (! empty($u['query'])) {
                    parse_str((string) $u['query'], $query);
                }
                foreach ($keys as $k) {
                    if (isset($out[$k])) {
                        continue;
                    }
                    $qv = $query[$k] ?? null;
                    if (is_string($qv) && trim($qv) !== '') {
                        $out[$k] = mb_substr(trim($qv), 0, 512);
                    }
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $fromRequest
     * @return array<string, string>
     */
    private function mergeSessionTrackingMetadata(CheckoutSession $session, array $fromRequest): array
    {
        $base = is_array($session->tracking_metadata) ? $session->tracking_metadata : [];
        $out = [];
        foreach ($base as $k => $v) {
            if (is_string($k) && (is_string($v) || is_numeric($v))) {
                $s = trim((string) $v);
                if ($s !== '') {
                    $out[$k] = mb_substr($s, 0, 512);
                }
            }
        }
        foreach ($fromRequest as $k => $v) {
            if (is_string($v) && trim($v) !== '') {
                $out[$k] = mb_substr(trim($v), 0, 512);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $tracking
     */
    private function persistOrderTrackingMetadata(Order $order, array $tracking): void
    {
        if ($tracking === []) {
            return;
        }
        $meta = $order->metadata ?? [];
        $changed = false;
        foreach ($tracking as $k => $v) {
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

    /**
     * Quando o produto está em modo empresa para Pagar.me, preenche endereço de cobrança a partir do produto (checkout não envia).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function applyPagarmeCompanyAddressToValidated(array $validated, Product $product, PaymentService $paymentService): array
    {
        $pb = array_replace_recursive(
            Product::defaultCheckoutConfig()['pagarme_billing'] ?? [],
            is_array($product->checkout_config['pagarme_billing'] ?? null) ? $product->checkout_config['pagarme_billing'] : []
        );
        if (($pb['mode'] ?? 'customer') !== 'company') {
            return $validated;
        }
        $pm = $validated['payment_method'] ?? '';
        $cardGw = $pm === 'card' ? $paymentService->getFirstAvailableGatewayForMethod($product->tenant_id, 'card', $product) : null;
        $boletoGw = $pm === 'boleto' ? $paymentService->getFirstAvailableGatewayForMethod($product->tenant_id, 'boleto', $product) : null;
        $needsMerge = ($pm === 'card' && in_array($cardGw, ['pagarme', 'efi'], true))
            || ($pm === 'boleto' && in_array($boletoGw, ['pagarme', 'efi'], true));
        if (! $needsMerge) {
            return $validated;
        }
        $addr = $pb['company_address'] ?? [];
        $zipRaw = preg_replace('/\D/', '', (string) ($addr['zipcode'] ?? ''));
        $street = trim((string) ($addr['street'] ?? ''));
        $number = trim((string) ($addr['number'] ?? ''));
        $neighborhood = trim((string) ($addr['neighborhood'] ?? ''));
        $city = trim((string) ($addr['city'] ?? ''));
        $state = strtoupper(substr(trim((string) ($addr['state'] ?? '')), 0, 2));
        if (strlen($zipRaw) < 8 || $street === '' || $number === '' || $neighborhood === '' || $city === '' || strlen($state) !== 2) {
            throw ValidationException::withMessages([
                'address_zipcode' => ['Endereço da empresa incompleto. Configure em Configurações do produto (cobrança Pagar.me / Efí).'],
            ]);
        }

        $validated['address_zipcode'] = substr($zipRaw, 0, 5).'-'.substr($zipRaw, 5, 3);
        $validated['address_street'] = $street;
        $validated['address_number'] = $number;
        $validated['address_neighborhood'] = $neighborhood;
        $validated['address_city'] = $city;
        $validated['address_state'] = $state;

        return $validated;
    }

    public function cajupayParceladoSession(Request $request): JsonResponse
    {
        $product = Product::where('id', $request->input('product_id'))->where('is_active', true)->first();
        if (! $product) {
            return response()->json(['message' => 'Produto não encontrado.'], 404);
        }

        if ($product->billing_type !== Product::BILLING_ONE_TIME) {
            return response()->json(['message' => 'PIX Parcelado disponível apenas para pagamento único.'], 422);
        }

        $allowedDisplayCurrencies = CheckoutCustomPriceByCurrency::currencyCodesFromTenantSettings(
            $this->tenantCurrenciesListFor($product->tenant_id)
        );

        $rules = [
            'product_id' => ['required', 'exists:products,id'],
            'product_offer_id' => ['nullable', 'exists:product_offers,id'],
            'subscription_plan_id' => ['nullable', 'exists:subscription_plans,id'],
            'order_bump_ids' => ['nullable', 'array'],
            'order_bump_ids.*' => ['integer', 'exists:product_order_bumps,id'],
            'display_currency' => ['nullable', 'string', 'max:8', Rule::in($allowedDisplayCurrencies)],
            'coupon_code' => ['nullable', 'string', 'max:64'],
        ];
        $validated = $request->validate($rules);

        $displayCurrency = strtoupper((string) ($validated['display_currency'] ?? 'BRL'));
        if ($displayCurrency !== 'BRL') {
            return response()->json(['message' => 'PIX Parcelado disponível apenas em BRL.'], 422);
        }

        try {
            $context = $this->calculateOrderContext($request, $product, $validated, false);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage() ?: 'Não foi possível calcular o pedido.'], 422);
        }

        $totalAmount = (float) $context['total_amount'];
        $amountCents = MoneyMinorUnits::toMinorUnits($totalAmount, 'BRL');
        if ($amountCents < CajuPayPixParceladoService::MIN_AMOUNT_CENTS) {
            return response()->json(['message' => 'Valor mínimo para PIX Parcelado é R$ 50,00.'], 422);
        }

        $parceladoService = app(CajuPayPixParceladoService::class);
        $creds = $parceladoService->credentialsForTenant($product->tenant_id);
        if (! $creds || ! $parceladoService->isEnrolled($creds)) {
            return response()->json(['message' => 'PIX Parcelado não está ativo na CajuPay.'], 422);
        }

        $payAccountId = $parceladoService->resolvePayAccountIdForTenant($product->tenant_id);
        if ($payAccountId === null || $payAccountId === '') {
            return response()->json(['message' => 'Conta de pagamento CajuPay indisponível.'], 422);
        }

        $productRules = $parceladoService->productRulesFromConfig($product->checkout_config ?? []);
        $platformRules = $parceladoService->platformRules($creds);
        $merged = $parceladoService->mergeProductRulesWithPlatform($amountCents, $productRules, $platformRules);
        $sdkOptions = $parceladoService->sdkOptionsFromRules($merged);

        $linkBody = array_merge([
            'amount_cents' => $amountCents,
            'currency' => 'BRL',
            'description' => Str::limit($product->name, 200),
            'allow_pix_parcelado' => true,
        ], $sdkOptions);

        try {
            $driver = GatewayRegistry::driver('cajupay');
            if (! $driver instanceof \App\Gateways\CajuPay\CajuPayDriver) {
                throw new \RuntimeException('Driver CajuPay indisponível.');
            }
            $link = $driver->createPaymentLink($creds, $linkBody);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage() ?: 'Não foi possível preparar PIX Parcelado.'], 422);
        }

        $linkPayAccountId = trim((string) ($link['pay_account_id'] ?? ''));
        if ($linkPayAccountId !== '') {
            $payAccountId = $linkPayAccountId;
            $parceladoService->rememberPayAccountId($product->tenant_id, $payAccountId);
        }

        $paymentLinkToken = is_string($link['token'] ?? null) ? $link['token']
            : (is_string($link['public_token'] ?? null) ? $link['public_token']
            : (is_string($link['payment_link_token'] ?? null) ? $link['payment_link_token'] : null));

        if ($paymentLinkToken === null || $paymentLinkToken === '') {
            return response()->json(['message' => 'Link de pagamento CajuPay inválido.'], 422);
        }

        return response()->json([
            'pay_account_id' => $payAccountId,
            'payment_link_token' => $paymentLinkToken,
            'amount_cents' => $amountCents,
            'description' => $product->name,
            'sdk_options' => $sdkOptions,
            'merged_rules' => $merged,
        ]);
    }

    public function cajupayParceladoConfirmOrder(Request $request): JsonResponse
    {
        $product = Product::where('id', $request->input('product_id'))->where('is_active', true)->first();
        if (! $product) {
            return response()->json(['message' => 'Produto não encontrado.'], 404);
        }

        if ($product->billing_type !== Product::BILLING_ONE_TIME) {
            return response()->json(['message' => 'PIX Parcelado disponível apenas para pagamento único.'], 422);
        }

        $allowedDisplayCurrencies = CheckoutCustomPriceByCurrency::currencyCodesFromTenantSettings(
            $this->tenantCurrenciesListFor($product->tenant_id)
        );

        $rules = array_merge([
            'product_id' => ['required', 'exists:products,id'],
            'product_offer_id' => ['nullable', 'exists:product_offers,id'],
            'subscription_plan_id' => ['nullable', 'exists:subscription_plans,id'],
            'order_bump_ids' => ['nullable', 'array'],
            'order_bump_ids.*' => ['integer', 'exists:product_order_bumps,id'],
            'display_currency' => ['nullable', 'string', 'max:8', Rule::in($allowedDisplayCurrencies)],
            'coupon_code' => ['nullable', 'string', 'max:64'],
            'email' => ['required', 'email'],
            'name' => ['required', 'string', 'max:255'],
            'cpf' => ['required', 'string', 'max:11'],
            'phone' => ['required', 'string', 'max:24'],
            'checkout_session_token' => ['nullable', 'string', 'max:64'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
        ], $this->checkoutAttributionValidationRules());

        $validated = $request->validate($rules);

        $displayCurrency = strtoupper((string) ($validated['display_currency'] ?? 'BRL'));
        if ($displayCurrency !== 'BRL') {
            return response()->json(['message' => 'PIX Parcelado disponível apenas em BRL.'], 422);
        }

        $cpfDigits = preg_replace('/\D/', '', (string) ($validated['cpf'] ?? ''));
        if (strlen($cpfDigits) !== 11) {
            return response()->json(['message' => 'CPF obrigatório.', 'errors' => ['cpf' => 'CPF inválido.']], 422);
        }
        if (trim((string) ($validated['phone'] ?? '')) === '') {
            return response()->json(['message' => 'Telefone obrigatório.', 'errors' => ['phone' => 'Telefone obrigatório.']], 422);
        }

        app(CheckoutAbuseGuard::class)->assertCanCreateCheckout($request, $product);

        try {
            $context = $this->calculateOrderContext($request, $product, $validated, false);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage() ?: 'Não foi possível calcular o pedido.'], 422);
        }

        $totalAmount = (float) $context['total_amount'];
        if (MoneyMinorUnits::toMinorUnits($totalAmount, 'BRL') < CajuPayPixParceladoService::MIN_AMOUNT_CENTS) {
            return response()->json(['message' => 'Valor mínimo para PIX Parcelado é R$ 50,00.'], 422);
        }

        $draft = [
            'product_id' => $product->id,
            'product_offer_id' => $context['offer']?->id,
            'subscription_plan_id' => null,
            'display_currency' => 'BRL',
            'charge_currency' => 'BRL',
            'charge_amount' => $totalAmount,
            'total_amount' => $totalAmount,
            'base_amount' => $context['base_amount'],
            'order_bump_ids' => $context['order_bump_ids'],
            'coupon_code' => $context['coupon_code'],
            'checkout_slug' => $context['checkout_slug'],
            'payment_method' => 'pix_parcelado',
            'cajupay_token' => null,
            'checkout_session_id' => null,
        ];

        try {
            $orderContext = $this->createUserAndOrderFromDraft($request, $product, $draft, $validated);
        } catch (\Throwable $e) {
            Log::warning('CajuPayParceladoConfirmOrder: falha ao criar Order', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage() ?: 'Falha ao registrar o pedido.'], 422);
        }

        /** @var Order $order */
        $order = $orderContext['order'];
        $pollingToken = Str::random(32);

        $this->updateCheckoutSessionForOrder($order, array_merge($validated, [
            'checkout_session_token' => $validated['checkout_session_token'] ?? null,
        ]));

        event(new OrderPending($order->fresh()));

        Cache::put('parcelado_order.'.$pollingToken, [
            'order_id' => $order->id,
        ], now()->addHours(2));

        return response()->json([
            'success' => true,
            'order_id' => $order->id,
            'polling_token' => $pollingToken,
            'amount_cents' => MoneyMinorUnits::toMinorUnits($totalAmount, 'BRL'),
            'polling_url' => route('checkout.order-status', ['token' => $pollingToken]),
        ]);
    }

    public function cajupayParceladoComplete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'polling_token' => ['required', 'string', 'size:32'],
            'plan_id' => ['required', 'string', 'max:64'],
            'payment_id' => ['nullable', 'string', 'max:64'],
            'pix_copy_paste' => ['required', 'string', 'max:65535'],
            'pix_qr_code' => ['nullable', 'string', 'max:65535'],
            'installment_count' => ['nullable', 'integer', 'min:1', 'max:24'],
            'down_payment_cents' => ['nullable', 'integer', 'min:1'],
        ]);

        $cached = Cache::get('parcelado_order.'.$validated['polling_token']);
        if (! is_array($cached) || empty($cached['order_id'])) {
            return response()->json(['message' => 'Sessão expirada. Recarregue a página.'], 404);
        }

        $order = Order::with('product')->find((int) $cached['order_id']);
        if (! $order || $order->status !== 'pending') {
            return response()->json(['message' => 'Pedido inválido ou já processado.'], 422);
        }

        $parceladoService = app(CajuPayPixParceladoService::class);
        $productRules = $parceladoService->productRulesFromConfig($order->product?->checkout_config ?? []);
        $configuredDownCents = isset($productRules['down_payment_cents']) && $productRules['down_payment_cents'] !== null && $productRules['down_payment_cents'] !== ''
            ? (int) $productRules['down_payment_cents']
            : null;
        $downPaymentCents = $parceladoService->resolveDownPaymentCentsFromPlanResult(
            ['down_payment_cents' => $validated['down_payment_cents'] ?? null],
            $configuredDownCents,
        );

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $meta['cajupay_parcelado_plan_id'] = $validated['plan_id'];
        $meta['cajupay_parcelado_first_payment_id'] = $validated['payment_id'] ?? null;
        if (! empty($validated['installment_count'])) {
            $meta['cajupay_parcelado_installment_count'] = (int) $validated['installment_count'];
        }
        if ($downPaymentCents !== null && $downPaymentCents > 0) {
            $meta['cajupay_parcelado_down_payment_cents'] = $downPaymentCents;
        }

        $order->update([
            'gateway' => 'cajupay',
            'gateway_id' => $validated['payment_id'] ?? $validated['plan_id'],
            'metadata' => $meta,
        ]);

        $product = $order->product;
        $redirectUrl = $product?->checkout_config['redirect_after_purchase'] ?? null;
        $redirectUrl = ! empty($redirectUrl) && is_string($redirectUrl) ? $redirectUrl : null;

        $pixResult = [
            'qrcode' => $validated['pix_qr_code'] ?? null,
            'copy_paste' => $validated['pix_copy_paste'],
        ];

        $displayAmount = $downPaymentCents !== null && $downPaymentCents > 0
            ? MoneyMinorUnits::fromMinorUnits($downPaymentCents, 'BRL')
            : (float) $order->amount;

        $displayToken = PixCheckoutDisplay::persistAndStoreSession($order->fresh(), $pixResult, [
            'amount' => $displayAmount,
            'order_total_amount' => (float) $order->amount,
            'down_payment_cents' => $downPaymentCents,
            'product_name' => $product?->name,
            'checkout_slug' => $order->getCheckoutSlug(),
            'redirect_after_purchase' => $redirectUrl,
            'customer_name' => $order->user?->name ?? null,
            'customer_email' => $order->email,
            'customer_phone' => $order->phone,
        ]);

        Cache::forget('parcelado_order.'.$validated['polling_token']);

        return response()->json([
            'success' => true,
            'redirect_url' => route('checkout.pix-parcelado', ['token' => $displayToken]),
            'order_id' => $order->id,
        ]);
    }

    /**
     * Página PIX da entrada do PIX Parcelado.
     *
     * @return \Illuminate\Http\RedirectResponse|Response
     */
    public function pixParceladoPage(Request $request)
    {
        $token = $request->query('token');
        if (! $token || ! is_string($token)) {
            return redirect()->route('login')->with('error', 'Link inválido ou expirado.');
        }

        $stored = session('pix_display.'.$token);
        if (! is_array($stored)) {
            return redirect()->route('login')->with('error', 'Código PIX expirado ou inválido. Gere um novo PIX.');
        }

        $orderId = (int) ($stored['order_id'] ?? 0);
        $order = Order::with('product', 'productOffer', 'subscriptionPlan', 'orderItems')->find($orderId);
        if (! $order || $order->status !== 'pending') {
            session()->forget('pix_display.'.$token);
            $slug = $order ? $order->getCheckoutSlug() : null;
            $redirect = $slug ? redirect()->route('checkout.show', ['slug' => $slug]) : redirect()->route('login');

            return $redirect->with('error', 'Código PIX expirado ou inválido. Gere um novo PIX.');
        }

        $createdAt = (int) ($stored['created_at'] ?? 0);
        if ($createdAt + self::PIX_EXPIRY_SECONDS < time()) {
            session()->forget('pix_display.'.$token);

            return redirect()->route('checkout.show', ['slug' => $order->getCheckoutSlug()])
                ->with('error', 'Código PIX expirado. Gere um novo PIX.');
        }

        $amount = (float) ($stored['amount'] ?? 0);
        $amountFormatted = 'R$ '.number_format($amount, 2, ',', '.');

        $product = $order->product;
        $conversionPixels = $product
            ? \App\Support\AffiliateAttribution::conversionPixelsForCheckout(
                $product,
                \App\Support\AffiliateAttribution::affiliateCodeFromOrderMetadata(is_array($order->metadata) ? $order->metadata : null)
            )
            : Product::defaultConversionPixels();

        return Inertia::render('Checkout/PixParcelado', [
            'token' => $token,
            'order_id' => $orderId,
            'qrcode' => $stored['qrcode'] ?? null,
            'copy_paste' => $stored['copy_paste'] ?? null,
            'amount' => $amount,
            'amount_formatted' => $amountFormatted,
            'order_total_amount' => (float) ($stored['order_total_amount'] ?? $order->amount),
            'product_name' => $stored['product_name'] ?? $order->product->name,
            'checkout_slug' => $stored['checkout_slug'] ?? $order->getCheckoutSlug(),
            'redirect_after_purchase' => $stored['redirect_after_purchase'] ?? null,
            'customer_name' => $stored['customer_name'] ?? null,
            'customer_email' => $stored['customer_email'] ?? null,
            'customer_phone' => $stored['customer_phone'] ?? null,
            'created_at' => $createdAt,
            'expiry_seconds' => self::PIX_EXPIRY_SECONDS,
            'conversion_pixels' => $conversionPixels,
            'meta_purchase_event_id' => MetaPurchaseTracking::purchaseEventId($order->id),
            'purchase_contents' => MetaPurchaseTracking::purchaseContentsFromOrder($order, false),
        ]);
    }
}
