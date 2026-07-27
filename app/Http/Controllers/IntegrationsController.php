<?php

namespace App\Http\Controllers;

use App\Gateways\GatewayRegistry;
use App\Models\GatewayCredential;
use App\Models\Product;
use App\Models\Setting;
use App\Models\CademiIntegration;
use App\Models\ConversionPixelIntegration;
use App\Services\LegacyConversionPixelsMigrator;
use App\Models\SpedyIntegration;
use App\Models\IntegraxConnection;
use App\Models\UtmifyIntegration;
use App\Models\ApiApplication;
use App\Models\Webhook;
use App\Models\InboundWebhookEndpoint;
use App\Models\PixelXIntegration;
use App\Http\Controllers\Integrations\ExternalCheckoutController;
use App\Support\WebhookEventCatalog;
use App\Plugins\PluginExtensionRegistry;
use App\Plugins\PluginRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationsController extends Controller
{
    public function index(): Response
    {
        $tenantId = auth()->user()->tenant_id;
        $pluginApps = array_values(array_filter(
            PluginRegistry::getIntegrationApps(),
            fn (array $app) => ($app['id'] ?? '') !== 'webhook-entrada'
        ));

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

        foreach ($pluginApps as $idx => $app) {
            $slug = (string) ($app['plugin_slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $ext = PluginExtensionRegistry::getBootstrapExtension($slug);
            $resolver = $ext['integration_status_resolver'] ?? null;
            if (! is_callable($resolver)) {
                continue;
            }
            try {
                if ($resolver($tenantId)) {
                    $pluginApps[$idx]['status'] = 'active';
                }
            } catch (\Throwable) {
                // Contrato genérico em paralelo ao legado; falhas não quebram a página.
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

        $utmifyWith = ['products:id,name'];
        if (Schema::hasTable('utmify_integration_api_application')) {
            $utmifyWith[] = 'apiApplications:id,name';
        }

        $utmifyIntegrations = UtmifyIntegration::forTenant($tenantId)
            ->with($utmifyWith)
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
                'api_application_ids' => $i->relationLoaded('apiApplications')
                    ? $i->apiApplications->pluck('id')->values()->all()
                    : [],
                'api_applications' => $i->relationLoaded('apiApplications')
                    ? $i->apiApplications->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values()->all()
                    : [],
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

        $pixelXIntegrations = [];
        if (Schema::hasTable('pixel_x_integrations')) {
            $pixelXIntegrations = PixelXIntegration::forTenant($tenantId)
                ->with('products:id,name')
                ->orderBy('name')
                ->get()
                ->map(function (PixelXIntegration $i) {
                    $tokenOk = false;
                    try {
                        $token = $i->token;
                        $tokenOk = $token !== null && $token !== '';
                    } catch (\Throwable) {
                        $tokenOk = false;
                    }

                    return [
                        'id' => $i->id,
                        'name' => $i->name,
                        'url' => $i->url,
                        'has_token' => $tokenOk,
                        'events' => $i->events ?? [],
                        'is_active' => $i->is_active,
                        'configured' => $tokenOk,
                        'products' => $i->products->map(fn ($p) => ['id' => (string) $p->id, 'name' => $p->name])->values()->all(),
                        'product_ids' => $i->products->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
                    ];
                })
                ->values()
                ->all();
        }

        $products = Product::forTenant($tenantId)->orderBy('name')->get(['id', 'name']);
        $apiApplications = ApiApplication::forTenant($tenantId)->orderBy('name')->get(['id', 'name']);

        $conversionPixelIntegrations = $this->loadConversionPixelIntegrations($tenantId);
        $externalCheckoutEndpoints = $this->loadExternalCheckoutEndpoints($tenantId);

        $integraxConnection = null;
        if (Schema::hasTable('integrax_connections')) {
            $integraxConnection = IntegraxConnection::forTenant($tenantId)->first();
        }

        return Inertia::render('Integrations/Index', [
            'gateways' => $gateways,
            'gateway_order' => $gatewayOrder,
            'webhooks' => $webhooks,
            'webhook_events' => $webhookEvents,
            'webhook_event_catalog' => WebhookEventCatalog::forUi(),
            'utmify_integrations' => $utmifyIntegrations,
            'spedy_integrations' => $spedyIntegrations,
            'cademi_integrations' => $cademiIntegrations,
            'pixel_x_integrations' => $pixelXIntegrations,
            'products' => $products,
            'api_applications' => $apiApplications,
            'plugin_apps' => $pluginApps,
            'conversion_pixel_integrations' => $conversionPixelIntegrations,
            'external_checkout_endpoints' => $externalCheckoutEndpoints,
            'integrax_connection' => IntegraxController::connectionToArray($integraxConnection),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadConversionPixelIntegrations(?int $tenantId): array
    {
        if (! Schema::hasTable('conversion_pixel_integrations')) {
            return [];
        }

        try {
            $migrator = app(LegacyConversionPixelsMigrator::class);
            if (! $migrator->tenantIsMigrated($tenantId) || $migrator->tenantHasLegacyInlinePixels($tenantId)) {
                $migrator->migrateTenant($tenantId);
            }

            $with = ['products:id,name'];

            return ConversionPixelIntegration::forTenant($tenantId)
                ->with($with)
                ->orderBy('platform')
                ->orderBy('name')
                ->get()
                ->map(fn (ConversionPixelIntegration $i) => ConversionPixelIntegrationController::integrationToArray($i))
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('IntegrationsController: falha ao carregar pixels de conversão', [
                'tenant_id' => $tenantId,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadExternalCheckoutEndpoints(?int $tenantId): array
    {
        if (! Schema::hasTable('inbound_webhook_endpoints')) {
            return [];
        }

        try {
            return InboundWebhookEndpoint::query()
                ->forTenant($tenantId)
                ->orderByDesc('id')
                ->get()
                ->map(fn (InboundWebhookEndpoint $e) => ExternalCheckoutController::serializeEndpoint($e))
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('IntegrationsController: falha ao carregar checkout externo', [
                'tenant_id' => $tenantId,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
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
