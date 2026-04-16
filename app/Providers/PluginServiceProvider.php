<?php

namespace App\Providers;

use App\Plugins\PluginRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $plugins = $this->getPluginsToLoad();
        foreach ($plugins as $plugin) {
            $this->loadPluginBootstrap($plugin);
            $this->loadPluginMigrations($plugin);
            $this->loadPluginRoutes($plugin);
        }
    }

    /**
     * Plugins to load: when registry table exists, only enabled; else fallback to all on disk with manifest.
     *
     * @return array<int, array{slug: string, path: string, menu?: array, routes?: string|array, events?: array}>
     */
    private function getPluginsToLoad(): array
    {
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('plugins')) {
                return PluginRegistry::enabled();
            }
        } catch (\Throwable) {}
        return $this->fallbackInstalledFromDisk();
    }

    /**
     * Fallback when plugins table does not exist: load every dir with plugin.json.
     */
    private function fallbackInstalledFromDisk(): array
    {
        return PluginRegistry::fallbackRowsWithoutDatabase();
    }

    private function loadPluginMigrations(array $plugin): void
    {
        $migrationsPath = $plugin['migrations'] ?? null;
        if (! is_string($migrationsPath) || $migrationsPath === '') {
            return;
        }
        $fullPath = $plugin['path'].DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $migrationsPath);
        if (! is_dir($fullPath)) {
            return;
        }
        $this->loadMigrationsFrom($fullPath);
    }

    private function loadPluginBootstrap(array $plugin): void
    {
        $bootstrap = $plugin['path'].DIRECTORY_SEPARATOR.'bootstrap.php';
        if (! is_file($bootstrap)) {
            return;
        }
        $register = require $bootstrap;
        if (is_callable($register)) {
            $register($this->app, Event::getFacadeRoot());
        }
    }

    private function loadPluginRoutes(array $plugin): void
    {
        $routesDecl = $plugin['routes'] ?? null;
        $pluginPath = $plugin['path'];
        $slug = $plugin['slug'];

        $routesFile = null;
        if (is_string($routesDecl) && $routesDecl !== '') {
            $routesFile = $pluginPath.DIRECTORY_SEPARATOR.$routesDecl;
        } elseif ($routesDecl === null || $routesDecl === true) {
            $default = $pluginPath.DIRECTORY_SEPARATOR.'routes.php';
            if (is_file($default)) {
                $routesFile = $default;
            }
        }
        if ($routesFile === null || ! is_file($routesFile)) {
            return;
        }

        $prefix = $slug;
        Route::middleware(['web', 'auth', 'role:admin|infoprodutor'])
            ->prefix($prefix)
            ->group($routesFile);
    }
}
