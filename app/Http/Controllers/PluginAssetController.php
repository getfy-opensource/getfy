<?php

namespace App\Http\Controllers;

use App\Plugins\PluginRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serve assets estáticos de plugins (imagens, etc.) em GET /plugins/{slug}/assets/{path}.
 * Apenas arquivos dentro da pasta assets/ do plugin; sem directory traversal.
 */
class PluginAssetController extends Controller
{
    public function __invoke(Request $request, string $slug, string $path): Response|BinaryFileResponse
    {
        $pluginDir = PluginRegistry::resolvePluginDirectory($slug);
        if ($pluginDir === null || ! is_dir($pluginDir)) {
            abort(404);
        }

        $path = str_replace(['../', '..\\'], '', $path);
        $path = ltrim($path, '/\\');
        if ($path === '' || preg_match('/\\.\\./', $path)) {
            abort(404);
        }

        $assetsDir = $pluginDir . DIRECTORY_SEPARATOR . 'assets';
        $fullPath = $assetsDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $realAssets = realpath($assetsDir);
        $realFile = realpath($fullPath);

        if ($realAssets === false || $realFile === false || substr($realFile, 0, strlen($realAssets) + 1) !== $realAssets . DIRECTORY_SEPARATOR) {
            abort(404);
        }
        if (! is_file($realFile)) {
            abort(404);
        }

        $mime = match (strtolower(pathinfo($realFile, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            default => 'application/octet-stream',
        };

        return response()->file($realFile, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
