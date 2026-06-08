<?php

namespace App\Gateways;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

class GatewayRegistry
{
    /** @var array<string, array<string, mixed>> */
    private static array $custom = [];

    /**
     * Register a gateway (e.g. from a plugin). Merges with core gateways from config.
     * BLOQUEADO: Apenas CajuPay e Asaas são permitidos. Plugins não podem adicionar/modificar gateways.
     *
     * @param  array{slug: string, name: string, image: string, methods: array, scope: string, signup_url: string, driver: string, credential_keys: array}  $gateway
     */
    public static function register(array $gateway): void
    {
        $slug = $gateway['slug'] ?? null;
        
        // Whitelist: apenas CajuPay e Asaas permitidos
        $allowed = ['cajupay', 'asaas'];
        
        if (!$slug || !is_string($slug)) {
            Log::warning('GatewayRegistry: tentativa de registrar gateway sem slug válido');
            return;
        }
        
        // Bloquear gateways não autorizados
        if (!in_array($slug, $allowed, true)) {
            Log::warning('GatewayRegistry: gateway bloqueado (não autorizado)', ['slug' => $slug]);
            return;
        }
        
        // Bloquear tentativas de sobrescrever via plugin
        $fromConfig = config('gateways.gateways', []);
        if (isset($fromConfig[$slug])) {
            Log::warning('GatewayRegistry: tentativa de plugin sobrescrever gateway core', ['slug' => $slug]);
            return;
        }
        
        self::$custom[$slug] = $gateway;
    }

    /**
     * All available gateways (config + custom from plugins).
     * Apenas gateways configurados no config/gateways.php são carregados.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $fromConfig = config('gateways.gateways', []);
        
        // Aqui só o config é retornado (plugins não podem adicionar)
        return array_values(array_map(function ($def, $slug) {
            $def['slug'] = $def['slug'] ?? $slug;
            return $def;
        }, $fromConfig, array_keys($fromConfig)));
    }

    /**
     * Get a single gateway definition by slug.
     *
     * @return array<string, mixed>|null
     */
    public static function get(string $slug): ?array
    {
        foreach (self::all() as $gateway) {
            if (($gateway['slug'] ?? '') === $slug) {
                return $gateway;
            }
        }
        return null;
    }

    /**
     * Get driver instance for a gateway slug.
     */
    public static function driver(string $slug): ?Contracts\GatewayDriver
    {
        $def = self::get($slug);
        if (!$def || empty($def['driver'])) {
            return null;
        }
        $class = $def['driver'];
        if (!is_string($class) || !class_exists($class)) {
            return null;
        }
        $instance = app($class);
        return $instance instanceof Contracts\GatewayDriver ? $instance : null;
    }

    /**
     * Resolve gateway image URL. Se a imagem for "plugin:{slug}/{path}", retorna a URL da rota de assets do plugin.
     * Plugins podem colocar a imagem em plugins/{slug}/assets/{path} e usar image => 'plugin:slug/path'.
     */
    public static function resolveImageUrl(?string $image): ?string
    {
        if ($image === null || $image === '') {
            return null;
        }
        if (str_starts_with($image, 'plugin:')) {
            $rest = substr($image, 7);
            $slash = strpos($rest, '/');
            if ($slash === false) {
                return null;
            }
            $pluginSlug = substr($rest, 0, $slash);
            $path = substr($rest, $slash + 1);
            $path = str_replace(['../', '..\\'], '', $path);
            if ($path === '' || preg_match('/\\.\\./', $path)) {
                return null;
            }
            if (Route::has('plugins.asset')) {
                return URL::route('plugins.asset', ['slug' => $pluginSlug, 'path' => $path]);
            }
            return null;
        }
        return $image;
    }
}
