<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // CSP só em produção — no local o Vite usa localhost:5173 e seria bloqueado
        if (config('app.env') === 'production') {
            $csp = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://js.stripe.com https://sdk.mercadopago.com https://static.cloudflareinsights.com",
                "script-src-elem 'self' 'unsafe-inline' https://js.stripe.com https://sdk.mercadopago.com https://static.cloudflareinsights.com",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "img-src 'self' data: https: blob:",
                "font-src 'self' https://fonts.gstatic.com",
                "connect-src 'self' https://api.stripe.com https://api.mercadopago.com wss:",
                "frame-src 'self' https://js.stripe.com https://www.mercadopago.com https://www.youtube-nocookie.com https://youtube-nocookie.com https://www.youtube.com https://youtube.com",
                "media-src 'self' https: blob:",
            ]);
            $response->headers->set('Content-Security-Policy', $csp);
        }

        if (config('app.env') === 'production' && $request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        return $response;
    }
}
