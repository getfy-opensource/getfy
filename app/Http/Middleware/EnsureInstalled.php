<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstalled
{
    /**
     * Redirect to /install when the application has not been installed yet.
     * Lê .env diretamente para não depender do config cache (evita redirect loop pós-instalação).
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('up') || $request->is('install') || $request->is('install/*')
            || $request->is('docker-setup') || $request->is('docker-setup/*')
            || $request->is('manifest.json') || $request->is('painel-sw.js')) {
            return $next($request);
        }

        if (! $this->isInstalled()) {
            return redirect('/install', 302);
        }

        return $next($request);
    }

    private function isInstalled(): bool
    {
        $envPath = base_path('.env');
        if (! is_file($envPath)) {
            return false;
        }
        $content = file_get_contents($envPath);

        return (bool) preg_match('/^\s*APP_INSTALLED\s*=\s*["\']?true["\']?\s*(?:#|$)/mi', $content);
    }
}
