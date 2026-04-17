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
            $cspConnectExtra = $this->cspExtraConnectSrcForStorage();
            $csp = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://js.stripe.com https://sdk.mercadopago.com https://http2.mlstatic.com https://*.mlstatic.com https://static.cloudflareinsights.com https://checkout.pagar.me https://connect.facebook.net https://www.googletagmanager.com https://analytics.tiktok.com",
                "script-src-elem 'self' 'unsafe-inline' https://js.stripe.com https://sdk.mercadopago.com https://http2.mlstatic.com https://*.mlstatic.com https://static.cloudflareinsights.com https://checkout.pagar.me https://connect.facebook.net https://www.googletagmanager.com https://analytics.tiktok.com",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "img-src 'self' data: https: blob:",
                "font-src 'self' https://fonts.gstatic.com",
                "connect-src 'self' https://api.stripe.com https://api.mercadopago.com https://*.mercadopago.com https://*.mercadopago.com.br https://http2.mlstatic.com https://*.mlstatic.com https://api.mercadolibre.com https://www.mercadolibre.com https://*.mercadolibre.com https://viacep.com.br https://api.pagar.me https://www.facebook.com https://www.googletagmanager.com https://analytics.tiktok.com wss: blob:".$cspConnectExtra,
                "frame-src 'self' https://js.stripe.com https://www.mercadopago.com https://*.mercadopago.com https://*.mercadopago.com.br https://www.mercadolibre.com https://*.mercadolibre.com https://www.youtube-nocookie.com https://youtube-nocookie.com https://www.youtube.com https://youtube.com",
                "media-src 'self' https: blob:",
                "worker-src 'self' blob:",
            ]);
            $response->headers->set('Content-Security-Policy', $csp);
        }

        if (config('app.env') === 'production' && $request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        return $response;
    }

    /**
     * PDF.js (MemberPdfPresentationViewer) faz fetch ao URL do ficheiro; com S3/R2 o domínio é externo a 'self'.
     * Inclui o host de AWS_URL e entradas em CSP_EXTRA_CONNECT_SRC (vírgulas).
     *
     * @return string Sufixo com espaço à frente, ex.: " https://r2.exemplo.com", ou vazio.
     */
    private function cspExtraConnectSrcForStorage(): string
    {
        $origins = [];

        // Domínio público típico do R2 na Getfy Cloud — PDF.js faz fetch a este host mesmo sem AWS_URL no .env da app.
        if (! config('csp.disable_getfy_r2_origin', false)) {
            $origins[] = 'https://r2.getfy.cloud';
        }

        $csv = (string) config('csp.extra_connect_src', '');
        if ($csv !== '') {
            foreach (explode(',', $csv) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $origins[] = $part;
                }
            }
        }
        $awsUrl = (string) config('filesystems.disks.s3.url', '');
        if ($awsUrl === '') {
            $awsUrl = (string) env('AWS_URL', '');
        }
        if ($awsUrl !== '' && str_starts_with($awsUrl, 'http')) {
            $parsed = parse_url($awsUrl);
            $scheme = $parsed['scheme'] ?? '';
            $host = $parsed['host'] ?? '';
            if (($scheme === 'https' || $scheme === 'http') && $host !== '') {
                $origins[] = $scheme.'://'.$host;
            }
        }
        $origins = array_values(array_unique($origins));
        if ($origins === []) {
            return '';
        }

        return ' '.implode(' ', $origins);
    }
}
