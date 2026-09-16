<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApplePayWellKnownTest extends TestCase
{
    public function test_apple_pay_domain_association_is_public(): void
    {
        $path = public_path('.well-known/apple-developer-merchantid-domain-association');
        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));

        $response = $this->get('/.well-known/apple-developer-merchantid-domain-association');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertMatchesRegularExpression('/^[0-9a-fA-F]+$/', trim((string) file_get_contents($path)));
    }
}
