<?php

namespace App\Http\Controllers;

use App\Gateways\GatewayRegistry;
use App\Models\GatewayCredential;
use App\Models\Product;
use App\Models\Setting;
use App\Models\CademiIntegration;
use App\Models\SpedyIntegration;
use App\Models\UtmifyIntegration;
use App\Models\Webhook;
use App\Plugins\PluginRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationsController extends Controller
{
    public function index(): Response
    {
        $tenantId = auth()->user()->tenant_id;
        $pluginApps = PluginRegistry::getIntegrationApps();

        // Plugin app badges (ex.: AutoZap "Ativo" quando configurado).
        // Plugins podem ser carregados sem autoload; por isso, usamos require_once quando necessário.
        foreach ($pluginApps as $idx => $app) {
            if (($app['id'] ?? null) !== 'autozap') {
                continue;
            }
            try {
                $pluginDir = PluginRegistry::resolvePluginDirectory('autozap');
                if (is_string($pluginDir) && $pluginDir !== '') {
                    $modelFile = rtrim($pluginDir, '/\\') . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . 'AutoZapConnection.php';
                    if (is_file($modelFile)) {
                        require_once $modelFile;
                    }
                }

                if (class_exists(\Plugins\AutoZap\Models\AutoZapConnection::class)) {
                    $conn = \Plugins\AutoZap\Models\AutoZapConnection::forTenant($tenantId)->first();
                    $isActive = (bool) ($conn?->is_active ?? false);
                    $hasCredentials = (bool) ($conn?->hasCredentials() ?? false);
                    if ($isActive && $hasCredentials) {
                        $pluginApps[$idx]['status'] = 'active';
                    }
                }
            } catch (\Throwable) {
                // Badge é "best-effort": não deve quebrar a página de integrações.
            }
        }

        $gateways = $this->buildGatewaysList($tenantId);
        $gatewayOrderRaw = Setting::get('gateway_order', null, $tenantId);
        $gatewayOrder = is_string($gatewayOrderRaw)
            ? (json_decode($gatewayOrderRaw, true) ?: config('gateways.default_order', ['pix' => [], 'card' => [], 'boleto' => [], 'pix_auto' => []]))
            : (is_array($gatewayOrderRaw) ? $gatewayOrderRaw : config('gateways.default_order', ['pix' => [], 'card' => [], 'boleto' => [], 'pix_auto' => []]));
        $gatewayOrder = [
            'pix' => $gatewayOrder['pix'] ?? [],
            'card' => $gatewayOrder['card'] ?? [],
            'boleto' => $gatewayOrder['boleto'] ?? [],
            'pix_auto' => $gatewayOrder['pix_auto'] ?? [],
        ];

        $webhooks = Webhook::forTenant($tenantId)
            ->with('products:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Webhook $w) => [
                'id' => $w->id,
                'name' => $w->name,
                'url' => $w->url,
                'has_bearer_token' => (bool) $w->bearer_token,
                'events' => $w->events ?? [],
                'is_active' => $w->is_active,
                'products' => $w->products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
            ])
            ->values()
            ->all();

        $webhookEvents = config('webhook_events.events', []);

        $utmifyIntegrations = UtmifyIntegration::forTenant($tenantId)
            ->with('products:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (UtmifyIntegration $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'is_active' => $i->is_active,
                'configured' => $i->api_key !== null && $i->api_key !== '',
                'api_key' => $i->api_key ?? '',
                'product_ids' => $i->products->pluck('id')->values()->all(),
                'products' => $i->products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
            ])
            ->values()
            ->all();

        $spedyIntegrations = SpedyIntegration::forTenant($tenantId)
            ->with('products:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (SpedyIntegration $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'is_active' => $i->is_active,
                'configured' => $i->api_key !== null && $i->api_key !== '',
                'api_key' => $i->api_key ?? '',
                'environment' => $i->environment ?? SpedyIntegration::ENVIRONMENT_PRODUCTION,
                'product_ids' => $i->products->pluck('id')->values()->all(),
                'products' => $i->products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
            ])
            ->values()
            ->all();

        $cademiIntegrations = CademiIntegration::forTenant($tenantId)
            ->with('products:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (CademiIntegration $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'base_url' => $i->base_url,
                'is_active' => $i->is_active,
                'configured' => $i->api_key !== null && $i->api_key !== '',
                'api_key' => $i->api_key ?? '',
                'product_ids' => $i->products->pluck('id')->values()->all(),
                'products' => $i->products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
            ])
            ->values()
            ->all();

        $products = Product::forTenant($tenantId)->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Integrations/Index', [
            'gateways' => $gateways,
            'gateway_order' => $gatewayOrder,
            'webhooks' => $webhooks,
            'webhook_events' => $webhookEvents,
            'utmify_integrations' => $utmifyIntegrations,
            'spedy_integrations' => $spedyIntegrations,
            'cademi_integrations' => $cademiIntegrations,
            'products' => $products,
            'plugin_apps' => $pluginApps,
        ]);
    }

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
                'scope' => $g['scope'] ?? 'national',
                'country' => $g['country'] ?? null,
                'country_name' => $g['country_name'] ?? null,
                'country_flag' => $g['country_flag'] ?? null,
                'countries' => $g['countries'] ?? null,
                'signup_url' => $g['signup_url'] ?? null,
                'is_configured' => $cred !== null,
                'is_connected' => $cred?->is_connected ?? false,
            ];
        }, $all);
    }

    public function enablePlugin(string $slug): RedirectResponse
    {
        $installed = collect(PluginRegistry::installed())->keyBy('slug');
        if (! $installed->has($slug)) {
            return back()->with('error', 'Plugin não encontrado.');
        }
        PluginRegistry::enable($slug);
        return back()->with('success', 'Plugin ativado.');
    }

    public function disablePlugin(string $slug): RedirectResponse
    {
        $installed = collect(PluginRegistry::installed())->keyBy('slug');
        if (! $installed->has($slug)) {
            return back()->with('error', 'Plugin não encontrado.');
        }
        PluginRegistry::disable($slug);
        return back()->with('success', 'Plugin desativado.');
    }

    public function uninstallPlugin(string $slug): RedirectResponse
    {
        $installed = collect(PluginRegistry::installed())->keyBy('slug');
        $plugin = $installed->get($slug);
        if (! $plugin) {
            return back()->with('error', 'Plugin não encontrado.');
        }
        $pluginPath = $plugin['path'] ?? null;
        if (! PluginRegistry::uninstall($slug, $pluginPath)) {
            return back()->with('error', 'Não foi possível excluir o plugin. Verifique permissões da pasta plugins.');
        }
        return back()->with('success', 'Plugin excluído.');
    }
}
