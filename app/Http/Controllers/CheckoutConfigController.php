<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Plugins\PluginRegistry;
use App\Services\StorageService;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class CheckoutConfigController extends Controller
{
    public function edit(Request $request, Product $produto): Response
    {
        $this->authorizeProduct($produto);

        $offerId = $request->integer('offer_id', 0) ?: null;
        $planId = $request->integer('plan_id', 0) ?: null;

        $defaults = Product::defaultCheckoutConfig();
        $checkoutSlug = $produto->checkout_slug;
        $scope = ['type' => 'main', 'offer_id' => null, 'plan_id' => null, 'checkout_slug' => $checkoutSlug, 'label' => 'Produto (preço base)'];

        if ($offerId) {
            $offer = ProductOffer::where('id', $offerId)->where('product_id', $produto->id)->firstOrFail();
            $stored = $offer->checkout_config ?? [];
            $config = array_replace_recursive($defaults, $stored);
            $checkoutSlug = $offer->checkout_slug;
            $scope = ['type' => 'offer', 'offer_id' => $offer->id, 'plan_id' => null, 'checkout_slug' => $checkoutSlug, 'label' => 'Oferta: ' . $offer->name];
        } elseif ($planId) {
            $plan = SubscriptionPlan::where('id', $planId)->where('product_id', $produto->id)->firstOrFail();
            $stored = $plan->checkout_config ?? [];
            $config = array_replace_recursive($defaults, $stored);
            $checkoutSlug = $plan->checkout_slug;
            $scope = ['type' => 'plan', 'offer_id' => null, 'plan_id' => $plan->id, 'checkout_slug' => $checkoutSlug, 'label' => 'Plano: ' . $plan->name];
        } else {
            $stored = $produto->checkout_config ?? [];
            $config = array_replace_recursive($defaults, $stored);
        }

        $tenantId = auth()->user()->tenant_id;
        $cupons = Coupon::forTenant($tenantId)
            ->whereHas('products', fn ($q) => $q->where('products.id', $produto->id))
            ->orderBy('code')
            ->get(['id', 'code', 'type', 'value'])
            ->map(fn (Coupon $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'type' => $c->type,
                'value' => (float) $c->value,
            ])
            ->values()
            ->all();

        return Inertia::render('Checkout/Builder', [
            'produto' => [
                'id' => $produto->id,
                'name' => $produto->name,
                'checkout_slug' => $checkoutSlug,
            ],
            'config' => $config,
            'checkout_scope' => $scope,
            'checkout_templates' => PluginRegistry::getCheckoutTemplates(),
            'cupons' => $cupons,
            'layoutFullWidth' => true,
        ]);
    }

    public function update(Request $request, Product $produto): RedirectResponse
    {
        $this->authorizeProduct($produto);

        $defaults = Product::defaultCheckoutConfig();
        $validated = $request->validate([
            'config' => ['required', 'array'],
            'offer_id' => ['nullable', 'integer'],
            'plan_id' => ['nullable', 'integer'],
        ]);

        $offerId = $validated['offer_id'] ?? null;
        $planId = $validated['plan_id'] ?? null;

        $stored = [];
        if ($offerId) {
            $offer = ProductOffer::where('id', $offerId)->where('product_id', $produto->id)->firstOrFail();
            $stored = $offer->checkout_config ?? [];
        } elseif ($planId) {
            $plan = SubscriptionPlan::where('id', $planId)->where('product_id', $produto->id)->firstOrFail();
            $stored = $plan->checkout_config ?? [];
        } else {
            $stored = $produto->checkout_config ?? [];
        }

        $base = array_replace_recursive($defaults, is_array($stored) ? $stored : []);
        $merged = array_replace_recursive($base, $validated['config']);
        $merged = $this->applyCheckoutConfigIndexedArraysFromRequest($merged, $validated['config']);

        // Downsell só pode estar ativo se upsell estiver ativo
        if (!($merged['upsell']['enabled'] ?? false)) {
            $merged['downsell']['enabled'] = false;
        }

        // Oferta/plano: não persistir chaves mantidas só no produto (Builder não as envia; gravar defaults
        // anularia payment_gateways no merge público — ver CheckoutController).
        if (! PluginRegistry::checkoutTemplateExists($merged['template'] ?? 'original')) {
            $merged['template'] = 'original';
        }

        if ($offerId || $planId) {
            foreach (['payment_gateways', 'card_installments', 'stripe_link_enabled', 'deliverable_link', 'email_template'] as $inheritKey) {
                unset($merged[$inheritKey]);
            }
        }

        if ($offerId) {
            $offer->update(['checkout_config' => $merged]);
            return $request->expectsJson()
                ? response()->json(['success' => true, 'message' => 'Checkout da oferta atualizado.'])
                : back()->with('success', 'Checkout da oferta atualizado.');
        }
        if ($planId) {
            $plan->update(['checkout_config' => $merged]);
            return $request->expectsJson()
                ? response()->json(['success' => true, 'message' => 'Checkout do plano atualizado.'])
                : back()->with('success', 'Checkout do plano atualizado.');
        }

        $produto->update(['checkout_config' => $merged]);
        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => 'Checkout atualizado.'])
            : back()->with('success', 'Checkout atualizado.');
    }

    public function uploadImage(Request $request, Product $produto): JsonResponse
    {
        $this->authorizeProduct($produto);

        $request->validate([
            'image' => ['required', 'image', 'max:5120'],
        ]);

        $storage = app(StorageService::class);
        $file = $request->file('image');
        $name = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $storage->putFileAs('checkout/' . $produto->id, $file, $name);

        // Retornamos a URL relativa (/storage/...) para nunca ficarmos amarrados a um host
        // específico no DB. Isso evita problemas de Mixed Content quando a aplicação é
        // servida por um host diferente do APP_URL (ex.: ngrok HTTPS apontando pro Laragon).
        // O navegador resolve / como o host da página atual, então funciona em qualquer ambiente.
        $relativeUrl = '/storage/' . ltrim($path, '/');

        return response()->json(['url' => $relativeUrl], HttpResponse::HTTP_CREATED);
    }

    private function authorizeProduct(Product $produto): void
    {
        $tenantId = auth()->user()->tenant_id;
        if ($produto->tenant_id !== $tenantId) {
            abort(403);
        }
    }

    /**
     * array_replace_recursive não substitui listas indexadas por [] (merge recursivo mantém índices antigos).
     * Sobrescreve chaves que são arrays sequenciais quando o cliente envia o valor completo.
     *
     * @param  array<string, mixed>  $merged
     * @param  array<string, mixed>  $requestConfig
     * @return array<string, mixed>
     */
    private function applyCheckoutConfigIndexedArraysFromRequest(array $merged, array $requestConfig): array
    {
        if (array_key_exists('reviews', $requestConfig)) {
            $merged['reviews'] = is_array($requestConfig['reviews'])
                ? array_values($requestConfig['reviews'])
                : [];
        }

        if (isset($requestConfig['appearance']) && is_array($requestConfig['appearance'])) {
            $appearance = $requestConfig['appearance'];
            if (! isset($merged['appearance']) || ! is_array($merged['appearance'])) {
                $merged['appearance'] = [];
            }
            if (array_key_exists('banners', $appearance)) {
                $merged['appearance']['banners'] = is_array($appearance['banners'])
                    ? array_values($appearance['banners'])
                    : [];
            }
            if (array_key_exists('side_banners', $appearance)) {
                $merged['appearance']['side_banners'] = is_array($appearance['side_banners'])
                    ? array_values($appearance['side_banners'])
                    : [];
            }
        }

        return $merged;
    }
}
