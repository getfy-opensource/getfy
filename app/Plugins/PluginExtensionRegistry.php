<?php

namespace App\Plugins;

use Illuminate\Support\Facades\URL;

/**
 * Agrega extensões registradas em runtime (bootstrap) e manifest frontend (dist/).
 */
class PluginExtensionRegistry
{
    /** @var array<string, array<string, mixed>> */
    private static array $bootstrapExtensions = [];

    /**
     * @param  array<string, mixed>  $extension
     */
    public static function register(string $slug, array $extension = []): void
    {
        $slug = trim($slug);
        if ($slug === '') {
            return;
        }
        self::$bootstrapExtensions[$slug] = array_merge(self::$bootstrapExtensions[$slug] ?? [], $extension);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getBootstrapExtension(string $slug): ?array
    {
        return self::$bootstrapExtensions[$slug] ?? null;
    }

    /**
     * Lê dist/ui.manifest.json do plugin, se existir.
     *
     * @return array<string, mixed>|null
     */
    public static function readUiManifest(string $pluginPath): ?array
    {
        $manifestPath = self::resolveFrontendManifestPath($pluginPath);
        if ($manifestPath === null) {
            return null;
        }
        $raw = file_get_contents($manifestPath);
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    public static function resolveFrontendManifestPath(string $pluginPath): ?string
    {
        $pluginPath = rtrim($pluginPath, DIRECTORY_SEPARATOR);
        $manifest = PluginRegistry::readManifest($pluginPath);
        if (! is_array($manifest)) {
            return null;
        }
        $frontend = $manifest['frontend'] ?? null;
        if (! is_array($frontend)) {
            return null;
        }
        $rel = trim((string) ($frontend['manifest'] ?? 'dist/ui.manifest.json'));
        if ($rel === '') {
            $rel = 'dist/ui.manifest.json';
        }
        $full = $pluginPath.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
        if (! is_file($full)) {
            return null;
        }

        return $full;
    }

    /**
     * Plugin expõe bundle frontend (plugin.json frontend + ui.manifest no disco).
     */
    public static function hasRuntimeFrontend(array $plugin): bool
    {
        $path = (string) ($plugin['path'] ?? '');
        if ($path === '') {
            return false;
        }

        return self::readUiManifest($path) !== null;
    }

    /**
     * URL pública para arquivo em plugins/{slug}/ (assets/ ou dist/).
     */
    public static function assetUrl(string $slug, string $relativePath): ?string
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return null;
        }
        try {
            return URL::route('plugins.asset', ['slug' => $slug, 'path' => $relativePath]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve export Vue para página Inertia (frontend.pages).
     */
    public static function resolvePageExport(array $plugin, string $page): ?string
    {
        $frontend = $plugin['frontend'] ?? null;
        if (! is_array($frontend)) {
            return null;
        }
        $pages = $frontend['pages'] ?? null;
        if (! is_array($pages)) {
            return null;
        }
        if (array_key_exists($page, $pages)) {
            $export = $pages[$page];

            return is_string($export) && $export !== '' ? $export : null;
        }

        return null;
    }

    /**
     * Resolve export name para um slot (settings, integrations, product_panel, checkout.card, …).
     */
    public static function resolveExportForSlot(array $plugin, string $slot): ?string
    {
        $frontend = $plugin['frontend'] ?? null;
        if (! is_array($frontend)) {
            return null;
        }
        $exports = $frontend['exports'] ?? null;
        if (! is_array($exports)) {
            return null;
        }
        if (! array_key_exists($slot, $exports)) {
            return null;
        }
        $value = $exports[$slot];
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (is_array($value)) {
            return null;
        }

        return null;
    }

    /**
     * Script principal do bundle (entry) para carregar exports globais.
     */
    public static function resolveEntryScriptUrl(array $plugin): ?string
    {
        $frontend = $plugin['frontend'] ?? null;
        if (! is_array($frontend)) {
            return null;
        }
        $entry = trim((string) ($frontend['entry'] ?? ''));
        if ($entry === '') {
            $ui = self::readUiManifest((string) ($plugin['path'] ?? ''));
            $chunks = is_array($ui['chunks'] ?? null) ? $ui['chunks'] : [];
            $main = $chunks['main'] ?? $chunks['entry'] ?? null;
            if (is_string($main) && $main !== '') {
                $entry = 'dist/'.$main;
            }
        }
        if ($entry === '') {
            return null;
        }

        return self::assetUrl((string) ($plugin['slug'] ?? ''), $entry);
    }

    /**
     * Metadados de UI para Inertia (painel Vue).
     *
     * @return array{plugins: array<int, array<string, mixed>>}
     */
    public static function inertiaPayload(): array
    {
        $plugins = [];
        foreach (PluginRegistry::enabled() as $plugin) {
            if (! self::hasRuntimeFrontend($plugin)) {
                continue;
            }
            $uiManifest = self::readUiManifest((string) $plugin['path']);
            $exports = [];
            if (is_array($uiManifest['exports'] ?? null)) {
                foreach ($uiManifest['exports'] as $name) {
                    if (is_string($name) && $name !== '') {
                        $exports[] = $name;
                    }
                }
            }
            $frontend = is_array($plugin['frontend'] ?? null) ? $plugin['frontend'] : [];
            $pages = is_array($frontend['pages'] ?? null) ? $frontend['pages'] : [];

            $plugins[] = [
                'slug' => $plugin['slug'],
                'entry' => self::resolveEntryScriptUrl($plugin),
                'exports' => $exports,
                'frontend_exports_map' => is_array($frontend['exports'] ?? null)
                    ? $frontend['exports']
                    : [],
                'frontend_pages' => $pages,
                'checkout_gateway_slug' => trim((string) ($frontend['checkout_gateway_slug'] ?? '')),
            ];
        }

        return ['plugins' => $plugins];
    }

    /**
     * Enriquece item de settings_tab / integration_app / product_panel com modo de resolução.
     *
     * @param  array<string, mixed>  $plugin
     * @param  array<string, mixed>  $item
     * @param  string  $slotKey  settings | integrations | product_panel
     * @return array<string, mixed>
     */
    public static function enrichUiSlotItem(array $plugin, array $item, string $slotKey): array
    {
        $slug = (string) ($plugin['slug'] ?? '');
        $export = self::resolveExportForSlot($plugin, $slotKey);
        if ($export !== null && self::hasRuntimeFrontend($plugin)) {
            return array_merge($item, [
                'plugin_slug' => $slug,
                'ui_mode' => 'runtime',
                'ui_export' => $export,
            ]);
        }
        $component = trim((string) ($item['component'] ?? ''));
        if ($component !== '' && str_starts_with($component, 'Plugin/')) {
            return array_merge($item, [
                'plugin_slug' => $slug,
                'ui_mode' => 'legacy',
                'ui_export' => null,
            ]);
        }

        return array_merge($item, [
            'plugin_slug' => $slug,
            'ui_mode' => 'legacy',
            'ui_export' => null,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public static function validateFrontend(array $plugin): array
    {
        $errors = [];
        $path = (string) ($plugin['path'] ?? '');
        $frontend = $plugin['frontend'] ?? null;
        if (! is_array($frontend) || $path === '') {
            return $errors;
        }
        $uiManifest = self::readUiManifest($path);
        if ($uiManifest === null) {
            $errors[] = 'frontend.manifest ausente ou inválido (esperado dist/ui.manifest.json).';

            return $errors;
        }
        $available = [];
        if (is_array($uiManifest['exports'] ?? null)) {
            foreach ($uiManifest['exports'] as $name) {
                if (is_string($name)) {
                    $available[$name] = true;
                }
            }
        }

        $pages = $frontend['pages'] ?? null;
        if (is_array($pages)) {
            foreach ($pages as $pageName => $exportName) {
                if (is_string($exportName) && $exportName !== '' && ! isset($available[$exportName])) {
                    $errors[] = "frontend.pages.{$pageName} → {$exportName} não listado em ui.manifest.json";
                }
            }
        }

        $declared = $frontend['exports'] ?? null;
        if (! is_array($declared) || $declared === []) {
            if ($errors === [] && ($pages === null || $pages === [])) {
                $errors[] = 'frontend.exports ou frontend.pages devem ser declarados quando há bundle UI.';
            }
            if ($entry = self::resolveEntryScriptUrl($plugin)) {
                // entry ok
            } elseif ($errors === []) {
                $errors[] = 'frontend.entry não resolvido (arquivo JS do bundle ausente).';
            }

            return $errors;
        }

        foreach ($declared as $slot => $exportName) {
            if (is_array($exportName)) {
                foreach ($exportName as $method => $methodExport) {
                    if (is_string($methodExport) && $methodExport !== '' && ! isset($available[$methodExport])) {
                        $errors[] = "export checkout.{$method} → {$methodExport} não listado em ui.manifest.json";
                    }
                }

                continue;
            }
            if (is_string($exportName) && $exportName !== '' && ! isset($available[$exportName])) {
                $errors[] = "export {$slot} → {$exportName} não listado em ui.manifest.json";
            }
        }
        $entry = self::resolveEntryScriptUrl($plugin);
        if ($entry === null) {
            $errors[] = 'frontend.entry não resolvido (arquivo JS do bundle ausente).';
        }

        return $errors;
    }

    /**
     * Checkout builder templates declarados nos plugins.
     *
     * Campos opcionais por template:
     * - core_layout: chave de layout no core (ex.: "ticto") — plugin thin, sem bundle
     * - ui_variant: skin do CheckoutForm / payment / bumps (ex.: "ticto")
     * - features: lista de flags para o Builder (ex.: order_bump_inner_color)
     *
     * @return array<int, array{id: string, name: string, description: ?string, plugin_slug: string, core_layout: ?string, ui_variant: ?string, features: array<int, string>}>
     */
    public static function getCheckoutBuilderTemplates(): array
    {
        $items = [];
        foreach (PluginRegistry::enabled() as $plugin) {
            $templates = $plugin['checkout_builder_templates'] ?? null;
            if (! is_array($templates)) {
                continue;
            }
            foreach ($templates as $tpl) {
                if (! is_array($tpl)) {
                    continue;
                }
                $normalized = self::normalizeCheckoutBuilderTemplate($tpl, (string) ($plugin['slug'] ?? ''));
                if ($normalized !== null) {
                    $items[] = $normalized;
                }
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $tpl
     * @return array{id: string, name: string, description: ?string, plugin_slug: string, core_layout: ?string, ui_variant: ?string, features: array<int, string>}|null
     */
    public static function normalizeCheckoutBuilderTemplate(array $tpl, string $pluginSlug): ?array
    {
        $id = trim((string) ($tpl['id'] ?? ''));
        $name = trim((string) ($tpl['name'] ?? $id));
        if ($id === '' || $name === '') {
            return null;
        }

        $coreLayout = trim((string) ($tpl['core_layout'] ?? ''));
        $uiVariant = trim((string) ($tpl['ui_variant'] ?? ''));
        $features = [];
        if (is_array($tpl['features'] ?? null)) {
            foreach ($tpl['features'] as $feature) {
                if (! is_string($feature)) {
                    continue;
                }
                $feature = trim($feature);
                if ($feature !== '') {
                    $features[] = $feature;
                }
            }
        }

        return [
            'id' => $id,
            'name' => $name,
            'description' => isset($tpl['description']) ? trim((string) $tpl['description']) : null,
            'plugin_slug' => $pluginSlug,
            'core_layout' => $coreLayout !== '' ? $coreLayout : null,
            'ui_variant' => $uiVariant !== '' ? $uiVariant : null,
            'features' => array_values(array_unique($features)),
        ];
    }

    /**
     * Resolve o template ativo do checkout público (id ≠ original).
     *
     * Prioridade:
     * 1) frontend.exports.checkout_template + entry (plugin full)
     * 2) core_layout no manifesto (plugin thin — layout no core)
     *
     * @return array{id: string, plugin_slug: string, export: ?string, entry: ?string, core_layout: ?string, ui_variant: ?string, features: array<int, string>}|null
     */
    public static function resolveActiveCheckoutTemplate(?string $templateId): ?array
    {
        $id = trim((string) $templateId);
        if ($id === '' || $id === 'original') {
            return null;
        }

        foreach (PluginRegistry::enabled() as $plugin) {
            $templates = $plugin['checkout_builder_templates'] ?? null;
            if (! is_array($templates)) {
                continue;
            }
            $matchedTpl = null;
            foreach ($templates as $tpl) {
                if (! is_array($tpl)) {
                    continue;
                }
                if (trim((string) ($tpl['id'] ?? '')) === $id) {
                    $matchedTpl = $tpl;
                    break;
                }
            }
            if ($matchedTpl === null) {
                continue;
            }

            $normalized = self::normalizeCheckoutBuilderTemplate($matchedTpl, (string) ($plugin['slug'] ?? ''));
            if ($normalized === null) {
                continue;
            }

            $frontend = is_array($plugin['frontend'] ?? null) ? $plugin['frontend'] : [];
            $exports = is_array($frontend['exports'] ?? null) ? $frontend['exports'] : [];
            $export = $exports['checkout_template'] ?? null;
            $exportName = is_string($export) && trim($export) !== '' ? trim($export) : null;

            return [
                'id' => $normalized['id'],
                'plugin_slug' => $normalized['plugin_slug'],
                'export' => $exportName,
                'entry' => $exportName ? self::resolveEntryScriptUrl($plugin) : null,
                'core_layout' => $normalized['core_layout'],
                'ui_variant' => $normalized['ui_variant'],
                'features' => $normalized['features'],
            ];
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getProductCardActions(): array
    {
        $items = [];
        foreach (PluginRegistry::enabled() as $plugin) {
            $actions = $plugin['product_card_actions'] ?? null;
            if (! is_array($actions)) {
                continue;
            }
            foreach ($actions as $action) {
                if (! is_array($action)) {
                    continue;
                }
                $items[] = array_merge($action, ['plugin_slug' => $plugin['slug']]);
            }
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getProductFormSections(): array
    {
        return self::collectSlotItemsWithUi('product_form_sections', 'product_form');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getDashboardWidgets(): array
    {
        $items = [];
        foreach (PluginRegistry::enabled() as $plugin) {
            $widgets = $plugin['dashboard_widgets'] ?? null;
            if (! is_array($widgets)) {
                continue;
            }
            $exports = is_array($plugin['frontend']['exports'] ?? null) ? $plugin['frontend']['exports'] : [];
            foreach ($widgets as $widget) {
                if (! is_array($widget)) {
                    continue;
                }
                $row = array_merge($widget, ['plugin_slug' => $plugin['slug']]);
                $exportName = null;
                if (! empty($widget['ui_export']) && is_string($widget['ui_export'])) {
                    $exportName = $widget['ui_export'];
                } elseif (! empty($widget['id']) && is_string($exports['dashboard'] ?? null)) {
                    $exportName = $exports['dashboard'];
                } elseif (! empty($widget['id']) && is_array($exports['dashboard'] ?? null)) {
                    $exportName = $exports['dashboard'][$widget['id']] ?? null;
                }
                if (is_string($exportName) && $exportName !== '' && self::hasRuntimeFrontend($plugin)) {
                    $row['ui_mode'] = 'runtime';
                    $row['ui_export'] = $exportName;
                }
                $items[] = $row;
            }
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getMemberAreaPanels(): array
    {
        $items = [];
        foreach (PluginRegistry::enabled() as $plugin) {
            $panels = $plugin['member_area_panels'] ?? null;
            if (! is_array($panels)) {
                continue;
            }
            foreach ($panels as $panel) {
                if (! is_array($panel)) {
                    continue;
                }
                $row = array_merge($panel, ['plugin_slug' => $plugin['slug']]);
                $export = null;
                if (is_array($plugin['frontend']['member_area'] ?? null)) {
                    $id = (string) ($panel['id'] ?? '');
                    $export = $plugin['frontend']['member_area'][$id] ?? null;
                }
                if (is_string($export) && $export !== '' && self::hasRuntimeFrontend($plugin)) {
                    $row['ui_mode'] = 'runtime';
                    $row['ui_export'] = $export;
                } else {
                    $row['ui_mode'] = 'legacy';
                }
                $items[] = $row;
            }
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getOrderFulfillmentProviders(): array
    {
        return self::collectSlotItemsWithUi('order_fulfillment_providers', 'fulfillment');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getVendasRowActions(): array
    {
        return self::collectSlotItemsWithUi('vendas_row_actions', 'vendas');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getOrderDetailPanels(): array
    {
        return self::collectSlotItemsWithUi('order_detail_panels', 'order_detail');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getFinanceiroTabs(): array
    {
        return self::collectSlotItemsWithUi('financeiro_tabs', 'financeiro');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getMemberBuilderTabs(): array
    {
        return self::collectSlotItemsWithUi('member_builder_tabs', 'member_builder');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function collectSlotItems(string $manifestKey): array
    {
        $items = [];
        foreach (PluginRegistry::enabled() as $plugin) {
            $decl = $plugin[$manifestKey] ?? null;
            if (! is_array($decl)) {
                continue;
            }
            foreach ($decl as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $items[] = array_merge($item, ['plugin_slug' => $plugin['slug']]);
            }
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function collectSlotItemsWithUi(string $manifestKey, string $exportSlot): array
    {
        $items = [];
        foreach (PluginRegistry::enabled() as $plugin) {
            $decl = $plugin[$manifestKey] ?? null;
            if (! is_array($decl)) {
                continue;
            }
            $exports = is_array($plugin['frontend']['exports'] ?? null) ? $plugin['frontend']['exports'] : [];
            foreach ($decl as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $row = array_merge($item, ['plugin_slug' => $plugin['slug']]);
                $exportName = null;
                if (! empty($item['ui_export']) && is_string($item['ui_export'])) {
                    $exportName = $item['ui_export'];
                } elseif (! empty($item['id']) && is_string($exports[$exportSlot] ?? null)) {
                    $exportName = $exports[$exportSlot];
                } elseif (! empty($item['id']) && is_array($exports[$exportSlot] ?? null)) {
                    $exportName = $exports[$exportSlot][$item['id']] ?? null;
                }
                if (is_string($exportName) && $exportName !== '' && self::hasRuntimeFrontend($plugin)) {
                    $row['ui_mode'] = 'runtime';
                    $row['ui_export'] = $exportName;
                }
                $items[] = $row;
            }
        }

        return $items;
    }

    /**
     * Normaliza product_card_actions (route → href, component runtime).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getNormalizedProductCardActions(): array
    {
        $items = [];
        foreach (self::collectSlotItemsWithUi('product_card_actions', 'product_card') as $action) {
            $slug = (string) ($action['plugin_slug'] ?? '');
            $row = $action;
            if (! empty($action['route']) && empty($action['href'])) {
                $route = (string) $action['route'];
                $row['href'] = str_starts_with($route, '/') ? $route : '/'.$slug.'/'.ltrim($route, '/');
            }
            $items[] = $row;
        }

        return $items;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function getAllRenderZones(): array
    {
        $byZone = [];
        foreach (PluginRegistry::enabled() as $plugin) {
            $zones = $plugin['render_zones'] ?? null;
            if (! is_array($zones)) {
                continue;
            }
            $slug = (string) ($plugin['slug'] ?? '');
            if ($slug === '' || ! self::hasRuntimeFrontend($plugin)) {
                continue;
            }
            foreach ($zones as $zoneId => $decl) {
                if (! is_string($zoneId) || trim($zoneId) === '' || ! is_array($decl)) {
                    continue;
                }
                $export = trim((string) ($decl['export'] ?? ''));
                if ($export === '') {
                    continue;
                }
                $byZone[$zoneId][] = array_merge($decl, [
                    'id' => (string) ($decl['id'] ?? $zoneId.'-'.$slug),
                    'plugin_slug' => $slug,
                    'ui_mode' => 'runtime',
                    'ui_export' => $export,
                ]);
            }
        }

        return PluginHookBus::applyFilters('plugin.render_zones', $byZone);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getRenderZoneItems(string $zoneId): array
    {
        $zones = self::getAllRenderZones();

        return $zones[$zoneId] ?? [];
    }

    public static function resetForTesting(): void
    {
        self::$bootstrapExtensions = [];
    }
}
