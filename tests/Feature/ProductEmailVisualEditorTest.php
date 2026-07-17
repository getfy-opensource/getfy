<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class ProductEmailVisualEditorTest extends TestCase
{
    public function test_product_update_persists_visual_email_html(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'name' => 'Produto com editor visual',
            'slug' => 'produto-editor-visual',
        ]);

        $accessHtml = '<h1>Olá {nome_cliente}</h1><p><strong>{nome_produto}</strong></p>';
        $recoveryHtml = '<p>Retome por <a href="{link_checkout}">este link</a>.</p>';

        $response = $this->actingAs($user)->put("/produtos/{$product->id}", [
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => '',
            'type' => Product::TYPE_LINK,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 49.9,
            'currency' => 'BRL',
            'is_active' => true,
            'email_template' => [
                'subject' => 'Seu acesso a {nome_produto}',
                'body_text' => '',
                'body_html' => $accessHtml,
            ],
            'cart_recovery_email' => [
                'enabled' => true,
                'stages' => [
                    '10m' => [
                        'subject' => 'Finalize sua compra',
                        'body_text' => '',
                        'body_html' => $recoveryHtml,
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();

        $config = $product->fresh()->checkout_config;
        $this->assertSame($accessHtml, $config['email_template']['body_html']);
        $this->assertEmpty($config['email_template']['body_text']);
        $this->assertSame($recoveryHtml, $config['cart_recovery_email']['stages']['10m']['body_html']);
        $this->assertEmpty($config['cart_recovery_email']['stages']['10m']['body_text']);
    }
}
