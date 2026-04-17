<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class ForceHttpsWhenForwardedProto
{
    public function handle(Request $request, Closure $next): Response
    {
        $isHttpsForwarded = $this->requestIsHttpsBehindProxy($request);

        if ($isHttpsForwarded) {
            URL::forceScheme('https');
            $request->server->set('HTTPS', 'on');
            $request->server->set('SERVER_PORT', '443');
        }

        return $next($request);
    }

    private function requestIsHttpsBehindProxy(Request $request): bool
    {
        $proto = strtolower((string) $request->headers->get('x-forwarded-proto', ''));
        if (str_contains($proto, 'https')) {
            return true;
        }

        $cfVisitor = strtolower((string) $request->headers->get('cf-visitor', ''));
        if (str_contains($cfVisitor, 'https')) {
            return true;
        }

        // Alguns proxies (nginx, etc.): SSL terminado no edge
        if (strtolower((string) $request->headers->get('x-forwarded-ssl', '')) === 'on') {
            return true;
        }

        // RFC 7239 — ex.: Forwarded: for=…;proto=https;host=…
        $forwarded = (string) $request->headers->get('Forwarded', '');
        if ($forwarded !== '' && preg_match('/proto=(?<p>[a-z][a-z0-9.+-]*)/i', $forwarded, $m)) {
            if (strtolower($m['p']) === 'https') {
                return true;
            }
        }

        // Cloudflare / fornecedores que enviam apenas o primeiro hop em X-Forwarded-Proto
        $front = strtolower((string) $request->headers->get('front-end-https', ''));
        if ($front === 'on') {
            return true;
        }

        return false;
    }
}

