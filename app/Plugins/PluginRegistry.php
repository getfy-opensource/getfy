<?php

namespace App\Plugins;

use App\Models\Plugin as PluginModel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class PluginRegistry
{
    /**
     * Plugins versionados com o repositório (ex.: example-gateway).
     */
    public static function bundledPluginsPath(): string
    {
        return rtrim(base_path('plugins'), '/\\');
    }

    /**
     * Pasta persistente para instalações via ZIP/loja.
     *
     * Em Docker (GETFY_DOCKER=true): `.docker/plugins-installed` — fica no volume `getfy_env` montado em `.docker/`,
     * independente de `storage/` (útil quando o update do contentor recria ou substitui dados em storage).
     * Fora de Docker: {@see storage_path}('app/plugins-installed').
     * Override absoluto: GETFY_PLUGINS_USER_PATH no .env.
     */
    public static function userInstallRoot(): string
    {
        $configured = config('plugins.user_install_path');
        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), '/\\');
        }

        if (config('plugins.docker_mode')) {
            return rtrim(base_path('.docker/plugins-installed'), '/\\');
        }

        return rtrim(storage_path('app/plugins-installed'), '/\\');
    }

    /**
     * Migra pastas de plugins de locais antigos para {@see userInstallRoot()}.
     * Marcadores: storage/app (raiz do projeto) e .docker/ (cópia desde storage quando em Docker).
     */
    public static function migrateLegacyPluginInstallDirectories(): void
    {
        try {
            $destRoot = self::userInstallRoot();
            if (! is_dir($destRoot)) {
                File::makeDirectory($destRoot, 0755, true);
            }

            $markerProjectRoot = storage_path('app/.getfy-migrated-plugins-from-project-root');
            if (! is_file($markerProjectRoot)) {
                self::migratePluginSubdirsIfPresent(rtrim(base_path('plugins-installed'), '/\\'), $destRoot);
                @file_put_contents($markerProjectRoot, (string) time());
            }

            if (config('plugins.docker_mode')) {
                $markerFromStorage = base_path('.docker/.getfy-migrated-plugins-from-storage-app');
                if (! is_file($markerFromStorage)) {
                    self::migratePluginSubdirsIfPresent(rtrim(storage_path('app/plugins-installed'), '/\\'), $destRoot);
                    @file_put_contents($markerFromStorage, (string) time());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Getfy: migração de pasta legacy de plugins falhou.', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Move cada subpasta com plugin.json de $legacy para $destRoot (não sobrescreve destinos já existentes).
     */
    private static function migratePluginSubdirsIfPresent(string $legacy, string $destRoot): void
    {
        if ($legacy === '' || ! is_dir($legacy)) {
            return;
        }
        $legacyReal = realpath($legacy);
        $destReal = realpath($destRoot);
        if ($legacyReal === false) {
            return;
        }
        $destResolved = $destReal !== false ? $destReal : $destRoot;
        if ($legacyReal === $destResolved || str_starts_with($destResolved, $legacyReal.DIRECTORY_SEPARATOR)) {
            return;
        }

        $items = @scandir($legacy);
        if (! is_array($items)) {
            return;
        }
        foreach (array_diff($items, ['.', '..']) as $name) {
            $from = $legacy.DIRECTORY_SEPARATOR.$name;
            if (! is_dir($from) || ! is_file($from.DIRECTORY_SEPARATOR.'plugin.json')) {
                continue;
            }
            $to = $destRoot.DIRECTORY_SEPARATOR.$name;
            if (is_dir($to)) {
                continue;
            }
            File::moveDirectory($from, $to);
        }

        $left = @scandir($legacy);
        if (is_array($left) && count(array_diff($left, ['.', '..'])) === 0) {
            @rmdir($legacy);
        }
    }

    /**
     * @return list<string> Raízes na ordem: bundled → instalações do utilizador → extras (.env).
     *                      O mesmo slug em raízes posteriores sobrepõe manifestos anteriores.
     */
    public static function discoveryRoots(): array
    {
        $roots = [];
        $roots[] = self::bundledPluginsPath();
        $user = self::userInstallRoot();
        if ($user !== '' && ! in_array($user, $roots, true)) {
            $roots[] = $user;
        }
        $extras = config('plugins.extra_scan_paths', []);
        if (is_array($extras)) {
            foreach ($extras as $extra) {
                if (! is_string($extra)) {
                    continue;
                }
                $e = rtrim(trim($extra), '/\\');
                if ($e !== '' && ! in_array($e, $roots, true)) {
                    $roots[] = $e;
                }
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * Garante a pasta de instalação persistente e devolve o caminho canónico quando possível.
     */
    public static function ensureUserInstallRoot(): string
    {
        $path = self::userInstallRoot();
        if (! is_dir($path)) {
            File::makeDirectory($path, 0755, true);
        }

        return realpath($path) ?: $path;
    }

    /**
     * @deprecated Utilize userInstallRoot() ou discoveryRoots(). Mantido: destino de escrita por omissão.
     */
    public static function pluginsPath(): string
    {
        return self::userInstallRoot();
    }

    /**
     * Diretório absoluto do plugin no disco (bundled ou persistente), ou null.
     */
    public static function resolvePluginDirectory(string $slug): ?string
    {
        foreach (self::installed() as $p) {
            if (($p['slug'] ?? '') === $slug) {
                $dir = $p['path'] ?? null;
                if (is_string($dir) && is_dir($dir)) {
                    return $dir;
                }
            }
        }

        return null;
    }

    /**
     * Quando a tabela `plugins` ainda não existe: carregar todos os manifestos do disco como ativos.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fallbackRowsWithoutDatabase(): array
    {
        $rows = [];
        foreach (self::collectDiskPluginsBySlug() as $row) {
            $rows[] = array_merge($row, [
                'is_registered' => false,
                'is_enabled' => true,
            ]);
        }

        return $rows;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function collectDiskPluginsBySlug(): array
    {
        $bySlug = [];
        foreach (self::discoveryRoots() as $root) {
            if ($root === '' || ! is_dir($root)) {
                continue;
            }
            $dirs = array_filter(glob($root.DIRECTORY_SEPARATOR.'*'), 'is_dir');
            foreach ($dirs as $dir) {
                $manifestFile = $dir.DIRECTORY_SEPARATOR.'plugin.json';
                if (! is_file($manifestFile)) {
                    continue;
                }
                $manifest = self::readManifest($dir);
                if (! $manifest) {
                    continue;
                }
                $slug = $manifest['slug'] ?? basename($dir);
                $row = [
                    'slug' => $slug,
                    'name' => $manifest['name'] ?? $slug,
                    'version' => $manifest['version'] ?? '1.0.0',
                    'path' => $dir,
                    'type' => $manifest['type'] ?? null,
                    'banner' => ! empty($manifest['banner']) ? $manifest['banner'] : null,
                    'category' => ! empty($manifest['category']) ? $manifest['category'] : 'outros',
                    'menu' => $manifest['menu'] ?? null,
                    'routes' => $manifest['routes'] ?? null,
                    'public_routes' => $manifest['public_routes'] ?? null,
                    'events' => $manifest['events'] ?? [],
                    'migrations' => $manifest['migrations'] ?? null,
                    'description' => $manifest['description'] ?? null,
                    'author' => $manifest['author'] ?? null,
                    'settings_tab' => $manifest['settings_tab'] ?? null,
                    'integration_app' => $manifest['integration_app'] ?? null,
                    'product_panel' => $manifest['product_panel'] ?? null,
                    'frontend' => $manifest['frontend'] ?? null,
                    'checkout_builder_templates' => $manifest['checkout_builder_templates'] ?? null,
                    'product_card_actions' => $manifest['product_card_actions'] ?? null,
                    'product_form_sections' => $manifest['product_form_sections'] ?? null,
                    'checkout_extensions' => $manifest['checkout_extensions'] ?? null,
                    'dashboard_widgets' => $manifest['dashboard_widgets'] ?? null,
                    'member_area_panels' => $manifest['member_area_panels'] ?? null,
                    'order_fulfillment_providers' => $manifest['order_fulfillment_providers'] ?? null,
                    'vendas_row_actions' => $manifest['vendas_row_actions'] ?? null,
                    'order_detail_panels' => $manifest['order_detail_panels'] ?? null,
                    'financeiro_tabs' => $manifest['financeiro_tabs'] ?? null,
                    'api_routes' => $manifest['api_routes'] ?? null,
                    'commerce_scopes' => $manifest['commerce_scopes'] ?? null,
                    'commands' => $manifest['commands'] ?? null,
                    'schedule' => $manifest['schedule'] ?? null,
                    'middleware' => $manifest['middleware'] ?? null,
                    'theme' => $manifest['theme'] ?? null,
                    'capabilities' => $manifest['capabilities'] ?? null,
                    'render_zones' => $manifest['render_zones'] ?? null,
                ];

                // Uma instalação persistente (ex.: ZIP) no mesmo slug sobrescreve a pasta bundled.
                // Se o plugin.json da cópia for minimalista, preservar blocos de UI já lidos
                // de uma deteção anterior (ex.: integração / painel no produto).
                if (isset($bySlug[$slug])) {
                    $prev = $bySlug[$slug];
                    foreach (['integration_app', 'product_panel', 'settings_tab', 'theme', 'capabilities', 'render_zones'] as $uiKey) {
                        $val = $row[$uiKey] ?? null;
                        if ($val === null || (is_array($val) && $val === [])) {
                            $p = $prev[$uiKey] ?? null;
                            if ($p !== null && (! is_array($p) || $p !== [])) {
                                $row[$uiKey] = $p;
                            }
                        }
                    }
                }
                $bySlug[$slug] = $row;
            }
        }

        return $bySlug;
    }

    /**
     * List all plugins found on disk (with valid plugin.json).
     * Merges with DB state for is_enabled when table exists.
     *
     * @return array<int, array{slug: string, name: string, version: string, path: string, is_enabled: bool, menu?: array, routes?: string|array, events?: array}>
     */
    public static function installed(): array
    {
        $dbPlugins = [];
        if (self::tableExists()) {
            $dbPlugins = PluginModel::all()->keyBy('slug')->all();
        }

        $result = [];
        foreach (self::collectDiskPluginsBySlug() as $slug => $row) {
            $record = $dbPlugins[$slug] ?? null;
            $isRegistered = $record !== null;
            $isEnabled = $record ? $record->is_enabled : false;

            $result[] = array_merge($row, [
                'is_registered' => $isRegistered,
                'is_enabled' => (bool) $isEnabled,
            ]);
        }

        return $result;
    }

    /**
     * Abas extra em Configurações declaradas no plugin.json (plugins ativos).
     *
     * @return array<int, array{id: string, label: string, component: string}>
     */
    public static function getSettingsTabs(): array
    {
        $items = [];
        foreach (self::enabled() as $plugin) {
            $tab = $plugin['settings_tab'] ?? null;
            if (! is_array($tab)) {
                continue;
            }
            $accepted = self::acceptUiSlot($plugin, $tab, 'settings', ['id', 'label']);
            if ($accepted !== null) {
                $items[] = $accepted;
            }
        }

        return $items;
    }

    /**
     * Apps extras na página de Integrações declarados no plugin.json (plugins ativos).
     *
     * @return array<int, array{id: string, name: string, description?: string, image?: string, component: string}>
     */
    public static function getIntegrationApps(): array
    {
        $items = [];
        foreach (self::enabled() as $plugin) {
            $app = $plugin['integration_app'] ?? null;
            if (! is_array($app)) {
                continue;
            }
            $id = trim((string) ($app['id'] ?? $plugin['slug'] ?? ''));
            $name = trim((string) ($app['name'] ?? $plugin['name'] ?? $id));
            if ($id === '' || $name === '') {
                continue;
            }
            $description = isset($app['description']) ? trim((string) $app['description']) : '';
            $image = isset($app['image']) ? trim((string) $app['image']) : '';

            // If plugin declares a relative image path, serve from /plugins/{slug}/assets/{path}.
            if ($image !== '' && ! str_contains($image, '://') && ! str_starts_with($image, '/')) {
                try {
                    $image = URL::route('plugins.asset', ['slug' => $plugin['slug'], 'path' => $image]);
                } catch (\Throwable) {
                    $image = '';
                }
            }
            $accepted = self::acceptUiSlot($plugin, array_merge($app, [
                'id' => $id,
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'image' => $image !== '' && $image !== null ? $image : null,
            ]), 'integrations', ['id', 'name']);
            if ($accepted !== null) {
                $items[] = $accepted;
            }
        }

        return $items;
    }

    /**
     * Painéis extras na edição de produto declarados no plugin.json (plugins ativos).
     *
     * @return array<int, array{id: string, label: string, component: string}>
     */
    public static function getProductPanels(): array
    {
        $items = [];
        foreach (self::enabled() as $plugin) {
            $panel = $plugin['product_panel'] ?? null;
            if (! is_array($panel)) {
                continue;
            }
            $accepted = self::acceptUiSlot($plugin, $panel, 'product_panel', ['id', 'label']);
            if ($accepted !== null) {
                $items[] = $accepted;
            }
        }

        return $items;
    }

    /**
     * Aceita slot de UI legado (Plugin/...) ou runtime (frontend.exports + dist).
     *
     * @param  array<string, mixed>  $plugin
     * @param  array<string, mixed>  $item
     * @param  list<string>  $requiredKeys
     * @return array<string, mixed>|null
     */
    public static function acceptUiSlot(array $plugin, array $item, string $slot, array $requiredKeys = ['id', 'label']): ?array
    {
        foreach ($requiredKeys as $key) {
            $val = trim((string) ($item[$key] ?? ''));
            if ($val === '') {
                $fallback = $key === 'label' ? ($item['name'] ?? $plugin['name'] ?? '') : ($plugin['slug'] ?? '');
                $val = trim((string) $fallback);
            }
            if ($val === '') {
                return null;
            }
            $item[$key] = $val;
        }

        $component = trim((string) ($item['component'] ?? ''));
        if ($component !== '' && str_starts_with($component, 'Plugin/')) {
            return PluginExtensionRegistry::enrichUiSlotItem($plugin, $item, $slot);
        }
        if (PluginExtensionRegistry::hasRuntimeFrontend($plugin)) {
            $export = PluginExtensionRegistry::resolveExportForSlot($plugin, $slot);
            if ($export !== null && $export !== '') {
                $item['component'] = $component;

                return PluginExtensionRegistry::enrichUiSlotItem($plugin, $item, $slot);
            }
        }
        if ($component !== '' && self::isValidUiComponentDeclaration($plugin, $component, $slot)) {
            return PluginExtensionRegistry::enrichUiSlotItem($plugin, $item, $slot);
        }

        return null;
    }

    /**
     * UI legado (Plugin/...) ou runtime (frontend.exports no manifest + dist).
     */
    private static function isValidUiComponentDeclaration(array $plugin, string $component, string $slot): bool
    {
        if ($component === '') {
            return PluginExtensionRegistry::hasRuntimeFrontend($plugin)
                && PluginExtensionRegistry::resolveExportForSlot($plugin, $slot) !== null;
        }
        if (str_starts_with($component, 'Plugin/')) {
            return true;
        }
        if (PluginExtensionRegistry::hasRuntimeFrontend($plugin)) {
            $export = PluginExtensionRegistry::resolveExportForSlot($plugin, $slot);

            return $export !== null && $export !== '';
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfig(string $slug, array $default = []): array
    {
        if (! self::tableExists()) {
            return $default;
        }
        $record = PluginModel::find($slug);
        if (! $record) {
            return $default;
        }
        $config = $record->config;
        if (! is_array($config)) {
            return $default;
        }

        return array_replace($default, $config);
    }

    public static function setConfig(string $slug, array $config): bool
    {
        if (! self::tableExists()) {
            return false;
        }
        $record = PluginModel::find($slug);
        if (! $record) {
            return false;
        }
        $record->update(['config' => $config]);

        return true;
    }

    /**
     * @return array<int, string>
     */
    public static function validatePluginPackage(string $pluginPath): array
    {
        $errors = [];
        $manifest = self::readManifest($pluginPath);
        if ($manifest === null) {
            return ['plugin.json ausente ou inválido.'];
        }
        $slug = (string) ($manifest['slug'] ?? basename($pluginPath));
        if ($slug === '') {
            $errors[] = 'slug obrigatório no manifest.';
        }
        $bootstrap = $pluginPath.DIRECTORY_SEPARATOR.'bootstrap.php';
        if (is_file($bootstrap)) {
            $syntax = self::checkPhpSyntax($bootstrap);
            if ($syntax !== null) {
                $errors[] = 'bootstrap.php: '.$syntax;
            }
        }
        $plugin = array_merge($manifest, ['slug' => $slug, 'path' => $pluginPath]);
        $errors = array_merge($errors, PluginExtensionRegistry::validateFrontend($plugin));
        $errors = array_merge($errors, PluginPublicRouteRegistrar::validatePublicRoutePrefixes());
        $errors = array_merge($errors, PluginApiRouteRegistrar::validateApiRoutePrefixes());
        $errors = array_merge($errors, self::validateDistSize($pluginPath));
        $errors = array_merge($errors, self::validateApiRoutesFile($pluginPath, $manifest));
        $errors = array_merge($errors, self::validateCommerceScopes($manifest));
        $errors = array_merge($errors, self::validateTheme($manifest));
        $errors = array_merge($errors, self::validateCapabilities($manifest));
        $errors = array_merge($errors, self::validateRenderZones($manifest));
        $errors = array_merge($errors, PluginMiddlewareRegistry::validateManifestEntries($manifest));
        $routes = $manifest['routes'] ?? null;
        if (is_string($routes) && $routes !== '') {
            $routesFile = $pluginPath.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $routes);
            if (! is_file($routesFile)) {
                $errors[] = "Arquivo de rotas não encontrado: {$routes}";
            }
        }

        return $errors;
    }

    /**
     * @return array<int, string>
     */
    /**
     * @param  array<string, mixed>  $manifest
     * @return array<int, string>
     */
    private static function validateApiRoutesFile(string $pluginPath, array $manifest): array
    {
        $decl = $manifest['api_routes'] ?? null;
        if ($decl === null || $decl === '' || $decl === []) {
            return [];
        }
        $file = is_array($decl) ? ($decl['file'] ?? $decl['path'] ?? 'routes-api.php') : $decl;
        if (! is_string($file) || $file === '') {
            return ['api_routes: arquivo inválido.'];
        }
        $routesFile = $pluginPath.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);
        if (! is_file($routesFile)) {
            return ["api_routes: arquivo não encontrado: {$file}"];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<int, string>
     */
    private static function validateCommerceScopes(array $manifest): array
    {
        $scopes = $manifest['commerce_scopes'] ?? null;
        if ($scopes === null) {
            return [];
        }
        if (! is_array($scopes)) {
            return ['commerce_scopes deve ser um array.'];
        }
        $allowed = ['catalog:read', 'cart:write', 'checkout:start', 'orders:read'];
        foreach ($scopes as $scope) {
            if (! is_string($scope) || ! in_array($scope, $allowed, true)) {
                return ['commerce_scopes contém valor inválido: '.(string) $scope];
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<int, string>
     */
    private static function validateTheme(array $manifest): array
    {
        $theme = $manifest['theme'] ?? null;
        if ($theme === null) {
            return [];
        }
        if (! is_array($theme)) {
            return ['theme deve ser um objeto.'];
        }
        $allowedTargets = ['panel', 'checkout', 'member_area', 'public', 'all'];
        $targets = $theme['targets'] ?? null;
        if ($targets !== null) {
            if (! is_array($targets)) {
                return ['theme.targets deve ser um array.'];
            }
            foreach ($targets as $target) {
                if (! is_string($target) || ! in_array($target, $allowedTargets, true)) {
                    return ['theme.targets contém valor inválido: '.(string) $target];
                }
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<int, string>
     */
    private static function validateCapabilities(array $manifest): array
    {
        $capabilities = $manifest['capabilities'] ?? null;
        if ($capabilities === null) {
            return [];
        }
        if (! is_array($capabilities)) {
            return ['capabilities deve ser um array.'];
        }
        foreach ($capabilities as $cap) {
            if (is_string($cap)) {
                if (trim($cap) === '') {
                    return ['capabilities contém string vazia.'];
                }
                continue;
            }
            if (is_array($cap)) {
                $id = trim((string) ($cap['id'] ?? ''));
                if ($id === '') {
                    return ['capabilities: cada objeto deve ter id.'];
                }
                continue;
            }

            return ['capabilities contém entrada inválida.'];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<int, string>
     */
    private static function validateRenderZones(array $manifest): array
    {
        $zones = $manifest['render_zones'] ?? null;
        if ($zones === null) {
            return [];
        }
        if (! is_array($zones)) {
            return ['render_zones deve ser um objeto.'];
        }
        foreach ($zones as $zoneId => $decl) {
            if (! is_string($zoneId) || trim($zoneId) === '') {
                return ['render_zones contém chave de zona inválida.'];
            }
            if (! is_array($decl)) {
                return ["render_zones.{$zoneId} deve ser um objeto."];
            }
            $export = trim((string) ($decl['export'] ?? ''));
            if ($export === '') {
                return ["render_zones.{$zoneId} requer export."];
            }
        }

        return [];
    }

    private static function validateDistSize(string $pluginPath): array
    {
        $dist = $pluginPath.DIRECTORY_SEPARATOR.'dist';
        if (! is_dir($dist)) {
            return [];
        }
        $maxBytes = (int) config('plugins.max_dist_bytes', 15 * 1024 * 1024);
        $total = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dist, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $total += $file->getSize();
            }
        }
        if ($total > $maxBytes) {
            return ['dist/ excede tamanho máximo ('.round($total / 1024 / 1024, 1).' MB).'];
        }

        return [];
    }

    private static function checkPhpSyntax(string $file): ?string
    {
        if (! is_readable($file)) {
            return 'arquivo não legível.';
        }

        $code = @file_get_contents($file);
        if ($code === false) {
            return 'não foi possível ler o arquivo.';
        }

        // Evita exec(PHP_BINARY): em php-fpm, PHP_BINARY aponta para php-fpm e "-l" imprime o help.
        try {
            token_get_all($code, TOKEN_PARSE);
        } catch (\ParseError $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Only plugins that are enabled (for loading bootstrap and routes).
     *
     * @return array<int, array{slug: string, name: string, version: string, path: string, menu?: array, routes?: string|array, events?: array}>
     */
    public static function enabled(): array
    {
        $installed = self::installed();

        return array_values(array_filter($installed, fn ($p) => $p['is_enabled']));
    }

    public static function enable(string $slug): bool
    {
        self::syncFromDisk();
        if (! self::tableExists()) {
            return false;
        }
        $plugin = PluginModel::find($slug);
        if (! $plugin) {
            $plugin = PluginModel::create([
                'slug' => $slug,
                'name' => $slug,
                'version' => '1.0.0',
                'is_enabled' => true,
            ]);
        } else {
            $plugin->update(['is_enabled' => true]);
        }
        self::clearRouteCacheIfCached();

        return true;
    }

    public static function disable(string $slug): bool
    {
        if (! self::tableExists()) {
            return false;
        }
        $plugin = PluginModel::find($slug);
        if ($plugin) {
            $plugin->update(['is_enabled' => false]);
            self::clearRouteCacheIfCached();

            return true;
        }

        return false;
    }

    private static function isPluginDirUnderAllowedRoots(string $pluginDirReal): bool
    {
        $sep = DIRECTORY_SEPARATOR;
        foreach (self::discoveryRoots() as $root) {
            if ($root === '' || ! is_dir($root)) {
                continue;
            }
            $base = realpath($root);
            if ($base === false) {
                continue;
            }
            if ($pluginDirReal === $base) {
                return false;
            }
            $prefix = $base.$sep;
            if (str_starts_with($pluginDirReal, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Uninstall plugin: delete plugin directory from disk, then remove from DB.
     * Pass $pluginPath (from installed()['path']) when the folder name differs from slug.
     */
    public static function uninstall(string $slug, ?string $pluginPath = null): bool
    {
        $pluginDir = $pluginPath !== null && $pluginPath !== ''
            ? realpath($pluginPath)
            : realpath(self::userInstallRoot().DIRECTORY_SEPARATOR.$slug);

        if ($pluginDir === false || ! is_dir($pluginDir)) {
            $pluginDir = realpath(self::bundledPluginsPath().DIRECTORY_SEPARATOR.$slug);
        }

        if ($pluginDir !== false && is_dir($pluginDir)) {
            if (! self::isPluginDirUnderAllowedRoots($pluginDir)) {
                return false;
            }
            if (! self::deletePluginDirectory($pluginDir)) {
                return false;
            }
        }

        if (self::tableExists()) {
            PluginModel::where('slug', $slug)->delete();
        }
        self::clearRouteCacheIfCached();

        return true;
    }

    /**
     * Recursively delete a directory. Makes files/dirs writable first to avoid failures on Windows.
     */
    private static function deletePluginDirectory(string $dir): bool
    {
        if (! is_dir($dir)) {
            return true;
        }
        $items = @scandir($dir);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($full)) {
                if (! is_link($full) && ! self::deletePluginDirectory($full)) {
                    return false;
                }
            } else {
                @chmod($full, 0777);
                if (! @unlink($full) && file_exists($full)) {
                    return false;
                }
            }
        }
        @chmod($dir, 0777);
        if (! @rmdir($dir) && is_dir($dir)) {
            return false;
        }

        return true;
    }

    /**
     * Read and validate plugin.json. Returns manifest array or null.
     *
     * @return array<string, mixed>|null
     */
    public static function readManifest(string $pluginPath): ?array
    {
        $manifestFile = rtrim($pluginPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'plugin.json';
        if (! is_file($manifestFile)) {
            return null;
        }
        $raw = file_get_contents($manifestFile);
        $manifest = json_decode($raw, true);
        if (! is_array($manifest)) {
            return null;
        }
        if (empty($manifest['name'])) {
            $manifest['name'] = basename($pluginPath);
        }
        if (empty($manifest['slug'])) {
            $manifest['slug'] = basename($pluginPath);
        }
        if (empty($manifest['version'])) {
            $manifest['version'] = '1.0.0';
        }

        return $manifest;
    }

    /**
     * Menu items for the sidebar: aggregate from all enabled plugins that have "menu" in manifest.
     * Format: [{ name, href, icon? }, ...]
     *
     * @return array<int, array{name: string, href: string, icon?: string}>
     */
    public static function getMenuItems(): array
    {
        $items = [];
        foreach (self::enabled() as $plugin) {
            $menu = $plugin['menu'] ?? null;
            if (! is_array($menu)) {
                continue;
            }
            foreach ($menu as $entry) {
                if (empty($entry['label']) || empty($entry['href'])) {
                    continue;
                }
                $items[] = [
                    'name' => $entry['label'],
                    'href' => $entry['href'],
                    'icon' => $entry['icon'] ?? null,
                ];
            }
        }

        return $items;
    }

    /**
     * Register a plugin that is on disk but not yet in DB (e.g. extracted manually).
     * Creates the DB record and returns true. Caller should run migrations after.
     */
    public static function register(string $slug): bool
    {
        $installed = collect(self::installed())->keyBy('slug');
        $plugin = $installed->get($slug);
        if (! $plugin) {
            return false;
        }
        if (! self::tableExists()) {
            return false;
        }
        PluginModel::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $plugin['name'],
                'version' => $plugin['version'],
                'is_enabled' => true,
            ]
        );
        self::clearRouteCacheIfCached();

        return true;
    }

    /**
     * Sync DB from disk: insert any new plugin dirs as enabled by default; do not disable existing.
     */
    public static function syncFromDisk(): void
    {
        if (! self::tableExists()) {
            return;
        }
        foreach (self::installed() as $p) {
            PluginModel::firstOrCreate(
                ['slug' => $p['slug']],
                [
                    'name' => $p['name'],
                    'version' => $p['version'],
                    'is_enabled' => true,
                ]
            );
        }
    }

    private static function tableExists(): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasTable('plugins');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Rotas de plugins são registradas no boot conforme o que está habilitado no banco.
     * Com `php artisan route:cache`, a lista fica congelada até limpar o cache.
     */
    private static function clearRouteCacheIfCached(): void
    {
        try {
            if (app()->routesAreCached()) {
                Artisan::call('route:clear');
            }
        } catch (\Throwable) {
            //
        }
    }
}
