<?php

namespace Tests\Feature;

use App\Models\PixelXIntegration;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PixelXIntegrationStoreTest extends TestCase
{
    public function test_store_creates_integration_with_products(): void
    {
        $user = User::factory()->create([
            'tenant_id' => 1,
            'role' => User::ROLE_INFOPRODUTOR,
        ]);
        $product = $this->createTestProduct(['tenant_id' => 1, 'name' => 'Produto Pixel X']);

        $response = $this->actingAs($user)->postJson(route('integrations.pixel-x.store'), [
            'name' => 'Pixel X',
            'url' => 'https://pxa.pixelx.app/api/integration/ae25f529-50d2',
            'token' => 'secret-token-value',
            'events' => ['pix_gerado', 'pedido_pago'],
            'is_active' => true,
            'product_ids' => [(string) $product->id],
        ]);

        $response->assertCreated()
            ->assertJsonPath('integration.name', 'Pixel X')
            ->assertJsonPath('integration.has_token', true)
            ->assertJsonPath('integration.products.0.id', (string) $product->id);

        $this->assertDatabaseHas('pixel_x_integrations', [
            'tenant_id' => 1,
            'name' => 'Pixel X',
            'url' => 'https://pxa.pixelx.app/api/integration/ae25f529-50d2',
            'is_active' => 1,
        ]);

        $integration = PixelXIntegration::query()->where('name', 'Pixel X')->first();
        $this->assertNotNull($integration);
        $this->assertTrue($integration->products->contains('id', $product->id));
    }

    public function test_store_returns_503_when_tables_missing(): void
    {
        $user = User::factory()->create([
            'tenant_id' => 1,
            'role' => User::ROLE_INFOPRODUTOR,
        ]);

        Schema::dropIfExists('pixel_x_integration_logs');
        Schema::dropIfExists('pixel_x_integration_product');
        Schema::dropIfExists('pixel_x_integrations');

        $response = $this->actingAs($user)->postJson(route('integrations.pixel-x.store'), [
            'name' => 'Pixel X',
            'url' => 'https://pxa.pixelx.app/api/integration/test',
            'token' => 'secret',
            'events' => ['pix_gerado'],
            'is_active' => true,
        ]);

        $response->assertStatus(503)
            ->assertJsonFragment(['message' => 'Tabelas da Pixel X não existem neste ambiente. Execute: php artisan migrate']);
    }
}
