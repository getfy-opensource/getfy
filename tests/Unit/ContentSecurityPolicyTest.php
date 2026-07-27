<?php

namespace Tests\Unit;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

class ContentSecurityPolicyTest extends TestCase
{
    public function test_csp_config_includes_checkout_payment_and_analytics_origins(): void
    {
        $scriptSrc = implode(' ', config('csp.script_src', []));
        $connectSrc = implode(' ', config('csp.connect_src', []));

        $this->assertStringContainsString('https://cdn.cajupay.com.br', $scriptSrc);
        $this->assertStringContainsString('https://cdn.utmify.com.br', $scriptSrc);
        $this->assertStringContainsString('https://challenges.cloudflare.com', $scriptSrc);
        $this->assertStringContainsString('https://www.googleadservices.com', $scriptSrc);

        $this->assertStringContainsString('https://api.cajupay.com.br', $connectSrc);
        $this->assertStringContainsString('https://tokenizer.sejaefi.com.br', $connectSrc);
        $this->assertStringContainsString('https://www.google-analytics.com', $connectSrc);
        $this->assertStringContainsString('https://www.googleadservices.com', $connectSrc);
        $this->assertStringContainsString('https://googleads.g.doubleclick.net', $connectSrc);
        $this->assertStringContainsString('https://api.utmify.com.br', $connectSrc);
        $this->assertStringContainsString('https://connect.facebook.net', $connectSrc);
        $this->assertStringContainsString('https://graph.facebook.com', $connectSrc);
        $this->assertStringContainsString('https://*.facebook.com', $connectSrc);
        $this->assertStringContainsString('https://*.facebook.net', $connectSrc);
    }

    public function test_production_response_includes_cajupay_in_csp_header(): void
    {
        config(['app.env' => 'production']);

        $middleware = new SecurityHeaders;
        $request = Request::create('/c/test', 'GET');
        $response = $middleware->handle($request, fn () => new Response('', 200));

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertNotSame('', $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString('https://cdn.cajupay.com.br', $csp);
        $this->assertStringContainsString('https://api.cajupay.com.br', $csp);
        $this->assertStringContainsString('https://www.google-analytics.com', $csp);
    }
}
