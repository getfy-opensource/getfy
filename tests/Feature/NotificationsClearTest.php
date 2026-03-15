<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberNotification;
use App\Models\PanelNotification;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationsClearTest extends TestCase
{
    public function test_panel_clear_all_removes_user_notifications(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        PanelNotification::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'A',
            'body' => 'B',
        ]);
        PanelNotification::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'C',
            'body' => 'D',
        ]);

        $this->actingAs($user)->deleteJson('/painel/notifications')
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertSame(0, PanelNotification::where('user_id', $user->id)->count());
    }

    public function test_member_area_clear_all_removes_user_product_notifications(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $user = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);

        $slug = strtolower('abc' . Str::random(3));
        $product = new Product([
            'tenant_id' => 1,
            'name' => 'Área de Membros',
            'slug' => $slug,
            'checkout_slug' => $slug,
            'type' => Product::TYPE_AREA_MEMBROS,
            'price' => 0,
            'is_active' => true,
            'member_area_config' => [
                'pwa' => ['push_enabled' => true],
            ],
        ]);
        $product->id = 1;
        $product->save();
        $user->products()->attach($product->id);

        MemberNotification::create([
            'product_id' => (string) $product->id,
            'user_id' => $user->id,
            'type' => 'push',
            'title' => 'A',
            'body' => 'B',
        ]);
        MemberNotification::create([
            'product_id' => (string) $product->id,
            'user_id' => $user->id,
            'type' => 'push',
            'title' => 'C',
            'body' => 'D',
        ]);

        $this->actingAs($user)->deleteJson("/m/{$slug}/notifications")
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertSame(0, MemberNotification::where('user_id', $user->id)->where('product_id', (string) $product->id)->count());
    }
}

