<?php

namespace App\Http\Controllers;

use App\Gateways\GatewayRegistry;
use App\Models\ApiApplication;
use App\Models\GatewayCredential;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ApiApplicationsController extends Controller
{
    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildGatewaysList(?int $tenantId): array
    {
        $all = GatewayRegistry::all();
        $credentialBySlug = GatewayCredential::forTenant($tenantId)->get()->keyBy('gateway_slug');

        return array_map(function ($g) use ($credentialBySlug) {
            $cred = $credentialBySlug->get($g['slug'] ?? '');
            $image = $g['image'] ?? null;
            return [
                'slug' => $g['slug'],
                'name' => $g['name'],
                'image' => GatewayRegistry::resolveImageUrl(is_string($image) ? $image : null),
                'methods' => $g['methods'] ?? [],
            ];
        }, $all);
    }

    /**
     * Gateways grouped by method (pix, card, boleto, etc.) for dropdowns.
     *
     * @return array<string, array<int, array{slug: string, name: string, image: string|null}>>
     */
    private function gatewaysByMethod(?int $tenantId): array
    {
        $list = $this->buildGatewaysList($tenantId);
        $byMethod = ['pix' => [], 'card' => [], 'boleto' => [], 'pix_auto' => [], 'crypto' => []];
        foreach ($list as $g) {
            foreach ($g['methods'] ?? [] as $method) {
                if (isset($byMethod[$method])) {
                    $byMethod[$method][] = ['slug' => $g['slug'], 'name' => $g['name'], 'image' => $g['image'] ?? null];
                }
            }
        }
        return $byMethod;
    }

    public function index(): Response
    {
        $tenantId = auth()->user()->tenant_id;
        $applications = ApiApplication::forTenant($tenantId)
            ->orderBy('name')
            ->get()
            ->map(fn (ApiApplication $app) => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
                'is_active' => $app->is_active,
                'webhook_url' => $app->webhook_url,
                'created_at' => $app->created_at?->toIso8601String(),
            ]);

        return Inertia::render('ApiApplications/Index', [
            'applications' => $applications,
        ]);
    }

    public function create(): Response
    {
        $tenantId = auth()->user()->tenant_id;
        $gatewaysByMethod = $this->gatewaysByMethod($tenantId);
        $defaultPaymentGateways = ApiApplication::defaultPaymentGateways();

        return Inertia::render('ApiApplications/Create', [
            'gateways_by_method' => $gatewaysByMethod,
            'default_payment_gateways' => $defaultPaymentGateways,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
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
            'webhook_url' => ['nullable', 'string', 'url', 'max:512'],
            'default_return_url' => ['nullable', 'string', 'url', 'max:512'],
            'webhook_secret' => ['nullable', 'string', 'max:64'],
            'allowed_ips' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'checkout_sidebar_bg' => ['nullable', 'string', 'max:32', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
        ]);

        $slug = ApiApplication::generateUniqueSlug($tenantId, $validated['name']);
        $plainKey = 'getfy_' . Str::random(12) . '_' . Str::random(32);
        $apiKeyHash = ApiApplication::hashApiKey($plainKey);

        $pg = $validated['payment_gateways'] ?? [];
        $paymentGateways = [
            'pix' => ! empty($pg['pix']) ? $pg['pix'] : null,
            'pix_redundancy' => array_values(array_filter(array_map(fn ($s) => is_string($s) ? trim($s) : '', $pg['pix_redundancy'] ?? []))),
            'card' => ! empty($pg['card']) ? $pg['card'] : null,
            'card_redundancy' => array_values(array_filter(array_map(fn ($s) => is_string($s) ? trim($s) : '', $pg['card_redundancy'] ?? []))),
            'boleto' => ! empty($pg['boleto']) ? $pg['boleto'] : null,
            'boleto_redundancy' => array_values(array_filter(array_map(fn ($s) => is_string($s) ? trim($s) : '', $pg['boleto_redundancy'] ?? []))),
            'pix_auto' => ! empty($pg['pix_auto']) ? $pg['pix_auto'] : null,
            'pix_auto_redundancy' => array_values(array_filter(array_map(fn ($s) => is_string($s) ? trim($s) : '', $pg['pix_auto_redundancy'] ?? []))),
            'crypto' => ! empty($pg['crypto']) ? $pg['crypto'] : null,
            'crypto_redundancy' => array_values(array_filter(array_map(fn ($s) => is_string($s) ? trim($s) : '', $pg['crypto_redundancy'] ?? []))),
        ];

        $allowedIps = [];
        if (! empty($validated['allowed_ips'])) {
            $lines = preg_split('/\s*[\r\n,]+\s*/', trim($validated['allowed_ips']), -1, PREG_SPLIT_NO_EMPTY);
            $allowedIps = array_values(array_unique(array_filter(array_map('trim', $lines))));
        }

        $app = ApiApplication::create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'slug' => $slug,
            'api_key_hash' => $apiKeyHash,
            'payment_gateways' => $paymentGateways,
            'allowed_ips' => $allowedIps,
            'webhook_url' => $validated['webhook_url'] ?? null,
            'default_return_url' => $validated['default_return_url'] ?? null,
            'webhook_secret' => $validated['webhook_secret'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'checkout_sidebar_bg' => $this->normalizeCheckoutSidebarBg($validated['checkout_sidebar_bg'] ?? null),
        ]);

        return redirect()
            ->route('api-applications.edit', $app)
            ->with('api_key_reveal', $plainKey)
            ->with('success', 'Aplicação criada. Copie a API key abaixo; ela não será exibida novamente.');
    }

    public function edit(ApiApplication $apiApplication): Response|RedirectResponse
    {
        $this->authorizeTenant($apiApplication);
        $tenantId = auth()->user()->tenant_id;
        $gatewaysByMethod = $this->gatewaysByMethod($tenantId);
        $pg = $apiApplication->payment_gateways ?? ApiApplication::defaultPaymentGateways();

        $storage = new StorageService($apiApplication->tenant_id);
        $logoUrl = $apiApplication->logo ? $storage->url($apiApplication->logo) : null;

        return Inertia::render('ApiApplications/Edit', [
            'application' => [
                'id' => $apiApplication->id,
                'name' => $apiApplication->name,
                'slug' => $apiApplication->slug,
                'logo_url' => $logoUrl,
                'checkout_sidebar_bg' => $apiApplication->checkout_sidebar_bg,
                'payment_gateways' => $pg,
                'webhook_url' => $apiApplication->webhook_url,
                'default_return_url' => $apiApplication->default_return_url,
                'webhook_secret' => '', // nunca enviar o valor real ao frontend; campo em branco = não alterar
                'allowed_ips' => is_array($apiApplication->allowed_ips) ? implode("\n", $apiApplication->allowed_ips) : '',
                'is_active' => $apiApplication->is_active,
            ],
            'gateways_by_method' => $gatewaysByMethod,
            'api_key_reveal' => session('api_key_reveal'),
        ]);
    }

    public function update(Request $request, ApiApplication $apiApplication): RedirectResponse
    {
        $this->authorizeTenant($apiApplication);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
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
            'webhook_url' => ['nullable', 'string', 'url', 'max:512'],
            'default_return_url' => ['nullable', 'string', 'url', 'max:512'],
            'webhook_secret' => ['nullable', 'string', 'max:64'],
            'allowed_ips' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'checkout_sidebar_bg' => ['nullable', 'string', 'max:32', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
        ]);

        $pg = $validated['payment_gateways'] ?? [];
        $paymentGateways = [
            'pix' => ! empty($pg['pix']) ? $pg['pix'] : null,
            'pix_redundancy' => array_values(array_filter(array_map(fn ($s) => is_string($s) ? trim($s) : '', $pg['pix_redundancy'] ?? []))),
            'card' => ! empty($pg['card']) ? $pg['card'] : null,
            'card_redundancy' => array_values(array_filter(array_map(fn ($s) => is_string($s) ? trim($s) : '', $pg['card_redundancy'] ?? []))),
            'boleto' => ! empty($pg['boleto']) ? $pg['boleto'] : null,
            'boleto_redundancy' => array_values(array_filter(array_map(fn ($s) => is_string($s) ? trim($s) : '', $pg['boleto_redundancy'] ?? []))),
            'pix_auto' => ! empty($pg['pix_auto']) ? $pg['pix_auto'] : null,
            'pix_auto_redundancy' => array_values(array_filter(array_map(fn ($s) => is_string($s) ? trim($s) : '', $pg['pix_auto_redundancy'] ?? []))),
            'crypto' => ! empty($pg['crypto']) ? $pg['crypto'] : null,
            'crypto_redundancy' => array_values(array_filter(array_map(fn ($s) => is_string($s) ? trim($s) : '', $pg['crypto_redundancy'] ?? []))),
        ];

        $allowedIps = [];
        if (! empty($validated['allowed_ips'])) {
            $lines = preg_split('/\s*[\r\n,]+\s*/', trim($validated['allowed_ips']), -1, PREG_SPLIT_NO_EMPTY);
            $allowedIps = array_values(array_unique(array_filter(array_map('trim', $lines))));
        }

        $webhookSecret = $validated['webhook_secret'] ?? '';
        $apiApplication->update([
            'name' => $validated['name'],
            'payment_gateways' => $paymentGateways,
            'allowed_ips' => $allowedIps,
            'webhook_url' => $validated['webhook_url'] ?? null,
            'default_return_url' => $validated['default_return_url'] ?? null,
            'webhook_secret' => strlen($webhookSecret) > 0 ? $webhookSecret : $apiApplication->webhook_secret,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'checkout_sidebar_bg' => $this->normalizeCheckoutSidebarBg($validated['checkout_sidebar_bg'] ?? null),
        ]);

        return redirect()->route('api-applications.index')->with('success', 'Aplicação atualizada.');
    }

    public function destroy(ApiApplication $apiApplication): RedirectResponse
    {
        $this->authorizeTenant($apiApplication);
        $apiApplication->delete();
        return redirect()->route('api-applications.index')->with('success', 'Aplicação removida.');
    }

    public function regenerateKey(ApiApplication $apiApplication): RedirectResponse
    {
        $this->authorizeTenant($apiApplication);
        $plainKey = 'getfy_' . Str::random(12) . '_' . Str::random(32);
        $apiApplication->update(['api_key_hash' => ApiApplication::hashApiKey($plainKey)]);

        return redirect()
            ->route('api-applications.edit', $apiApplication)
            ->with('api_key_reveal', $plainKey)
            ->with('success', 'Nova API key gerada. Copie-a abaixo; a key anterior deixa de funcionar.');
    }

    public function uploadLogo(Request $request, ApiApplication $apiApplication): JsonResponse
    {
        $this->authorizeTenant($apiApplication);

        $request->validate([
            'image' => ['required', 'image', 'max:5120'],
        ]);

        $storage = new StorageService($apiApplication->tenant_id);
        $oldPath = $apiApplication->logo;
        if ($oldPath && $storage->exists($oldPath)) {
            $storage->delete($oldPath);
        }

        $file = $request->file('image');
        $name = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $storage->putFileAs('api-applications/' . $apiApplication->id, $file, $name);
        $apiApplication->update(['logo' => $path]);
        $url = $storage->url($path);

        return response()->json(['url' => $url], HttpResponse::HTTP_CREATED);
    }

    public function removeLogo(ApiApplication $apiApplication): JsonResponse
    {
        $this->authorizeTenant($apiApplication);

        $storage = new StorageService($apiApplication->tenant_id);
        $oldPath = $apiApplication->logo;
        if ($oldPath && $storage->exists($oldPath)) {
            $storage->delete($oldPath);
        }
        $apiApplication->update(['logo' => null]);

        return response()->json(['success' => true]);
    }

    private function authorizeTenant(ApiApplication $apiApplication): void
    {
        $tenantId = auth()->user()->tenant_id;
        if ($apiApplication->tenant_id !== $tenantId) {
            abort(404);
        }
    }

    /** Default black (#18181b = zinc-900); store null when default. */
    private function normalizeCheckoutSidebarBg(?string $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '' || $v === '#18181b') {
            return null;
        }
        return $v;
    }
}
