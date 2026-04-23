<?php

namespace App\Http\Controllers;

use App\Events\ProductBeforeSave;
use App\Events\ProductCreated;
use App\Events\ProductDeleted;
use App\Events\ProductDuplicated;
use App\Events\ProductIndexLoading;
use App\Events\ProductUpdated;
use App\Gateways\GatewayRegistry;
use App\Models\CademiIntegration;
use App\Models\GatewayCredential;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\ProductOrderBump;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\StorageService;
use App\Services\TeamAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProdutosController extends Controller
{
    private const TYPES = [
        Product::TYPE_APLICATIVO,
        Product::TYPE_AREA_MEMBROS,
        Product::TYPE_AREA_MEMBROS_EXTERNA,
        Product::TYPE_LINK,
        Product::TYPE_LINK_PAGAMENTO,
    ];

    private const BILLING_TYPES = [
        Product::BILLING_ONE_TIME,
        Product::BILLING_SUBSCRIPTION,
    ];

    public function index(Request $request): Response
    {
        $tenantId = auth()->user()->tenant_id;
        $rates = config('products.rates', ['brl_eur' => 0.16, 'brl_usd' => 0.18]);
        $query = Product::forTenant($tenantId)->orderBy('name');
        if (auth()->user()->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $query->whereIn('id', $allowed ?: ['__none__']);
        }
        $products = $query->paginate(20)->withQueryString()->through(fn (Product $p) => $this->productToArray($p, $rates));

        $productTypes = collect(Product::typeConfig())->map(fn ($config, $value) => [
            'value' => $value,
            'label' => $config['label'],
            'description' => $config['description'],
            'available' => $config['available'],
        ])->values()->all();

        $billingTypes = collect(Product::billingTypeLabels())->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all();

        $data = new \ArrayObject([
            'produtos' => $products,
            'productTypes' => $productTypes,
            'billingTypes' => $billingTypes,
            'exchange_rates' => $rates,
            'plugin_card_actions' => [],
            'plugin_form_sections' => [],
        ]);
        event(new ProductIndexLoading($data));
        $payload = $data->getArrayCopy();

        return Inertia::render('Produtos/Index', $payload);
    }

    public function create()
    {
        return redirect()->route('produtos.index');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            'billing_type' => ['required', 'string', 'in:'.implode(',', self::BILLING_TYPES)],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'in:BRL,EUR,USD'],
            'is_active' => ['boolean'],
            'image' => ['nullable', 'image', 'max:2048'],
            'deliverable_link' => ['nullable', 'string', 'url', 'max:500'],
        ]);
        $validated['tenant_id'] = auth()->user()->tenant_id;
        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['name']);
        $validated['currency'] = $validated['currency'] ?? config('products.currency_default', 'BRL');
        $validated['is_active'] = $request->boolean('is_active', true);

        $product = new Product($validated);
        $beforeEvent = new ProductBeforeSave($product, $validated, true);
        event($beforeEvent);
        if ($beforeEvent->abort !== null) {
            return back()->with('error', $beforeEvent->abort)->withInput();
        }

        unset($validated['image']);
        $deliverableLink = $validated['deliverable_link'] ?? null;
        unset($validated['deliverable_link']);
        $product = Product::create($validated);

        if ($request->has('deliverable_link')) {
            $config = $product->checkout_config ?? [];
            $config['deliverable_link'] = $deliverableLink ?? '';
            $product->update(['checkout_config' => $config]);
        }

        if ($request->hasFile('image')) {
            $path = app(StorageService::class)->putFile('products', $request->file('image'));
            $product->update(['image' => $path]);
        }

        event(new ProductCreated($product));

        return redirect()->route('produtos.index')->with('success', 'Produto criado.');
    }

    public function edit(Product $produto): Response
    {
        $this->authorizeProduct($produto);
        $produto->load([
            'users:id,name,email',
            'offers',
            'subscriptionPlans',
            'orderBumps',
        ]);

        $allComboIds = collect($produto->combo_product_ids ?? []);
        foreach ($produto->offers as $o) {
            $allComboIds = $allComboIds->merge($o->combo_product_ids ?? []);
        }
        foreach ($produto->subscriptionPlans as $p) {
            $allComboIds = $allComboIds->merge($p->combo_product_ids ?? []);
        }
        $uniqueComboIds = $allComboIds->unique()->filter()->values()->all();
        $comboNameById = $uniqueComboIds !== []
            ? Product::whereIn('id', $uniqueComboIds)->pluck('name', 'id')
            : collect();

        $resolveComboNames = static function (?array $ids) use ($comboNameById): array {
            if ($ids === null || $ids === []) {
                return [];
            }

            return collect($ids)->map(fn ($id) => $comboNameById[$id] ?? null)->filter()->values()->all();
        };

        $rates = config('products.rates', ['brl_eur' => 0.16, 'brl_usd' => 0.18]);
        $productTypes = collect(Product::typeConfig())->map(fn ($config, $value) => [
            'value' => $value,
            'label' => $config['label'],
            'description' => $config['description'],
            'available' => $config['available'],
        ])->values()->all();

        $billingTypes = collect(Product::billingTypeLabels())->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all();

        $produtoArray = $this->productToArray($produto, $rates);
        $produtoArray['combo_product_names'] = $resolveComboNames($produto->combo_product_ids ?? []);
        $produtoArray['users'] = $produto->users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->all();
        $produtoArray['offers'] = $produto->offers->map(fn ($o) => [
            'id' => $o->id,
            'name' => $o->name,
            'price' => (float) $o->price,
            'currency' => $o->currency ?? $produto->currency ?? 'BRL',
            'checkout_slug' => $o->checkout_slug,
            'position' => $o->position,
            'combo_product_ids' => $o->combo_product_ids ?? [],
            'combo_product_names' => $resolveComboNames($o->combo_product_ids ?? []),
        ])->values()->all();
        $produtoArray['subscription_plans'] = $produto->subscriptionPlans->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'price' => (float) $p->price,
            'currency' => $p->currency ?? $produto->currency ?? 'BRL',
            'interval' => $p->interval,
            'checkout_slug' => $p->checkout_slug,
            'position' => $p->position,
            'combo_product_ids' => $p->combo_product_ids ?? [],
            'combo_product_names' => $resolveComboNames($p->combo_product_ids ?? []),
        ])->values()->all();

        $basePlan = $produto->subscriptionPlans->sortBy('position')->first();
        $produtoArray['base_interval'] = $basePlan?->interval ?? 'monthly';

        $orderBumps = $produto->orderBumps()->with(['targetProduct', 'targetProductOffer'])->get();
        $produtoArray['order_bumps'] = $orderBumps->map(function (ProductOrderBump $b) {
            $target = $b->targetProduct;
            $imageUrl = $target && $target->image
                ? app(StorageService::class)->url($target->image)
                : null;
            return [
                'id' => $b->id,
                'target_product_id' => $b->target_product_id,
                'target_product_offer_id' => $b->target_product_offer_id,
                'target_name' => $target?->name,
                'target_image_url' => $imageUrl,
                'title' => $b->title,
                'description' => $b->description,
                'price_override' => $b->price_override !== null ? (float) $b->price_override : null,
                'cta_title' => $b->cta_title,
                'position' => $b->position,
                'effective_amount_brl' => $b->getEffectiveAmountBrl(),
            ];
        })->values()->all();

        $tenantId = auth()->user()->tenant_id;
        $availableForBump = Product::forTenant($tenantId)
            ->where('id', '!=', $produto->id)
            ->where('billing_type', Product::BILLING_ONE_TIME)
            ->where('is_active', true)
            ->orderBy('name')
            ->with('offers')
            ->get();
        $produtoArray['available_products_for_bump'] = $availableForBump->map(function (Product $p) use ($rates) {
            $imageUrl = $p->image ? app(StorageService::class)->url($p->image) : null;
            return [
                'id' => $p->id,
                'name' => $p->name,
                'image_url' => $imageUrl,
                'price' => (float) $p->price,
                'currency' => $p->currency ?? 'BRL',
                'offers' => $p->offers->map(fn ($o) => [
                    'id' => $o->id,
                    'name' => $o->name,
                    'price' => (float) $o->price,
                    'currency' => $o->currency ?? $p->currency ?? 'BRL',
                ])->values()->all(),
            ];
        })->values()->all();

        $availableForCombo = Product::forTenant($tenantId)
            ->where('id', '!=', $produto->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
        $produtoArray['available_products_for_combo'] = $availableForCombo->map(function (Product $p) {
            $imageUrl = $p->image ? app(StorageService::class)->url($p->image) : null;

            return [
                'id' => $p->id,
                'name' => $p->name,
                'image_url' => $imageUrl,
                'price' => (float) $p->price,
                'currency' => $p->currency ?? 'BRL',
            ];
        })->values()->all();

        $checkoutConfig = $produto->checkout_config ?? [];
        $checkoutConfig['email_template'] = array_merge(
            Product::defaultEmailTemplate(),
            $checkoutConfig['email_template'] ?? []
        );
        $produtoArray['checkout_config'] = $checkoutConfig;

        $external = DB::table('cademi_integration_product')
            ->where('product_id', $produto->id)
            ->orderByDesc('id')
            ->first();
        $produtoArray['external_member_area'] = $external ? [
            'integration_id' => (int) $external->cademi_integration_id,
            'cademi_tag_id' => $external->cademi_tag_id !== null ? (int) $external->cademi_tag_id : null,
            'cademi_produto_id' => $external->cademi_produto_id !== null ? (int) $external->cademi_produto_id : null,
            'cademi_produto_ids' => property_exists($external, 'cademi_produto_ids') && $external->cademi_produto_ids
                ? (json_decode((string) $external->cademi_produto_ids, true) ?: [])
                : [],
        ] : [
            'integration_id' => null,
            'cademi_tag_id' => null,
            'cademi_produto_id' => null,
            'cademi_produto_ids' => [],
        ];

        $productsForUpsell = Product::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('id', '!=', $produto->id)
            ->with('offers')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'offers' => $p->offers->map(fn ($o) => [
                    'id' => $o->id,
                    'name' => $o->name,
                    'price' => (float) $o->price,
                ])->values()->all(),
            ])
            ->values()->all();
        $produtoArray['products_for_upsell'] = $productsForUpsell;

        $gatewaysByMethod = $this->gatewaysByMethodForTenant($tenantId);

        $cademiIntegrations = CademiIntegration::forTenant($tenantId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (CademiIntegration $i) => ['id' => $i->id, 'name' => $i->name])
            ->values()
            ->all();

        return Inertia::render('Produtos/Edit', [
            'produto' => $produtoArray,
            'productTypes' => $productTypes,
            'billingTypes' => $billingTypes,
            'exchange_rates' => $rates,
            'gateways_by_method' => $gatewaysByMethod,
            'cademi_integrations' => $cademiIntegrations,
            'layoutContentFlushLeft' => true,
            'pageTitleBadge' => $produto->name,
        ]);
    }

    public function updateExternalMemberArea(Request $request, Product $produto)
    {
        $this->authorizeProduct($produto);

        $validated = $request->validate([
            'cademi_integration_id' => ['nullable', 'integer', 'exists:cademi_integrations,id'],
            'cademi_tag_id' => ['nullable', 'integer', 'min:1'],
            'cademi_produto_id' => ['nullable', 'integer', 'min:1'],
            'cademi_produto_ids' => ['nullable', 'array'],
            'cademi_produto_ids.*' => ['integer', 'min:1'],
        ]);

        $tenantId = auth()->user()->tenant_id;
        if (! empty($validated['cademi_integration_id'])) {
            $exists = CademiIntegration::forTenant($tenantId)->where('id', (int) $validated['cademi_integration_id'])->exists();
            if (! $exists) {
                abort(422, 'Integração Cademí inválida para este tenant.');
            }
        }

        // TAG é opcional (postback pode funcionar sem tags).

        DB::table('cademi_integration_product')->where('product_id', $produto->id)->delete();

        if (! empty($validated['cademi_integration_id'])) {
            $produtoIds = [];
            if (! empty($validated['cademi_produto_ids']) && is_array($validated['cademi_produto_ids'])) {
                $produtoIds = array_values(array_unique(array_map('intval', $validated['cademi_produto_ids'])));
                $produtoIds = array_values(array_filter($produtoIds, fn ($v) => $v > 0));
            } elseif (! empty($validated['cademi_produto_id'])) {
                $produtoIds = [(int) $validated['cademi_produto_id']];
            }

            if ($produtoIds === []) {
                abort(422, 'Informe ao menos 1 Produto ID da Cademí.');
            }

            DB::table('cademi_integration_product')->insert([
                'cademi_integration_id' => (int) $validated['cademi_integration_id'],
                'product_id' => $produto->id,
                'cademi_tag_id' => $validated['cademi_tag_id'] ?? null,
                // keep legacy single field in sync (first id)
                'cademi_produto_id' => $produtoIds[0] ?? null,
                'cademi_produto_ids' => json_encode($produtoIds),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    public function update(Request $request, Product $produto)
    {
        $this->authorizeProduct($produto);

        if ($request->has('conversion_pixels') && is_string($request->conversion_pixels)) {
            $decoded = json_decode($request->conversion_pixels, true);
            $request->merge(['conversion_pixels' => is_array($decoded) ? $decoded : null]);
        }

        if ($request->has('cart_recovery_email') && is_string($request->cart_recovery_email)) {
            $decoded = json_decode($request->cart_recovery_email, true);
            $request->merge(['cart_recovery_email' => is_array($decoded) ? $decoded : null]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            'billing_type' => ['required', 'string', 'in:'.implode(',', self::BILLING_TYPES)],
            'price' => ['required', 'numeric', 'min:0'],
            'combo_product_ids' => ['nullable', 'array'],
            'combo_product_ids.*' => ['string', 'exists:products,id'],
            'currency' => ['nullable', 'string', 'in:BRL,EUR,USD'],
            'is_active' => ['boolean'],
            'image' => ['nullable', 'image', 'max:2048'],
            'conversion_pixels' => ['nullable', 'array'],
            'conversion_pixels.meta' => ['nullable', 'array'],
            'conversion_pixels.meta.enabled' => ['nullable', 'boolean'],
            'conversion_pixels.meta.entries' => ['nullable', 'array'],
            'conversion_pixels.meta.entries.*.id' => ['nullable', 'string', 'max:64'],
            'conversion_pixels.meta.entries.*.pixel_id' => ['nullable', 'string', 'max:64'],
            'conversion_pixels.meta.entries.*.access_token' => ['nullable', 'string', 'max:500'],
            'conversion_pixels.meta.entries.*.fire_purchase_on_pix' => ['nullable', 'boolean'],
            'conversion_pixels.meta.entries.*.fire_purchase_on_boleto' => ['nullable', 'boolean'],
            'conversion_pixels.meta.entries.*.disable_order_bump_events' => ['nullable', 'boolean'],
            'conversion_pixels.tiktok' => ['nullable', 'array'],
            'conversion_pixels.tiktok.enabled' => ['nullable', 'boolean'],
            'conversion_pixels.tiktok.entries' => ['nullable', 'array'],
            'conversion_pixels.tiktok.entries.*.id' => ['nullable', 'string', 'max:64'],
            'conversion_pixels.tiktok.entries.*.pixel_id' => ['nullable', 'string', 'max:64'],
            'conversion_pixels.tiktok.entries.*.access_token' => ['nullable', 'string', 'max:500'],
            'conversion_pixels.tiktok.entries.*.fire_purchase_on_pix' => ['nullable', 'boolean'],
            'conversion_pixels.tiktok.entries.*.fire_purchase_on_boleto' => ['nullable', 'boolean'],
            'conversion_pixels.tiktok.entries.*.disable_order_bump_events' => ['nullable', 'boolean'],
            'conversion_pixels.google_ads' => ['nullable', 'array'],
            'conversion_pixels.google_ads.enabled' => ['nullable', 'boolean'],
            'conversion_pixels.google_ads.entries' => ['nullable', 'array'],
            'conversion_pixels.google_ads.entries.*.id' => ['nullable', 'string', 'max:64'],
            'conversion_pixels.google_ads.entries.*.conversion_id' => ['nullable', 'string', 'max:64'],
            'conversion_pixels.google_ads.entries.*.conversion_label' => ['nullable', 'string', 'max:64'],
            'conversion_pixels.google_ads.entries.*.fire_purchase_on_pix' => ['nullable', 'boolean'],
            'conversion_pixels.google_ads.entries.*.fire_purchase_on_boleto' => ['nullable', 'boolean'],
            'conversion_pixels.google_ads.entries.*.disable_order_bump_events' => ['nullable', 'boolean'],
            'conversion_pixels.google_analytics' => ['nullable', 'array'],
            'conversion_pixels.google_analytics.enabled' => ['nullable', 'boolean'],
            'conversion_pixels.google_analytics.entries' => ['nullable', 'array'],
            'conversion_pixels.google_analytics.entries.*.id' => ['nullable', 'string', 'max:64'],
            'conversion_pixels.google_analytics.entries.*.measurement_id' => ['nullable', 'string', 'max:64'],
            'conversion_pixels.google_analytics.entries.*.fire_purchase_on_pix' => ['nullable', 'boolean'],
            'conversion_pixels.google_analytics.entries.*.fire_purchase_on_boleto' => ['nullable', 'boolean'],
            'conversion_pixels.google_analytics.entries.*.disable_order_bump_events' => ['nullable', 'boolean'],
            'conversion_pixels.custom_script' => ['nullable', 'array'],
            'conversion_pixels.custom_script.*.id' => ['nullable', 'string', 'max:64'],
            'conversion_pixels.custom_script.*.name' => ['nullable', 'string', 'max:255'],
            'conversion_pixels.custom_script.*.script' => ['nullable', 'string', 'max:65535'],
            'payment_gateways' => ['nullable', 'array'],
            'payment_gateways.pix' => ['nullable', 'string', 'max:64'],
            'payment_gateways.pix_redundancy' => ['nullable', 'array'],
            'payment_gateways.pix_redundancy.*' => ['string', 'max:64'],
            'payment_gateways.card' => ['nullable', 'string', 'max:64'],
            'payment_gateways.card_redundancy' => ['nullable', 'array'],
            'payment_gateways.card_redundancy.*' => ['string', 'max:64'],
            'payment_gateways.boleto' => ['nullable', 'string', 'max:64'],
            'payment_gateways.boleto_redundancy' => ['nullable', 'array'],
            'payment_gateways.boleto_redundancy.*' => ['string', 'max:64'],
            'payment_gateways.pix_auto' => ['nullable', 'string', 'max:64'],
            'payment_gateways.pix_auto_redundancy' => ['nullable', 'array'],
            'payment_gateways.pix_auto_redundancy.*' => ['string', 'max:64'],
            'payment_gateways.crypto' => ['nullable', 'string', 'max:64'],
            'payment_gateways.crypto_redundancy' => ['nullable', 'array'],
            'payment_gateways.crypto_redundancy.*' => ['string', 'max:64'],
            'card_installments' => ['nullable', 'array'],
            'card_installments.enabled' => ['nullable', 'boolean'],
            'card_installments.max' => ['nullable', 'integer', 'min:1', 'max:12'],
            'stripe_link_enabled' => ['nullable', 'boolean'],
            'email_template' => ['nullable', 'array'],
            'email_template.logo_url' => ['nullable', 'string', 'max:500'],
            'email_template.from_name' => ['nullable', 'string', 'max:255'],
            'email_template.subject' => ['nullable', 'string', 'max:255'],
            'email_template.body_text' => ['nullable', 'string', 'max:65535'],
            'email_template.body_html' => ['nullable', 'string', 'max:65535'],
            'cart_recovery_email' => ['nullable', 'array'],
            'cart_recovery_email.enabled' => ['nullable', 'boolean'],
            'cart_recovery_email.stages' => ['nullable', 'array'],
            'cart_recovery_email.stages.10m' => ['nullable', 'array'],
            'cart_recovery_email.stages.10m.subject' => ['nullable', 'string', 'max:255'],
            'cart_recovery_email.stages.10m.body_text' => ['nullable', 'string', 'max:65535'],
            'cart_recovery_email.stages.10m.body_html' => ['nullable', 'string', 'max:65535'],
            'cart_recovery_email.stages.5h' => ['nullable', 'array'],
            'cart_recovery_email.stages.5h.subject' => ['nullable', 'string', 'max:255'],
            'cart_recovery_email.stages.5h.body_text' => ['nullable', 'string', 'max:65535'],
            'cart_recovery_email.stages.5h.body_html' => ['nullable', 'string', 'max:65535'],
            'cart_recovery_email.stages.24h' => ['nullable', 'array'],
            'cart_recovery_email.stages.24h.subject' => ['nullable', 'string', 'max:255'],
            'cart_recovery_email.stages.24h.body_text' => ['nullable', 'string', 'max:65535'],
            'cart_recovery_email.stages.24h.body_html' => ['nullable', 'string', 'max:65535'],
            'deliverable_link' => ['nullable', 'string', 'url', 'max:500'],
            'base_interval' => ['nullable', 'string', 'in:weekly,monthly,quarterly,semi_annual,annual,lifetime'],
            'pagarme_billing' => ['nullable', 'array'],
            'pagarme_billing.mode' => ['nullable', 'string', 'in:customer,company'],
            'pagarme_billing.company_address' => ['nullable', 'array'],
            'pagarme_billing.company_address.zipcode' => ['nullable', 'string', 'max:32'],
            'pagarme_billing.company_address.street' => ['nullable', 'string', 'max:255'],
            'pagarme_billing.company_address.number' => ['nullable', 'string', 'max:32'],
            'pagarme_billing.company_address.neighborhood' => ['nullable', 'string', 'max:255'],
            'pagarme_billing.company_address.city' => ['nullable', 'string', 'max:255'],
            'pagarme_billing.company_address.state' => ['nullable', 'string', 'max:2'],
        ]);
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['currency'] = $validated['currency'] ?? config('products.currency_default', 'BRL');

        $tenantId = auth()->user()->tenant_id;
        $validated['combo_product_ids'] = $this->assertValidComboProductIdsForHost(
            $tenantId,
            $produto->id,
            $request->input('combo_product_ids', [])
        );

        $beforeEvent = new ProductBeforeSave($produto, $validated, false);
        event($beforeEvent);
        if ($beforeEvent->abort !== null) {
            return back()->with('error', $beforeEvent->abort)->withInput();
        }

        $oldImage = $produto->image;
        unset($validated['image']);
        $paymentGateways = $validated['payment_gateways'] ?? null;
        unset($validated['payment_gateways']);
        $pagarmeBillingInput = array_key_exists('pagarme_billing', $validated) ? $validated['pagarme_billing'] : null;
        unset($validated['pagarme_billing']);
        if (is_array($pagarmeBillingInput)) {
            $mergedPb = array_replace_recursive(
                Product::defaultCheckoutConfig()['pagarme_billing'] ?? [],
                $pagarmeBillingInput
            );
            $pgw = is_array($paymentGateways)
                ? $paymentGateways
                : (($produto->checkout_config['payment_gateways'] ?? []) ?: []);
                if (($mergedPb['mode'] ?? 'customer') === 'company') {
                    $cardPm = in_array($pgw['card'] ?? null, ['pagarme', 'efi'], true);
                    $boletoPm = in_array($pgw['boleto'] ?? null, ['pagarme', 'efi'], true);
                    if ($cardPm || $boletoPm) {
                    $ca = $mergedPb['company_address'] ?? [];
                    $zip = preg_replace('/\D/', '', (string) ($ca['zipcode'] ?? ''));
                    $street = trim((string) ($ca['street'] ?? ''));
                    $number = trim((string) ($ca['number'] ?? ''));
                    $neighborhood = trim((string) ($ca['neighborhood'] ?? ''));
                    $city = trim((string) ($ca['city'] ?? ''));
                    $state = strtoupper(substr(trim((string) ($ca['state'] ?? '')), 0, 2));
                    if (strlen($zip) < 8 || $street === '' || $number === '' || $neighborhood === '' || $city === '' || strlen($state) !== 2) {
                        throw ValidationException::withMessages([
                            'pagarme_billing.company_address' => ['Preencha o endereço completo da empresa (CEP com 8 dígitos, rua, número, bairro, cidade e UF) quando usar cobrança Pagar.me ou Efí no modo empresa.'],
                        ]);
                    }
                }
            }
        }
        $cardInstallments = $validated['card_installments'] ?? null;
        unset($validated['card_installments']);
        $stripeLinkEnabled = array_key_exists('stripe_link_enabled', $validated) ? $validated['stripe_link_enabled'] : null;
        unset($validated['stripe_link_enabled']);
        $emailTemplate = $validated['email_template'] ?? null;
        unset($validated['email_template']);
        $cartRecoveryEmail = $validated['cart_recovery_email'] ?? null;
        unset($validated['cart_recovery_email']);
        $deliverableLink = $validated['deliverable_link'] ?? null;
        unset($validated['deliverable_link']);
        $conversionPixels = $validated['conversion_pixels'] ?? null;
        unset($validated['conversion_pixels']);
        $baseInterval = $validated['base_interval'] ?? null;
        unset($validated['base_interval']);
        $produto->update($validated);

        if ($produto->billing_type === Product::BILLING_SUBSCRIPTION && $baseInterval !== null) {
            $basePlan = $produto->subscriptionPlans()->orderBy('position')->first();
            $labels = SubscriptionPlan::intervalLabels();
            $planName = $labels[$baseInterval] ?? ucfirst($baseInterval);
            if ($basePlan) {
                $basePlan->update([
                    'name' => $planName,
                    'price' => $produto->price,
                    'currency' => $produto->currency ?? 'BRL',
                    'interval' => $baseInterval,
                ]);
            } else {
                SubscriptionPlan::create([
                    'product_id' => $produto->id,
                    'name' => $planName,
                    'price' => $produto->price,
                    'currency' => $produto->currency ?? 'BRL',
                    'interval' => $baseInterval,
                    'checkout_slug' => null,
                    'position' => 0,
                ]);
            }
        }

        if ($request->has('conversion_pixels')) {
            $merged = [];
            foreach (['meta', 'tiktok', 'google_ads', 'google_analytics'] as $key) {
                $raw = is_array($conversionPixels[$key] ?? null) ? $conversionPixels[$key] : [];
                $merged[$key] = Product::normalizeConversionPixelBlock($raw, $key);
            }
            $merged['custom_script'] = [];
            foreach ($conversionPixels['custom_script'] ?? [] as $item) {
                if (! empty($item['script'] ?? '')) {
                    $merged['custom_script'][] = [
                        'id' => $item['id'] ?? Str::uuid()->toString(),
                        'name' => $item['name'] ?? '',
                        'script' => $item['script'],
                    ];
                }
            }
            $produto->update(['conversion_pixels' => $merged]);
        }

        $config = is_array($produto->checkout_config) ? $produto->checkout_config : [];
        $configUpdated = false;
        if ($request->has('deliverable_link')) {
            $config['deliverable_link'] = $deliverableLink ?? '';
            $configUpdated = true;
        }
        if (is_array($paymentGateways)) {
            $config['payment_gateways'] = [
                'pix' => ! empty($paymentGateways['pix']) ? $paymentGateways['pix'] : null,
                'pix_redundancy' => array_values(array_filter(array_map(fn ($s) => is_string($s) ? trim($s) : '', $paymentGateways['pix_redundancy'] ?? []))),
                'card' => ! empty($paymentGateways['card']) ? $paymentGateways['card'] : null,
                'card_redundancy' => array_values(array_filter(array_map(fn ($s) => is_string($s) ? trim($s) : '', $paymentGateways['card_redundancy'] ?? []))),
                'boleto' => ! empty($paymentGateways['boleto']) ? $paymentGateways['boleto'] : null,
                'boleto_redundancy' => array_values(array_filter(array_map(fn ($s) => is_string($s) ? trim($s) : '', $paymentGateways['boleto_redundancy'] ?? []))),
                'crypto' => ! empty($paymentGateways['crypto']) ? $paymentGateways['crypto'] : null,
                'crypto_redundancy' => array_values(array_filter(array_map(fn ($s) => is_string($s) ? trim($s) : '', $paymentGateways['crypto_redundancy'] ?? []))),
            ];
            if ($produto->billing_type === Product::BILLING_SUBSCRIPTION) {
                $config['payment_gateways']['pix_auto'] = ! empty($paymentGateways['pix_auto']) ? $paymentGateways['pix_auto'] : null;
                $config['payment_gateways']['pix_auto_redundancy'] = array_values(array_filter(array_map(fn ($s) => is_string($s) ? trim($s) : '', $paymentGateways['pix_auto_redundancy'] ?? [])));
            } else {
                $config['payment_gateways']['pix_auto'] = null;
                $config['payment_gateways']['pix_auto_redundancy'] = [];
            }
            $configUpdated = true;
        }
        if (is_array($cardInstallments)) {
            $config['card_installments'] = [
                'enabled' => ! empty($cardInstallments['enabled']),
                'max' => min(12, max(1, (int) ($cardInstallments['max'] ?? 1))),
            ];
            $configUpdated = true;
        }
        if ($stripeLinkEnabled !== null) {
            $config['stripe_link_enabled'] = (bool) $stripeLinkEnabled;
            $configUpdated = true;
        }
        if (is_array($emailTemplate)) {
            $config['email_template'] = array_merge(
                Product::defaultEmailTemplate(),
                $emailTemplate
            );
            $configUpdated = true;
        }
        if (is_array($cartRecoveryEmail)) {
            $config['cart_recovery_email'] = array_replace_recursive(
                Product::defaultCheckoutConfig()['cart_recovery_email'] ?? [],
                $cartRecoveryEmail
            );
            $configUpdated = true;
        }
        if (is_array($pagarmeBillingInput)) {
            $config['pagarme_billing'] = array_replace_recursive(
                Product::defaultCheckoutConfig()['pagarme_billing'] ?? [],
                $pagarmeBillingInput
            );
            $configUpdated = true;
        }
        if ($configUpdated) {
            $produto->update(['checkout_config' => $config]);
        }

        if ($request->hasFile('image')) {
            $storage = app(StorageService::class);
            if ($oldImage && $storage->exists($oldImage)) {
                $storage->delete($oldImage);
            }
            $path = $storage->putFile('products', $request->file('image'));
            $produto->update(['image' => $path]);
        }

        event(new ProductUpdated($produto));

        $url = route('produtos.edit', $produto);
        $tab = $request->query('tab');
        if ($tab) {
            $url .= '?tab=' . urlencode($tab);
        }

        return redirect($url)->with('success', 'Produto atualizado.');
    }

    public function destroy(Product $produto)
    {
        $this->authorizeProduct($produto);
        event(new ProductDeleted($produto));
        $storage = app(StorageService::class);
        if ($produto->image && $storage->exists($produto->image)) {
            $storage->delete($produto->image);
        }
        $produto->delete();

        return redirect()->route('produtos.index')->with('success', 'Produto removido.');
    }

    public function duplicate(Product $produto)
    {
        $this->authorizeProduct($produto);
        $tenantId = auth()->user()->tenant_id;
        $baseName = $produto->name.' (cópia)';
        $slug = Str::slug($baseName);
        $uniqueSlug = $slug;
        $n = 0;
        while (Product::forTenant($tenantId)->where('slug', $uniqueSlug)->exists()) {
            $n++;
            $uniqueSlug = $slug.'-'.$n;
        }

        $newProduct = Product::create([
            'tenant_id' => $tenantId,
            'name' => $baseName,
            'slug' => $uniqueSlug,
            'description' => $produto->description,
            'type' => $produto->type,
            'billing_type' => $produto->billing_type ?? Product::BILLING_ONE_TIME,
            'image' => null,
            'price' => $produto->price,
            'currency' => $produto->currency ?? config('products.currency_default', 'BRL'),
            'is_active' => $produto->is_active,
            'checkout_config' => $produto->checkout_config,
        ]);

        event(new ProductDuplicated($produto, $newProduct));

        return redirect()->route('produtos.index')->with('success', 'Produto duplicado.');
    }

    public function addAluno(Request $request, Product $produto)
    {
        $this->authorizeProduct($produto);
        $validated = $request->validate(['email' => ['required', 'email', 'exists:users,email']]);
        $user = User::where('email', $validated['email'])->where('role', User::ROLE_ALUNO)->firstOrFail();
        $produto->users()->syncWithoutDetaching([$user->id]);

        return back()->with('success', 'Acesso concedido.');
    }

    public function uploadEmailTemplateLogo(Request $request, Product $produto)
    {
        $this->authorizeProduct($produto);
        $request->validate([
            'logo' => ['required', 'image', 'max:2048'],
        ]);

        $file = $request->file('logo');
        $ext = $file->getClientOriginalExtension() ?: 'png';
        $path = 'email-templates/'.$produto->id.'/logo.'.strtolower($ext);

        $storage = app(StorageService::class);
        $storage->putFileAs(dirname($path), $file, basename($path));
        $logoUrl = $storage->url($path);

        $config = $produto->checkout_config ?? [];
        $config['email_template'] = array_merge(
            Product::defaultEmailTemplate(),
            $config['email_template'] ?? [],
            ['logo_url' => $logoUrl]
        );
        $produto->update(['checkout_config' => $config]);

        return response()->json(['logo_url' => $logoUrl]);
    }

    public function storeOffer(Request $request, Product $produto)
    {
        $this->authorizeProduct($produto);
        if (($produto->billing_type ?? Product::BILLING_ONE_TIME) !== Product::BILLING_ONE_TIME) {
            return back()->with('error', 'Ofertas são apenas para produtos com pagamento único.');
        }
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'in:BRL,EUR,USD'],
            'combo_product_ids' => ['nullable', 'array'],
            'combo_product_ids.*' => ['string', 'exists:products,id'],
        ]);
        $validated['product_id'] = $produto->id;
        $validated['currency'] = $validated['currency'] ?? $produto->currency ?? 'BRL';
        $tenantId = auth()->user()->tenant_id;
        $validated['combo_product_ids'] = $this->assertValidComboProductIdsForHost(
            $tenantId,
            $produto->id,
            $request->input('combo_product_ids', [])
        );
        $maxPosition = $produto->offers()->max('position') ?? 0;
        $validated['position'] = $maxPosition + 1;
        ProductOffer::create($validated);
        return back()->with('success', 'Oferta adicionada.');
    }

    public function updateOffer(Request $request, Product $produto, ProductOffer $offer)
    {
        $this->authorizeProduct($produto);
        if ($offer->product_id !== $produto->id) {
            abort(404);
        }
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'in:BRL,EUR,USD'],
            'combo_product_ids' => ['nullable', 'array'],
            'combo_product_ids.*' => ['string', 'exists:products,id'],
        ]);
        $validated['currency'] = $validated['currency'] ?? $produto->currency ?? 'BRL';
        $tenantId = auth()->user()->tenant_id;
        $validated['combo_product_ids'] = $this->assertValidComboProductIdsForHost(
            $tenantId,
            $produto->id,
            $request->input('combo_product_ids', [])
        );
        $offer->update($validated);
        return back()->with('success', 'Oferta atualizada.');
    }

    public function destroyOffer(Product $produto, ProductOffer $offer)
    {
        $this->authorizeProduct($produto);
        if ($offer->product_id !== $produto->id) {
            abort(404);
        }
        $offer->delete();
        return back()->with('success', 'Oferta removida.');
    }

    public function storeOrderBump(Request $request, Product $produto)
    {
        $this->authorizeProduct($produto);
        $tenantId = auth()->user()->tenant_id;
        $validated = $request->validate([
            'target_product_id' => ['required', 'string', 'exists:products,id'],
            'target_product_offer_id' => ['nullable', 'integer', 'exists:product_offers,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            'price_override' => ['nullable', 'numeric', 'min:0'],
            'cta_title' => ['required', 'string', 'max:255'],
        ]);
        $target = Product::forTenant($tenantId)->find($validated['target_product_id']);
        if (! $target || $target->id === $produto->id) {
            return back()->with('error', 'Produto inválido para order bump.');
        }
        if (! empty($validated['target_product_offer_id'])) {
            $offer = ProductOffer::where('id', $validated['target_product_offer_id'])->where('product_id', $target->id)->first();
            if (! $offer) {
                return back()->with('error', 'Oferta não pertence ao produto selecionado.');
            }
        }
        $validated['product_id'] = $produto->id;
        $validated['target_product_offer_id'] = $validated['target_product_offer_id'] ?? null;
        $validated['price_override'] = isset($validated['price_override']) ? (float) $validated['price_override'] : null;
        $maxPosition = $produto->orderBumps()->max('position') ?? 0;
        $validated['position'] = $maxPosition + 1;
        ProductOrderBump::create($validated);
        return back()->with('success', 'Order bump adicionado.');
    }

    public function updateOrderBump(Request $request, Product $produto, ProductOrderBump $bump)
    {
        $this->authorizeProduct($produto);
        if ($bump->product_id !== $produto->id) {
            abort(404);
        }
        $tenantId = auth()->user()->tenant_id;
        $validated = $request->validate([
            'target_product_id' => ['required', 'string', 'exists:products,id'],
            'target_product_offer_id' => ['nullable', 'integer', 'exists:product_offers,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            'price_override' => ['nullable', 'numeric', 'min:0'],
            'cta_title' => ['required', 'string', 'max:255'],
        ]);
        $target = Product::forTenant($tenantId)->find($validated['target_product_id']);
        if (! $target || $target->id === $produto->id) {
            return back()->with('error', 'Produto inválido para order bump.');
        }
        if (! empty($validated['target_product_offer_id'])) {
            $offer = ProductOffer::where('id', $validated['target_product_offer_id'])->where('product_id', $target->id)->first();
            if (! $offer) {
                return back()->with('error', 'Oferta não pertence ao produto selecionado.');
            }
        }
        $validated['target_product_offer_id'] = $validated['target_product_offer_id'] ?? null;
        $validated['price_override'] = isset($validated['price_override']) ? (float) $validated['price_override'] : null;
        $bump->update($validated);
        return back()->with('success', 'Order bump atualizado.');
    }

    public function destroyOrderBump(Product $produto, ProductOrderBump $bump)
    {
        $this->authorizeProduct($produto);
        if ($bump->product_id !== $produto->id) {
            abort(404);
        }
        $bump->delete();
        return back()->with('success', 'Order bump removido.');
    }

    public function storeSubscriptionPlan(Request $request, Product $produto)
    {
        $this->authorizeProduct($produto);
        if (($produto->billing_type ?? Product::BILLING_ONE_TIME) !== Product::BILLING_SUBSCRIPTION) {
            return back()->with('error', 'Planos de assinatura são apenas para produtos do tipo assinatura.');
        }
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'in:BRL,EUR,USD'],
            'interval' => ['required', 'string', 'in:weekly,monthly,quarterly,semi_annual,annual,lifetime'],
            'combo_product_ids' => ['nullable', 'array'],
            'combo_product_ids.*' => ['string', 'exists:products,id'],
        ]);
        $validated['product_id'] = $produto->id;
        $validated['currency'] = $validated['currency'] ?? $produto->currency ?? 'BRL';
        $tenantId = auth()->user()->tenant_id;
        $validated['combo_product_ids'] = $this->assertValidComboProductIdsForHost(
            $tenantId,
            $produto->id,
            $request->input('combo_product_ids', [])
        );
        $maxPosition = $produto->subscriptionPlans()->max('position') ?? 0;
        $validated['position'] = $maxPosition + 1;
        SubscriptionPlan::create($validated);
        return back()->with('success', 'Plano adicionado.');
    }

    public function updateSubscriptionPlan(Request $request, Product $produto, SubscriptionPlan $plan)
    {
        $this->authorizeProduct($produto);
        if ($plan->product_id !== $produto->id) {
            abort(404);
        }
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'in:BRL,EUR,USD'],
            'interval' => ['required', 'string', 'in:weekly,monthly,quarterly,semi_annual,annual,lifetime'],
            'combo_product_ids' => ['nullable', 'array'],
            'combo_product_ids.*' => ['string', 'exists:products,id'],
        ]);
        $validated['currency'] = $validated['currency'] ?? $produto->currency ?? 'BRL';
        $tenantId = auth()->user()->tenant_id;
        $validated['combo_product_ids'] = $this->assertValidComboProductIdsForHost(
            $tenantId,
            $produto->id,
            $request->input('combo_product_ids', [])
        );
        $plan->update($validated);
        return back()->with('success', 'Plano atualizado.');
    }

    public function destroySubscriptionPlan(Product $produto, SubscriptionPlan $plan)
    {
        $this->authorizeProduct($produto);
        if ($plan->product_id !== $produto->id) {
            abort(404);
        }
        $plan->delete();
        return back()->with('success', 'Plano removido.');
    }

    private function authorizeProduct(Product $produto): void
    {
        $tenantId = auth()->user()->tenant_id;
        if ($produto->tenant_id !== $tenantId) {
            abort(403);
        }

        if (auth()->user()->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            if (! in_array($produto->id, $allowed, true)) {
                abort(403);
            }
        }
    }

    private function productToArray(Product $p, array $rates): array
    {
        $priceBrl = (float) $p->price;
        $currency = $p->currency ?? 'BRL';
        if ($currency !== 'BRL') {
            $priceBrl = $currency === 'EUR' ? $priceBrl / ($rates['brl_eur'] ?? 0.16) : $priceBrl / ($rates['brl_usd'] ?? 0.18);
        }
        $priceEur = round($priceBrl * ($rates['brl_eur'] ?? 0.16), 2);
        $priceUsd = round($priceBrl * ($rates['brl_usd'] ?? 0.18), 2);

        $imageUrl = $p->image
            ? app(StorageService::class)->url($p->image)
            : null;

        $config = Product::typeConfig()[$p->type] ?? null;
        $typeLabel = $config['label'] ?? $p->type;

        $billingLabels = Product::billingTypeLabels();
        $billingType = $p->billing_type ?? Product::BILLING_ONE_TIME;

        return [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'checkout_slug' => $p->checkout_slug,
            'description' => $p->description,
            'type' => $p->type,
            'type_label' => $typeLabel,
            'billing_type' => $billingType,
            'billing_type_label' => $billingLabels[$billingType] ?? $billingType,
            'image' => $p->image,
            'image_url' => $imageUrl,
            'price' => (float) $p->price,
            'currency' => $p->currency ?? 'BRL',
            'price_brl' => round($priceBrl, 2),
            'price_eur' => $priceEur,
            'price_usd' => $priceUsd,
            'is_active' => $p->is_active,
            'conversion_pixels' => $p->conversion_pixels,
            'combo_product_ids' => $p->combo_product_ids ?? [],
        ];
    }

    /**
     * @param  mixed  $input
     * @return array<int, string>
     */
    private function assertValidComboProductIdsForHost(?int $tenantId, string $hostProductId, $input): array
    {
        if ($input === null || $input === '' || $input === []) {
            return [];
        }
        $ids = is_array($input) ? $input : [$input];
        $ids = array_values(array_unique(array_filter(array_map(static function ($v) {
            if ($v === null || $v === '') {
                return null;
            }

            return is_string($v) || is_numeric($v) ? (string) $v : null;
        }, $ids))));

        $out = [];
        foreach ($ids as $id) {
            $combo = Product::forTenant($tenantId)->where('id', $id)->first();
            if (! $combo || $combo->id === $hostProductId) {
                throw ValidationException::withMessages([
                    'combo_product_ids' => ['Produto de combo inválido.'],
                ]);
            }
            if (! $combo->is_active) {
                throw ValidationException::withMessages([
                    'combo_product_ids' => ['O produto do combo deve estar ativo: '.$combo->name],
                ]);
            }
            $out[] = $combo->id;
        }

        return array_values(array_unique($out));
    }

    /**
     * Garante que o produto, uma oferta ou um plano tenha checkout_slug (gera se estiver vazio).
     * Redireciona de volta à edição do produto para recarregar os dados.
     */
    public function ensureCheckoutSlug(Request $request, Product $produto): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeProduct($produto);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:main,offer,plan'],
            'offer_id' => ['required_if:type,offer', 'nullable', 'integer'],
            'plan_id' => ['required_if:type,plan', 'nullable', 'integer'],
        ]);

        $type = $validated['type'];

        if ($type === 'main') {
            if (empty($produto->checkout_slug)) {
                $produto->checkout_slug = Product::generateUniqueCheckoutSlug();
                $produto->save();
            }
            return redirect()->to(route('produtos.edit', $produto) . '?tab=checkout')->with('success', 'Link do checkout (produto base) gerado.');
        }

        if ($type === 'offer') {
            $offer = ProductOffer::where('id', $validated['offer_id'])->where('product_id', $produto->id)->firstOrFail();
            if (empty($offer->checkout_slug)) {
                $offer->checkout_slug = ProductOffer::generateUniqueCheckoutSlug();
                $offer->save();
            }
            return redirect()->to(route('produtos.edit', $produto) . '?tab=checkout')->with('success', 'Link do checkout da oferta gerado.');
        }

        $plan = SubscriptionPlan::where('id', $validated['plan_id'])->where('product_id', $produto->id)->firstOrFail();
        if (empty($plan->checkout_slug)) {
            $plan->checkout_slug = SubscriptionPlan::generateUniqueCheckoutSlug();
            $plan->save();
        }
        return redirect()->to(route('produtos.edit', $produto) . '?tab=checkout')->with('success', 'Link do checkout do plano gerado.');
    }

    /**
     * Remove o checkout exclusivo de uma oferta ou plano (passa a usar o checkout principal).
     * Só aplica a ofertas e planos; o produto principal não pode ter slug removido.
     */
    public function removeCheckoutSlug(Request $request, Product $produto): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeProduct($produto);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:offer,plan'],
            'offer_id' => ['required_if:type,offer', 'nullable', 'integer'],
            'plan_id' => ['required_if:type,plan', 'nullable', 'integer'],
        ]);

        if ($validated['type'] === 'offer') {
            $offer = ProductOffer::where('id', $validated['offer_id'])->where('product_id', $produto->id)->firstOrFail();
            $offer->update(['checkout_slug' => null]);
            return redirect()->to(route('produtos.edit', $produto) . '?tab=checkout')->with('success', 'Checkout exclusivo da oferta removido; ela passará a usar o checkout principal.');
        }

        $plan = SubscriptionPlan::where('id', $validated['plan_id'])->where('product_id', $produto->id)->firstOrFail();
        $plan->update(['checkout_slug' => null]);
        return redirect()->to(route('produtos.edit', $produto) . '?tab=checkout')->with('success', 'Checkout exclusivo do plano removido; ele passará a usar o checkout principal.');
    }

    /**
     * Gateways conectados agrupados por método (pix, card, boleto).
     *
     * @return array{pix: array<int, array{slug: string, name: string}>, card: array, boleto: array, crypto: array}
     */
    private function gatewaysByMethodForTenant(?int $tenantId): array
    {
        $connectedSlugs = GatewayCredential::forTenant($tenantId)
            ->where('is_connected', true)
            ->pluck('gateway_slug')
            ->all();
        $byMethod = ['pix' => [], 'card' => [], 'boleto' => [], 'pix_auto' => [], 'crypto' => []];
        foreach (GatewayRegistry::all() as $gateway) {
            $slug = $gateway['slug'] ?? '';
            if (! in_array($slug, $connectedSlugs, true)) {
                continue;
            }
            $methods = $gateway['methods'] ?? [];
            $item = ['slug' => $slug, 'name' => $gateway['name'] ?? $slug];
            foreach (['pix', 'card', 'boleto', 'pix_auto', 'crypto'] as $method) {
                if (in_array($method, $methods, true)) {
                    $byMethod[$method][] = $item;
                }
            }
        }
        return $byMethod;
    }
}
