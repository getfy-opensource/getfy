<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberPushSubscription;
use App\Models\PanelPushSubscription;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class PushSubscriptionsMultiDeviceTest extends TestCase
{
    public function test_panel_allows_multiple_subscriptions_per_user(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $payload1 = [
            'endpoint' => 'https://example.com/push/endpoint-1',
            'keys' => ['auth' => 'AAA+/=', 'p256dh' => 'BBB+/='],
        ];
        $payload2 = [
            'endpoint' => 'https://example.com/push/endpoint-2',
            'keys' => ['auth' => 'CCC+/=', 'p256dh' => 'DDD+/='],
        ];

        $this->actingAs($user)->postJson('/painel/push-subscribe', $payload1)->assertStatus(200);
        $this->actingAs($user)->postJson('/painel/push-subscribe', $payload2)->assertStatus(200);

        $this->assertSame(2, PanelPushSubscription::where('user_id', $user->id)->count());
        $this->assertNotNull(PanelPushSubscription::where('endpoint', $payload1['endpoint'])->first());
        $this->assertNotNull(PanelPushSubscription::where('endpoint', $payload2['endpoint'])->first());
    }

    public function test_member_area_allows_multiple_subscriptions_per_user_and_product(): void
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

        $payload1 = [
            'endpoint' => 'https://example.com/push/member-endpoint-1',
            'keys' => ['auth' => 'EEE+/=', 'p256dh' => 'FFF+/='],
        ];
        $payload2 = [
            'endpoint' => 'https://example.com/push/member-endpoint-2',
            'keys' => ['auth' => 'GGG+/=', 'p256dh' => 'HHH+/='],
        ];

        $this->actingAs($user)->postJson("/m/{$slug}/push-subscribe", $payload1)->assertStatus(200);
        $this->actingAs($user)->postJson("/m/{$slug}/push-subscribe", $payload2)->assertStatus(200);

        $this->assertSame(2, MemberPushSubscription::where('user_id', $user->id)->where('product_id', $product->id)->count());
        $this->assertNotNull(MemberPushSubscription::where('endpoint', $payload1['endpoint'])->first());
        $this->assertNotNull(MemberPushSubscription::where('endpoint', $payload2['endpoint'])->first());
    }
}
